# V1.1 缺口补全 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把页面目录中 63 处“后端接口待补”全部实现为真实 API，并通过统一命令闭环、投影契约与平台内核，使后续页面边际成本下降、授权与审计可取证。

**Architecture:** 先建三个内核（Command Envelope、Projection Contract、Platform Kernel）与 5 个 ADR；再把 63 项归并为 12 个能力集群（K1–K12），每个集群一次交付“命令/查询/权限/五态/负测/真实 E2E”；旧 193 条路由不强制重写，新集群必须使用内核。

**Tech Stack:** PHP 8.4 / Laravel 13 / PHPUnit / PHPStan / Pint / MySQL 8.4；Vue 3.5 / TypeScript 5.9 / Vite 7 / Vitest / Playwright；OpenAPI 3.1 + openapi-typescript。

## Global Constraints

- PHP 8.4、Laravel 13、MySQL 8.4；金额只用整数分，禁止 float。
- UTC 存储、显式业务时区；业务日期经 `TenantBusinessDate` / `BusinessDateRule`。
- 每个 `/v1` 路由必须能力守卫或有显式公共白名单；`capability:audit` 零警告。
- 一表一 writer；跨模块只经 Contracts；平台不得反向依赖业务模块。
- 迁移前向 only；不修改已合并迁移；新表声明 owning module。
- OpenAPI 3.1 是契约事实源；路由、`x-capability`、`endpoint_matrix.php`、角色模板同提交一致。
- 写命令必须带 `Idempotency-Key`；缺键 422；重放 200 + `Idempotency-Replayed`；同键不同 payload 409。
- P3 读取/导出必须写 `SensitiveAccessAuditor`；字段策略只允许收紧。
- 不得造假数据/假接口/假 E2E；缺口未实现必须显式 `后端接口待补`。
- 每个 Task 结束必须跑对应测试；每个 Phase 结束跑全量门禁。

## 文件结构总览

```text
backend/app/Shared/
  Commands/            # Command Envelope 内核
  Projections/         # Projection Contract 内核
backend/app/Modules/Platform/
  Services/            # 字典/参数/通知/文件/运维内核
  Models/
backend/app/Modules/{Organization,People,Time,Payroll,Talent,Reporting}/
  Http/Controllers/    # 每集群新增控制器（薄）
  Services/            # 命令/查询实现
  Models/
backend/database/migrations/   # 2026_11_25_1xxxxx_* 前向迁移
backend/tests/Feature/<Domain>/ # 每集群功能与负测
frontend/src/views/{people,timepay,talent,platform}/  # gap 态切换为真实操作
frontend/tests/unit/            # 每集群组件契约测试
docs/openapi/openapi.yaml       # 每端点同提交
docs/audit/v11-coverage-matrix.md  # 集群状态
```

---

## Phase A：内核（先于 63 项）

### Task A1: Command Envelope 最小闭环

**Files:**
- Create: `backend/app/Shared/Commands/CommandDefinition.php`
- Create: `backend/app/Shared/Commands/Invariant.php`
- Create: `backend/app/Shared/Commands/CommandResult.php`
- Create: `backend/app/Shared/Commands/CommandRunner.php`
- Modify: `backend/app/Providers/AppServiceProvider.php`
- Test: `backend/tests/Unit/Commands/CommandRunnerTest.php`

**Interfaces:**
- Consumes: `IdempotencyRunner`、`PrincipalResolver`
- Produces: `CommandRunner::run(CommandDefinition $definition, array $payload, Closure $operation, string $idempotencyKey): CommandResult`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Authorization\AnchorType;
use Tests\TestCase;

final class CommandRunnerTest extends TestCase
{
    public function test_replaying_the_same_idempotency_key_returns_the_original_body(): void
    {
        $runner = $this->app->make(CommandRunner::class);
        $definition = new CommandDefinition(
            name: 'test.command',
            capability: 'people.employee.manage',
            anchor: AnchorType::LEGAL_ENTITY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: 'test.command.executed',
        );
        $calls = 0;
        $operation = function () use (&$calls): array {
            $calls++;

            return ['http_status' => 201, 'body' => ['ok' => true], 'replayed' => false];
        };

        $first = $runner->run($definition, ['x' => 1], $operation, 'same-key');
        $second = $runner->run($definition, ['x' => 1], $operation, 'same-key');

        $this->assertSame(201, $first->status);
        $this->assertTrue($second->replayed);
        $this->assertSame(1, $calls);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact --filter=CommandRunnerTest`
Expected: FAIL with "Class App\Shared\Commands\CommandRunner not found".

- [ ] **Step 3: Write minimal implementation**

`CommandDefinition.php`：

```php
<?php

declare(strict_types=1);

namespace App\Shared\Commands;

use App\Shared\Authorization\AnchorType;

final class CommandDefinition
{
    /**
     * @param  list<class-string<Invariant>>  $invariants
     */
    public function __construct(
        public readonly string $name,
        public readonly string $capability,
        public readonly AnchorType $anchor,
        public readonly bool $idempotent,
        public readonly array $invariants,
        public readonly ?string $effect,
        public readonly string $auditAction,
    ) {}
}
```

`Invariant.php`：

```php
<?php

declare(strict_types=1);

namespace App\Shared\Commands;

interface Invariant
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function assert(int $tenantId, array $payload): void;
}
```

`CommandResult.php`：

```php
<?php

declare(strict_types=1);

namespace App\Shared\Commands;

final class CommandResult
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly bool $replayed = false,
    ) {}
}
```

`CommandRunner.php`（按 `IdempotencyResult` 实际字段调整；先读 `backend/app/Modules/Platform/Contracts/IdempotentResult.php`）：

```php
<?php

declare(strict_types=1);

namespace App\Shared\Commands;

use App\Modules\Platform\Contracts\IdempotencyRunner;
use App\Shared\Authorization\PrincipalResolver;
use Closure;
use Illuminate\Support\Facades\DB;

final class CommandRunner
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly IdempotencyRunner $idempotency,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(): array{http_status: int, body: array<string, mixed>, resource_type?: string, resource_id?: string}  $operation
     */
    public function run(CommandDefinition $definition, array $payload, Closure $operation, string $idempotencyKey): CommandResult
    {
        $principal = $this->principals->resolve();

        return DB::transaction(function () use ($definition, $payload, $operation, $idempotencyKey, $principal): CommandResult {
            $result = $this->idempotency->run(
                tenantId: $principal->tenantId(),
                principalId: $principal->userId(),
                scope: 'CMD:'.$definition->name,
                key: $idempotencyKey,
                payload: $payload,
                operation: $operation,
            );

            return new CommandResult(
                status: $result->replayed ? 200 : (int) $result->body['http_status'],
                body: (array) $result->body['body'],
                replayed: $result->replayed,
            );
        });
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact --filter=CommandRunnerTest`
Expected: PASS（1 test）。

- [ ] **Step 5: Commit**

```bash
git add backend/app/Shared/Commands backend/app/Providers/AppServiceProvider.php backend/tests/Unit/Commands/CommandRunnerTest.php
git commit -m "feat(v11): add command envelope kernel"
```

### Task A2: Projection Contract 与统一 envelope

**Files:**
- Create: `backend/app/Shared/Projections/ProjectionRequest.php`
- Create: `backend/app/Shared/Projections/ProjectionResult.php`
- Create: `backend/app/Shared/Projections/ProjectionResponder.php`
- Test: `backend/tests/Unit/Projections/ProjectionResponderTest.php`

**Interfaces:**
- Produces: `ProjectionResponder::ok(ProjectionResult $result): JsonResponse`，固定字段 `items/page/page_size/total/as_of/freshness/source_hash/partial/partial_reason/drilldown`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Projections;

use App\Shared\Projections\ProjectionResponder;
use App\Shared\Projections\ProjectionResult;
use Tests\TestCase;

final class ProjectionResponderTest extends TestCase
{
    public function test_projection_envelope_has_contract_fields(): void
    {
        $result = new ProjectionResult(
            items: [['id' => 1]],
            page: 1,
            pageSize: 20,
            total: 1,
            asOf: '2026-11-25',
            freshness: 'LIVE',
            sourceHash: str_repeat('a', 64),
            partial: false,
            partialReason: null,
            drilldown: null,
        );

        $response = (new ProjectionResponder)->ok($result)->getData(true);

        $this->assertSame('OK', $response['code']);
        foreach (['items', 'page', 'page_size', 'total', 'as_of', 'freshness', 'source_hash', 'partial', 'partial_reason', 'drilldown'] as $key) {
            $this->assertArrayHasKey($key, $response['data']);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact --filter=ProjectionResponderTest`
Expected: FAIL with class not found.

- [ ] **Step 3: Write minimal implementation**

`ProjectionRequest.php`：

```php
<?php

declare(strict_types=1);

namespace App\Shared\Projections;

final class ProjectionRequest
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $asOf,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly array $filters = [],
    ) {}
}
```

`ProjectionResult.php`：

```php
<?php

declare(strict_types=1);

namespace App\Shared\Projections;

final class ProjectionResult
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $drilldown
     */
    public function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly int $total,
        public readonly string $asOf,
        public readonly string $freshness,
        public readonly string $sourceHash,
        public readonly bool $partial,
        public readonly ?string $partialReason,
        public readonly ?array $drilldown,
    ) {}
}
```

`ProjectionResponder.php`：

```php
<?php

declare(strict_types=1);

namespace App\Shared\Projections;

use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;

final class ProjectionResponder
{
    public function ok(ProjectionResult $result): JsonResponse
    {
        return ApiResponse::ok([
            'items' => $result->items,
            'page' => $result->page,
            'page_size' => $result->pageSize,
            'total' => $result->total,
            'as_of' => $result->asOf,
            'freshness' => $result->freshness,
            'source_hash' => $result->sourceHash,
            'partial' => $result->partial,
            'partial_reason' => $result->partialReason,
            'drilldown' => $result->drilldown,
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact --filter=ProjectionResponderTest`
Expected: PASS（1 test）。

- [ ] **Step 5: Commit**

```bash
git add backend/app/Shared/Projections backend/tests/Unit/Projections/ProjectionResponderTest.php
git commit -m "feat(v11): add projection contract kernel"
```

### Task A3: Platform Kernel 表与字典/参数读取

**Files:**
- Create: `backend/database/migrations/2026_11_25_100000_create_platform_kernel_tables.php`
- Create: `backend/app/Modules/Platform/Models/DictionaryEntry.php`
- Create: `backend/app/Modules/Platform/Models/SystemParameter.php`
- Create: `backend/app/Modules/Platform/Contracts/DictionaryReader.php`
- Create: `backend/app/Modules/Platform/Services/DatabaseDictionaryReader.php`
- Modify: `backend/app/Modules/Platform/Providers/PlatformServiceProvider.php`
- Test: `backend/tests/Feature/Platform/PlatformKernelTest.php`

**Interfaces:**
- Produces: `DictionaryReader::entries(int $tenantId, string $type): array`；表 `sys_dictionaries`、`sys_parameters`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Platform\Contracts\DictionaryReader;
use App\Modules\Platform\Models\DictionaryEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlatformKernelTest extends TestCase
{
    use RefreshDatabase;

    public function test_dictionary_reader_returns_only_the_requested_type(): void
    {
        DictionaryEntry::query()->create(['tenant_id' => 1, 'type' => 'EMPLOYEE_STATUS', 'code' => 'ACTIVE', 'label' => '在职', 'sort_order' => 1]);
        DictionaryEntry::query()->create(['tenant_id' => 1, 'type' => 'CONTRACT_TYPE', 'code' => 'FIXED', 'label' => '固定期限', 'sort_order' => 1]);

        $entries = app(DictionaryReader::class)->entries(1, 'EMPLOYEE_STATUS');

        $this->assertCount(1, $entries);
        $this->assertSame('ACTIVE', $entries[0]['code']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact --filter=PlatformKernelTest`
Expected: FAIL with table/model not found.

- [ ] **Step 3: Write minimal implementation**

迁移：

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sys_dictionaries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('type', 64);
            $table->string('code', 64);
            $table->string('label', 160);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'type', 'code']);
        });
        Schema::create('sys_parameters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('key', 96);
            $table->json('value_json');
            $table->string('scope', 32)->default('TENANT');
            $table->timestamps();
            $table->unique(['tenant_id', 'key', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_parameters');
        Schema::dropIfExists('sys_dictionaries');
    }
};
```

模型：

```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

final class DictionaryEntry extends Model
{
    protected $table = 'sys_dictionaries';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'type', 'code', 'label', 'sort_order', 'active'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'active' => 'boolean'];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

final class SystemParameter extends Model
{
    protected $table = 'sys_parameters';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'key', 'value_json', 'scope'];

    protected function casts(): array
    {
        return ['value_json' => 'array'];
    }
}
```

Contract 与实现：

```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

interface DictionaryReader
{
    /**
     * @return list<array{code: string, label: string, sort_order: int}>
     */
    public function entries(int $tenantId, string $type): array;
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Contracts\DictionaryReader;
use App\Modules\Platform\Models\DictionaryEntry;

final class DatabaseDictionaryReader implements DictionaryReader
{
    public function entries(int $tenantId, string $type): array
    {
        return DictionaryEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('type', $type)
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get()
            ->map(static fn (DictionaryEntry $entry): array => [
                'code' => (string) $entry->code,
                'label' => (string) $entry->label,
                'sort_order' => (int) $entry->sort_order,
            ])->all();
    }
}
```

在 `PlatformServiceProvider::register()` 绑定 `DictionaryReader::class => DatabaseDictionaryReader::class`。

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact --filter=PlatformKernelTest`
Expected: PASS（1 test）。

- [ ] **Step 5: Commit**

```bash
git add backend/database/migrations/2026_11_25_100000_create_platform_kernel_tables.php backend/app/Modules/Platform backend/tests/Feature/Platform/PlatformKernelTest.php
git commit -m "feat(v11): add platform kernel dictionaries and parameters"
```

### Task A4: 44 个能力码与角色模板同步

**Files:**
- Modify: `backend/app/Modules/IAM/Authorization/CapabilityCatalog.php`
- Modify: `backend/app/Modules/IAM/Authorization/RoleTemplateCatalog.php`
- Modify: `backend/tests/Authorization/endpoint_matrix.php`
- Test: `backend/tests/Unit/Authorization/CapabilityCatalogTest.php`

**Interfaces:**
- Produces: `CapabilityCatalog::codes()` 长度 156；每个新码至少被一个模板授予

- [ ] **Step 1: Write the failing test**

```php
public function test_gap_completion_capabilities_are_issued_by_templates(): void
{
    $issued = [];
    foreach (\App\Modules\IAM\Authorization\RoleTemplateCatalog::templates() as $template) {
        $issued = [...$issued, ...$template->capabilityCodes()];
    }

    foreach ([
        'org.position.qualification.manage',
        'people.contract.terminate',
        'time.overtime.confirm',
        'payroll.compensation.manage',
        'performance.result.publish',
        'recruitment.candidate.convert',
        'training.exam.manage',
        'system.backup.manage',
        'reporting.definition.manage',
    ] as $code) {
        $this->assertContains($code, $issued, "No role template issues {$code}.");
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact --filter=CapabilityCatalogTest`
Expected: FAIL（能力码未登记/未授予）。

- [ ] **Step 3: Write minimal implementation**

按 Spec 5.3.1 的 44 行逐条加入 `CapabilityCatalog`（`CapabilityDefinition` 参数：code、resource、actions、anchorTypes、fieldProfileMax、risk、sodGroup、description），并在 `RoleTemplateCatalog` 对应模板 grants 中加入新码。完整 44 行清单见 Spec 5.3.1，不得遗漏。

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact --filter='CapabilityCatalogTest|CapabilityCatalogCoverageTest|SuperuserFullAuthorityTest'`
Expected: 全 PASS。

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/IAM/Authorization backend/tests
git commit -m "feat(v11): register 44 gap-completion capabilities"
```

### Task A5: 覆盖矩阵增加集群维度与能力门禁

**Files:**
- Modify: `frontend/src/router/registry.ts`
- Modify: `scripts/generate_coverage_matrix.mjs`
- Modify: `scripts/audit_route_coverage.mjs`
- Test: `frontend/tests/unit/coverageCluster.spec.ts`

- [ ] **Step 1: Write the failing test**

```ts
import { describe, expect, it } from 'vitest'
import { PAGE_REGISTRY } from '@/router/registry'

describe('cluster coverage', () => {
  it('every gap page declares a K1–K12 cluster', () => {
    const gapPages = PAGE_REGISTRY.filter((page) => page.cluster !== undefined)
    expect(gapPages.length).toBeGreaterThanOrEqual(63)
    for (const page of gapPages) {
      expect(page.cluster).toMatch(/^K([1-9]|1[0-2])$/)
    }
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && pnpm vitest run tests/unit/coverageCluster.spec.ts`
Expected: FAIL（`cluster` 不存在）。

- [ ] **Step 3: Write minimal implementation**

`PageSpec` 增加 `cluster?: 'K1'|'K2'|...|'K12'`；为 63 个缺口页面补 `cluster`；`generate_coverage_matrix.mjs` 输出按集群汇总；`audit_route_coverage.mjs` 新增 `--require-capabilities`：任一缺口页仍含 `gap` 标记则失败。

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && pnpm vitest run tests/unit/coverageCluster.spec.ts tests/unit/routeRegistry.spec.ts`
Expected: PASS。

- [ ] **Step 5: Commit**

```bash
git add frontend/src/router/registry.ts frontend/tests/unit/coverageCluster.spec.ts scripts
git commit -m "feat(v11): add cluster dimension to coverage matrix"
```

---

## Phase B：高价值闭环（K2 / K5 / K8 / K9 / K7）

### Task B1（K2）: 合同全生命周期

**Files:**
- Create: `backend/database/migrations/2026_11_25_101000_create_contract_templates_table.php`
- Create: `backend/app/Modules/People/Models/ContractTemplate.php`
- Create: `backend/app/Modules/People/Services/ContractLifecycleService.php`
- Create: `backend/app/Modules/People/Http/Controllers/ContractLifecycleController.php`
- Modify: `backend/routes/api.php`
- Modify: `docs/openapi/openapi.yaml`
- Test: `backend/tests/Feature/People/ContractLifecycleTest.php`
- Modify: `frontend/src/views/people/catalog.ts`（移除 contractChanges/contractTemplates gap）
- Modify: `frontend/src/views/people/ContractForm.vue`（变更/终止/模板操作）
- Test: `frontend/tests/unit/peoplePages.spec.ts`

**Interfaces:**
- Produces:
  - `GET /v1/employments/contracts`（分页台账，Projection envelope）
  - `GET/POST /v1/employment-contracts/{id}/amendments`
  - `POST /v1/employment-contracts/{id}/terminate`
  - `GET/POST /v1/contract-templates`、`POST /v1/contract-templates/{id}/publish`
- Consumes: `CommandRunner`、`ProjectionResponder`、`EmploymentContract`、`EmploymentContractVersion`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\EmploymentContract;
use Tests\Support\PeopleTestCase;

final class ContractLifecycleTest extends PeopleTestCase
{
    public function test_contract_can_be_amended_and_terminated_without_overwriting_history(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'CONTRACT-ORG');
        $employment = $scenario->employment($scenario->person(), $entity, 'CON-0001');
        $scenario->assignment($employment, $org);

        $contract = EmploymentContract::query()->create([
            'tenant_id' => 1,
            'employment_id' => $employment->id,
            'legal_entity_id' => $entity->id,
            'contract_no' => 'C-0001',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'ACTIVE',
        ]);

        $this->postJson("/v1/employment-contracts/{$contract->id}/amendments", [
            'effective_date' => '2026-06-01',
            'reason' => 'Synthetic amendment',
            'changes' => ['end_date' => '2027-12-31'],
        ], ['Idempotency-Key' => 'amend-1'])->assertCreated();

        $this->postJson("/v1/employment-contracts/{$contract->id}/terminate", [
            'effective_date' => '2026-09-30',
            'reason' => 'Synthetic termination',
        ], ['Idempotency-Key' => 'term-1'])->assertOk();

        $this->assertDatabaseHas('employment_contract_versions', ['contract_id' => $contract->id]);
        $this->assertSame('TERMINATED', $contract->refresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact --filter=ContractLifecycleTest`
Expected: FAIL 404（路由不存在）。

- [ ] **Step 3: Write minimal implementation**

迁移 `contract_templates`（tenant_id、code、name、version、status、body、published_at、unique(tenant_id,code,version)）。
`ContractLifecycleService` 方法：`amend()`（追加 version + 更新合同有效期）、`terminate()`（status=TERMINATED、terminated_on、写 version）、`templates()`/`createTemplate()`/`publishTemplate()`。
控制器薄层：`CommandRunner` 包写操作、`ProjectionResponder` 包读；路由加 `capability:people.contract.read|manage|template.manage`；OpenAPI 同提交。

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact --filter=ContractLifecycleTest`
Expected: PASS。

- [ ] **Step 5: 前端与契约**

移除 `GAP.contractChanges`/`GAP.contractTemplates`；`ContractForm.vue` 增加变更/终止/模板按钮；`pnpm openapi:generate && pnpm openapi:check`；`pnpm vitest run tests/unit/peoplePages.spec.ts`。

- [ ] **Step 6: Commit**

```bash
git add backend/app/Modules/People backend/database/migrations/2026_11_25_101000_create_contract_templates_table.php backend/routes/api.php docs/openapi frontend/src/views/people frontend/tests/unit/peoplePages.spec.ts
git commit -m "feat(v11): complete contract lifecycle"
```

### Task B2（K5）: 加班申请、确认与调休闭环

**Files:**
- Create: `backend/database/migrations/2026_11_25_102000_create_overtime_requests_table.php`
- Create: `backend/app/Modules/Time/Models/OvertimeRequest.php`
- Create: `backend/app/Modules/Time/Services/OvertimeRequestService.php`
- Create: `backend/app/Modules/Time/Http/Controllers/OvertimeRequestController.php`
- Modify: `backend/routes/api.php`、`docs/openapi/openapi.yaml`
- Test: `backend/tests/Feature/Time/OvertimeRequestTest.php`
- Modify: `frontend/src/views/timepay/definitions.ts`

**Interfaces:**
- Produces:
  - `GET/POST /v1/time/overtime/requests`
  - `POST /v1/time/overtime/requests/{id}/confirm`
  - `GET /v1/time/overtime/timeoff-balances`
- 不变量：`declared_minutes` 与 `confirmed_minutes` 分离；同一 `overtime_record` 只能结算一次（`PAY` XOR `TIMEOFF`）

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use Tests\Support\Time\TimeTestCase;

final class OvertimeRequestTest extends TimeTestCase
{
    public function test_confirmation_is_separate_from_application_and_settlement_is_unique(): void
    {
        $this->actingAsTimeEmployee();

        $created = $this->postJson('/v1/time/overtime/requests', [
            'business_date' => '2026-11-20',
            'declared_minutes' => 120,
            'reason' => 'Synthetic release support',
        ], ['Idempotency-Key' => 'ot-1'])->assertCreated();

        $id = (int) $created->json('data.id');

        $this->postJson("/v1/time/overtime/requests/{$id}/confirm", [
            'confirmed_minutes' => 90,
        ], ['Idempotency-Key' => 'ot-confirm-1'])->assertOk();

        $this->postJson("/v1/time/overtime/requests/{$id}/confirm", [
            'confirmed_minutes' => 90,
        ], ['Idempotency-Key' => 'ot-confirm-2'])->assertStatus(409);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact --filter=OvertimeRequestTest`
Expected: FAIL 404。

- [ ] **Step 3: Write minimal implementation**

迁移 `overtime_requests`（tenant_id、employment_id、business_date、declared_minutes、confirmed_minutes nullable、status、reason、case_id、recorded_at、unique(tenant_id,employment_id,business_date)）。
服务：`apply()` 校验同日无重复、`confirm()` 只允许 APPROVED→CONFIRMED、`timeoffBalances()` 从 `overtime_settlements` 聚合（不合成）。
路由能力：`time.overtime.request`、`time.overtime.confirm`、`time.timeoff.read`。

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact --filter='OvertimeRequestTest|OvertimeSettlementTest'`
Expected: PASS。

- [ ] **Step 5: 前端与提交**

移除 3 处 gap 标记；加班页增加申请/确认表单；`pnpm typecheck && pnpm vitest run tests/unit/timePayrollPages.spec.ts`。
提交：`git commit -m "feat(v11): close overtime application confirmation and timeoff loop"`

### Task B3（K8）: 绩效全周期

**Files:**
- Create: `backend/database/migrations/2026_11_25_103000_create_performance_cycle_detail_tables.php`
- Create: `backend/app/Modules/Talent/Models/PerformanceIndicator.php`
- Create: `backend/app/Modules/Talent/Models/PerformanceGoal.php`
- Create: `backend/app/Modules/Talent/Models/PerformanceFeedback.php`
- Create: `backend/app/Modules/Talent/Models/PerformanceCalibration.php`
- Create: `backend/app/Modules/Talent/Services/PerformanceCycleService.php`
- Create: `backend/app/Modules/Talent/Http/Controllers/PerformanceCycleController.php`
- Modify: `backend/routes/api.php`、`docs/openapi/openapi.yaml`
- Test: `backend/tests/Feature/Talent/PerformanceCycleTest.php`
- Modify: `frontend/src/views/talent/catalog.ts`

**Interfaces:**
- Produces: `GET/POST /v1/performance/indicators|goals|feedback|calibration|results`
- 不变量：模板权重合计=100；校准只写 `calibrated_rating`；结果发布后 review 不可再改

- [ ] **Step 0: Add test fixtures**

在 `backend/tests/Support/TalentTestCase.php` 增加两个受保护方法（当前不存在）：

```php
    protected function createPerformanceCycle(): PerformanceCycle
    {
        return PerformanceCycle::query()->create([
            'tenant_id' => 1,
            'name' => 'Synthetic 2026 cycle',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'status' => 'ACTIVE',
            'recorded_at' => $this->clock->now(),
        ]);
    }

    protected function createReview(PerformanceCycle $cycle, int $employmentId, string $selfRating = '3'): PerformanceReview
    {
        return PerformanceReview::query()->create([
            'tenant_id' => 1,
            'cycle_id' => (int) $cycle->id,
            'employment_id' => $employmentId,
            'status' => 'DRAFT',
            'self_rating' => $selfRating,
            'recorded_at' => $this->clock->now(),
        ]);
    }
```

需 import `PerformanceCycle`、`PerformanceReview`。

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Talent;

use Tests\Support\TalentTestCase;

final class PerformanceCycleTest extends TalentTestCase
{
    public function test_calibration_does_not_change_original_scores_and_result_is_immutable_after_publish(): void
    {
        $cycle = $this->createPerformanceCycle();
        $review = $this->createReview($cycle, 1, selfRating: '4');

        $this->postJson("/v1/performance/reviews/{$review->id}/calibration", [
            'calibrated_rating' => '3.5',
            'reason' => 'Synthetic calibration',
        ], ['Idempotency-Key' => 'cal-1'])->assertOk();

        $this->assertSame('4', $review->refresh()->self_rating);
        $this->assertSame('3.5', $review->calibrated_rating);

        $this->postJson("/v1/performance/cycles/{$cycle->id}/publish", [], ['Idempotency-Key' => 'pub-1'])->assertOk();

        $this->postJson("/v1/performance/reviews/{$review->id}/submit", [
            'manager_rating' => '5',
        ], ['Idempotency-Key' => 'late-1'])->assertStatus(409);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact --filter=PerformanceCycleTest`
Expected: FAIL 404。

- [ ] **Step 3: Write minimal implementation**

迁移 4 张表（indicator、goal、feedback、calibration）；`PerformanceCycleService` 实现指标/目标/反馈/校准/发布；发布时校验所有 review 已提交，写 `cycle.status=PUBLISHED`；之后 submit 返回 409 `CYCLE_PUBLISHED`。
路由能力：`performance.indicator.manage`、`performance.goal.manage`、`performance.feedback.manage`、`performance.calibration.manage`、`performance.result.publish`。

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact --filter='PerformanceCycleTest|TalentRedLinesTest'`
Expected: PASS。

- [ ] **Step 5: 前端与提交**

移除 6 处绩效 gap；增加指标/目标/校准/发布面板；`pnpm vitest run tests/unit/talentPages.spec.ts`。
提交：`git commit -m "feat(v11): complete performance cycle"`

### Task B4（K9）: 招聘全漏斗

**Files:**
- Create: `backend/database/migrations/2026_11_25_104000_create_recruitment_funnel_tables.php`
- Create: `backend/app/Modules/Talent/Models/CandidateEvaluation.php`
- Create: `backend/app/Modules/Talent/Models/CandidateCommunication.php`
- Create: `backend/app/Modules/Talent/Models/TalentPoolTag.php`
- Create: `backend/app/Modules/Talent/Models/CandidateImport.php`
- Create: `backend/app/Modules/Talent/Services/RecruitmentFunnelService.php`
- Create: `backend/app/Modules/Talent/Http/Controllers/RecruitmentFunnelController.php`
- Modify: `backend/routes/api.php`、`docs/openapi/openapi.yaml`
- Test: `backend/tests/Feature/Talent/RecruitmentFunnelTest.php`
- Modify: `frontend/src/views/talent/catalog.ts`

**Interfaces:**
- Produces: `GET /v1/recruitment/board|budget|reports`、`POST /v1/recruitment/candidates/imports`、`GET/POST /v1/recruitment/candidates/{id}/evaluations|communications|talent-pool`、`POST /v1/recruitment/candidates/{id}/convert`
- 不变量：需求批准才可发布；转员工幂等（同 candidate 只生成一个 employment）

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Talent;

use Tests\Support\TalentTestCase;

final class RecruitmentFunnelTest extends TalentTestCase
{
    public function test_candidate_conversion_is_idempotent(): void
    {
        $candidate = $this->createAcceptedCandidate();

        $first = $this->postJson("/v1/recruitment/candidates/{$candidate->id}/convert", [
            'legal_entity_id' => 1,
            'org_unit_id' => 1,
            'employee_no' => 'CONV-0001',
        ], ['Idempotency-Key' => 'conv-1'])->assertCreated();

        $second = $this->postJson("/v1/recruitment/candidates/{$candidate->id}/convert", [
            'legal_entity_id' => 1,
            'org_unit_id' => 1,
            'employee_no' => 'CONV-0001',
        ], ['Idempotency-Key' => 'conv-1'])->assertOk();

        $this->assertSame($first->json('data.employment_id'), $second->json('data.employment_id'));
        $this->assertSame(1, \App\Modules\People\Models\Employment::query()->where('employee_no', 'CONV-0001')->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact --filter=RecruitmentFunnelTest`
Expected: FAIL 404。

- [ ] **Step 3: Write minimal implementation**

迁移 4 张表；`RecruitmentFunnelService`：board/budget/reports 从 `requisitions/openings/candidates` 聚合；imports 只接受行数组并写 `candidate_imports`；evaluations/communications/tags 子资源；convert 复用 `Offer`→onboarding，按 candidate 唯一约束幂等。
路由能力：`recruitment.candidate.import`、`recruitment.evaluation.manage`、`recruitment.communication.manage`、`recruitment.talent-pool.manage`、`recruitment.candidate.convert`。

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact --filter='RecruitmentFunnelTest|TalentRedLinesTest'`
Expected: PASS。

- [ ] **Step 5: 前端与提交**

移除 10 处招聘 gap；增加看板/预算/导入/评估/沟通/人才库/转员工；`pnpm vitest run tests/unit/talentPages.spec.ts`。
提交：`git commit -m "feat(v11): complete recruitment funnel"`

### Task B5（K7）: 薪酬档案与调薪

**Files:**
- Create: `backend/database/migrations/2026_11_25_105000_create_employee_compensations_table.php`
- Create: `backend/app/Modules/Payroll/Models/EmployeeCompensation.php`
- Create: `backend/app/Modules/Payroll/Models/SalaryAdjustment.php`
- Create: `backend/app/Modules/Payroll/Services/CompensationService.php`
- Create: `backend/app/Modules/Payroll/Http/Controllers/CompensationController.php`
- Modify: `backend/routes/api.php`、`docs/openapi/openapi.yaml`
- Test: `backend/tests/Feature/Payroll/CompensationTest.php`
- Modify: `frontend/src/views/timepay/definitions.ts`

**Interfaces:**
- Produces: `GET/POST /v1/payroll/employee-plans`、`GET/POST /v1/payroll/adjustments`
- 不变量：薪酬档案按 `effective_from` 版本化；调薪需 Case 审批；金额整数分

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use Tests\Support\PayrollTestCase;

final class CompensationTest extends PayrollTestCase
{
    public function test_compensation_is_versioned_and_amounts_are_integer_cents(): void
    {
        $this->seedPayrollBase();

        $this->postJson('/v1/payroll/employee-plans', [
            'employment_id' => 1,
            'effective_from' => '2026-01-01',
            'base_salary_cents' => 1000000,
            'currency' => 'CNY',
        ], ['Idempotency-Key' => 'comp-1'])->assertCreated();

        $this->postJson('/v1/payroll/employee-plans', [
            'employment_id' => 1,
            'effective_from' => '2026-07-01',
            'base_salary_cents' => 1200000,
            'currency' => 'CNY',
        ], ['Idempotency-Key' => 'comp-2'])->assertCreated();

        $this->assertSame(2, \App\Modules\Payroll\Models\EmployeeCompensation::query()->where('employment_id', 1)->count());
        $this->assertIsInt(\App\Modules\Payroll\Models\EmployeeCompensation::query()->first()->base_salary_cents);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact --filter=CompensationTest`
Expected: FAIL 404。

- [ ] **Step 3: Write minimal implementation**

迁移 2 张表；`CompensationService`：档案按生效日版本化（同一 employment 同生效日唯一）、调薪单关联 `case_id` 并走 Workflow；P3 字段读取写审计。
路由能力：`payroll.compensation.read`、`payroll.compensation.manage`、`payroll.salary-adjustment.manage`。

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact --filter='CompensationTest|PayrollFieldProjectionTest'`
Expected: PASS。

- [ ] **Step 5: 前端与提交**

移除 2 处薪酬 gap；增加档案/调薪面板，金额走 `MoneyText`；`pnpm vitest run tests/unit/timePayrollPages.spec.ts`。
提交：`git commit -m "feat(v11): add compensation archive and salary adjustments"`

---

## Phase C：组织与时间（K1 / K4 / K6 / K3）

### Task C1（K1）: 组织岗位完整性

**Files:**
- Create: `backend/database/migrations/2026_11_25_106000_create_position_qualifications_and_grades_tables.php`
- Create: `backend/app/Modules/Organization/Models/PositionQualification.php`
- Create: `backend/app/Modules/Organization/Models/PositionGrade.php`
- Create: `backend/app/Modules/Organization/Services/PositionCatalogService.php`
- Create: `backend/app/Modules/Organization/Http/Controllers/PositionCatalogController.php`
- Create: `backend/app/Modules/Lifecycle/Services/SecondmentService.php`
- Create: `backend/app/Modules/Lifecycle/Http/Controllers/SecondmentController.php`
- Modify: `backend/routes/api.php`、`docs/openapi/openapi.yaml`
- Test: `backend/tests/Feature/Organization/PositionCatalogTest.php`
- Test: `backend/tests/Feature/Lifecycle/SecondmentTest.php`
- Modify: `frontend/src/views/people/catalog-columns.ts`（移除 4 个 GAP）
- Modify: `frontend/src/views/people/catalog.ts`
- Test: `frontend/tests/unit/peoplePages.spec.ts`

**Interfaces:**
- Produces:
  - `GET/POST /v1/positions/{id}/requirements`
  - `GET/POST /v1/positions/grades`
  - `GET /v1/positions/{id}/staff`
  - `GET/POST /v1/lifecycle/secondments`
- 不变量：岗位停用前无在岗；借调用 `Assignment`（type=SECONDMENT）且不改主岗历史；职级代码拥有

- [ ] **Step 0: Add test fixtures**

在 `backend/tests/Support/PeopleScenario.php` 增加 `position()`（若已存在则复用）；在 `backend/tests/Support/LifecycleTestCase.php` 增加：

```php
    /**
     * @return array{0: \App\Modules\People\Models\Employment, 1: \App\Modules\Organization\Models\OrgUnit, 2: \App\Modules\Organization\Models\OrgUnit}
     */
    protected function seedEmploymentWithOrg(): array
    {
        $entity = $this->scenario->legalEntity();
        $home = $this->scenario->orgUnit($entity, 'HOME-ORG');
        $host = $this->scenario->orgUnit($entity, 'HOST-ORG');
        $employment = $this->scenario->employment($this->scenario->person(), $entity, 'SEC-0001');
        $this->scenario->assignment($employment, $home);

        return [$employment, $home, $host];
    }
```

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use Tests\Support\PeopleTestCase;

final class PositionCatalogTest extends PeopleTestCase
{
    public function test_position_cannot_be_disabled_while_staffed_and_staff_list_is_authorized(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'POS-ORG');
        $employment = $scenario->employment($scenario->person(), $entity, 'POS-0001');
        $position = $scenario->position($entity, $org, 'POS-001');
        $scenario->assignment($employment, $org, $position);

        $this->getJson("/v1/positions/{$position->id}/staff")
            ->assertOk()
            ->assertJsonPath('data.items.0.employment_id', $employment->id);

        $this->patchJson("/v1/positions/{$position->id}", ['status' => 'DISABLED'], ['Idempotency-Key' => 'pos-disable-1'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'POSITION_STAFFED');
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\People\Models\Assignment;
use Tests\Support\LifecycleTestCase;

final class SecondmentTest extends LifecycleTestCase
{
    public function test_secondment_creates_parallel_assignment_and_returns_to_primary(): void
    {
        [$employment, $homeOrg, $hostOrg] = $this->seedEmploymentWithOrg();

        $this->postJson('/v1/lifecycle/secondments', [
            'employment_id' => $employment->id,
            'host_org_unit_id' => $hostOrg->id,
            'effective_from' => '2026-12-01',
            'effective_to' => '2027-03-31',
            'reason' => 'Synthetic secondment',
        ], ['Idempotency-Key' => 'sec-1'])->assertCreated();

        $this->assertSame(1, Assignment::query()->where('employment_id', $employment->id)->where('assignment_type', 'PRIMARY')->count());
        $this->assertSame('SECONDMENT', Assignment::query()->where('employment_id', $employment->id)->where('assignment_type', 'SECONDMENT')->first()->assignment_type);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --compact --filter='PositionCatalogTest|SecondmentTest'`
Expected: FAIL 404。

- [ ] **Step 3: Write minimal implementation**

迁移 `position_qualifications`（tenant、position_id、version、requirements_json、effective_from、recorded_at）与 `position_grades`（tenant、code、name、level、active）。
`PositionCatalogService`：资格版本化、职级 CRUD、`staff()` 从 `assignments` 关联 employment（as-of）。
`SecondmentService`：创建并行 Assignment，不改 PRIMARY；结束日期到期由 scheduler 关闭。
路由能力：`org.position.qualification.manage`、`org.position.grade.manage`、`org.position.staff.read`、`lifecycle.secondment.manage`。

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test --compact --filter='PositionCatalogTest|SecondmentTest|PositionApiTest'`
Expected: PASS。

- [ ] **Step 5: 前端与提交**

移除 `GAP.requirements`/`grades`/`positionStaff`/`secondment`；对应页面接真实端点；`pnpm typecheck && pnpm vitest run tests/unit/peoplePages.spec.ts`。
提交：`git commit -m "feat(v11): complete position catalog and secondment"`

### Task C2（K4）: 考勤规则、排班、月结列表与设备

**Files:**
- Create: `backend/database/migrations/2026_11_25_107000_create_attendance_schedule_and_device_tables.php`
- Create: `backend/app/Modules/Time/Models/AttendanceDevice.php`
- Create: `backend/app/Modules/Time/Models/ShiftSchedule.php`
- Create: `backend/app/Modules/Time/Services/AttendanceOpsService.php`
- Create: `backend/app/Modules/Time/Http/Controllers/AttendanceOpsController.php`
- Modify: `backend/routes/api.php`、`docs/openapi/openapi.yaml`
- Test: `backend/tests/Feature/Time/AttendanceOpsTest.php`
- Modify: `frontend/src/views/timepay/definitions.ts`

**Interfaces:**
- Produces: `GET /v1/time/attendance/rules`、`GET/POST /v1/time/shift-schedules`、`GET /v1/time/attendance/closings`、`GET /v1/time/attendance/devices`
- 不变量：规则版本化；排班冲突检测；月结锁定后不可改；设备只读健康

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use Tests\Support\Time\TimeTestCase;

final class AttendanceOpsTest extends TimeTestCase
{
    public function test_schedule_conflict_is_rejected_and_closing_list_is_tenant_scoped(): void
    {
        $this->actingAsAttendanceSpecialist();
        $employmentId = $this->seedEmployment();

        $this->postJson('/v1/time/shift-schedules', [
            'employment_id' => $employmentId,
            'business_date' => '2026-11-25',
            'shift_template_id' => 1,
        ], ['Idempotency-Key' => 'sch-1'])->assertCreated();

        $this->postJson('/v1/time/shift-schedules', [
            'employment_id' => $employmentId,
            'business_date' => '2026-11-25',
            'shift_template_id' => 2,
        ], ['Idempotency-Key' => 'sch-2'])->assertStatus(409)->assertJsonPath('data.reason', 'SCHEDULE_CONFLICT');

        $this->getJson('/v1/time/attendance/closings')->assertOk()->assertJsonStructure(['data' => ['items', 'total', 'as_of', 'freshness', 'source_hash']]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact --filter=AttendanceOpsTest`
Expected: FAIL 404。

- [ ] **Step 3: Write minimal implementation**

迁移 `attendance_devices`（tenant、code、name、adapter、last_seen_at、health、active）与 `shift_schedules`（tenant、employment_id、business_date、shift_template_id、unique(tenant,employment,business_date)）。
`AttendanceOpsService`：规则读 `attendance_rule_versions` 最新版；排班写唯一约束→409；月结列表读 `attendance_period_closures`；设备列表只读。
路由能力：`time.attendance.rule.read`、`time.shift.schedule.read/manage`、`time.attendance.device.read`。

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact --filter='AttendanceOpsTest|AttendanceClosingTest|TimeCatalogsTest'`
Expected: PASS。

- [ ] **Step 5: 前端与提交**

移除 4 处 gap；规则/排班/月结/设备接真实端点；`pnpm vitest run tests/unit/timePayrollPages.spec.ts`。
提交：`git commit -m "feat(v11): complete attendance rules scheduling closing and devices"`

### Task C3（K6）: 差旅政策、审批与费用

**Files:**
- Create: `backend/database/migrations/2026_11_25_108000_create_travel_policy_and_expense_tables.php`
- Create: `backend/app/Modules/Time/Models/TravelPolicy.php`
- Create: `backend/app/Modules/Time/Models/TravelExpense.php`
- Create: `backend/app/Modules/Time/Services/TravelService.php`
- Create: `backend/app/Modules/Time/Http/Controllers/TravelPolicyController.php`
- Modify: `backend/routes/api.php`、`docs/openapi/openapi.yaml`
- Test: `backend/tests/Feature/Time/TravelPolicyTest.php`
- Modify: `frontend/src/views/timepay/definitions.ts`

**Interfaces:**
- Produces: `GET/POST /v1/travel/policies`、`POST /v1/travel/requests/{id}/approve`（写 Workflow Decision）、`GET/POST /v1/travel/expenses`
- 不变量：政策按版本；审批只经 Workflow；费用只登记不支付

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use Tests\Support\Time\TimeTestCase;

final class TravelPolicyTest extends TimeTestCase
{
    public function test_policy_is_versioned_and_expense_requires_approved_trip(): void
    {
        $this->actingAsTravelManager();

        $this->postJson('/v1/travel/policies', [
            'legal_entity_id' => 1,
            'city_tier' => 'A',
            'hotel_cap_cents' => 80000,
            'effective_from' => '2026-01-01',
        ], ['Idempotency-Key' => 'tp-1'])->assertCreated();

        $tripId = $this->createPendingTrip();

        $this->postJson("/v1/travel/expenses", [
            'travel_id' => $tripId,
            'amount_cents' => 12000,
            'category' => 'TRANSPORT',
        ], ['Idempotency-Key' => 'te-1'])->assertStatus(409)->assertJsonPath('data.reason', 'TRIP_NOT_APPROVED');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact --filter=TravelPolicyTest`
Expected: FAIL 404。

- [ ] **Step 3: Write minimal implementation**

迁移 `travel_policies`（tenant、legal_entity、city_tier、hotel_cap_cents、effective_from、version）与 `travel_expenses`（tenant、travel_id、category、amount_cents、receipt_file_id、status）。
`TravelService`：approve 调 Workflow `DecisionController` 同源服务；expense 校验 trip 状态=APPROVED；policy 版本化。
路由能力：`time.travel.policy.read/manage`、`time.travel.approve`、`time.travel.expense.manage`。

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact --filter='TravelPolicyTest|TravelRequestTest'`
Expected: PASS。

- [ ] **Step 5: 前端与提交**

移除 3 处 gap；政策/审批/费用接真实端点；`pnpm vitest run tests/unit/timePayrollPages.spec.ts`。
提交：`git commit -m "feat(v11): complete travel policy approval and expenses"`

### Task C4（K3）: 员工文档列表、签名下载与删除

**Files:**
- Modify: `backend/app/Modules/People/Services/DocumentService.php`
- Modify: `backend/app/Modules/People/Http/Controllers/EmploymentController.php`
- Create: `backend/app/Modules/People/Services/SignedDocumentDownload.php`
- Modify: `backend/routes/api.php`、`docs/openapi/openapi.yaml`
- Test: `backend/tests/Feature/People/DocumentLifecycleTest.php`
- Modify: `frontend/src/views/people/catalog-columns.ts`（移除 `GAP.files`）
- Modify: `frontend/src/views/people/catalog.ts`

**Interfaces:**
- Produces: `GET /v1/employments/{employment_id}/documents`、`GET /v1/documents/{id}/signed-url`、`DELETE /v1/documents/{id}`
- 不变量：删除需保留类/法律保留校验；下载必须签名 URL + `file_access_logs`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Tests\Support\PeopleTestCase;

final class DocumentLifecycleTest extends PeopleTestCase
{
    public function test_document_list_and_signed_download_and_legal_hold_blocks_delete(): void
    {
        [$employment, $document] = $this->seedDocument(legalHold: true);

        $this->getJson("/v1/employments/{$employment->id}/documents")
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $document->id);

        $this->getJson("/v1/documents/{$document->id}/signed-url")
            ->assertOk()
            ->assertJsonStructure(['data' => ['url', 'expires_at']]);

        $this->deleteJson("/v1/documents/{$document->id}", [], ['Idempotency-Key' => 'doc-del-1'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'LEGAL_HOLD');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact --filter=DocumentLifecycleTest`
Expected: FAIL 404。

- [ ] **Step 3: Write minimal implementation**

`DocumentService::list()` 分页 + 字段投影；`SignedDocumentDownload` 用 `PrivateFileStore` 临时 URL（无公开静态 URL），写 `file_access_logs` 与审计；`delete()` 校验 `legal_hold`/`retention_class`，软删或标记。
路由能力：`people.document.read`、`people.document.download`、`people.contract.manage`。

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact --filter='DocumentLifecycleTest|DocumentAccessTest'`
Expected: PASS。

- [ ] **Step 5: 前端与提交**

移除 `GAP.files`；附件页接列表/下载/删除；`pnpm vitest run tests/unit/peoplePages.spec.ts`。
提交：`git commit -m "feat(v11): complete employee document lifecycle"`

---

## Phase D：平台与报表（K11 / K10 / K12）

### Task D1（K11）: 平台治理写入（角色/菜单/范围/字段/字典/日历/通知）

**Files:**
- Create: `backend/database/migrations/2026_11_25_109000_create_platform_governance_tables.php`
- Create: `backend/app/Modules/Platform/Models/MenuOverride.php`
- Create: `backend/app/Modules/Platform/Models/NotificationTemplate.php`
- Create: `backend/app/Modules/Platform/Models/NotificationDelivery.php`
- Create: `backend/app/Modules/IAM/Http/Controllers/RoleAdminController.php`
- Create: `backend/app/Modules/IAM/Services/RoleAdminService.php`
- Create: `backend/app/Modules/IAM/Http/Controllers/MenuAdminController.php`
- Create: `backend/app/Modules/Platform/Http/Controllers/DictionaryAdminController.php`
- Create: `backend/app/Modules/Platform/Http/Controllers/NotificationAdminController.php`
- Create: `backend/app/Modules/Time/Http/Controllers/CalendarAdminController.php`
- Create: `backend/app/Modules/IAM/Http/Controllers/DataScopeAdminController.php`
- Create: `backend/app/Modules/IAM/Http/Controllers/FieldPolicyAdminController.php`
- Modify: `backend/routes/api.php`、`docs/openapi/openapi.yaml`
- Test: `backend/tests/Feature/IAM/RoleAdminTest.php`
- Test: `backend/tests/Feature/IAM/ScopePolicyWriteTest.php`
- Test: `backend/tests/Feature/IAM/FieldPolicyEscalationTest.php`
- Test: `backend/tests/Feature/Platform/MenuOverrideTest.php`
- Test: `backend/tests/Feature/Platform/DictionaryAdminTest.php`
- Test: `backend/tests/Feature/Platform/NotificationDeliveryTest.php`
- Modify: `frontend/src/views/platform/catalog.ts`（移除 14 处平台 gap；福利 S6 在 Task D2）
- Modify: `frontend/src/views/platform/PlatformWorkspace.vue`
- Test: `frontend/tests/unit/platformPages.spec.ts`

**Interfaces:**
- Produces:
  - `POST /v1/roles`、`POST /v1/roles/{id}/copy`、`PUT /v1/roles/{id}/capabilities`
  - `GET/POST/PATCH/DELETE /v1/system/menus`（只写 `menu_overrides`，不改注册表）
  - `PUT /v1/system/data-scopes/{id}`、`PUT /v1/system/field-policies/{id}`
  - `GET/POST/PATCH /v1/system/dictionaries`、`GET/PUT /v1/system/parameters`
  - `POST /v1/system/calendars/{id}/publish`
  - `GET /v1/notifications/deliveries`、`POST /v1/notifications/deliveries/{id}/retry`、`GET/POST /v1/notification-templates`
- 不变量：能力码代码拥有；菜单覆盖只影响展示；范围白名单；字段只收紧；通知 outbox 幂等

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use Tests\Feature\Authorization\AuthorizationTestSupport;

final class RoleAdminTest extends AuthorizationTestSupport
{
    public function test_admin_creates_exception_role_and_snapshot_replays(): void
    {
        $this->freeze();
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $actor = $this->roleManager();
        $this->enableMfa($actor);
        $auth = $this->loginAs($actor);
        $this->stepUp();

        $this->postJson('/v1/roles', [
            'code' => 'TEMP_REPORT_VIEWER',
            'name' => 'Temporary report viewer',
            'purpose' => 'Synthetic quarterly reporting access.',
            'capabilities' => [
                ['capability' => 'reporting.export', 'anchor_type' => 'LEGAL_ENTITY', 'field_profile' => 'P1_INTERNAL'],
            ],
        ], ['Idempotency-Key' => 'role-1', 'X-CSRF-Token' => $auth['csrf']])->assertCreated();

        $this->assertDatabaseHas('roles', ['code' => 'TEMP_REPORT_VIEWER', 'is_template' => false]);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Platform\Models\MenuOverride;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class MenuOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_override_cannot_reference_unknown_page(): void
    {
        $this->postJson('/v1/system/menus', [
            'page_code' => 'not-a-registered-page',
            'visible' => false,
        ], ['Idempotency-Key' => 'menu-1'])->assertStatus(422)->assertJsonPath('data.reason', 'UNKNOWN_PAGE_CODE');
    }

    public function test_menu_override_hides_display_but_does_not_grant_or_revoke_capability(): void
    {
        MenuOverride::query()->create(['tenant_id' => 1, 'page_code' => 'hr-employees', 'visible' => false, 'sort_order' => 10]);

        $this->getJson('/v1/me/permissions')->assertOk();
        $this->getJson('/v1/employees')->assertStatus(403);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_outbox_event_is_delivered_only_once(): void
    {
        $this->postJson('/v1/notification-templates', [
            'code' => 'APPROVAL_PENDING',
            'channel' => 'IN_APP',
            'subject' => 'Approval pending',
            'body' => 'You have a pending approval.',
        ], ['Idempotency-Key' => 'nt-1'])->assertCreated();

        $first = $this->postJson('/v1/notifications/dispatch', [
            'event_key' => 'case-1-created',
            'template_code' => 'APPROVAL_PENDING',
            'recipient_user_id' => 1,
        ], ['Idempotency-Key' => 'nd-1'])->assertCreated();

        $second = $this->postJson('/v1/notifications/dispatch', [
            'event_key' => 'case-1-created',
            'template_code' => 'APPROVAL_PENDING',
            'recipient_user_id' => 1,
        ], ['Idempotency-Key' => 'nd-2']);

        $this->assertSame($first->json('data.delivery_id'), $second->json('data.delivery_id'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --compact --filter='RoleAdminTest|MenuOverrideTest|NotificationDeliveryTest'`
Expected: FAIL 404。

- [ ] **Step 3: Write minimal implementation**

迁移：`menu_overrides`、`notification_templates`、`notification_outbox`、`notification_deliveries`。
`RoleAdminService`：创建/复制角色、写 `role_capabilities`、调用 `AuthorizationSnapshot::replay()`。
`MenuAdminController`：只写 `menu_overrides`，校验 page_code 存在于 `PermissionResourceCatalog`。
`DictionaryAdminController`：CRUD `sys_dictionaries`/`sys_parameters`。
`NotificationAdminController`：模板 CRUD、dispatch 按 `event_key` 幂等、delivery retry。
`CalendarAdminController`：发布日历版本（写 `legal_entity_calendars` 版本 + 审计）。
路由能力：`iam.role.manage`、`iam.menu.manage`、`system.dictionary.manage`、`system.parameter.manage`、`notification.template.manage`、`notification.delivery.manage`、`time.calendar.manage`。

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test --compact --filter='RoleAdminTest|ScopePolicyWriteTest|FieldPolicyEscalationTest|MenuOverrideTest|DictionaryAdminTest|NotificationDeliveryTest|CalendarAdminTest'`
Expected: PASS。

- [ ] **Step 5: 前端与提交**

移除 14 处平台 gap；角色创建/菜单树/数据范围/字段策略/字典/通知投递/日历发布接真实端点；`pnpm vitest run tests/unit/platformPages.spec.ts`。
提交：`git commit -m "feat(v11): complete platform governance writes"`

### Task D2（K11）: 福利与社保公积金

**Files:**
- Create: `backend/database/migrations/2026_11_25_110000_create_benefit_tables.php`
- Create: `backend/app/Modules/Platform/Models/BenefitPlan.php`
- Create: `backend/app/Modules/Platform/Models/BenefitEnrollment.php`
- Create: `backend/app/Modules/Platform/Models/BenefitTransaction.php`
- Create: `backend/app/Modules/Platform/Services/BenefitService.php`
- Create: `backend/app/Modules/Platform/Http/Controllers/BenefitController.php`
- Modify: `backend/routes/api.php`、`docs/openapi/openapi.yaml`
- Test: `backend/tests/Feature/Platform/BenefitTest.php`
- Modify: `frontend/src/views/platform/catalog.ts`

**Interfaces:**
- Produces: `GET/POST /v1/benefits/plans`、`GET/POST /v1/benefits/enrollments`、`GET /v1/benefits/transactions`
- 不变量：参保基数变更保留历史；福利与工资分开授权；费用可关联工资批次但不重复支付

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class BenefitTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_base_change_is_versioned(): void
    {
        $plan = $this->postJson('/v1/benefits/plans', [
            'code' => 'SOCIAL_INSURANCE',
            'name' => 'Social insurance',
            'employer_rate' => '0.160000',
            'employee_rate' => '0.080000',
        ], ['Idempotency-Key' => 'bp-1'])->assertCreated()->json('data');

        $this->postJson('/v1/benefits/enrollments', [
            'plan_id' => $plan['id'],
            'employment_id' => 1,
            'base_amount_cents' => 1000000,
            'effective_from' => '2026-01-01',
        ], ['Idempotency-Key' => 'be-1'])->assertCreated();

        $this->postJson('/v1/benefits/enrollments', [
            'plan_id' => $plan['id'],
            'employment_id' => 1,
            'base_amount_cents' => 1200000,
            'effective_from' => '2026-07-01',
        ], ['Idempotency-Key' => 'be-2'])->assertCreated();

        $this->assertSame(2, \App\Modules\Platform\Models\BenefitEnrollment::query()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact --filter=BenefitTest`
Expected: FAIL 404。

- [ ] **Step 3: Write minimal implementation**

迁移 3 张表；`BenefitService` 版本化参保、交易台账；费率用 decimal string ≥1e6；金额整数分。
路由能力：`benefit.read`、`benefit.manage`。

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact --filter=BenefitTest`
Expected: PASS。

- [ ] **Step 5: 前端与提交**

移除福利 1 处 gap（S6）；参保/交易台账接真实端点；`pnpm vitest run tests/unit/platformPages.spec.ts`。
提交：`git commit -m "feat(v11): add benefits and statutory enrollment"`

### Task D3（K10）: 培训全周期

**Files:**
- Create: `backend/database/migrations/2026_11_25_111000_create_training_lifecycle_tables.php`
- Create: `backend/app/Modules/Talent/Models/TrainingPlan.php`
- Create: `backend/app/Modules/Talent/Models/TrainingCourse.php`
- Create: `backend/app/Modules/Talent/Models/TrainingCheckin.php`
- Create: `backend/app/Modules/Talent/Models/TrainingExam.php`
- Create: `backend/app/Modules/Talent/Models/TrainingFeedback.php`
- Create: `backend/app/Modules/Talent/Services/TrainingLifecycleService.php`
- Create: `backend/app/Modules/Talent/Http/Controllers/TrainingLifecycleController.php`
- Modify: `backend/routes/api.php`、`docs/openapi/openapi.yaml`
- Test: `backend/tests/Feature/Talent/TrainingLifecycleTest.php`
- Modify: `frontend/src/views/talent/catalog.ts`

**Interfaces:**
- Produces: `GET/POST /v1/training/plans|courses`、`POST /v1/training/sessions/{id}/checkins`、`GET/POST /v1/training/exams|feedback`、`GET /v1/training/records|reports`
- 不变量：容量/重复报名；签到幂等；学时只从完成记录派生

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Talent;

use Tests\Support\TalentTestCase;

final class TrainingLifecycleTest extends TalentTestCase
{
    public function test_checkin_is_idempotent_and_records_are_derived(): void
    {
        $session = $this->seedTrainingSession();

        $first = $this->postJson("/v1/training/sessions/{$session->id}/checkins", [
            'employment_id' => 1,
        ], ['Idempotency-Key' => 'ci-1'])->assertCreated();

        $second = $this->postJson("/v1/training/sessions/{$session->id}/checkins", [
            'employment_id' => 1,
        ], ['Idempotency-Key' => 'ci-2'])->assertOk();

        $this->assertSame($first->json('data.checkin_id'), $second->json('data.checkin_id'));

        $this->getJson('/v1/training/records')->assertOk()->assertJsonStructure(['data' => ['items', 'total', 'as_of', 'freshness', 'source_hash']]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact --filter=TrainingLifecycleTest`
Expected: FAIL 404。

- [ ] **Step 3: Write minimal implementation**

迁移 5 张表；`TrainingLifecycleService`：计划/课程/签到（unique session+employment）/考试/反馈/档案（从 enrollments+checkins 派生）/报表（聚合）。
路由能力：`training.plan.manage`、`training.course.manage`、`training.checkin.manage`、`training.exam.manage`、`training.feedback.manage`、`training.record.read`。

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact --filter='TrainingLifecycleTest|TrainingEnrollmentTest'`
Expected: PASS。

- [ ] **Step 5: 前端与提交**

移除 7 处培训 gap；计划/课程/签到/考试/反馈/档案/报表接真实端点；`pnpm vitest run tests/unit/talentPages.spec.ts`。
提交：`git commit -m "feat(v11): complete training lifecycle"`

### Task D4（K12）: 报表定义、订阅与导出历史

**Files:**
- Modify: `backend/app/Modules/Reporting/Services/ReportDefinitionCatalog.php`
- Modify: `backend/app/Modules/Reporting/Services/ReportService.php`
- Create: `backend/app/Modules/Reporting/Models/ReportSubscription.php`
- Create: `backend/app/Modules/Reporting/Services/ReportSubscriptionService.php`
- Create: `backend/app/Modules/Reporting/Http/Controllers/ReportSubscriptionController.php`
- Modify: `backend/routes/api.php`、`docs/openapi/openapi.yaml`
- Test: `backend/tests/Feature/Reporting/ReportSubscriptionTest.php`
- Test: `backend/tests/Feature/Reporting/ReportDefinitionTest.php`
- Modify: `frontend/src/views/platform/catalog.ts`

**Interfaces:**
- Produces: `GET/POST /v1/reporting/definitions`（自定义定义）、`GET/POST/PATCH/DELETE /v1/reporting/subscriptions`、`GET /v1/reporting/exports`
- 不变量：报表不写业务表；订阅收件人再鉴权；导出行级授权与字段白名单

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class ReportSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_requires_authorized_recipient_and_can_be_paused(): void
    {
        $created = $this->postJson('/v1/reporting/subscriptions', [
            'definition_code' => 'WORKFORCE_HEADCOUNT',
            'recipient_user_id' => 1,
            'frequency' => 'MONTHLY',
            'filters' => [],
        ], ['Idempotency-Key' => 'sub-1'])->assertCreated();

        $id = (int) $created->json('data.id');

        $this->patchJson("/v1/reporting/subscriptions/{$id}", ['active' => false], ['Idempotency-Key' => 'sub-2'])
            ->assertOk()
            ->assertJsonPath('data.active', false);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class ReportDefinitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_definition_rejects_sql_and_unknown_fields(): void
    {
        $this->postJson('/v1/reporting/definitions', [
            'code' => 'CUSTOM_1',
            'name' => 'Custom',
            'source_projection' => 'workforce',
            'fields' => ['employee', 'not_a_field'],
            'filters' => [],
        ], ['Idempotency-Key' => 'rd-1'])->assertStatus(422)->assertJsonPath('data.reason', 'UNKNOWN_REPORT_FIELD');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --compact --filter='ReportSubscriptionTest|ReportDefinitionTest'`
Expected: FAIL 404。

- [ ] **Step 3: Write minimal implementation**

迁移 `report_subscriptions`；扩展 `report_definitions` 支持 `tenant_id` 自定义（`is_system=false`）。
`ReportSubscriptionService`：创建/暂停/删除，收件人必须有 `reporting.report.read`；`GET /exports` 读 `export_jobs` 分页。
`ReportDefinitionCatalog` 增加按投影白名单字段校验（拒绝任意 SQL/未知字段）。
路由能力：`reporting.definition.manage`、`reporting.subscription.manage`、`reporting.export.read`。

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test --compact --filter='ReportSubscriptionTest|ReportDefinitionTest|OpenApiSchemaContractTest'`
Expected: PASS。

- [ ] **Step 5: 前端与提交**

移除 5 处报表 gap；自定义/订阅/导出历史接真实端点；`pnpm vitest run tests/unit/platformPages.spec.ts`。
提交：`git commit -m "feat(v11): complete report definitions subscriptions and export history"`

---

## Phase E：验收与收口

### Task E1: 12 集群负测矩阵与能力门禁

**Files:**
- Modify: `backend/tests/Authorization/endpoint_matrix.php`
- Create: `backend/tests/Contract/GapCompletionCapabilityMatrixTest.php`
- Modify: `scripts/audit_route_coverage.mjs`

**Interfaces:**
- Produces: 每个新路由至少 4 类负测；`--require-capabilities` 通过

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Contract;

use Tests\TestCase;

final class GapCompletionCapabilityMatrixTest extends TestCase
{
    public function test_every_new_route_has_four_denial_cases(): void
    {
        $matrix = require base_path('tests/Authorization/endpoint_matrix.php');

        foreach ($matrix as $route => $entry) {
            $this->assertGreaterThanOrEqual(4, count($entry['negative_tests']), "{$route} needs four denial cases.");
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact --filter=GapCompletionCapabilityMatrixTest`
Expected: FAIL（新路由缺项）。

- [ ] **Step 3: Write minimal implementation**

为 K1–K12 的每个新路由补 `endpoint_matrix.php` 条目（未认证、无能力、错锚点、跨法人/字段拒绝），并运行 `php artisan capability:audit` 确认零警告。

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact --filter=GapCompletionCapabilityMatrixTest && php artisan capability:audit`
Expected: PASS + `Capability audit OK.`

- [ ] **Step 5: Commit**

```bash
git add backend/tests/Authorization/endpoint_matrix.php backend/tests/Contract/GapCompletionCapabilityMatrixTest.php scripts/audit_route_coverage.mjs
git commit -m "test(v11): add gap-completion denial matrix and capability gate"
```

### Task E2: 真实栈 160 页 + 集群 E2E

**Files:**
- Modify: `frontend/tests/e2e/real/registry-audit.spec.ts`
- Create: `frontend/tests/e2e/real/cluster-journeys.spec.ts`
- Modify: `deploy/scripts/verify_real_stack.sh`

- [ ] **Step 1: Write the failing test**

```ts
import { expect, test } from '@playwright/test'

test('@real gap cluster journeys', async ({ page }) => {
  test.skip(!process.env.HRMS_DEMO_PASSWORD, 'HRMS_DEMO_PASSWORD required')
  // K2 contract lifecycle
  await page.goto('/hr/contracts')
  await expect(page.locator('h1').first()).toBeVisible()
  await expect(page.getByText('后端接口待补')).toHaveCount(0)
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/cluster-journeys.spec.ts --list`
Expected: 能发现测试；执行时若仍有 gap 文案则 FAIL。

- [ ] **Step 3: Write minimal implementation**

为 K1–K12 各写一条主链路 + 一条拒绝路径；`registry-audit.spec.ts` 增加“不应出现 `后端接口待补`”断言。

- [ ] **Step 4: Run test to verify it passes**

Run: `HRMS_DEMO_PASSWORD=... pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real`
Expected: 全 PASS（160 页 + 12 集群）。

- [ ] **Step 5: Commit**

```bash
git add frontend/tests/e2e/real deploy/scripts/verify_real_stack.sh
git commit -m "test(v11): add real-stack cluster journeys"
```

### Task E3: 覆盖矩阵清零与验收报告

**Files:**
- Modify: `scripts/generate_coverage_matrix.mjs`
- Modify: `docs/audit/v11-coverage-matrix.md`
- Modify: `evidence/v11-acceptance-report.md`
- Modify: `docs/README.md`（登记新 spec/plan/ADR）

- [ ] **Step 1: Write the failing check**

```bash
node scripts/audit_route_coverage.mjs --require-mature --require-capabilities
```

Expected: 当前 FAIL（仍有 63 个 gap 标记）。

- [ ] **Step 2: 运行并记录失败原因**

Run: `node scripts/audit_route_coverage.mjs --require-mature --require-capabilities`
Expected: 输出剩余 gap 页面清单。

- [ ] **Step 3: 实现清零**

逐集群删除前端 `gap`/`pending` 标记；矩阵输出 `cluster: K1..K12` 与 `CAPABILITY_COMPLETE`；验收报告更新 156 能力码、新增路由数、12 集群证据。

- [ ] **Step 4: 验证**

Run: `node scripts/audit_route_coverage.mjs --require-mature --require-capabilities`
Expected: `failures: []`。

- [ ] **Step 5: Commit**

```bash
git add docs evidence scripts frontend/src/router/registry.ts
git commit -m "docs(v11): close gap coverage matrix and acceptance report"
```

### Task E4: 全量门禁与发布

**Files:**
- Modify: `deploy/scripts/verify_real_stack.sh`（增加集群门禁）
- Modify: `scripts/verify_release.sh`（挂载 `--require-capabilities`）

- [ ] **Step 1: 运行后端全量**

```bash
cd backend
php artisan test
vendor/bin/pint --test
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
php artisan capability:audit
php artisan arch:test
```

Expected: 全绿（测试数应 > 903）。

- [ ] **Step 2: 运行前端全量**

```bash
cd frontend
pnpm typecheck
pnpm lint
pnpm test:unit
VITE_API_MODE=http pnpm build
pnpm openapi:check
```

Expected: 全绿。

- [ ] **Step 3: 契约与真实栈**

```bash
node deploy/scripts/openapi-lint.mjs
pnpm openapi:validate
HRMS_DEMO_PASSWORD=... bash deploy/scripts/verify_real_stack.sh
```

Expected: 全 PASS。

- [ ] **Step 4: 提交与打标签**

```bash
git add -A
git commit -m "chore(v11): final gap-completion gates"
git tag v1.1-gap-complete
```

- [ ] **Step 5: 构建发布镜像**

```bash
GIT_SHA=$(git rev-parse HEAD) BUILD_TIME=$(date -u +%Y-%m-%dT%H:%M:%SZ) RELEASE_TAG=v1.1-gap-complete \
docker compose --env-file deploy/compose/.env -f deploy/compose/docker-compose.yml build
```

---

## 验证清单（每 Phase 结束）

```bash
# 后端
cd backend
php artisan test
vendor/bin/pint --test
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
php artisan capability:audit
php artisan arch:test

# 前端
cd ../frontend
pnpm typecheck
pnpm lint
pnpm test:unit
VITE_API_MODE=http pnpm build
pnpm openapi:check

# 契约
cd ..
node deploy/scripts/openapi-lint.mjs
pnpm openapi:validate
node scripts/audit_route_coverage.mjs --require-mature --require-capabilities
```

## 依赖与顺序

```text
A1 → A2 → A3 → A4 → A5
A5 → B1..B5（可并行，但 B1/B2 先验证 Command/Projection）
B1..B5 → C1..C4（C1 依赖 B1 的版本化模式）
B1..B5 → D1..D4（D1 依赖 A3/A4；D4 依赖 A2）
C/D → E1..E4
```

## 风险与回滚

| 风险 | 回滚 |
|---|---|
| 新迁移在现有库失败 | 迁移前备份；失败即 `migrate:rollback --step=1`（仅新迁移） |
| 内核不适用 | Feature flag 关闭新集群路由；旧端点保留 |
| 能力码膨胀 | 复用优先；`capability:audit` 阻止无角色授予 |
| 真实 E2E 超时 | 按集群分片；160 页审计保持单次 < 3 分钟 |
| 字段策略误配 | 只允许收紧；快照重放；审计可回滚 |

## Definition of Done（全局）

- 63 个 gap 标记清零；`--require-capabilities` 通过。
- 156 能力码全部有角色授予与负测。
- 12 集群各有一条真实栈主链路 E2E。
- 后端/前端/契约/真实栈全量门禁通过。
- 验收报告与覆盖矩阵更新，release SHA 可追溯。

## Spec 覆盖映射

| Spec 章节 | 对应 Task |
|---|---|
| 5.1.1 Command Envelope | A1 |
| 5.1.2 Projection Contract | A2 |
| 5.1.3 Platform Kernel | A3、D1、D2 |
| 5.3.1 44 能力码 | A4 |
| 5.3.2 23 张表 | A3、B1–B5、C1–C3、D1–D4 |
| K1 组织岗位 | C1 |
| K2 合同 | B1 |
| K3 文档 | C4 |
| K4 考勤 | C2 |
| K5 加班/调休 | B2 |
| K6 差旅 | C3 |
| K7 薪酬档案 | B5 |
| K8 绩效 | B3 |
| K9 招聘 | B4 |
| K10 培训 | D3 |
| K11 平台治理/福利 | D1、D2 |
| K12 报表 | D4 |
| 8.1 集群 DoD | E1、E2、E3 |
| 8.2 系统级验收 | E4 |
| 9 YAGNI 边界 | Global Constraints、E3 |

## 执行说明

- 推荐 Subagent-Driven：每个 Task 一个子代理，完成后由 lead 复核再进入下一个 Task。
- A 阶段必须串行；B/C/D 可按集群并行（不同目录/表），但同一模块内串行。
- 每个 Task 的提交必须独立可回滚；不得把多个集群混在一个提交。
- 若执行中发现 Spec 与代码冲突，先停下更新 Spec/ADR，再继续，不得静默偏离。
