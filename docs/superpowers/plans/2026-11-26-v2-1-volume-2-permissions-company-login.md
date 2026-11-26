# FactoryHR V2.1 整改第 2 册：权限、单公司与密码登录专项 — 详细实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 完成完整权限闭环、单公司数据模型 cutover 和账号密码登录基线，使五类真实角色在关闭 MFA 时可从登录走到页面、动作、数据与字段均受服务端约束的完整任务。

**Architecture:** 以 `company_profile` 单例替换 tenant/legal entity；以生成的动作清单驱动 `role_pages`、能力授权、数据范围与字段策略；以服务端负测证明隐藏按钮不是安全边界；以真实迁移演练证明删列与恢复可执行。

**Tech Stack:** Laravel 11 / PHP 8.4 / MySQL 8.4 / Redis / Vue 3.5 / TypeScript 5.9 / Element Plus 2.14 / PHPUnit / Vitest / Playwright / OpenAPI 3.1。

## Global Constraints

- 规范优先级：用户裁决 > `04 专项补充书` > `01/02 V2.1` > `03 路由矩阵` > V1.1/历史 ADR。
- 单公司方向已确定；本册必须完成代码、OpenAPI、种子、测试与数据库迁移，而不是只改文档。
- 默认 `IAM_MFA_ENABLED=false`；MFA 代码可保留，但不得成为登录或 API 的验收前提。
- 页面进入权 = `role_pages` ∧ 页面能力；动作执行权 = 独立能力授权 + anchor + field profile。
- 隐藏按钮不是安全边界；所有写/导出/附件/统计 API 必须服务端鉴权。
- 金额整数最小单位；费率十进制字符串；时间 UTC 存储，业务日期按 `company_profile.timezone`。
- 迁移 forward-only；生产删列必须备份、测试库演练、恢复校验与窗口审批。
- 未执行或未通过的命令必须记录为 `NOT RUN`/`FAIL`，不得写“已完成”。
- 测试与种子只用合成数据；禁止提交 `.env`、凭据、SQLite 数据库或真实员工数据。

---

## 0. 执行顺序与依赖

```text
Task 1  单公司迁移预检与冲突报告
Task 2  单公司数据合并与 COMPANY 锚点迁移
Task 3  后端运行时去 tenant/legal entity
Task 4  前端与 OpenAPI 去 tenant/legal entity
Task 5  动作清单补齐与权限目录校验
Task 6  用户管理独立工作台
Task 7  角色管理四 Tab 与差异保存
Task 8  菜单/页面、数据范围、字段权限工作台
Task 9  权限会话一致性与管理员保护
Task 10 密码登录与可选 MFA 验收
Task 11 五类角色权限正负矩阵
Task 12 单公司迁移演练、恢复与第 2 册验收
```

依赖关系：

- Task 1 必须先于 Task 2/3；冲突未裁定不得进入删列。
- Task 2 必须先于 Task 3/4；否则运行路径会同时依赖新旧两套键。
- Task 5 是 Task 6–9 的动作与按钮真相源。
- Task 6/7 可共用 `PermissionProjection`，但不得共用巨型平台容器。
- Task 10 独立于单公司迁移，但必须在 Task 11 前通过，否则五角色矩阵无法执行。
- Task 12 必须使用 Task 2 的真实迁移与 Task 11 的真实账号矩阵。

---

### Task 1: 单公司迁移预检与冲突报告

**Files:**
- Create: `backend/app/Modules/Organization/Commands/CompanyPreflightCommand.php`
- Create: `backend/tests/Feature/Organization/SingleCompanyPreflightTest.php`
- Create: `docs/audit/2026-11-26-single-company-preflight.md`
- Modify: `backend/bootstrap/app.php`
- Modify: `backend/tests/Contract/NoTenantNoLegalEntityTest.php` (扫描根加入 `backend/database/seeders`、`docs/generated`、`docs/openapi/openapi.yaml`；仅按文件+方法精确豁免预检的列名检查)
- Test: `backend/tests/Feature/Organization/SingleCompanyPreflightTest.php`

**Interfaces:**
- Consumes: 现有 schema、`company_profile`、`legal_entities`、tenant/legal 列。
- Produces: 可重复执行的 `php artisan company:preflight`；输出表级行数、旧列清单、业务键重复与授权绑定统计。

- [ ] **Step 1: 写失败测试**

创建 `backend/tests/Feature/Organization/SingleCompanyPreflightTest.php`：

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SingleCompanyPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_reports_company_profile_and_legacy_columns(): void
    {
        $this->artisan('company:preflight', ['--json' => true])
            ->expectsOutputToContain('"tables"')
            ->assertExitCode(0);

        $this->assertDatabaseCount('company_profile', 1);
    }

    public function test_report_is_driver_neutral_and_lists_every_legacy_column(): void
    {
        $report = (new \App\Modules\Organization\Commands\CompanyPreflightCommand)->buildReport();

        $this->assertContains($report['connection'], ['sqlite', 'mysql']);
        $this->assertArrayHasKey('tables', $report);
        foreach ($report['tables'] as $table) {
            $this->assertIsArray($table['legacy_columns']);
            $this->assertSame(
                Schema::hasColumn($table['table'], 'tenant_id') || Schema::hasColumn($table['table'], 'legal_entity_id'),
                $table['legacy_columns'] !== [],
            );
        }
    }
}
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=SingleCompanyPreflightTest
```

Expected: FAIL，`Command "company:preflight" is not defined.`

- [ ] **Step 3: 实现预检命令**

创建 `backend/app/Modules/Organization/Commands/CompanyPreflightCommand.php`：

```php
<?php

declare(strict_types=1);

namespace App\Modules\Organization\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CompanyPreflightCommand extends Command
{
    protected $signature = 'company:preflight {--json : Output JSON}';

    protected $description = 'Audit schema and data before the single-company cutover.';

    public function handle(): int
    {
        $report = $this->buildReport();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->table(['table', 'rows', 'legacy columns'], array_map(
            static fn (array $row): array => [$row['table'], $row['rows'], implode(',', $row['legacy_columns'])],
            $report['tables'],
        ));

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    public function buildReport(): array
    {
        // Driver-neutral: Schema::getTables works on SQLite (tests) and MySQL 8.4.
        $tables = collect(Schema::getTables())
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->values();

        return [
            'connection' => (string) DB::connection()->getDriverName(),
            'company_profile_rows' => Schema::hasTable('company_profile') ? DB::table('company_profile')->count() : 0,
            'tables' => $tables->map(function (string $table): array {
                $legacy = array_values(array_filter(
                    ['tenant_id', 'legal_entity_id'],
                    static fn (string $column): bool => Schema::hasColumn($table, $column),
                ));

                return ['table' => $table, 'rows' => DB::table($table)->count(), 'legacy_columns' => $legacy];
            })->all(),
            'legal_entity_bindings' => Schema::hasTable('authority_bindings')
                ? DB::table('authority_bindings')->where('anchor_type', 'LEGAL_ENTITY')->count()
                : 0,
        ];
    }
}
```

注册：把 `CompanyPreflightCommand::class` 加入 `backend/bootstrap/app.php` 的 `->withCommands([...])`。命令位于 PSR-4 `App\` -> `app/` 内，可被自动加载。

**扫描范围契约（Step 3 必须同时修改测试）：** `NoTenantNoLegalEntityTest` 的 roots 扩展为：

```php
$roots = [
    $this->app->basePath('app'),
    $this->app->basePath('routes'),
    $this->app->basePath('database/seeders'),
    $this->app->basePath('../frontend/src'),
    $this->app->basePath('../docs/generated'),
];
$explicit = [__FILE__]; // 测试自身；文档与历史审计不在运行时代码面
```

另对 `docs/openapi/openapi.yaml` 做同样的字符串扫描（它在 API 契约面）。迁移目录继续排除。`CompanyPreflightCommand::buildReport()` 是唯一允许的运行时例外：豁免必须按**文件+方法**精确匹配（可用 `// single-company:preflight-only` 行标记 + 该测试只忽略该标记行），不得忽略整个目录或整个文件。

- [ ] **Step 4: 运行测试确认通过**

Run:

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=SingleCompanyPreflightTest
```

Expected: PASS，2 tests。

- [ ] **Step 5: 生成真实预检报告**

Run:

```bash
cd backend && ../.tools/php/php.exe artisan company:preflight --json > ../docs/audit/2026-11-26-single-company-preflight.json
```

把表级统计、冲突与保留决策写入 `docs/audit/2026-11-26-single-company-preflight.md`，至少包含：

```markdown
# 单公司迁移预检

## 保留公司
- company_profile.id: 1
- name/timezone/currency: （实际值）

## 旧列清单
（逐表列出 tenant_id / legal_entity_id）

## 冲突清单
（业务键重复、空值、跨法人同键；没有则写 none）

## 授权绑定
- LEGAL_ENTITY 数量：
- 目标 COMPANY 映射：
- 受影响的 user/role：

## 阻断项
（任何无法判定归属的数据必须列此；有阻断则停止 Task 2）
```

- [ ] **Step 6: Commit**

```bash
git add backend/app/Modules/Organization/Commands/CompanyPreflightCommand.php backend/bootstrap/app.php backend/tests/Feature/Organization/SingleCompanyPreflightTest.php backend/tests/Contract/NoTenantNoLegalEntityTest.php docs/audit/2026-11-26-single-company-preflight.json docs/audit/2026-11-26-single-company-preflight.md
git commit -m "feat(org): add single-company cutover preflight"
```

---

### Task 2: 单公司数据合并与 COMPANY 锚点迁移

**Files:**
- Create: `backend/database/migrations/2026_11_26_000300_migrate_legal_entity_bindings_to_company.php`
- Create: `backend/database/migrations/2026_11_26_000310_merge_single_company_duplicates.php`
- Create: `backend/database/migrations/2026_11_26_000320_drop_tenant_and_legal_entity_columns.php`
- Create: `backend/tests/Feature/Organization/SingleCompanyMigrationTest.php`
- Modify: `backend/database/migrations/2026_11_26_000050_create_company_profile.php`：**不得修改**；所有变更走新迁移。
- Test: `backend/tests/Feature/Organization/SingleCompanyMigrationTest.php`

**Interfaces:**
- Consumes: Task 1 预检报告、`company_profile` 单例、`authority_bindings`。
- Produces: 可重复演练的 forward 迁移；`LEGAL_ENTITY` → `COMPANY`；旧列删除；唯一索引重建。

- [ ] **Step 1: 写迁移失败测试**

创建 `backend/tests/Feature/Organization/SingleCompanyMigrationTest.php`：

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SingleCompanyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_company_schema_has_no_legacy_columns(): void
    {
        foreach (Schema::getTables() as $table) {
            $name = (string) $table['name'];
            $this->assertFalse(Schema::hasColumn($name, 'tenant_id'), "{$name} still has tenant_id");
            $this->assertFalse(Schema::hasColumn($name, 'legal_entity_id'), "{$name} still has legal_entity_id");
        }
        $this->assertFalse(Schema::hasTable('legal_entities'));
    }

    public function test_legal_entity_bindings_become_company(): void
    {
        $this->assertSame(0, DB::table('authority_bindings')->where('anchor_type', 'LEGAL_ENTITY')->count());
        $this->assertGreaterThan(0, DB::table('authority_bindings')->where('anchor_type', 'COMPANY')->count());
    }

    public function test_company_profile_is_singleton(): void
    {
        $this->assertSame(1, DB::table('company_profile')->count());
    }
}
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=SingleCompanyMigrationTest
```

Expected: FAIL：旧列仍存在、`legal_entities` 仍存在、绑定仍有 `LEGAL_ENTITY`。

- [ ] **Step 3: 写绑定迁移**

创建 `2026_11_26_000300_migrate_legal_entity_bindings_to_company.php`：

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('authority_bindings')) {
            return;
        }

        DB::table('authority_bindings')
            ->where('anchor_type', 'LEGAL_ENTITY')
            ->update([
                'anchor_type' => 'COMPANY',
                'anchor_id' => null,
                'anchor_key' => 0,
            ]);
    }

    public function down(): void
    {
        // Forward-only: COMPANY cannot be losslessly converted back.
    }
};
```

- [ ] **Step 4: 写冲突合并迁移**

创建 `2026_11_26_000310_merge_single_company_duplicates.php`。迁移必须只处理 Task 1 报告已确认的重复键，不得静默合并：

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_profile') || DB::table('company_profile')->count() !== 1) {
            throw new RuntimeException('Single company profile must exist exactly once before merge.');
        }

        $duplicates = DB::table('org_units')
            ->select('code', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('code')
            ->having('aggregate', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Duplicate org unit codes require manual resolution: '.$duplicates->pluck('code')->implode(', '));
        }
    }

    public function down(): void
    {
        // Forward-only: duplicate resolution is not reversible.
    }
};
```

按预检报告为每个有业务键的表重复该检查；禁止用 `firstOrCreate` 或删除重复行“自动修复”。

- [ ] **Step 5: 写删列与唯一索引重建迁移**

创建 `2026_11_26_000320_drop_tenant_and_legal_entity_columns.php`。**必须先删除依赖索引/外键，再重建单公司业务唯一索引，最后删除旧列**；不得只删列：

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single-company business keys. Every legacy unique index whose columns
     * intersect this list must be replaced by the corresponding key below.
     *
     * @var array<string, list<list<string>>>
     */
    private const REBUILT_UNIQUES = [
        'users' => [['username']],
        'org_units' => [['code']],
        'positions' => [['code']],
        'position_grades' => [['code']],
        'employments' => [['employee_no']],
        'employment_contracts' => [['contract_no']],
        'leave_types' => [['code']],
        'calendar_versions' => [['year']],
        'holidays' => [['date']],
        'shift_templates' => [['code', 'version', 'valid_from']],
        'payroll_items' => [['code']],
        'payroll_schemes' => [['code', 'version']],
        'attendance_devices' => [['code']],
        'attendance_period_closures' => [['month']],
        'report_definitions' => [['code']],
        'benefit_plans' => [['code', 'version']],
        'contract_templates' => [['code', 'version']],
        'notice_templates' => [['code', 'version']],
        'travel_policies' => [['city_tier', 'version']],
        'performance_indicators' => [['code']],
        'training_plans' => [['code']],
        'training_courses' => [['code']],
        'training_exams' => [['code', 'version']],
        'feature_flags' => [['flag']],
        'menu_overrides' => [['page_code']],
        'notification_templates' => [['code']],
        'iam_scope_templates' => [['template_code']],
        'iam_field_policies' => [['field_key']],
        'idempotency_keys' => [['principal_id', 'scope', 'key']],
        'demo_datasets' => [['dataset']],
        'attendance_rules' => [['version']],
        'overtime_requests' => [['employment_id', 'business_date']],
    ];

    public function up(): void
    {
        // Never hardcode the legacy column list: enumerate the live schema.
        $tables = collect(Schema::getTables())
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->filter(static fn (string $table): bool => Schema::hasColumn($table, 'tenant_id')
                || Schema::hasColumn($table, 'legal_entity_id'))
            ->values();

        foreach ($tables as $table) {
            $this->assertNoDuplicates($table);

            // Drop dependent foreign keys first, then non-primary indexes.
            foreach (Schema::getForeignKeys($table) as $foreign) {
                $columns = array_map(static fn (mixed $column): string => (string) $column, $foreign['columns']);
                if (array_intersect($columns, ['tenant_id', 'legal_entity_id']) !== []) {
                    Schema::table($table, static fn (Blueprint $blueprint) => $blueprint->dropForeign($foreign['name']));
                }
            }
            foreach (Schema::getIndexes($table) as $index) {
                if (($index['primary'] ?? false) === true) {
                    continue; // Never drop the primary key.
                }
                $columns = array_map(static fn (mixed $column): string => (string) $column, $index['columns']);
                if (array_intersect($columns, ['tenant_id', 'legal_entity_id']) !== []) {
                    Schema::table($table, static fn (Blueprint $blueprint) => $blueprint->dropIndex($index['name']));
                }
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                foreach (['tenant_id', 'legal_entity_id'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->dropColumn($column);
                    }
                }
            });

            // Rebuild the single-company unique keys that the drop removed.
            foreach (self::REBUILT_UNIQUES[$table] ?? [] as $columns) {
                Schema::table($table, static fn (Blueprint $blueprint) => $blueprint->unique($columns));
            }
        }

        Schema::dropIfExists('legal_entities');
    }

    private function assertNoDuplicates(string $table): void
    {
        foreach (self::REBUILT_UNIQUES[$table] ?? [] as $columns) {
            $duplicates = \Illuminate\Support\Facades\DB::table($table)
                ->select($columns)
                ->selectRaw('COUNT(*) AS aggregate')
                ->groupBy($columns)
                ->havingRaw('COUNT(*) > 1')
                ->limit(5)
                ->get();

            if ($duplicates->isNotEmpty()) {
                throw new RuntimeException("Duplicate business key in {$table}(" . implode(',', $columns) . '): ' . $duplicates->toJson());
            }
        }
    }

    public function down(): void
    {
        // Forward-only: restore from the pre-cutover snapshot.
    }
};
```

若某张表的单公司业务键无法从现有唯一索引推导，必须先在预检报告中列明并由人裁定；**不得用“跳过该表”换取绿灯**。实际删列前必须对新键做重复检查，并在 MySQL 8.4 测试库执行，不得只在 SQLite 验证。

- [ ] **Step 6: 在测试库运行迁移**

Run:

```bash
cd backend
../.tools/php/php.exe artisan migrate:fresh --seed
../.tools/php/php.exe artisan test --compact --filter=SingleCompanyMigrationTest
```

Expected: 迁移成功；3 tests PASS；`company_profile` 恰好 1 行；旧列与 `legal_entities` 不存在；`users.username`、`org_units.code`、`employments.employee_no` 等单公司唯一索引存在。

- [ ] **Step 7: 实现 ADR-0037 承诺的 `single-company:check` 门禁**

ADR-0037 与规格都引用 `single-company:check`，但仓库尚无此脚本。新增：

- Create: `scripts/audit_single_company.mjs`
- Create: `scripts/tests/audit_single_company.test.mjs`
- Modify: `package.json`（`"single-company:check": "node scripts/audit_single_company.mjs"`）

扫描范围必须覆盖 ADR-0037 的承诺：`backend/app`、`backend/routes`、`backend/database/seeders`、`frontend/src`、`frontend/src/generated`、`docs/openapi/openapi.yaml`、`docs/generated`。允许清单只包含迁移目录与 `CompanyPreflightCommand` 的精确行标记；禁止目录级豁免。

```js
const FORBIDDEN = [/tenant_id/, /legal_entity_id/, /LEGAL_ENTITY/, /X-Legal-Entity-Id/, /activeLegalEntityId/]
// exit 1 and print file:line for each match outside the explicit allowlist
```

反例测试至少覆盖：`tenant_id` 命中、`LEGAL_ENTITY` 命中、allowlist 精确匹配、目录级豁免被拒绝。

Run:

```bash
node scripts/audit_single_company.mjs
node --test scripts/tests/audit_single_company.test.mjs
```

Expected: 本步结束时真实仓库仍 FAIL（`app/` 尚未清理）；测试自身 PASS。本步只建立门禁，由本册 Task 3/4 清零。

- [ ] **Step 8: 验证迁移可重复与失败即停**

Run:

```bash
cd backend
../.tools/php/php.exe artisan migrate:rollback --step=3
../.tools/php/php.exe artisan migrate --force
```

Expected: 回滚/重跑无异常；冲突数据存在时迁移必须失败并输出表与键，不得成功。

- [ ] **Step 9: Commit**

```bash
git add backend/database/migrations/2026_11_26_000300_migrate_legal_entity_bindings_to_company.php backend/database/migrations/2026_11_26_000310_merge_single_company_duplicates.php backend/database/migrations/2026_11_26_000320_drop_tenant_and_legal_entity_columns.php backend/tests/Feature/Organization/SingleCompanyMigrationTest.php scripts/audit_single_company.mjs scripts/tests/audit_single_company.test.mjs package.json
git commit -m "feat(db): cut over to single-company schema"
```

---

### Task 3: 后端运行时去 tenant/legal entity

**Files:**
- Modify: `backend/app/Shared/Authorization/AuthorizationSnapshot.php`
- Modify: `backend/app/Modules/IAM/Auth/AuthenticatedPrincipal.php`
- Modify: `backend/app/Modules/IAM/Auth/PrincipalResolver.php`
- Modify: `backend/app/Shared/Authorization/EnsureCapability.php`
- Delete: `backend/app/Shared/Authorization/ResolveLegalEntityContext.php`
- Delete: `backend/app/Shared/Authorization/ResolveResourceLegalEntityContext.php`
- Create: `backend/app/Shared/Authorization/ResolveCompanyScope.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Contract/NoTenantNoLegalEntityTest.php`

**Interfaces:**
- Consumes: Task 2 删除后的 schema、`company_profile`。
- Produces: 认证主体、授权快照、路由中间件不再携带 tenant/legal entity；`COMPANY` 为全公司范围。

- [ ] **Step 1: 运行现有合同测试确认失败**

Run:

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=NoTenantNoLegalEntityTest
```

Expected: FAIL，列出仍含 `tenant_id`/`legal_entity_id`/`LEGAL_ENTITY` 的生产文件。

- [ ] **Step 2: 删除旧上下文中间件并替换**

删除两个旧中间件文件；新增 `ResolveCompanyScope.php`：

```php
<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Modules\Organization\Contracts\CompanyProfileReader;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveCompanyScope
{
    public function __construct(private readonly CompanyProfileReader $profiles) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('company_profile', $this->profiles->current());
        return $next($request);
    }
}
```

- [ ] **Step 3: 改写认证主体与快照**

在 `AuthenticatedPrincipal` 删除 `tenantId` 字段与访问器；在 `PrincipalResolver` 删除 `tenant_id` 查询条件；在 `AuthorizationSnapshot` 删除所有 `where('tenant_id', ...)` 与 `legalEntityId` 字段。

修改后必须保留：

- `userId`、`roles`、`capabilities`、`anchor`、`fieldProfile`、`authVersion`。
- 快照重放仍按角色/绑定有效性与 `auth_version` 执行。

- [ ] **Step 4: 改写路由中间件**

在 `backend/routes/api.php` 中：

- 删除 `use App\Shared\Authorization\ResolveLegalEntityContext;` 与 `ResolveResourceLegalEntityContext`。
- 所有 `capability:...,COMPANY,legal_entity_id` 改为 `capability:...,COMPANY` 或具体 `ORG_UNIT`/`SELF` 锚点。
- 时间日历路由 `/system/calendars/{legal_entity_id}/publish` 与 `/calendars/{legal_entity_id}` 改为 `/system/calendars/publish` 与 `/calendars`。
- 不得保留 `X-Legal-Entity-Id` 的读取或回退。

- [ ] **Step 5: 运行架构与合同测试**

Run:

```bash
cd backend
../.tools/php/php.exe artisan test --compact --filter=NoTenantNoLegalEntityTest
../.tools/php/php.exe artisan arch:test
../.tools/php/php.exe artisan capability:audit
```

Expected: 合同测试通过；`arch:test` 通过；capability audit 无新增违规。

- [ ] **Step 6: Commit**

```bash
git add backend/app/Shared/Authorization/AuthorizationSnapshot.php backend/app/Modules/IAM/Auth/AuthenticatedPrincipal.php backend/app/Modules/IAM/Auth/PrincipalResolver.php backend/app/Shared/Authorization/EnsureCapability.php backend/app/Shared/Authorization/ResolveCompanyScope.php backend/routes/api.php
git rm backend/app/Shared/Authorization/ResolveLegalEntityContext.php backend/app/Shared/Authorization/ResolveResourceLegalEntityContext.php
git commit -m "refactor(auth): remove tenant and legal-entity runtime context"
```

---

### Task 4: 前端与 OpenAPI 去 tenant/legal entity

**Files:**
- Modify: `frontend/src/api/http.ts`
- Modify: `frontend/src/api/normalise.ts`
- Modify: `frontend/src/layouts/AppShell.vue`
- Modify: `frontend/src/views/LoginView.vue`
- Modify: `frontend/src/modules/people/components/EmployeeCreateForm.vue`
- Modify: `frontend/src/modules/people/components/DepartmentForms.vue`
- Modify: `frontend/src/modules/people/components/MovementForms.vue`
- Modify: `frontend/src/modules/people/components/PositionForms.vue`
- Modify: `frontend/src/views/timepay/panels/TravelPanel.vue`
- Modify: `docs/openapi/openapi.yaml`
- Modify: `frontend/src/generated/api.ts`
- Test: `frontend/tests/unit/noTenantLegal.spec.ts`

**Interfaces:**
- Consumes: Task 3 路由与 API 契约。
- Produces: 前端请求、表单、展示与生成类型不再引用 tenant/legal entity；公司信息来自 `/v1/me` 的 `company_profile`。

- [ ] **Step 1: 写失败测试**

创建 `frontend/tests/unit/noTenantLegal.spec.ts`：

```ts
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const files = [
  'src/api/http.ts',
  'src/api/normalise.ts',
  'src/layouts/AppShell.vue',
  'src/views/LoginView.vue',
  'src/modules/people/components/EmployeeCreateForm.vue',
  'src/modules/people/components/DepartmentForms.vue',
  'src/modules/people/components/MovementForms.vue',
  'src/modules/people/components/PositionForms.vue',
]

describe('single company frontend', () => {
  it('contains no legacy tenant or legal entity references', () => {
    const root = resolve(__dirname, '../..')
    for (const file of files) {
      const source = readFileSync(resolve(root, file), 'utf8')
      expect(source, file).not.toMatch(/tenant_id|legal_entity|LEGAL_ENTITY|X-Legal-Entity-Id|activeLegalEntityId|tenantLabel/)
    }
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
cd frontend && pnpm exec vitest run tests/unit/noTenantLegal.spec.ts
```

Expected: FAIL，列出仍含旧引用的文件。

- [ ] **Step 3: 清理请求与状态**

在 `frontend/src/api/http.ts`：

- 删除 `legalEntityId()` 与 `X-Legal-Entity-Id` 请求头。
- 删除 `tenant_id`、`legalEntity`、`legalEntityName` 字段。
- 删除所有 `params.legal_entity_id`。

在 `frontend/src/layouts/AppShell.vue` 与 `LoginView.vue`：

```vue
<span class="sidebar__company">{{ session.companyName ?? 'FactoryHR' }}</span>
```

公司名来自 `/v1/me` 的 `company_profile.name`；不得回退到租户名。

- [ ] **Step 4: 清理业务表单**

删除员工、部门、异动、岗位表单中的“法人编号”输入与 `legal_entity_id` 提交字段；删除差旅政策中的法人编号输入。所有组织归属改为：

```ts
{ org_unit_id: numeric(form.orgUnitId) }
```

服务端从 `company_profile` 与组织树推导公司范围。

- [ ] **Step 5: 同步 OpenAPI 与生成类型**

在 `docs/openapi/openapi.yaml`：

- 删除所有 `tenant_id`、`legal_entity_id`、`legal_entity_name`、`X-Legal-Entity-Id`。
- 日历路径移除 `{legal_entity_id}`。
- `anchor_type` 枚举删除 `LEGAL_ENTITY`，加入/保留 `COMPANY`。
- `late_grace_source` 枚举删除 `LEGAL_ENTITY`。

运行：

```bash
pnpm openapi:generate
pnpm openapi:check
node deploy/scripts/openapi-lint.mjs
```

Expected: 生成类型更新；check/lint 通过。

- [ ] **Step 6: 运行前端测试与全量扫描**

Run:

```bash
cd frontend && pnpm exec vitest run tests/unit/noTenantLegal.spec.ts
cd .. && rg -n 'tenant_id|legal_entity|LEGAL_ENTITY|X-Legal-Entity-Id|activeLegalEntityId|tenantLabel' frontend/src docs/openapi
```

Expected: 测试 PASS；`node scripts/audit_single_company.mjs` 退出码 0；`rg` 扫描仅允许迁移文件与 `CompanyPreflightCommand` 的精确标记行。

- [ ] **Step 7: Commit**

```bash
git add frontend/src/api/http.ts frontend/src/api/normalise.ts frontend/src/layouts/AppShell.vue frontend/src/views/LoginView.vue frontend/src/modules/people/components/EmployeeCreateForm.vue frontend/src/modules/people/components/DepartmentForms.vue frontend/src/modules/people/components/MovementForms.vue frontend/src/modules/people/components/PositionForms.vue frontend/src/views/timepay/panels/TravelPanel.vue docs/openapi/openapi.yaml frontend/src/generated/api.ts frontend/tests/unit/noTenantLegal.spec.ts
git commit -m "refactor(ui): remove tenant and legal-entity concepts"
```

---

### Task 5: 动作清单补齐与权限目录校验

**Files:**
- Modify: `docs/generated/action-input.json`
- Modify: `docs/generated/page-actions.json`
- Modify: `backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php`
- Modify: `backend/app/Modules/IAM/Authorization/CapabilityCatalog.php`
- Modify: `docs/openapi/openapi.yaml`
- Test: `scripts/tests/generate_action_manifest.test.mjs`
- Test: `backend/tests/Contract/ActionManifestParityTest.php`

**Interfaces:**
- Consumes: 第 1 册生成器、现有 OpenAPI、现有 `CapabilityCatalog`。
- Produces: 所有真实写按钮均有动作声明；无页面以 `required_capability` 冒充按钮。

- [ ] **Step 1: 运行现状门禁并记录缺口**

Run:

```bash
node scripts/audit_action_manifest.mjs
node scripts/generate_action_manifest.mjs
```

Expected: 输出当前真实缺口；把清单保存到 `docs/audit/2026-11-26-v2-action-gaps.json`，并在同名 `.md` 中写人类可读汇总。

- [ ] **Step 2: 逐页补齐动作输入**

在 `docs/generated/action-input.json` 中为每个可写页面补齐动作，至少覆盖：

- 员工：新增、编辑、归档、恢复、导入、导出、附件下载。
- 部门/岗位：新增、编辑、停用、恢复、成员/编制调整。
- 异动/合同：创建、提交、撤回、审批、终止、续签。
- 考勤/假勤：补卡、纠错、申请、撤回、审批。
- 薪资：试算、复核、发布、导出、调整。
- 招聘/培训：创建、审批、发布、报名、签到、完成。
- 系统：用户创建/编辑/停用、角色创建/复制/保存授权、菜单保存、数据范围保存、字段策略保存。

每个动作必须有真实 API；没有 API 的能力先在第 3 册补口，不得写假动作。

- [ ] **Step 3: 补齐权限目录**

为 `PermissionResourceCatalog.php` 中每个页面的 `actions` 补真实能力码。要求：

```php
// Source of truth is docs/generated/page-actions.json; this array is a projection.
'actions' => ['people.employee.manage', 'people.employee.archive', 'people.employee.restore'],
```

不得把 `required_capability` 复制为唯一动作；不得出现 `.read` 作为写动作。

- [ ] **Step 4: 校验能力定义**

在 `CapabilityCatalog.php` 为新增能力补 `CapabilityDefinition`，字段必须包含：

```php
new CapabilityDefinition(
    'people.employee.archive',
    'people',
    ['archive'],
    [AnchorType::COMPANY, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE],
    FieldProfile::P1_INTERNAL,
    'high',
    'employee_lifecycle',
    'Archive an employee without deleting history.',
),
```

实际构造参数以现有类签名为准；新增后运行 `php artisan capability:audit`。

- [ ] **Step 5: 运行双向校验**

Run:

```bash
node scripts/generate_action_manifest.mjs
node scripts/audit_action_manifest.mjs
cd backend && ../.tools/php/php.exe artisan test --compact --filter=ActionManifestParityTest
```

Expected: 生成文件稳定；门禁通过；不存在“写 API 无动作”或“动作无 API”。

- [ ] **Step 6: Commit**

```bash
git add docs/generated/action-input.json docs/generated/page-actions.json backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php backend/app/Modules/IAM/Authorization/CapabilityCatalog.php docs/openapi/openapi.yaml docs/audit/2026-11-26-v2-action-gaps.json docs/audit/2026-11-26-v2-action-gaps.md
git commit -m "feat(iam): declare every writable page action"
```

---

### Task 6: 用户管理独立工作台

**Files:**
- Modify: `frontend/src/modules/platform/pages/SystemUsersPage.vue`
- Create: `frontend/src/modules/platform/components/UserCreateDialog.vue`
- Create: `frontend/src/modules/platform/components/UserRoleDrawer.vue`
- Create: `frontend/src/modules/platform/components/UserPermissionPreview.vue`
- Modify: `backend/app/Modules/IAM/Http/Controllers/UserAdminController.php`
- Modify: `backend/app/Modules/IAM/Services/RoleAdminService.php`
- Modify: `frontend/tests/unit/systemUsersPage.spec.ts`（已存在；扩展而不是新建重复测试）
- Modify: `backend/tests/Feature/IAM/UserRoleAssignmentApiTest.php`（若文件不存在则创建；先运行 `rg --files backend/tests | rg UserRole` 确认）
- Create: `backend/tests/Support/UserAdminScenario.php`（提供 `userWithRole(string $role, ...$args)` 与 `admin()`；真实创建 User + Role + AuthorityBinding）

**Interfaces:**
- Consumes: `/v1/users`、`/v1/users/{id}/roles`、`/v1/users/{id}/permissions-preview`、角色列表。
- Produces: 列表 + 创建弹窗 + 编辑弹窗 + 启停 + 重置密码 + 角色抽屉 + 权限预览 + 变更历史。

> 测试辅助契约（必须在 `UserAdminScenario` 中实现，供本册所有 IAM 测试复用）：
>
> - `admin(): User`
> - `userWithRole(string $roleCode, string $anchorType = 'COMPANY', ?int $anchorId = null): User`
> - `userWithCapability(string $capability, string $anchorType = 'COMPANY', ?int $anchorId = null): User`
> - `employee(): \App\Modules\People\Models\Employment`
>
> 每个 helper 必须创建有效 `UserRole` + `AuthorityBinding`（`auth_version` 与用户当前值一致），不得只写 `role_code` 字段。

- [ ] **Step 1: 写前端失败测试**

扩展 `frontend/tests/unit/systemUsersPage.spec.ts`；新增用例（保留现有 3 个用例，不删除、不弱化）：

```ts
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SystemUsersPage from '@/modules/platform/pages/SystemUsersPage.vue'

vi.mock('@/api/resourceClient', () => ({
  resourceGet: vi.fn(async (path: string) => {
    if (path === '/v1/users') return { items: [{ id: 1, username: 'demo.hr', status: 'ACTIVE', roles: [] }], total: 1 }
    if (path === '/v1/roles') return { roles: [{ code: 'HR_ADMIN', name: '人事管理员' }] }
    return {}
  }),
  resourcePost: vi.fn(async () => ({})),
  resourcePatch: vi.fn(async () => ({})),
  resourceDelete: vi.fn(async () => ({})),
}))

describe('SystemUsersPage', () => {
  it('shows Chinese status and opens create dialog', async () => {
    const wrapper = mount(SystemUsersPage, { global: { stubs: { ElButton: true, ElTable: true, ElDialog: true } } })
    await new Promise((resolve) => setTimeout(resolve))
    expect(wrapper.text()).toContain('在职')
    expect(wrapper.find('[data-testid="user-create"]').exists()).toBe(true)
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
cd frontend && pnpm exec vitest run tests/unit/systemUsersPage.spec.ts
```

Expected: FAIL：页面仍显示 `ACTIVE`，且没有创建弹窗入口。

- [ ] **Step 3: 重建用户页**

`SystemUsersPage.vue` 必须：

- 使用 `PageShell`、`DataTable`、`translate('iam', 'userStatus', ...)`。
- 页头主操作“新增用户”，打开 `UserCreateDialog`。
- 行操作“编辑”“启用/停用”“重置密码”“分配角色”“权限预览”。
- 角色分配使用 `UserRoleDrawer`，显示现有角色、可分配角色、变更差异与风险提示。
- 权限预览显示有效页面/动作/拒绝原因，不显示后端能力码给普通用户。
- 不出现接口路径、`ACTIVE`、`MFA` 等内部术语；MFA 列仅在开关开启时显示。

- [ ] **Step 4: 后端补齐启停与重置密码**

停用复用现有 `PATCH /v1/users/{id}`（`UserAdminController::update`，`status=DISABLED` + `reason`），不新增 `/deactivate`。重置密码新增：

```php
public function resetPassword(Request $request, int $id): JsonResponse
{
    $validated = $request->validate([
        'password' => ['required', 'string', 'min:12'],
        'reason' => ['required', 'string', 'min:3', 'max:255'],
    ]);
    // forceFill password_hash + increment auth_version, revokeAllForUser, audit
    return ApiResponse::ok(['id' => $id, 'auth_version' => $authVersion]);
}
```

同时在 `backend/routes/api.php` 注册 `POST /v1/users/{id}/password-reset`，中间件为 `['mfa.enrolled', 'mfa.step-up', 'capability:iam.user.manage,COMPANY']`，并在 `docs/openapi/openapi.yaml` 增加同名 operation、`x-capability: iam.user.manage` 与 `x-action-label: 重置密码`。

- [ ] **Step 5: 后端测试**

扩展 `UserRoleAssignmentApiTest`：

```php
public function test_disabling_user_revokes_existing_session_and_blocks_next_request(): void
{
    $admin = $this->userWithCapability('iam.user.manage');
    $target = $this->userWithCapability('iam.session.manage');

    $this->actingAs($admin)
        ->patchJson("/v1/users/{$target->id}", ['status' => 'DISABLED', 'reason' => '合成测试停用'])
        ->assertOk();
    $this->actingAs($target)->getJson('/v1/me')->assertStatus(401);
}
```

- [ ] **Step 6: 运行测试**

Run:

```bash
cd frontend && pnpm exec vitest run tests/unit/SystemUsersPage.spec.ts
cd ../backend && ../.tools/php/php.exe artisan test --compact --filter='UserRoleAssignmentApiTest|PasswordOnlyLoginTest'
```

Expected: PASS；停用后旧会话下一次请求 401。

- [ ] **Step 7: Commit**

```bash
git add frontend/src/modules/platform/pages/SystemUsersPage.vue frontend/src/modules/platform/components/UserCreateDialog.vue frontend/src/modules/platform/components/UserRoleDrawer.vue frontend/src/modules/platform/components/UserPermissionPreview.vue backend/app/Modules/IAM/Http/Controllers/UserAdminController.php frontend/tests/unit/systemUsersPage.spec.ts backend/tests/Feature/IAM/UserRoleAssignmentApiTest.php backend/tests/Support/UserAdminScenario.php backend/routes/api.php docs/openapi/openapi.yaml
git commit -m "feat(iam): rebuild user management workspace"
```

---

### Task 7: 角色管理四 Tab 与差异保存

**Files:**
- Modify: `frontend/src/modules/platform/pages/SystemRolesPage.vue`
- Create: `frontend/src/modules/platform/components/RolePageTree.vue`
- Create: `frontend/src/modules/platform/components/RoleActionMatrix.vue`
- Create: `frontend/src/modules/platform/components/RoleScopeEditor.vue`
- Create: `frontend/src/modules/platform/components/RoleFieldPolicyEditor.vue`
- Modify: `backend/app/Modules/IAM/Services/RolePageService.php`
- Modify: `backend/app/Modules/IAM/Http/Controllers/RolePageController.php`
- Modify: `backend/app/Modules/IAM/Http/Controllers/RoleAdminController.php`
- Test: `frontend/tests/unit/RolePermissionMatrix.spec.ts`
- Test: `backend/tests/Feature/IAM/RolePagesApiTest.php`

**Interfaces:**
- Consumes: `/v1/pages/catalog`、`/v1/capabilities`、`/v1/roles/{id}/pages`、`/v1/roles/{id}/capabilities`。
- Produces: 同一角色内的四个合法 Tab；保存前差异；事务写入页面/能力/范围/字段；快照重放。

- [ ] **Step 1: 写前端失败测试**

创建 `frontend/tests/unit/RolePermissionMatrix.spec.ts`：

```ts
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import RoleActionMatrix from '@/modules/platform/components/RoleActionMatrix.vue'

describe('RoleActionMatrix', () => {
  it('shows only real actions for the selected page and defaults new actions unchecked', () => {
    const wrapper = mount(RoleActionMatrix, {
      props: {
        page: {
          page_code: 'employees',
          actions: [
            { key: 'employees.create', label: '新增员工', capability: 'people.employee.manage' },
            { key: 'employees.export', label: '导出员工', capability: 'people.employee.export' },
          ],
        },
        selected: [],
      },
    })
    expect(wrapper.text()).toContain('新增员工')
    expect(wrapper.text()).toContain('导出员工')
    expect(wrapper.findAll('input[type="checkbox"]').every((input) => !(input.element as HTMLInputElement).checked)).toBe(true)
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
cd frontend && pnpm exec vitest run tests/unit/RolePermissionMatrix.spec.ts
```

Expected: FAIL，组件不存在。

- [ ] **Step 3: 实现四个 Tab 组件**

`RoleActionMatrix.vue` 使用 `page.actions`，不读取 `required_capability` 冒充动作；默认不勾选新增动作。`RoleScopeEditor.vue` 使用组织树，不出现租户/法人。`RoleFieldPolicyEditor.vue` 使用 P0–P3 中文标签。`RolePageTree.vue` 只允许选择实际页面资源，父分组不隐式赋权。

- [ ] **Step 4: 改写 `SystemRolesPage.vue`**

页面结构：

```text
角色列表（左）
角色详情（右）
  Tab 1 菜单与页面权限
  Tab 2 按钮与操作权限
  Tab 3 数据范围权限
  Tab 4 字段权限
  保存 → 差异预览 → 确认
```

删除所有 `LEGAL_ENTITY` 选项与裸 `<select>`；使用 `ElTree`、`ElTable`、`ElCheckbox`、`ElDialog`。

- [ ] **Step 5: 后端事务保存**

在 `RolePageService` 增加单一保存入口：

```php
public function saveAuthorization(string $roleCode, array $pages, array $capabilities, array $scopes, array $fields): array
{
    return DB::transaction(function () use ($roleCode, $pages, $capabilities, $scopes, $fields): array {
        $this->replacePages($roleCode, $pages);
        $this->replaceCapabilities($roleCode, $capabilities, $scopes, $fields);
        $this->snapshot->replayRole($roleCode, 'role.authorization.changed');
        return $this->show($roleCode);
    });
}
```

任何子步骤失败必须整事务回滚；不得先保存页面再异步补能力。

- [ ] **Step 6: 后端测试**

扩展 `RolePagesApiTest`：

```php
public function test_partial_capability_failure_rolls_back_page_grants(): void
{
    $role = $this->customRole();
    $before = DB::table('role_pages')->where('role_code', $role)->pluck('page_code')->all();

    $this->actingAs($this->admin())->putJson("/v1/roles/{$role}/authorization", [
        'pages' => ['employees'],
        'capabilities' => [['capability' => 'unknown.capability', 'anchor_type' => 'COMPANY', 'field_profile' => 'P0_PUBLIC']],
    ])->assertStatus(422);

    $this->assertSame($before, DB::table('role_pages')->where('role_code', $role)->pluck('page_code')->all());
}
```

- [ ] **Step 7: 运行测试**

Run:

```bash
cd frontend && pnpm exec vitest run tests/unit/RolePermissionMatrix.spec.ts
cd ../backend && ../.tools/php/php.exe artisan test --compact --filter='RolePagesApiTest|RoleAdminTest|PermissionPreviewApiTest'
```

Expected: PASS；失败时角色授权无部分写入。

- [ ] **Step 8: Commit**

```bash
git add frontend/src/modules/platform/pages/SystemRolesPage.vue frontend/src/modules/platform/components/RolePageTree.vue frontend/src/modules/platform/components/RoleActionMatrix.vue frontend/src/modules/platform/components/RoleScopeEditor.vue frontend/src/modules/platform/components/RoleFieldPolicyEditor.vue backend/app/Modules/IAM/Services/RolePageService.php backend/app/Modules/IAM/Http/Controllers/RolePageController.php backend/app/Modules/IAM/Http/Controllers/RoleAdminController.php frontend/tests/unit/RolePermissionMatrix.spec.ts backend/tests/Feature/IAM/RolePagesApiTest.php
git commit -m "feat(iam): rebuild role authorization workspace"
```

---

### Task 8: 菜单/页面、数据范围、字段权限工作台

**Files:**
- Modify: `frontend/src/modules/platform/pages/SystemMenusPage.vue`
- Modify: `frontend/src/modules/platform/pages/SystemPermissionsPage.vue`
- Modify: `frontend/src/modules/platform/pages/SystemDataScopesPage.vue`
- Modify: `frontend/src/modules/platform/pages/SystemFieldPoliciesPage.vue`
- Modify: `backend/app/Modules/IAM/Http/Controllers/MenuAdminController.php`
- Modify: `backend/app/Modules/IAM/Http/Controllers/DataScopeAdminController.php`
- Modify: `backend/app/Modules/IAM/Http/Controllers/FieldPolicyAdminController.php`
- Test: `backend/tests/Feature/IAM/MenuAdminTest.php`
- Test: `backend/tests/Feature/IAM/FieldPolicyApiTest.php`

**Interfaces:**
- Consumes: 页面资源目录、`CapabilityCatalog`、`OrgUnit` 树。
- Produces: 菜单只改展示属性；页面/按钮分配不越权；数据范围按组织树；字段策略服务端裁剪。

- [ ] **Step 1: 写菜单越权失败测试**

```php
public function test_menu_admin_cannot_invent_unknown_page_or_url(): void
{
    $this->actingAs($this->menuAdmin())
        ->postJson('/v1/system/menus', [
            'page_code' => 'fake-page',
            'path' => '/fake',
            'label' => '伪造页面',
        ])
        ->assertStatus(422);
}
```

- [ ] **Step 2: 实现菜单只改展示属性**

`MenuAdminController` 只允许更新：

```text
label, icon, sort_order, visible, parent_group
```

`page_code` 必须存在于 `PermissionResourceCatalog`；不允许创建新 URL、能力码或组件键。菜单隐藏不得改变页面授权。

- [ ] **Step 3: 数据范围工作台**

`SystemDataScopesPage.vue` 使用组织树选择 `ORG_UNIT`/`ORG_SUBTREE`；`COMPANY` 显示“本公司全部数据”；不出现租户/法人选择器。高敏感范围保存前弹出确认，写明影响人数与角色。

- [ ] **Step 4: 字段策略工作台**

`SystemFieldPoliciesPage.vue` 按业务对象分组展示字段，中文标签与 P0–P3 中文说明；保存前显示“可见/可编辑/可导出”差异。后端在读取、写入、导出三处统一调用字段策略。

- [ ] **Step 5: 字段越权测试**

```php
public function test_sensitive_field_is_redacted_even_when_client_requests_it(): void
{
    $response = $this->actingAs($this->hrWithoutP3())
        ->getJson('/v1/employees/'.$this->employee()->employment_id.'?include=bank_account');

    $response->assertOk();
    $this->assertArrayNotHasKey('bank_account', $response->json('data'));
}
```

- [ ] **Step 6: 运行测试**

Run:

```bash
cd backend
../.tools/php/php.exe artisan test --compact --filter='MenuAdminTest|DataScopeAdminTest|FieldPolicyApiTest'
```

Expected: PASS；伪造页面 422；敏感字段不出现在响应。

- [ ] **Step 7: Commit**

```bash
git add frontend/src/modules/platform/pages/SystemMenusPage.vue frontend/src/modules/platform/pages/SystemPermissionsPage.vue frontend/src/modules/platform/pages/SystemDataScopesPage.vue frontend/src/modules/platform/pages/SystemFieldPoliciesPage.vue backend/app/Modules/IAM/Http/Controllers/MenuAdminController.php backend/app/Modules/IAM/Http/Controllers/DataScopeAdminController.php backend/app/Modules/IAM/Http/Controllers/FieldPolicyAdminController.php backend/tests/Feature/IAM/MenuAdminTest.php backend/tests/Feature/IAM/FieldPolicyApiTest.php
git commit -m "feat(iam): add menu, scope and field policy workspaces"
```

---

### Task 9: 权限会话一致性与管理员保护

**Files:**
- Modify: `backend/app/Shared/Authorization/AuthorizationSnapshot.php`
- Modify: `backend/app/Modules/IAM/Services/SessionManager.php`
- Modify: `backend/app/Modules/IAM/Services/RoleAdminService.php`
- Modify: `backend/app/Modules/IAM/Services/LoginService.php`
- Create: `backend/tests/Feature/Authorization/AuthorizationConsistencyTest.php`
- Create: `backend/tests/Feature/Authorization/AdminProtectionTest.php`

**Interfaces:**
- Consumes: 角色/用户授权变更、`auth_version`、会话表。
- Produces: 撤权/停用/改密后下一次请求失效；并发保存不覆盖；至少一个管理员；普通管理员不能自授超管。

- [ ] **Step 1: 写撤权即时失效测试**

```php
public function test_revoked_role_loses_page_and_action_on_next_request(): void
{
    $user = $this->userWithRole('HR_ADMIN');
    $this->actingAs($user)->getJson('/v1/me/permissions')->assertOk();

    $this->admin()->deleteJson("/v1/users/{$user->id}/roles/HR_ADMIN", ['reason' => '合成测试撤权'])->assertOk();

    $this->actingAs($user)->getJson('/v1/me/permissions')->assertOk();
    $this->actingAs($user)->postJson('/v1/users', [])->assertStatus(403);
}
```

- [ ] **Step 2: 写管理员保护测试**

```php
public function test_admin_cannot_grant_super_admin_to_self(): void
{
    $admin = $this->userWithRole('SYSTEM_ADMIN');
    $this->actingAs($admin)
        ->postJson("/v1/users/{$admin->id}/roles", ['role_code' => 'SUPER_ADMIN', 'reason' => '合成测试自授'])
        ->assertStatus(403);
}

public function test_last_enabled_system_admin_cannot_be_disabled(): void
{
    $admin = $this->userWithRole('SYSTEM_ADMIN');
    $this->actingAs($admin)
        ->patchJson("/v1/users/{$admin->id}", ['status' => 'DISABLED', 'reason' => '合成测试停用'])
        ->assertStatus(409);
}
```

- [ ] **Step 3: 实现一致性规则**

- 角色/能力/范围/字段保存后调用 `snapshot->replayRole()` 与 `session->invalidateForRole()`。
- 用户停用、密码重置、角色撤销递增 `auth_version` 并撤销会话。
- 保存使用乐观锁版本号；版本冲突返回 409，不覆盖。
- 授予角色前校验操作者可授予范围；`SUPER_ADMIN` 只能由既有 `SUPER_ADMIN` 授予。
- 禁用最后一个启用管理员返回 409。

- [ ] **Step 4: 运行测试**

Run:

```bash
cd backend
../.tools/php/php.exe artisan test --compact --filter='AuthorizationConsistencyTest|AdminProtectionTest|SessionRevocationTest|ConcurrencyTest'
```

Expected: PASS；撤权后下一次请求 403/401；最后管理员不可禁用。

- [ ] **Step 5: Commit**

```bash
git add backend/app/Shared/Authorization/AuthorizationSnapshot.php backend/app/Modules/IAM/Services/SessionManager.php backend/app/Modules/IAM/Services/RoleAdminService.php backend/app/Modules/IAM/Services/LoginService.php backend/tests/Feature/Authorization/AuthorizationConsistencyTest.php backend/tests/Feature/Authorization/AdminProtectionTest.php
git commit -m "feat(iam): enforce authorization consistency and admin protection"
```

---

### Task 10: 密码登录与可选 MFA 验收

**Files:**
- Modify: `backend/config/iam.php`
- Modify: `backend/app/Modules/IAM/Console/Commands/InstallCommand.php`
- Modify: `frontend/src/views/LoginView.vue`
- Modify: `frontend/src/modules/platform/pages/AccountSecurityPage.vue`
- Modify: `backend/tests/Feature/Authorization/PasswordOnlyLoginTest.php`
- Create: `backend/tests/Feature/Authorization/MfaDisabledApiAccessTest.php`
- Test: `frontend/tests/unit/LoginView.spec.ts`

**Interfaces:**
- Consumes: `IAM_MFA_ENABLED=false`、`RequireMfaEnrollment`、`RequireMfaStepUp`。
- Produces: 单步登录；关闭 MFA 时合法 API 不因 MFA 拒绝；安全基线测试。

- [ ] **Step 1: 写 MFA 关闭 API 访问测试**

创建 `backend/tests/Feature/Authorization/MfaDisabledApiAccessTest.php`：

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MfaDisabledApiAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_hr_and_payroll_apis_pass_with_password_only(): void
    {
        config(['iam.mfa.enabled' => false]);
        foreach (['SYSTEM_ADMIN', 'HR_ADMIN', 'PAYROLL_SPECIALIST'] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->getJson('/v1/me')->assertOk();
        }

        $this->actingAs($this->userWithRole('SYSTEM_ADMIN'))
            ->getJson('/v1/users')
            ->assertOk();
    }
}
```

- [ ] **Step 2: 运行测试确认现状**

Run:

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=MfaDisabledApiAccessTest
```

Expected: 若中间件放行则 PASS；若返回 403/202 则记录为缺陷并修复 Task 10 第 3 步。

- [ ] **Step 3: 校正安装器与登录页**

- `InstallCommand` 在 `mfa.enabled=false` 时不生成 TOTP、不打印密钥、不要求绑定，记录 `administrator_mfa=SKIPPED`。
- `LoginView.vue` 只渲染账号、密码、登录按钮；不渲染验证码、MFA 提示或“必须设置 MFA”。
- `AccountSecurityPage.vue` 仅在 MFA 开关开启时显示启用入口；关闭时显示“当前部署未启用多因素认证”。

- [ ] **Step 4: 安全基线测试**

确认以下测试存在且通过：

```text
LoginSecurityTest
CsrfTest
SessionRevocationTest
PasswordOnlyLoginTest
MfaDisabledApiAccessTest
```

并补充断言：登录失败日志不含明文密码；停用账号拒绝登录；缺失 CSRF 的写请求被拒。

- [ ] **Step 5: 运行测试**

Run:

```bash
cd backend
../.tools/php/php.exe artisan test --compact --filter='PasswordOnlyLoginTest|MfaDisabledApiAccessTest|LoginSecurityTest|CsrfTest|SessionRevocationTest'
cd ../frontend && pnpm exec vitest run tests/unit/LoginView.spec.ts
```

Expected: PASS；登录页无 MFA/验证码元素；管理员与 HR API 仅凭密码可访问。

- [ ] **Step 6: Commit**

```bash
git add backend/config/iam.php backend/app/Modules/IAM/Console/Commands/InstallCommand.php frontend/src/views/LoginView.vue frontend/src/modules/platform/pages/AccountSecurityPage.vue backend/tests/Feature/Authorization/PasswordOnlyLoginTest.php backend/tests/Feature/Authorization/MfaDisabledApiAccessTest.php frontend/tests/unit/LoginView.spec.ts
git commit -m "feat(auth): enforce password-only login posture"
```

---

### Task 11: 五类角色权限正负矩阵

**Files:**
- Create: `backend/tests/Feature/Authorization/RoleMatrixTest.php`
- Create: `backend/tests/Support/AuthorizationMatrixScenario.php`（提供 `userWithRole()`、`employeeIn()`、`deptA()`/`deptB()`；不得在各测试里重复造 fixture）
- Create: `frontend/tests/e2e/real/role-matrix.spec.ts`
- Create: `docs/audit/2026-11-26-role-permission-matrix.md`
- Modify: `backend/database/seeders/DemoRoleSeeder.php`

**Interfaces:**
- Consumes: Task 6–10 的授权工作台、动作清单、登录基线。
- Produces: 系统管理员、人事管理员、部门经理、普通员工、工资专员五类账号的真实登录—菜单—页面—按钮—数据—字段—导出闭环证据。员工建档等第 3 册新增闭环在 Volume 3 Task 18 复验；本册只使用既有 API 与页面。

`AuthorizationMatrixScenario` 必须提供：

- `userWithRole(string $roleCode, string $anchorType = 'COMPANY', ?int $anchorId = null): User`
- `deptA(): int` / `deptB(): int` — 真实 `org_units.id`
- `employeeIn(int $orgUnitId): \App\Modules\People\Models\Employment`
- `hrWithoutP3(): User` — HR_ADMIN，但明确缺少 P3 字段/导出能力

- [ ] **Step 1: 写 API 矩阵测试**

创建 `RoleMatrixTest.php`：

```php
public function test_five_roles_have_expected_page_action_and_scope(): void
{
    $cases = [
        'SYSTEM_ADMIN' => ['/v1/users', 200, 'POST /v1/users', 201],
        'HR_ADMIN' => ['/v1/users', 403, 'POST /v1/users', 403],
        'DEPT_MANAGER' => ['/v1/employees', 200, 'POST /v1/users', 403],
        'EMPLOYEE' => ['/v1/employees', 403, 'POST /v1/users', 403],
        'PAYROLL_SPECIALIST' => ['/v1/employees', 403, 'GET /v1/payroll/runs', 200],
    ];

    foreach ($cases as $role => [$getPath, $getStatus, $write, $writeStatus]) {
        $user = $this->userWithRole($role);
        $this->actingAs($user)->getJson($getPath)->assertStatus($getStatus);
        [$method, $path] = explode(' ', $write, 2);
        $payload = $path === '/v1/users'
            ? ['username' => 'matrix.'.strtolower($role).'.'.Str::lower(Str::random(6)), 'password' => 'Synthetic-Pass-2026!']
            : [];
        $this->actingAs($user)->json($method, $path, $payload)->assertStatus($writeStatus);
    }
}
```

- [ ] **Step 2: 写数据范围负测**

```php
public function test_department_manager_cannot_read_other_department_employee(): void
{
    $manager = $this->userWithRole('DEPT_MANAGER', 'ORG_UNIT', $this->deptA());
    $other = $this->employeeIn($this->deptB());

    $this->actingAs($manager)->getJson("/v1/employees/{$other->employment_id}")->assertStatus(403);
}
```

- [ ] **Step 3: 写字段与导出负测**

```php
public function test_payroll_specialist_cannot_export_employee_bank_account_without_p3(): void
{
    // /v1/reporting/exports is POST-only; field whitelist violations are 403.
    $user = $this->userWithRole('PAYROLL_SPECIALIST');
    $response = $this->actingAs($user)->postJson('/v1/reporting/exports', [
        'definition_code' => 'EMPLOYEE_ROSTER',
        'purpose' => '合成权限矩阵负测',
        'fields' => ['bank_account'],
    ]);
    $response->assertStatus(403);
}
```

- [ ] **Step 4: 写 E2E 五角色闭环**

`role-matrix.spec.ts` 至少断言：

```ts
test('HR admin can open the employee workspace and cannot manage users', async ({ page }) => {
  await login(page, 'demo.hr')
  await page.goto('/hr/employees')
  await expect(page.getByRole('heading', { name: '员工列表' })).toBeVisible()
  await page.goto('/system/users')
  await expect(page.getByText('无权访问')).toBeVisible()
})
```

其余四个角色按同一结构覆盖：能进/不能进、能点/不能点、能读/不能读、能导出/不能导出。

- [ ] **Step 5: 生成矩阵文档**

`docs/audit/2026-11-26-role-permission-matrix.md` 必须包含：

```markdown
| 角色 | 登录 | 主菜单 | 页面 | 动作 | 数据范围 | 字段 | 导出 | 证据 |
|---|---|---|---|---|---|---|---|---|
| 系统管理员 | 通过/失败 | ... | ... | ... | COMPANY | P0-P3 | 允许 | test id |
```

不得只写“支持”，必须引用测试名与命令输出。

- [ ] **Step 6: 运行测试**

Run:

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter='RoleMatrixTest|AnchorScopeTest|FieldDenialHttpTest|DenyByDefaultTest'
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/role-matrix.spec.ts
```

Expected: API 与 E2E 均通过；若真实栈不可用，E2E 标 `NOT RUN` 并阻断第 2 册验收。

- [ ] **Step 7: Commit**

```bash
git add backend/tests/Feature/Authorization/RoleMatrixTest.php frontend/tests/e2e/real/role-matrix.spec.ts docs/audit/2026-11-26-role-permission-matrix.md backend/database/seeders/DemoRoleSeeder.php
git commit -m "test(iam): prove five-role permission matrix"
```

---

### Task 12: 单公司迁移演练、恢复与第 2 册验收

**Files:**
- Create: `deploy/scripts/single_company_rehearsal.sh`
- Create: `docs/audit/2026-11-26-v2-volume-2-acceptance.md`
- Modify: `docs/superpowers/plans/2026-11-26-v2-1-remediation-master-plan.md`

**Interfaces:**
- Consumes: Task 1–11 的迁移、代码、测试与角色矩阵。
- Produces: 可重复的迁移演练脚本；备份恢复证据；第 2 册退出结论。

- [ ] **Step 1: 写演练脚本**

演练必须在 **MySQL 8.4 测试库**执行，不能只用 SQLite。复用现有 Compose 备份工具，不假设宿主机已安装 `mysqldump`/`mysql`。创建 `deploy/scripts/single_company_rehearsal.sh`：

```bash
#!/usr/bin/env bash
# Single-company cutover rehearsal on the MySQL 8.4 Compose service.
set -euo pipefail

: "${COMPOSE_PROJECT_NAME:?set COMPOSE_PROJECT_NAME}"
: "${MYSQL_DATABASE:?set MYSQL_DATABASE}"
: "${MYSQL_ROOT_PASSWORD:?set MYSQL_ROOT_PASSWORD}"
: "${BACKUP_DIR:?set BACKUP_DIR}"

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
PHP_BIN="${PHP_BINARY:-$REPO_ROOT/.tools/php/php.exe}"
IMAGE_PHP="/var/www/html/backend/artisan"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup="$BACKUP_DIR/pre-single-company-$timestamp.sql"
mkdir -p "$BACKUP_DIR"

docker exec -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" "$(docker compose -p "$COMPOSE_PROJECT_NAME" ps -q mysql)" \
  sh -c "mysqldump -uroot --single-transaction --routines --triggers --set-gtid-purged=OFF $MYSQL_DATABASE" > "$backup"
[ -s "$backup" ] || { echo "empty backup" >&2; exit 1; }

"$PHP_BIN" "$REPO_ROOT/backend/artisan" company:preflight --json > "$BACKUP_DIR/preflight-$timestamp.json"
"$PHP_BIN" "$REPO_ROOT/backend/artisan" migrate --force
"$PHP_BIN" "$REPO_ROOT/backend/artisan" test --compact --filter=SingleCompanyMigrationTest
docker exec -i -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" "$(docker compose -p "$COMPOSE_PROJECT_NAME" ps -q mysql)" \
  mysql -uroot "$MYSQL_DATABASE" < "$backup"
"$PHP_BIN" "$REPO_ROOT/backend/artisan" company:preflight --json > "$BACKUP_DIR/restored-$timestamp.json"
diff -u "$BACKUP_DIR/preflight-$timestamp.json" "$BACKUP_DIR/restored-$timestamp.json"
echo "single-company rehearsal OK: $timestamp"
```

脚本必须从仓库根解析路径；所有数据库命令都在 Compose 的 MySQL 容器内执行，宿主机不需要 `mysql`/`mysqldump`；密码通过 `MYSQL_PWD` 传入，不进入进程参数或日志。

- [ ] **Step 2: 在测试库执行演练**

Run:

```bash
COMPOSE_PROJECT_NAME=factoryhr \
MYSQL_DATABASE=factoryhr_rehearsal \
MYSQL_ROOT_PASSWORD='<测试库密码>' \
BACKUP_DIR=evidence/single-company \
bash deploy/scripts/single_company_rehearsal.sh
```

Expected: 备份生成；迁移成功；合同测试通过；恢复后预检 JSON 与迁移前一致；脚本输出 `single-company rehearsal OK`。

- [ ] **Step 3: 运行第 2 册门禁**

```bash
cd backend
../.tools/php/php.exe artisan test --compact
../.tools/php/php.exe artisan capability:audit
../.tools/php/php.exe artisan arch:test
cd ..
node scripts/audit_action_manifest.mjs
node scripts/audit_single_company.mjs
node scripts/audit_page_model.mjs
node scripts/audit_ui_primitives.mjs
node scripts/audit_user_copy.mjs
cd frontend && pnpm typecheck && pnpm lint && pnpm test:unit
```

Expected: 后端全量测试全绿；`audit_single_company` 为 0 命中；三个页面门禁对第 2 册范围（平台页）通过；前端全绿。业务页存量违规必须归入第 3 册并阻断发布，不得写成通过。

- [ ] **Step 4: 写验收记录**

`docs/audit/2026-11-26-v2-volume-2-acceptance.md` 必须记录：

- 预检报告与冲突数量。
- 迁移前后关键表行数与校验和。
- 备份恢复演练输出。
- 权限矩阵测试结果与五角色证据。
- MFA 关闭时管理员/HR/工资 API 的测试结果。
- 遗留失败与归属册。

- [ ] **Step 5: 更新总控并提交**

```bash
git add deploy/scripts/single_company_rehearsal.sh docs/audit/2026-11-26-v2-volume-2-acceptance.md docs/superpowers/plans/2026-11-26-v2-1-remediation-master-plan.md
git commit -m "docs(plan): accept V2.1 volume 2 permissions and cutover"
```

---

## 第 2 册退出条件

- 单公司 schema 完成：旧列、旧表、旧上下文中间件、旧请求头、旧前端字段零运行引用。
- `LEGAL_ENTITY` 授权绑定全部迁移为 `COMPANY`；预检冲突均有裁定。
- 迁移在测试库演练成功，备份可恢复，关键表行数与校验和一致。
- 所有可写页面有真实动作；动作—能力—API 双向门禁通过。
- 用户、角色、菜单、数据范围、字段权限五个工作台可操作，保存事务与审计完整。
- 密码登录主流程无 MFA/验证码前置；关闭 MFA 时管理员、HR、工资专员合法 API 均通过。
- 五类角色 API 与 E2E 正负矩阵通过；撤权、停用、最后管理员保护有证据。
- 第 3 册员工与业务域所需契约、测试命令、真实账号已冻结。
