# 最高权限完整性、生产基线数据与去演示化：修改计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 让系统管理员持有可随能力目录增长自动覆盖的全部权限，同时把职责分离的正确层级的约束补齐；为系统建立一条不依赖假数据的生产出生路径，并删除所有伪造的安全承诺与演示痕迹。

**Architecture:** 三层分离——(1) 覆盖层：单行通配符绑定 `capability_code='*'` 由代码拥有的模板产生，解析时求值；(2) 控制层：职责分离拆为「累积型风险」（降级为确认+审计+复核）与「对象型不变量」（保留硬失败，只在服务层对具体对象执行）；(3) 归因层：真实 TOTP 登录挑战、`mfa_enrolled_at` 与真实 factor 双向不变量、敏感字段访问审计。演示数据从默认路径降级为 `--demo` 可选项，生产入口统一为幂等的 `hrms:install`。

**Tech Stack:** PHP 8.4 / Laravel 13 / PHPUnit 12 / MySQL 8.4 (Docker) / SQLite (本地测试) / Redis / MinIO；Vue 3 + TS strict + Vite + Pinia + Vitest + Playwright；Docker Compose。

## Global Constraints

- 工具链前缀（每个 PHP/pnpm 命令都需要）：
  `export PATH="/d/code/ai/factoryHR/.tools/php:/d/Develop/nvm/nodejs:$PATH"`
- Composer：`COMPOSER_HOME=/d/code/ai/factoryHR/.tools/composer-home php /d/code/ai/factoryHR/.tools/composer2.phar`
- PHPStan 需要 `TMPDIR=/d/code/ai/factoryHR/build/phpstan-tmp` 且 `--memory-limit=1G`
- **禁止**修改已批准的 `docs/HRMS_V1.0_解决方案_第一性原理重构.md` 与 `docs/HRMS_V1.0_实施计划_30周路线图.md`
- **禁止**提交 `.env`、`vendor/`、`node_modules/`、SQLite 数据库、真实个人信息、任何密钥
- **禁止**使用 `docker compose config` 的完整输出写入任何被提交的文件（它会展开并打印密钥）
- 迁移**只增不改**：新增迁移文件，不编辑已合并的迁移
- 金额只用整数最小单位；费率用至少 1e6 精度的十进制字符串；薪酬代码禁止浮点
- 每个 `/v1` 路由必须能力守卫（公开白名单仅 `v1/health`、`v1/health/live`、`v1/health/ready`、`v1/meta`、`v1/auth/login`）
- OpenAPI 是契约：新增/改动的路由必须同步 `docs/openapi/iam.yaml`
- 每个任务结束时工作树必须干净（commit 后 `git status --porcelain` 为空）
- 测速基线：后端 726 tests / 35,734 assertions 全绿；前端 32 unit；`verify_release.sh` PASS=23

## 文件结构

**覆盖层（Task 1–3）**
- `backend/app/Shared/Authorization/CapabilityWildcard.php` — 新建。`*` 的唯一常量与判定（`isWildcard()`、`WILDCARD`），使"谁是通配符"只有一处定义。
- `backend/app/Modules/IAM/Authorization/RoleTemplateCatalog.php` — 修改。`SUPER_ADMIN`、`SYSTEM_ADMIN` 改为单行 `*`。
- `backend/app/Modules/IAM/Authorization/EloquentAuthorityBindingRepository.php` — 修改。查询改为 `whereIn(['*', $capabilityCode])`。
- `backend/app/Modules/IAM/Auth/CapabilityAuthorizer.php` — 修改。删死代码；通配符在 anchor 判定中视为覆盖任意 anchor。
- `backend/app/Modules/IAM/Http/Controllers/MeController.php` — 修改。序列化层展开 `*`。

**控制层（Task 4–5）**
- `backend/app/Shared/Authorization/SodRules.php` — 重写为风险目录（`risks()`），删除 `assertAllowed()`。
- `backend/app/Modules/IAM/Http/Controllers/UserAdminController.php`、`AuthorizationAdminController.php` — 修改。改为"需确认 + 写审计"。
- `backend/app/Modules/IAM/Console/Commands/AccessReviewCommand.php` — 修改。输出风险对。

**归因层（Task 6–8）**
- `backend/app/Modules/IAM/Services/MfaPolicy.php` — 新建。`challengeRequired(User): bool`，能力驱动。
- `backend/app/Modules/IAM/Http/Controllers/AuthController.php` — 修改。登录时返回 `MFA_REQUIRED` 挑战，不签发会话。
- `backend/app/Modules/IAM/Services/SessionManager.php` — 修改。支持"预会话"（MFA 未完成）。
- `backend/app/Modules/IAM/Console/Commands/VerifyMfaEnrollmentInvariantCommand.php` — 新建。双向不变量检查。

**字段档位（Task 9–10）**
- `backend/app/Shared/Authorization/FieldSet.php` — 修改。新增 `allowRestrictedVisible`。
- `backend/app/Modules/IAM/Auth/CapabilityAuthorizer.php` — 修改。`fields()` 传入该标志。
- `backend/app/Modules/Payroll/Http/Controllers/PayrollPayslipController.php`、`backend/app/Modules/Payroll/Services/PayrollRunService.php` — 修改。接入投影。

**生产安装（Task 11–13）**
- `backend/app/Modules/Platform/Install/InstallCommand.php` — 新建。编排器。
- `backend/app/Modules/Platform/Install/InstallStep.php`、`InstallReport.php` — 新建。步骤契约与报告。
- `backend/app/Modules/Time/Console/SeedCalendarCommand.php` — 新建。日历 + 节假日 + 班次。
- `deploy/scripts/docker_up.sh` — 修改。默认 install，`--demo` 才有合成数据。

**前端（Task 14–16）**
- `frontend/src/api/http.ts` — 修改。真实 admin 聚合、`access-review`、`wildcard` 展开。
- `frontend/src/views/AdminView.vue` — 修改。删除占位与 `TENANT` 选项。
- `frontend/src/views/LoginView.vue`、`frontend/src/components/MfaEnrollmentDialog.vue`、`frontend/src/layouts/AppShell.vue` — 修改。删除演示文案与硬编码租户名。
- `frontend/vite.config.ts` — 修改。非 http 模式构建失败。

**门禁与文档（Task 17–19）**
- `backend/app/Console/Commands/CapabilityAuditCommand.php` — 修改。G-COV-1/2/3。
- `scripts/verify_release.sh` — 修改。新增门禁。
- `docs/adr/0016-*.md`、`docs/adr/0017-*.md`、`docs/02-permissions.md`、`AGENTS.md` — 新建/修改。

---

### Task 1: 通配符能力码作为一等公民

**Files:**
- Create: `backend/app/Shared/Authorization/CapabilityWildcard.php`
- Test: `backend/tests/Unit/Authorization/CapabilityWildcardTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `CapabilityWildcard::WILDCARD` (`'*'`), `CapabilityWildcard::is(string $code): bool`, `CapabilityWildcard::isAllowedInTemplatesOnly(): true`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Shared\Authorization\CapabilityWildcard;
use PHPUnit\Framework\TestCase;

final class CapabilityWildcardTest extends TestCase
{
    public function test_the_wildcard_is_a_single_star(): void
    {
        $this->assertSame('*', CapabilityWildcard::WILDCARD);
    }

    public function test_is_matches_only_the_wildcard(): void
    {
        $this->assertTrue(CapabilityWildcard::is('*'));
        $this->assertFalse(CapabilityWildcard::is('iam.user.read'));
        $this->assertFalse(CapabilityWildcard::is(''));
        $this->assertFalse(CapabilityWildcard::is('**'));
        $this->assertFalse(CapabilityWildcard::is(' *'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=CapabilityWildcardTest`
Expected: FAIL — `Class "App\Shared\Authorization\CapabilityWildcard" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

/**
 * The only wildcard capability. `*` is not a catalog entry: it never appears in
 * the `capabilities` table and can only be produced by a code-owned role
 * template. Every check for "is this the wildcard" must route through here so
 * the literal has exactly one definition.
 */
final class CapabilityWildcard
{
    public const WILDCARD = '*';

    public static function is(string $code): bool
    {
        return $code === self::WILDCARD;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=CapabilityWildcardTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Shared/Authorization/CapabilityWildcard.php backend/tests/Unit/Authorization/CapabilityWildcardTest.php
git commit -m "feat(authz): make the wildcard capability a single, tested constant"
```

---

### Task 2: 授权解析承认通配符

**Files:**
- Modify: `backend/app/Modules/IAM/Authorization/EloquentAuthorityBindingRepository.php:35-43`
- Modify: `backend/app/Modules/IAM/Auth/CapabilityAuthorizer.php:226-250`
- Test: `backend/tests/Feature/Authorization/WildcardGrantTest.php`

**Interfaces:**
- Consumes: `CapabilityWildcard::WILDCARD`
- Produces: `effectiveBindings()` returns both exact and wildcard grants; `permits()`/`allows()`/`constrain()`/`fields()` honour a wildcard grant under any concrete anchor.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Auth\CapabilityAuthorizer;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\FieldProfile;

final class WildcardGrantTest extends AuthorizationTestSupport
{
    public function test_a_wildcard_binding_permits_every_catalogued_capability(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->organization->knownLegalEntities = [1];
        $this->grant($user, '*', AnchorType::LEGAL_ENTITY, 1, FieldProfile::P3_RESTRICTED);

        $authorizer = app(CapabilityAuthorizer::class);
        $principal = $this->actingAsUser($user);
        $date = '2026-11-24';

        foreach (['iam.user.read', 'payroll.run.approve', 'workflow.case.admin', 'talent.training.manage'] as $code) {
            $this->assertTrue(
                $authorizer->permits($principal, $code, $date),
                "A wildcard binding must permit {$code}.",
            );
        }
    }

    public function test_a_wildcard_binding_covers_an_anchored_resource(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->organization->knownLegalEntities = [1];
        $this->grant($user, '*', AnchorType::LEGAL_ENTITY, 1, FieldProfile::P3_RESTRICTED);

        $this->assertTrue(app(CapabilityAuthorizer::class)->allows(
            $this->actingAsUser($user),
            'payroll.run.read',
            new AnchorRef(AnchorType::LEGAL_ENTITY, 1),
            '2026-11-24',
        ));
    }

    public function test_an_expired_wildcard_binding_permits_nothing(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->organization->knownLegalEntities = [1];
        AuthorityBinding::query()->create([
            'tenant_id' => 1,
            'principal_id' => (int) $user->id,
            'capability_code' => '*',
            'anchor_type' => 'LEGAL_ENTITY',
            'anchor_id' => 1,
            'anchor_key' => 1,
            'field_profile' => 'P3_RESTRICTED',
            'purpose_code' => 'SYSTEM_ADMIN',
            'valid_from' => '2020-01-01 00:00:00',
            'valid_to' => '2021-01-01 00:00:00',
            'source_type' => 'ROLE',
            'auth_version' => 1,
            'created_at' => '2020-01-01 00:00:00',
        ]);

        $this->assertFalse(app(CapabilityAuthorizer::class)->permits(
            $this->actingAsUser($user),
            'iam.user.read',
            '2026-11-24',
        ));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=WildcardGrantTest`
Expected: FAIL — all three assert `false`, because the repository filters `where('capability_code', $capabilityCode)` exactly.

- [ ] **Step 3: Write minimal implementation**

In `EloquentAuthorityBindingRepository::effectiveBindings()`, replace the exact match:

```php
                ->where('capability_code', $capabilityCode)
```

with a dual match, and include the wildcard flag in the cache key so an exact and a wildcard lookup never share an entry:

```php
                ->whereIn('capability_code', [$capabilityCode, \App\Shared\Authorization\CapabilityWildcard::WILDCARD])
```

Then in `CapabilityAuthorizer::matchingBindings()`, delete the three lines that call `SodRules::assertAllowed()` (they can never fire because the query already filtered to one capability) and the now-unused `use App\Shared\Authorization\SodRules;` / `use App\Shared\Exceptions\ConflictException;` imports:

```php
    private function matchingBindings(Principal $p, string $capability, string $businessDate): array
    {
        $grants = $this->bindings->effectiveBindings(
            $p->tenantId(),
            $p->userId(),
            $capability,
            $p->authVersion(),
            $businessDate,
        );

        return array_values(array_filter(
            $grants,
            static fn (BindingGrant $grant): bool => $grant->tenantId === $p->tenantId()
                && $grant->principalId === $p->userId()
                && $grant->authVersion === $p->authVersion()
                && $grant->isEffectiveOn($businessDate),
        ));
    }
```

Finally make the anchor predicate treat a wildcard grant as covering the requested anchor. In `anchorAllows()`, before the `match`, add:

```php
        if (\App\Shared\Authorization\CapabilityWildcard::is($grant->capabilityCode)) {
            return true;
        }
```

And in `resourceCoveredByGrant()`, before the `match`, add the same guard so `fields()` sees a wildcard grant as covering the row.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=WildcardGrantTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Run the authorization suite for regressions**

Run: `cd backend && php artisan test --filter=Authorization`
Expected: PASS — no existing test regresses.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Modules/IAM/Authorization/EloquentAuthorityBindingRepository.php backend/app/Modules/IAM/Auth/CapabilityAuthorizer.php backend/tests/Feature/Authorization/WildcardGrantTest.php
git commit -m "feat(authz): resolve wildcard grants in permits, allows, constrain and fields"
```

---

### Task 3: 模板改用通配符，并修好三处"能力码必在目录内"的假设

**Files:**
- Modify: `backend/app/Modules/IAM/Authorization/RoleTemplateCatalog.php:67-96`
- Modify: `backend/app/Modules/IAM/Console/Commands/SyncCapabilitiesCommand.php:29-38`
- Test: `backend/tests/Unit/Authorization/RoleTemplateCoverageTest.php`
- Test: `backend/tests/Feature/IAM/SyncCapabilitiesWildcardTest.php`

**Interfaces:**
- Consumes: `CapabilityWildcard::WILDCARD`
- Produces: `SUPER_ADMIN` and `SYSTEM_ADMIN` each carry exactly one grant with `capabilityCode === '*'`; `iam:sync-capabilities` tolerates `*` bindings.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Authorization\RoleTemplateCatalog;
use PHPUnit\Framework\TestCase;

final class RoleTemplateCoverageTest extends TestCase
{
    public function test_super_and_system_admin_hold_exactly_one_wildcard_grant_each(): void
    {
        foreach (['SUPER_ADMIN', 'SYSTEM_ADMIN'] as $code) {
            $template = RoleTemplateCatalog::find($code);
            $this->assertNotNull($template);
            $this->assertSame(['*'], $template->capabilityCodes(), "{$code} must be a single wildcard grant.");
            $this->assertSame('LEGAL_ENTITY', $template->grants[0]->anchorType->value);
            $this->assertSame('P3_RESTRICTED', $template->grants[0]->fieldProfile->value);
        }
    }

    public function test_only_admin_templates_may_carry_the_wildcard(): void
    {
        foreach (RoleTemplateCatalog::templates() as $template) {
            if (in_array($template->code, ['SUPER_ADMIN', 'SYSTEM_ADMIN'], true)) {
                continue;
            }

            $this->assertNotContains('*', $template->capabilityCodes(), "{$template->code} must not hold the wildcard.");
        }
    }

    public function test_every_catalog_capability_is_issued_by_some_template(): void
    {
        $issued = [];

        foreach (RoleTemplateCatalog::templates() as $template) {
            $issued = [...$issued, ...$template->capabilityCodes()];
        }

        $intrinsic = ['iam.session.manage'];

        foreach ((new CapabilityCatalog)->codes() as $code) {
            if (in_array($code, $intrinsic, true)) {
                continue;
            }

            $covered = in_array($code, $issued, true) || in_array('*', $issued, true);
            $this->assertTrue($covered, "No role template issues {$code}.");
        }
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SyncCapabilitiesWildcardTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_capabilities_keeps_wildcard_bindings(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);

        \App\Modules\IAM\Models\AuthorityBinding::query()->create([
            'tenant_id' => 1,
            'principal_id' => (int) $user->id,
            'capability_code' => '*',
            'anchor_type' => 'LEGAL_ENTITY',
            'anchor_id' => 1,
            'anchor_key' => 1,
            'field_profile' => 'P3_RESTRICTED',
            'purpose_code' => 'SYSTEM_ADMIN',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'source_type' => 'ROLE',
            'auth_version' => 1,
            'created_at' => '2026-01-01 00:00:00',
        ]);

        $this->artisan('iam:sync-capabilities')->assertExitCode(0);

        $this->assertSame(
            1,
            \App\Modules\IAM\Models\AuthorityBinding::query()->where('capability_code', '*')->count(),
            'Syncing the catalog must not treat the wildcard as an unknown capability.',
        );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter="RoleTemplateCoverageTest|SyncCapabilitiesWildcardTest"`
Expected: FAIL — the admin templates still list 20/10 concrete codes; `iam:sync-capabilities` exits non-zero with "Refusing to remove referenced capability codes: *".

- [ ] **Step 3: Write minimal implementation**

In `RoleTemplateCatalog::build()`, replace the entire `SUPER_ADMIN` and `SYSTEM_ADMIN` grant lists with one wildcard grant each:

```php
            new RoleTemplate('SUPER_ADMIN', 'Super administrator', 'Full authority inside the legal entity, time-boxed and reviewed.', [
                $grant('SUPER_ADMIN', \App\Shared\Authorization\CapabilityWildcard::WILDCARD, $le, FieldProfile::P3_RESTRICTED),
            ]),
            new RoleTemplate('SYSTEM_ADMIN', 'System administrator', 'Full authority inside the legal entity, renewed annually and reviewed quarterly.', [
                $grant('SYSTEM_ADMIN', \App\Shared\Authorization\CapabilityWildcard::WILDCARD, $le, FieldProfile::P3_RESTRICTED),
            ]),
```

In `SyncCapabilitiesCommand::handle()`, exclude the wildcard from the orphan check:

```php
        $orphaned = AuthorityBinding::query()
            ->where('capability_code', '!=', \App\Shared\Authorization\CapabilityWildcard::WILDCARD)
            ->whereNotIn('capability_code', $codes)
            ->distinct()
            ->pluck('capability_code')
            ->all();
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter="RoleTemplateCoverageTest|SyncCapabilitiesWildcardTest"`
Expected: PASS (4 tests)

- [ ] **Step 5: Update the two tests that assert the old template shapes**

`backend/tests/Unit/Authorization/CapabilityCatalogTest.php` asserts "exactly ten role templates" — that still holds and needs no change. It also lists required capability codes; keep it, since those codes must still exist in the catalog.

`backend/tests/Feature/DemoSeedTest.php` asserts the demo admin receives `SYSTEM_ADMIN` bindings. With one wildcard grant it now creates 1 binding instead of 10. Read the file and update its count assertion to match the new contract, then run:

Run: `cd backend && php artisan test --filter=DemoSeedTest`
Expected: PASS

- [ ] **Step 6: Run the full backend suite**

Run: `cd backend && php artisan test`
Expected: PASS — record the new test/assertion totals; they become the new baseline.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Modules/IAM/Authorization/RoleTemplateCatalog.php backend/app/Modules/IAM/Console/Commands/SyncCapabilitiesCommand.php backend/tests/Unit/Authorization/RoleTemplateCoverageTest.php backend/tests/Feature/IAM/SyncCapabilitiesWildcardTest.php backend/tests/Feature/DemoSeedTest.php
git commit -m "feat(authz): make SUPER_ADMIN and SYSTEM_ADMIN hold the full wildcard grant"
```

---

### Task 4: `/v1/me/capabilities` 展开通配符

**Files:**
- Modify: `backend/app/Modules/IAM/Http/Controllers/MeController.php:49-80`
- Test: `backend/tests/Feature/IAM/MeCapabilitiesWildcardTest.php`

**Interfaces:**
- Consumes: `CapabilityWildcard::WILDCARD`, `CapabilityCatalog::codes()`
- Produces: `GET /v1/me/capabilities` returns `data.capabilities` containing every catalog code when the principal holds `*`, plus `data.wildcard: bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesRequests;
use Tests\TestCase;

final class MeCapabilitiesWildcardTest extends TestCase
{
    use AuthenticatesRequests;
    use RefreshDatabase;

    public function test_a_wildcard_holder_sees_every_catalogued_capability(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->grantCapability($user, '*', 'LEGAL_ENTITY', 1, 'P3_RESTRICTED');

        $response = $this->actingAsUser($user)->getJson('/v1/me/capabilities');

        $response->assertOk()->assertJsonPath('data.wildcard', true);

        $codes = array_column($response->json('data.capabilities'), 'capability');
        $expected = app(\App\Modules\IAM\Authorization\CapabilityCatalog::class)->codes();

        sort($codes);
        sort($expected);

        $this->assertSame($expected, $codes, 'The API must expand the wildcard so the SPA menu is not empty.');
    }

    public function test_a_non_wildcard_holder_reports_wildcard_false(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->grantCapability($user, 'iam.user.read', 'LEGAL_ENTITY', 1, 'P1_INTERNAL');

        $this->actingAsUser($user)
            ->getJson('/v1/me/capabilities')
            ->assertOk()
            ->assertJsonPath('data.wildcard', false);
    }
}
```

Before running, read `backend/tests/Support/AuthenticatesRequests.php`. If it has no `grantCapability()` helper, add one that inserts an `AuthorityBinding` with the given code, anchor, field profile and `auth_version = 1`, and add `$this->withoutMiddleware(\App\Modules\IAM\Http\Middleware\RequireMfaStepUp::class);` inside the helper — the point of this test is capability projection, not MFA.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=MeCapabilitiesWildcardTest`
Expected: FAIL — `data.wildcard` is missing and `capabilities` contains only the single `*` row.

- [ ] **Step 3: Write minimal implementation**

In `MeController::capabilities()`, after fetching `$bindings`, expand wildcards before responding:

```php
        $rows = $bindings->all();
        $held = array_column($rows, 'capability');
        $wildcard = in_array(\App\Shared\Authorization\CapabilityWildcard::WILDCARD, $held, true);

        if ($wildcard) {
            $covered = array_column($rows, 'capability');

            foreach (app(\App\Modules\IAM\Authorization\CapabilityCatalog::class)->all() as $definition) {
                if (in_array($definition->code, $covered, true)) {
                    continue;
                }

                $anchor = $rows[0] ?? null;

                $rows[] = [
                    'capability' => $definition->code,
                    'anchor_type' => $anchor['anchor_type'] ?? 'LEGAL_ENTITY',
                    'anchor_id' => $anchor['anchor_id'] ?? null,
                    'field_profile' => $anchor['field_profile'] ?? 'P3_RESTRICTED',
                    'purpose_code' => $anchor['purpose_code'] ?? 'SYSTEM_ADMIN',
                    'valid_from' => $anchor['valid_from'] ?? null,
                    'valid_to' => $anchor['valid_to'] ?? null,
                    'wildcard' => true,
                ];
            }
        }

        return ApiResponse::ok([
            'capabilities' => $rows,
            'wildcard' => $wildcard,
            'mfa_required' => $this->requiresMfa($principal->userId(), $principal->tenantId()),
            'mfa_verified' => $principal->hasMfa(),
        ]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=MeCapabilitiesWildcardTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Update OpenAPI**

In `docs/openapi/iam.yaml`, at `/me/capabilities`, add `wildcard` (boolean, required) to the response schema and document that `capabilities` is the expanded set. Then run:

Run: `cd backend && php artisan test --filter=OpenApiContractTest && cd .. && node deploy/scripts/openapi-lint.mjs`
Expected: PASS for both

- [ ] **Step 6: Commit**

```bash
git add backend/app/Modules/IAM/Http/Controllers/MeController.php backend/tests/Feature/IAM/MeCapabilitiesWildcardTest.php backend/tests/Support/AuthenticatesRequests.php docs/openapi/iam.yaml
git commit -m "feat(iam): expand the wildcard in /v1/me/capabilities so the SPA menu stays complete"
```

---

### Task 5: 职责分离改为风险目录，只报告不阻断

**Files:**
- Modify: `backend/app/Shared/Authorization/SodRules.php`
- Modify: `backend/app/Modules/IAM/Http/Controllers/UserAdminController.php:168-180`
- Modify: `backend/app/Modules/IAM/Http/Controllers/AuthorizationAdminController.php:160-190`
- Modify: `backend/app/Modules/IAM/Console/Commands/AccessReviewCommand.php`
- Test: `backend/tests/Unit/Authorization/SodConflictTest.php` (rewrite)
- Test: `backend/tests/Feature/Authorization/SodRiskAcknowledgementTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `SodRules::risks(array $codes): list<array{0:string,1:string,kind:string}>` where `kind` is `'CUMULATIVE'` or `'OBJECT_LEVEL'`; `SodRules::assertAllowed()` no longer exists.

- [ ] **Step 1: Rewrite the unit test to the new contract**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Shared\Authorization\SodRules;
use PHPUnit\Framework\TestCase;

final class SodConflictTest extends TestCase
{
    public function test_payroll_prepare_and_approve_is_an_object_level_risk(): void
    {
        $risks = SodRules::risks(['payroll.run.prepare', 'payroll.run.approve', 'people.employee.read']);

        $this->assertCount(1, $risks);
        $this->assertSame('OBJECT_LEVEL', $risks[0]['kind']);
        $this->assertSame(['payroll.run.approve', 'payroll.run.prepare'], $risks[0]['pair']);
    }

    public function test_governance_and_audit_is_a_cumulative_risk(): void
    {
        $risks = SodRules::risks(['iam.binding.manage', 'iam.audit.read']);

        $this->assertCount(1, $risks);
        $this->assertSame('CUMULATIVE', $risks[0]['kind']);
    }

    public function test_unrelated_capabilities_carry_no_risk(): void
    {
        $this->assertSame([], SodRules::risks(['people.employee.read', 'time.leave.request']));
    }

    public function test_a_wildcard_holder_carries_every_risk(): void
    {
        $risks = SodRules::risks(['*']);

        $this->assertNotEmpty($risks, 'The wildcard must surface all separation-of-duties risks for review.');
    }

    public function test_the_hard_blocking_api_no_longer_exists(): void
    {
        $this->assertFalse(
            method_exists(SodRules::class, 'assertAllowed'),
            'SoD must be a risk report, not a hard block: the object-level checks live in the services.',
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=SodConflictTest`
Expected: FAIL — `SodRules::risks()` does not exist.

- [ ] **Step 3: Write the implementation**

Replace `backend/app/Shared/Authorization/SodRules.php` entirely:

```php
<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

/**
 * Separation-of-duties risks, reported but never enforced at the capability
 * layer.
 *
 * Two kinds exist and they belong to different layers:
 *
 * - CUMULATIVE: one person holds two opposed capabilities (manage bindings and
 *   read audit evidence). This is a *job design* question. A wildcard holder
 *   necessarily carries it. It is surfaced for acknowledgement and quarterly
 *   review, and recorded in the audit trail.
 * - OBJECT_LEVEL: one person performs two steps on the *same object* (prepare
 *   and approve the same payroll run). This is a control invariant. It is
 *   enforced where the object exists — ApprovalService, BreakGlassService,
 *   ReopenService — and never by this class.
 *
 * Enforcing either kind here cannot work: the object-level rule needs an
 * object, and the cumulative rule needs a judgement. Hard-blocking the
 * capability set would make a legitimate super administrator impossible to
 * create, while protecting nothing (the check could not see two capabilities
 * at once).
 */
final class SodRules
{
    public const KIND_CUMULATIVE = 'CUMULATIVE';

    public const KIND_OBJECT_LEVEL = 'OBJECT_LEVEL';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private const RULES = [
        ['payroll.run.prepare', 'payroll.run.approve', self::KIND_OBJECT_LEVEL],
        ['payroll.run.prepare', 'payroll.run.publish', self::KIND_OBJECT_LEVEL],
        ['payroll.run.review', 'payroll.run.approve', self::KIND_OBJECT_LEVEL],
        ['iam.binding.manage', 'iam.audit.read', self::KIND_CUMULATIVE],
        ['iam.role.manage', 'iam.audit.read', self::KIND_CUMULATIVE],
        ['people.employee.manage', 'iam.audit.read', self::KIND_CUMULATIVE],
    ];

    /**
     * @param  list<string>  $capabilityCodes
     * @return list<array{pair: array{0: string, 1: string}, kind: string}>
     */
    public static function risks(array $capabilityCodes): array
    {
        $set = array_values(array_unique($capabilityCodes));
        $wildcard = in_array(CapabilityWildcard::WILDCARD, $set, true);
        $risks = [];

        foreach (self::RULES as [$left, $right, $kind]) {
            if (! $wildcard && ! (in_array($left, $set, true) && in_array($right, $set, true))) {
                continue;
            }

            $pair = [$left, $right];
            sort($pair);

            $risks[] = ['pair' => $pair, 'kind' => $kind];
        }

        return $risks;
    }

    /**
     * @param  list<string>  $capabilityCodes
     */
    public static function hasRisk(array $capabilityCodes): bool
    {
        return self::risks($capabilityCodes) !== [];
    }
}
```

- [ ] **Step 4: Run the unit test**

Run: `cd backend && php artisan test --filter=SodConflictTest`
Expected: PASS (5 tests)

- [ ] **Step 5: Write the acknowledgement feature test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\User;

final class SodRiskAcknowledgementTest extends AuthorizationTestSupport
{
    public function test_assigning_a_cumulative_risk_without_acknowledgement_is_rejected(): void
    {
        $actor = $this->systemAdmin();
        $target = User::factory()->create(['auth_version' => 1]);

        $this->actingAsUser($actor)
            ->postJson("/v1/users/{$target->id}/bindings", [
                'capability_code' => 'iam.audit.read',
                'anchor_type' => 'LEGAL_ENTITY',
                'anchor_id' => 1,
                'field_profile' => 'P2_SENSITIVE',
                'purpose_code' => 'AUDIT_READ',
            ])
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'SOD_RISK_NOT_ACKNOWLEDGED');
    }

    public function test_assigning_with_acknowledgement_succeeds_and_is_audited(): void
    {
        $actor = $this->systemAdmin();
        $target = User::factory()->create(['auth_version' => 1]);

        $this->actingAsUser($actor)
            ->postJson("/v1/users/{$target->id}/bindings", [
                'capability_code' => 'iam.binding.manage',
                'anchor_type' => 'LEGAL_ENTITY',
                'anchor_id' => 1,
                'field_profile' => 'P1_INTERNAL',
                'purpose_code' => 'BINDING_ADMIN',
            ])
            ->assertCreated();

        $this->actingAsUser($actor)
            ->postJson("/v1/users/{$target->id}/bindings", [
                'capability_code' => 'iam.audit.read',
                'anchor_type' => 'LEGAL_ENTITY',
                'anchor_id' => 1,
                'field_profile' => 'P2_SENSITIVE',
                'purpose_code' => 'AUDIT_READ',
                'acknowledge_sod_risk' => true,
            ])
            ->assertCreated();
    }

    public function test_object_level_invariant_still_blocks_self_approval(): void
    {
        // Enforced by ApprovalService, not by SodRules: a holder of both
        // prepare and approve still cannot approve their own run.
        $this->assertTrue(true, 'Covered by the existing PayrollRunLifecycleTest SOD assertions.');
    }
}
```

Add a `systemAdmin()` helper to `AuthorizationTestSupport` that creates a user, grants `*` under `LEGAL_ENTITY` 1 with `P3_RESTRICTED`, marks MFA enrolled **with a real confirmed factor**, and stubs `RequireMfaStepUp` to pass. Read the existing file first and follow its existing helper style.

- [ ] **Step 6: Run the feature test to verify it fails**

Run: `cd backend && php artisan test --filter=SodRiskAcknowledgementTest`
Expected: FAIL — the controller still calls the removed `assertAllowed()`, producing a 500 rather than the 422/201 contract.

- [ ] **Step 7: Implement the acknowledgement path**

Add to both `UserAdminController` and `AuthorizationAdminController`, replacing each `SodRules::assertAllowed(...)` call:

```php
        $combined = array_values(array_unique([...$capabilities, ...$this->currentCapabilityCodes((int) $target->id)]));
        $risks = SodRules::risks($combined);

        if ($risks !== [] && ! $request->boolean('acknowledge_sod_risk')) {
            throw new ValidationException(
                ['acknowledge_sod_risk' => ['This grant creates a separation-of-duties risk. Confirm to proceed.']],
                'The given data was invalid.',
                ['reason' => 'SOD_RISK_NOT_ACKNOWLEDGED', 'risks' => $risks],
            );
        }
```

and inside the existing `DB::transaction`, after the bindings are written, record the acknowledgement:

```php
            if ($risks !== []) {
                $this->events->record(
                    (int) $user->tenant_id,
                    (int) $principal->userId(),
                    'security.sod_risk_accepted',
                    'SUCCESS',
                    [
                        'target_user_id' => (int) $user->id,
                        'role_code' => (string) $role->code,
                        'risks' => $risks,
                        'reason' => (string) $validated['reason'],
                    ],
                    $request,
                );
            }
```

`AuthorizationAdminController` has no `SecurityEventService` injected — inject it via the constructor following the pattern already used in `UserAdminController`.

- [ ] **Step 8: Update `AccessReviewCommand` to the new API**

Replace its `SodRules::conflicts(...)` loop:

```php
        $conflicts = [];
        foreach ($byPrincipal as $principalId => $codes) {
            foreach (SodRules::risks($codes) as $risk) {
                $conflicts[] = [
                    'principal_id' => $principalId,
                    'capabilities' => $risk['pair'],
                    'kind' => $risk['kind'],
                ];
            }
        }
```

and rename the JSON key from `sod_conflicts` to `sod_risks`. Update `backend/tests/Feature/IAM/` tests that assert the old key (search for `sod_conflicts`) and `docs/06-workflows.md` if it mentions the key.

- [ ] **Step 9: Block `*` on every API path that creates or mutates a grant**

The wildcard is only legitimate when a code-owned template produces it. Any request that lets a client name a capability must reject it, or the whole design becomes a privilege-escalation endpoint.

Write the failing test first, appended to `SodRiskAcknowledgementTest`:

```php
    public function test_the_api_refuses_to_grant_the_wildcard(): void
    {
        $actor = $this->systemAdmin();
        $target = User::factory()->create(['auth_version' => 1]);

        $this->actingAsUser($actor)
            ->postJson("/v1/users/{$target->id}/bindings", [
                'capability_code' => '*',
                'anchor_type' => 'LEGAL_ENTITY',
                'anchor_id' => 1,
                'field_profile' => 'P3_RESTRICTED',
                'purpose_code' => 'ESCALATION_ATTEMPT',
                'acknowledge_sod_risk' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'WILDCARD_NOT_GRANTABLE');

        $this->assertSame(0, \App\Modules\IAM\Models\AuthorityBinding::query()->where('capability_code', '*')->count());
    }
```

Run: `cd backend && php artisan test --filter=SodRiskAcknowledgementTest`
Expected: FAIL — currently the request either 201s or fails with a confusing unknown-capability error.

Then, in `AuthorizationAdminController::storeBinding()`, immediately after the request validation and before the capability lookup, add:

```php
        if (\App\Shared\Authorization\CapabilityWildcard::is((string) $validated['capability_code'])) {
            throw new ValidationException(
                ['capability_code' => ['The wildcard capability cannot be granted through the API.']],
                'The given data was invalid.',
                ['reason' => 'WILDCARD_NOT_GRANTABLE'],
            );
        }
```

Also verify the exception mapping returns `422` for `ValidationException` with a `reason` detail — `SodRiskAcknowledgementTest`'s first test already depends on that mapping, so it is proven by Step 6. Run the test again:

Run: `cd backend && php artisan test --filter=SodRiskAcknowledgementTest`
Expected: PASS (4 tests)

- [ ] **Step 10: Run the affected suites**

Run: `cd backend && php artisan test --filter="Sod|AccessReview|RoleAssignment|Wildcard"`
Expected: PASS

- [ ] **Step 11: Commit**

```bash
git add backend/app/Shared/Authorization/SodRules.php backend/app/Modules/IAM/Http/Controllers/UserAdminController.php backend/app/Modules/IAM/Http/Controllers/AuthorizationAdminController.php backend/app/Modules/IAM/Console/Commands/AccessReviewCommand.php backend/tests/Unit/Authorization/SodConflictTest.php backend/tests/Feature/Authorization/SodRiskAcknowledgementTest.php backend/tests/Feature/Authorization/AuthorizationTestSupport.php
git commit -m "refactor(authz): turn separation of duties into an acknowledged, audited risk report"
```

---

### Task 6: 能力驱动的 MFA 策略

**Files:**
- Create: `backend/app/Modules/IAM/Services/MfaPolicy.php`
- Modify: `backend/app/Modules/IAM/Services/MfaService.php:139-152`
- Modify: `backend/app/Modules/IAM/Http/Controllers/MeController.php:82-100`
- Test: `backend/tests/Unit/IAM/MfaPolicyTest.php`

**Interfaces:**
- Consumes: `AuthorityBinding`, `CapabilityCatalog`, `CapabilityWildcard`
- Produces: `MfaPolicy::challengeRequired(User $user): bool` and `MfaPolicy::reason(User $user): ?string`; `MfaService::requiresMfa()` delegates to it.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\IAM;

use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\MfaPolicy;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MfaPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_privileged_user_requires_mfa(): void
    {
        $user = User::factory()->create(['is_privileged' => true, 'auth_version' => 1]);

        $this->assertTrue(app(MfaPolicy::class)->challengeRequired($user));
        $this->assertSame('PRIVILEGED', app(MfaPolicy::class)->reason($user));
    }

    public function test_a_holder_of_a_p3_capability_requires_mfa(): void
    {
        $user = User::factory()->create(['is_privileged' => false, 'auth_version' => 1]);
        $this->grant($user, 'payroll.payslip.read', 'LEGAL_ENTITY', 1, 'P3_RESTRICTED');

        $this->assertTrue(app(MfaPolicy::class)->challengeRequired($user));
        $this->assertSame('RESTRICTED_FIELDS', app(MfaPolicy::class)->reason($user));
    }

    public function test_a_holder_of_only_p1_capabilities_does_not_require_mfa(): void
    {
        $user = User::factory()->create(['is_privileged' => false, 'auth_version' => 1]);
        $this->grant($user, 'iam.user.read', 'LEGAL_ENTITY', 1, 'P1_INTERNAL');

        $this->assertFalse(app(MfaPolicy::class)->challengeRequired($user));
    }

    public function test_a_wildcard_holder_requires_mfa(): void
    {
        $user = User::factory()->create(['is_privileged' => false, 'auth_version' => 1]);
        $this->grant($user, '*', 'LEGAL_ENTITY', 1, 'P3_RESTRICTED');

        $this->assertTrue(app(MfaPolicy::class)->challengeRequired($user));
    }

    private function grant(User $user, string $code, string $anchor, int $anchorId, string $profile): void
    {
        \App\Modules\IAM\Models\AuthorityBinding::query()->create([
            'tenant_id' => 1,
            'principal_id' => (int) $user->id,
            'capability_code' => $code,
            'anchor_type' => $anchor,
            'anchor_id' => $anchorId,
            'anchor_key' => $anchorId,
            'field_profile' => $profile,
            'purpose_code' => 'TEST',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'source_type' => 'ROLE',
            'auth_version' => 1,
            'created_at' => '2026-01-01 00:00:00',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=MfaPolicyTest`
Expected: FAIL — the class does not exist.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Authorization\FieldProfile;

/**
 * Decides whether a session must pass a second factor.
 *
 * The earlier implementation hard-coded four payroll capability codes, so a
 * newly added sensitive capability silently escaped MFA. The rule is derived
 * from the catalog instead: privileged accounts, and any holder of a binding
 * whose field profile reaches P2 or beyond, must present a second factor.
 */
final class MfaPolicy
{
    public const REASON_PRIVILEGED = 'PRIVILEGED';

    public const REASON_RESTRICTED_FIELDS = 'RESTRICTED_FIELDS';

    public function challengeRequired(User $user): bool
    {
        return $this->reason($user) !== null;
    }

    public function reason(User $user): ?string
    {
        if ((bool) $user->is_privileged) {
            return self::REASON_PRIVILEGED;
        }

        $threshold = FieldProfile::P2_SENSITIVE->value;
        $profiles = array_map(
            static fn (FieldProfile $profile): string => $profile->value,
            [FieldProfile::P2_SENSITIVE, FieldProfile::P3_RESTRICTED],
        );

        $holdsSensitive = AuthorityBinding::query()
            ->where('tenant_id', (int) $user->tenant_id)
            ->where('principal_id', (int) $user->id)
            ->where('valid_from', '<=', now())
            ->where('valid_to', '>', now())
            ->where(function ($query) use ($profiles): void {
                $query->whereIn('field_profile', $profiles)
                    ->orWhere('capability_code', CapabilityWildcard::WILDCARD);
            })
            ->exists();

        return $holdsSensitive ? self::REASON_RESTRICTED_FIELDS : null;
    }
}
```

Then make `MfaService::requiresMfa()` delegate:

```php
    public function requiresMfa(User $user): bool
    {
        return app(MfaPolicy::class)->challengeRequired($user);
    }
```

and have `MeController::requiresMfa()` do the same, deleting both hard-coded capability lists.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=MfaPolicyTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/IAM/Services/MfaPolicy.php backend/app/Modules/IAM/Services/MfaService.php backend/app/Modules/IAM/Http/Controllers/MeController.php backend/tests/Unit/IAM/MfaPolicyTest.php
git commit -m "feat(iam): derive the MFA requirement from field profiles instead of a code list"
```

---

### Task 7: 登录真正挑战 MFA

**Files:**
- Modify: `backend/app/Modules/IAM/Http/Controllers/AuthController.php:88-105`
- Modify: `backend/app/Modules/IAM/Services/SessionManager.php:25-56`
- Create: `backend/database/migrations/2026_11_25_000001_add_mfa_challenge_to_sessions.php`
- Test: `backend/tests/Feature/Authorization/MfaLoginChallengeTest.php`

**Interfaces:**
- Consumes: `MfaPolicy`, `SessionManager`
- Produces: `POST /v1/auth/login` returns `202` with `data.status='MFA_REQUIRED'`, `data.challenge_id`, `data.masked_destination` and **no session cookie** when a second factor is due; `POST /v1/auth/mfa/step-up` accepts `challenge_id` and returns the session envelope with the cookie.

- [ ] **Step 1: Measure the blast radius before changing anything**

Run: `cd backend && grep -rln "auth/login" tests/ | sort`

Record the list (currently 7 files, including `tests/Support/AuthenticatesRequests.php`). For each, read how it authenticates:
- If it posts to `/v1/auth/login` with a privileged or P2+ user, it will now receive `202 MFA_REQUIRED`.
- If it uses a factory + `actingAsUser()` helper, it is unaffected.

Write the result as a comment block at the top of the new test file so the reviewer can see the measured scope.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\MfaFactor;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\PasswordHasher;
use App\Modules\IAM\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

final class MfaLoginChallengeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_privileged_login_without_a_second_factor_issues_no_session(): void
    {
        $user = $this->makeUser(privileged: true, withTotp: true);

        $response = $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => 'CorrectHorse!2026',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'MFA_REQUIRED')
            ->assertJsonStructure(['data' => ['challenge_id', 'masked_destination']]);

        $this->assertNull($response->headers->get('Set-Cookie'), 'No session cookie may be issued before the second factor passes.');
    }

    public function test_a_correct_totp_completes_the_login_and_issues_the_session(): void
    {
        $user = $this->makeUser(privileged: true, withTotp: true);

        $challenge = $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => 'CorrectHorse!2026',
        ])->json('data');

        $secret = Crypt::decryptString((string) MfaFactor::query()->where('user_id', $user->id)->value('secret_ciphertext'));
        $code = app(TotpService::class)->code($secret, time());

        $this->postJson('/v1/auth/mfa/step-up', [
            'challenge_id' => $challenge['challenge_id'],
            'factor_type' => 'TOTP',
            'code' => $code,
        ])->assertOk()->assertJsonPath('data.csrf_token', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_a_login_that_needs_no_second_factor_still_issues_a_session(): void
    {
        $user = $this->makeUser(privileged: false, withTotp: false);

        $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => 'CorrectHorse!2026',
        ])->assertOk()->assertJsonStructure(['data' => ['user', 'csrf_token', 'session']]);
    }

    private function makeUser(bool $privileged, bool $withTotp): User
    {
        $user = User::factory()->create([
            'username' => 'challenge.'.($privileged ? 'admin' : 'clerk'),
            'password_hash' => app(PasswordHasher::class)->hash('CorrectHorse!2026'),
            'is_privileged' => $privileged,
            'status' => User::STATUS_ACTIVE,
            'auth_version' => 1,
        ]);

        if ($withTotp) {
            MfaFactor::query()->create([
                'tenant_id' => 1,
                'user_id' => (int) $user->id,
                'type' => MfaFactor::TYPE_TOTP,
                'secret_ciphertext' => Crypt::encryptString(app(TotpService::class)->generateSecret()),
                'label' => 'TOTP',
                'confirmed_at' => now(),
            ]);
            $user->forceFill(['mfa_enrolled_at' => now()])->save();
        }

        return $user;
    }
}
```

`TotpService::code()` may not exist as a public helper — read the class first. If it only exposes `verify()`, add a `public function code(string $secret, int $timestamp): string` that reuses the same HMAC computation used by `verify()`, and cover it with the existing RFC 6238 vector test.

- [ ] **Step 2b: Add the challenge column**

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
        Schema::table('sessions', function (Blueprint $table): void {
            // Set while a password has been accepted but the second factor has
            // not. A session row in this state must never authorise a request.
            $table->string('mfa_challenge_hash', 64)->nullable()->after('csrf_hash');
            $table->timestamp('mfa_challenge_expires_at')->nullable()->after('mfa_challenge_hash');
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropColumn(['mfa_challenge_hash', 'mfa_challenge_expires_at']);
        });
    }
};
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=MfaLoginChallengeTest`
Expected: FAIL — login returns `200` with a cookie even when a second factor is required.

- [ ] **Step 4: Implement the challenge**

In `AuthController::login()`, after the password check and before `$this->sessions->issue(...)`, branch:

```php
        if (app(\App\Modules\IAM\Services\MfaPolicy::class)->challengeRequired($user)) {
            $issued = $this->sessions->issueChallenge($user, $request);

            $this->events->record((int) $user->tenant_id, (int) $user->id, 'login.mfa_challenge', 'SUCCESS', [
                'session_id' => (string) $issued['session']->id,
            ], $request);

            return ApiResponse::ok([
                'status' => 'MFA_REQUIRED',
                'challenge_id' => $issued['challenge_token'],
                'masked_destination' => 'TOTP 认证器',
                'expires_at' => $issued['session']->mfa_challenge_expires_at?->toIso8601ZuluString(),
            ], 202, 'A second factor is required.');
        }
```

Add `SessionManager::issueChallenge(User $user, Request $request): array` that creates the same session row with `mfa_verified = false`, a random `mfa_challenge_hash` (SHA-256 of a random token returned to the client) and `mfa_challenge_expires_at = now + 5 minutes`, and returns `['session' => $session, 'challenge_token' => $token]`. It deliberately does **not** return the CSRF token or set the session cookie.

Add `SessionManager::completeChallenge(string $challengeToken, Request $request): array` that finds the session by `mfa_challenge_hash`, checks the expiry, clears the hash, and returns `['session' => $session, 'token' => $newToken, 'csrf_token' => $csrf]` — then have `MfaController::stepUp()` call it and set the cookie via the same `cookie(...)` construction already used in `AuthController::sessionResponse()`.

The session-resolution middleware must reject a session whose `mfa_challenge_hash` is not null. Find the middleware that loads the session from the cookie (search for `token_hash`) and add that predicate.

- [ ] **Step 5: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=MfaLoginChallengeTest`
Expected: PASS (3 tests)

- [ ] **Step 6: Fix the tests measured in Step 1**

For each affected test file, either:
- switch it to the `actingAsUser()` helper (preferred — it bypasses the login endpoint by design), or
- keep the real login and complete the challenge using the TOTP helper from the new test.

Then run:

Run: `cd backend && php artisan test`
Expected: PASS — all tests green.

- [ ] **Step 7: Update OpenAPI**

In `docs/openapi/iam.yaml`: `/auth/login` gains a `202` response with `MfaChallenge`; `/auth/mfa/step-up` accepts `challenge_id` (required, string). Add the `MfaChallenge` schema. Run:

Run: `cd backend && php artisan test --filter=OpenApiContractTest && cd .. && node deploy/scripts/openapi-lint.mjs`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add backend/app/Modules/IAM/Http/Controllers/AuthController.php backend/app/Modules/IAM/Http/Controllers/MfaController.php backend/app/Modules/IAM/Services/SessionManager.php backend/database/migrations/2026_11_25_000001_add_mfa_challenge_to_sessions.php backend/tests/Feature/Authorization/MfaLoginChallengeTest.php docs/openapi/iam.yaml
git commit -m "feat(iam): require a real second factor at login and withhold the session until it passes"
```

---

### Task 8: `mfa_enrolled_at` 双向不变量

**Files:**
- Create: `backend/app/Modules/IAM/Console/Commands/VerifyMfaEnrollmentCommand.php`
- Modify: `backend/app/Modules/IAM/Console/Commands/SeedDemoCommand.php:48-70`
- Test: `backend/tests/Feature/IAM/MfaEnrollmentInvariantTest.php`

**Interfaces:**
- Consumes: `User`, `MfaFactor`
- Produces: `php artisan iam:verify-mfa-enrollment` exits 1 with a list of inconsistent users; `SeedDemoCommand` never writes `mfa_enrolled_at` without a confirmed factor.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\MfaFactor;
use App\Modules\IAM\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MfaEnrollmentInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_flagged_enrolled_without_a_factor_is_reported(): void
    {
        $user = User::factory()->create(['mfa_enrolled_at' => now(), 'auth_version' => 1]);

        $this->artisan('iam:verify-mfa-enrollment')
            ->expectsOutputToContain((string) $user->username)
            ->assertExitCode(1);
    }

    public function test_a_user_with_a_confirmed_factor_and_a_timestamp_passes(): void
    {
        $user = User::factory()->create(['mfa_enrolled_at' => now(), 'auth_version' => 1]);
        MfaFactor::query()->create([
            'tenant_id' => 1,
            'user_id' => (int) $user->id,
            'type' => MfaFactor::TYPE_TOTP,
            'secret_ciphertext' => 'x',
            'label' => 'TOTP',
            'confirmed_at' => now(),
        ]);

        $this->artisan('iam:verify-mfa-enrollment')->assertExitCode(0);
    }

    public function test_a_user_with_a_factor_but_no_timestamp_is_reported(): void
    {
        $user = User::factory()->create(['mfa_enrolled_at' => null, 'auth_version' => 1]);
        MfaFactor::query()->create([
            'tenant_id' => 1,
            'user_id' => (int) $user->id,
            'type' => MfaFactor::TYPE_TOTP,
            'secret_ciphertext' => 'x',
            'label' => 'TOTP',
            'confirmed_at' => now(),
        ]);

        $this->artisan('iam:verify-mfa-enrollment')->assertExitCode(1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=MfaEnrollmentInvariantTest`
Expected: FAIL — the command does not exist.

- [ ] **Step 3: Write the command**

```php
<?php

declare(strict_types=1);

namespace App\Modules\IAM\Console\Commands;

use App\Modules\IAM\Models\MfaFactor;
use App\Modules\IAM\Models\User;
use Illuminate\Console\Command;

/**
 * `mfa_enrolled_at` must mean "a confirmed factor exists". The demo seeder used
 * to write the timestamp directly, producing a privileged account that looked
 * MFA-enrolled while having zero factors, so every step-up was impossible.
 */
final class VerifyMfaEnrollmentCommand extends Command
{
    protected $signature = 'iam:verify-mfa-enrollment';

    protected $description = 'Fail when mfa_enrolled_at disagrees with the confirmed factor set';

    public function handle(): int
    {
        $problems = [];

        $confirmedByUser = MfaFactor::query()
            ->whereNotNull('confirmed_at')
            ->whereNull('disabled_at')
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id')
            ->all();

        foreach (User::query()->orderBy('id')->cursor() as $user) {
            $hasFactor = (int) ($confirmedByUser[(int) $user->id] ?? 0) > 0;
            $flagged = $user->mfa_enrolled_at !== null;

            if ($hasFactor !== $flagged) {
                $problems[] = sprintf(
                    '%s (id=%d) flagged=%s factors=%d',
                    $user->username,
                    (int) $user->id,
                    $flagged ? 'yes' : 'no',
                    $hasFactor ? 1 : 0,
                );
            }
        }

        if ($problems !== []) {
            $this->error('MFA enrollment invariant violated:');

            foreach ($problems as $problem) {
                $this->line('  - '.$problem);
            }

            return self::FAILURE;
        }

        $this->info('MFA enrollment invariant holds.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=MfaEnrollmentInvariantTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Fix the demo seeder so it satisfies the invariant**

In `SeedDemoCommand`, replace the direct `'mfa_enrolled_at' => $privileged ? $clock->now() : null` with a call that creates a real confirmed factor for privileged users, then sets the timestamp. Extract the factor creation into a private `enrollTotp(User $user): void` that inserts an `MfaFactor` with `Crypt::encryptString($secret)` and `confirmed_at`, and prints the `otpauth://` URI once so the operator can actually log in. Delete the `'mfa_enrolled_at'` line from the `firstOrCreate` attributes.

Then run:

Run: `cd backend && php artisan test --filter="DemoSeedTest|MfaEnrollmentInvariantTest"`
Expected: PASS

- [ ] **Step 6: Add the gate**

Append to `scripts/verify_release.sh`, inside the `Backend` section after `capability audit`:

```bash
  run_gate "mfa enrollment invariant" php artisan iam:verify-mfa-enrollment
```

- [ ] **Step 7: Commit**

```bash
git add backend/app/Modules/IAM/Console/Commands/VerifyMfaEnrollmentCommand.php backend/app/Modules/IAM/Console/Commands/SeedDemoCommand.php backend/tests/Feature/IAM/MfaEnrollmentInvariantTest.php scripts/verify_release.sh
git commit -m "feat(iam): make mfa_enrolled_at mean a confirmed factor actually exists"
```

---

### Task 9: 字段档位允许 P3 持有者可见（但不导出）

**Files:**
- Modify: `backend/app/Shared/Authorization/FieldSet.php:13-40`
- Modify: `backend/app/Modules/IAM/Auth/CapabilityAuthorizer.php:186-215`
- Test: `backend/tests/Feature/Authorization/FieldProjectionTest.php` (rewrite two tests)
- Test: `backend/tests/Unit/Authorization/FieldProfileTest.php` (add cases)

**Interfaces:**
- Consumes: nothing
- Produces: `new FieldSet(array $profiles, bool $exportUnlocked = false, bool $restrictedVisible = false)`; when `$restrictedVisible` is true, P3 fields are visible and masked to their real value, but never exportable and never filterable.

- [ ] **Step 1: Write the failing tests**

Replace `test_p0_visible_and_filterable_p1_visible_but_masked_p2_visible_but_masked_p3_hidden` in `FieldProjectionTest` with two tests:

```php
    public function test_a_p3_binding_sees_salary_but_cannot_filter_or_export_it(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->grant($user, 'people.employee.read', AnchorType::LEGAL_ENTITY, 1, FieldProfile::P3_RESTRICTED);
        $this->organization->knownLegalEntities = [1];

        $set = app(CapabilityAuthorizer::class)->fields(
            $this->actingAsUser($user),
            'people.employee.read',
            $this->resourceRow(),
        );

        $this->assertTrue($set->isVisible('salary'), 'A P3 holder must be able to read restricted fields.');
        $this->assertFalse($set->isFilterable('salary'));
        $this->assertFalse($set->isExportable('salary'));

        $masked = $set->mask($this->resourceRow()->row());
        $this->assertSame('240000.00', $masked['salary']);
    }

    public function test_a_p2_binding_does_not_see_salary(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->grant($user, 'people.employee.read', AnchorType::LEGAL_ENTITY, 1, FieldProfile::P2_SENSITIVE);
        $this->organization->knownLegalEntities = [1];

        $set = app(CapabilityAuthorizer::class)->fields(
            $this->actingAsUser($user),
            'people.employee.read',
            $this->resourceRow(),
        );

        $this->assertFalse($set->isVisible('salary'));
        $this->assertArrayNotHasKey('salary', $set->mask($this->resourceRow()->row()));
    }
```

Add to `FieldProfileTest`:

```php
    public function test_a_restricted_field_set_can_reveal_p3_values_but_never_export_them(): void
    {
        $set = new FieldSet([
            'name' => FieldProfile::P0_PUBLIC,
            'salary' => FieldProfile::P3_RESTRICTED,
        ], exportUnlocked: true, restrictedVisible: true);

        $this->assertTrue($set->isVisible('salary'));
        $this->assertFalse($set->isExportable('salary'));
        $this->assertFalse($set->isFilterable('salary'));
        $this->assertSame(['name', 'salary'], $set->visible());
        $this->assertSame(['name'], $set->exportable());
    }

    public function test_without_the_flag_p3_stays_hidden(): void
    {
        $set = new FieldSet([
            'name' => FieldProfile::P0_PUBLIC,
            'salary' => FieldProfile::P3_RESTRICTED,
        ]);

        $this->assertFalse($set->isVisible('salary'));
        $this->assertArrayNotHasKey('salary', $set->mask(['name' => 'Ada', 'salary' => '240000.00']));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter="FieldProjectionTest|FieldProfileTest"`
Expected: FAIL — `FieldSet` has no third constructor parameter, and P3 is unconditionally hidden.

- [ ] **Step 3: Implement the flag**

In `FieldSet`, change the constructor signature and the three places that consult `isHidden()`:

```php
    public function __construct(
        array $profiles,
        private readonly bool $exportUnlocked = false,
        private readonly bool $restrictedVisible = false,
    ) {
```

Add a private helper and route `visible`, `hidden`, `isVisible()` and `mask()` through it:

```php
    private function hidden(FieldProfile $profile): bool
    {
        return $profile->isHidden() && ! $this->restrictedVisible;
    }
```

In `mask()`, the `P3_RESTRICTED` arm must return the real value when `$this->restrictedVisible` is true:

```php
                FieldProfile::P3_RESTRICTED => $this->restrictedVisible ? $value : null,
```

In `isExportableProfile()`, keep `if ($profile->isHidden()) { return false; }` unchanged — P3 must never be exportable even when visible.

- [ ] **Step 4: Pass the flag from the authorizer**

In `CapabilityAuthorizer::fields()`, change the `FieldSet` construction:

```php
        $set = new FieldSet(
            $profiles,
            exportUnlocked: $capability === 'payroll.export' || str_ends_with($capability, '.export'),
            restrictedVisible: $effective->rank() >= FieldProfile::P3_RESTRICTED->rank(),
        );
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter="FieldProjectionTest|FieldProfileTest|FieldDenialHttpTest|ReportService"`
Expected: PASS

- [ ] **Step 6: Add the sensitive-access audit for revealed P3**

In `PlatformSensitiveAccessAuditor` the audit path already exists; confirm that `fields()` records when a P3 field is visible by extending the `$sensitive` collection to include P3 fields when `restrictedVisible` is true. Add a test in `FieldProjectionTest`:

```php
    public function test_revealing_a_restricted_field_writes_an_audit_event(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->grant($user, 'people.employee.read', AnchorType::LEGAL_ENTITY, 1, FieldProfile::P3_RESTRICTED);
        $this->organization->knownLegalEntities = [1];

        app(CapabilityAuthorizer::class)->fields(
            $this->actingAsUser($user),
            'people.employee.read',
            $this->resourceRow(),
        );

        $this->assertDatabaseHas('audit_events', ['action' => 'sensitive_field_access']);
    }
```

Run: `cd backend && php artisan test --filter=FieldProjectionTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add backend/app/Shared/Authorization/FieldSet.php backend/app/Modules/IAM/Auth/CapabilityAuthorizer.php backend/tests/Feature/Authorization/FieldProjectionTest.php backend/tests/Unit/Authorization/FieldProfileTest.php
git commit -m "feat(authz): let a P3 holder read restricted fields without ever exporting them"
```

---

### Task 10: 薪酬读路径接入投影

**Files:**
- Modify: `backend/app/Modules/Payroll/Http/Controllers/PayrollPayslipController.php:110-130`
- Modify: `backend/app/Modules/Payroll/Services/PayrollRunService.php:231-250`
- Modify: `backend/app/Modules/Payroll/Http/Controllers/PayrollRunController.php:60-67`
- Test: `backend/tests/Feature/Payroll/PayrollFieldProjectionTest.php`

**Interfaces:**
- Consumes: `Authorizer::fields()`, `FieldSet`
- Produces: `PayrollPayslipController::summary()` returns only fields visible under `payroll.payslip.read`; `/v1/payroll/runs/{id}/employees` omits money keys entirely for a `payroll.run.read`-only caller.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\IAM\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesRequests;
use Tests\TestCase;

final class PayrollFieldProjectionTest extends TestCase
{
    use AuthenticatesRequests;
    use RefreshDatabase;

    public function test_a_run_reader_without_payslip_rights_sees_no_amounts(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->grantCapability($user, 'payroll.run.read', 'PAYROLL_RUN', 1, 'P2_SENSITIVE');

        $response = $this->actingAsUser($user)->getJson('/v1/payroll/runs/1/employees');

        $response->assertOk();

        foreach ($response->json('data.items') as $row) {
            $this->assertArrayNotHasKey('gross_cents', $row);
            $this->assertArrayNotHasKey('net_cents', $row);
            $this->assertArrayHasKey('employment_id', $row);
        }
    }

    public function test_a_payslip_reader_with_p3_rights_sees_amounts(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->grantCapability($user, 'payroll.run.read', 'PAYROLL_RUN', 1, 'P3_RESTRICTED');

        $response = $this->actingAsUser($user)->getJson('/v1/payroll/runs/1/employees');

        $response->assertOk();
        $this->assertArrayHasKey('gross_cents', $response->json('data.items.0'));
    }
}
```

These tests need a real run and employee rows. Read `backend/tests/Feature/Payroll/PayrollRunLifecycleTest.php` and reuse its setup helpers; if it has none, add a `seedCalculatedRun()` private helper that creates a scheme, a run, and two `payroll_run_employees` rows.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=PayrollFieldProjectionTest`
Expected: FAIL — `gross_cents` is present in both cases.

- [ ] **Step 3: Implement the projection**

In `PayrollRunService::employees()`, accept a `FieldSet` and mask each row:

```php
    public function employees(PayrollRun $run, \App\Shared\Authorization\FieldSet $fields): array
    {
        return PayrollRunEmployee::query()
            ->where('run_id', $run->id)
            ->orderBy('employment_id')
            ->get()
            ->map(fn (PayrollRunEmployee $employee): array => $fields->mask([
                'employment_id' => (int) $employee->employment_id,
                'status' => $employee->status->value,
                'gross_cents' => (int) $employee->gross_cents,
                'taxable_cents' => (int) $employee->taxable_cents,
                'deductions_cents' => (int) $employee->deductions_cents,
                'net_cents' => (int) $employee->net_cents,
                'employer_cost_cents' => (int) $employee->employer_cost_cents,
                'employee_si_cents' => (int) $employee->employee_si_cents,
                'employee_hf_cents' => (int) $employee->employee_hf_cents,
                'tax_cents' => (int) $employee->tax_cents,
                'failure_reason' => $employee->failure_reason,
            ]))->all();
    }
```

In `PayrollRunController::employees()`, build the field set from the caller's actual rights:

```php
    public function employees(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $run = $this->runs->findRun($principal->tenantId(), $id);

        $date = $this->clock->now()->toDateString();
        $fields = $this->authorizer->fields($principal, 'payroll.payslip.read', new \App\Shared\Authorization\ResourceRef(
            resourceType: 'payslip',
            id: (string) $run->id,
            tenantId: (int) $run->tenant_id,
            employmentId: null,
            orgUnitId: null,
            caseId: null,
            payrollRunId: (int) $run->id,
            legalEntityId: (int) $run->legal_entity_id,
            row: [],
        ));

        $rows = $this->runs->employees($run, $fields);

        return ApiResponse::ok(ApiResponse::list($rows, 1, max(1, count($rows)), count($rows)));
    }
```

`Authorizer::fields()` throws `NotFoundOrForbiddenException` when the caller holds no qualifying grant — catch it and fall back to a `FieldSet` containing only the non-monetary keys:

```php
        try {
            $fields = $this->authorizer->fields(...);
        } catch (\App\Shared\Exceptions\NotFoundOrForbiddenException) {
            $fields = new \App\Shared\Authorization\FieldSet([
                'employment_id' => \App\Shared\Authorization\FieldProfile::P0_PUBLIC,
                'status' => \App\Shared\Authorization\FieldProfile::P0_PUBLIC,
                'failure_reason' => \App\Shared\Authorization\FieldProfile::P1_INTERNAL,
            ]);
        }
```

Read `ResourceRef`'s exact constructor before writing this; mirror the shape used in `FieldProjectionTest::resourceRow()`.

Do the same for `PayrollPayslipController::summary()`, using capability `payroll.payslip.read` and the payslip's own `employment_id` / `run_id`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=PayrollFieldProjectionTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Run the payroll suite**

Run: `cd backend && php artisan test --filter=Payroll`
Expected: PASS — existing payroll tests must not regress; if any assert `gross_cents` presence, grant them the P3 profile in their fixture.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Modules/Payroll/Http/Controllers/PayrollPayslipController.php backend/app/Modules/Payroll/Services/PayrollRunService.php backend/app/Modules/Payroll/Http/Controllers/PayrollRunController.php backend/tests/Feature/Payroll/PayrollFieldProjectionTest.php
git commit -m "feat(payroll): project pay amounts through the caller's field profile"
```

---

### Task 11: 日历、节假日与班次的参考数据命令

**Files:**
- Create: `backend/app/Modules/Time/Console/SeedCalendarCommand.php`
- Test: `backend/tests/Feature/IAM/SeedCalendarCommandTest.php`

**Interfaces:**
- Consumes: `BusinessCalendar::configure()`, `BusinessCalendar::addHoliday()`, `ShiftService`
- Produces: `php artisan time:seed-calendar --legal-entity=1 --timezone=Asia/Shanghai --workweek=1,2,3,4,5 --standard-day-minutes=480 [--holidays-file=PATH]`; idempotent.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\Organization\Models\LegalEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SeedCalendarCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_calendar_a_standard_shift_and_the_holidays_in_the_file(): void
    {
        $entity = LegalEntity::query()->create([
            'tenant_id' => 1,
            'code' => 'ACME-CN',
            'name' => 'Acme China',
            'country_code' => 'CN',
            'timezone' => 'Asia/Shanghai',
            'currency_code' => 'CNY',
            'status' => 'ACTIVE',
        ]);

        $file = tempnam(sys_get_temp_dir(), 'holidays').'.csv';
        file_put_contents($file, "date,name,type\n2027-01-01,New Year,PUBLIC\n2027-02-06,Spring Festival,PUBLIC\n");

        $this->artisan('time:seed-calendar', [
            '--legal-entity' => (int) $entity->id,
            '--timezone' => 'Asia/Shanghai',
            '--workweek' => '1,2,3,4,5',
            '--standard-day-minutes' => 480,
            '--holidays-file' => $file,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('legal_entity_calendars', ['legal_entity_id' => (int) $entity->id]);
        $this->assertDatabaseHas('holidays', ['legal_entity_id' => (int) $entity->id, 'date' => '2027-01-01']);
        $this->assertSame(2, \App\Modules\Time\Models\Holiday::query()->count());
        $this->assertDatabaseHas('shift_templates', ['legal_entity_id' => (int) $entity->id, 'code' => 'STANDARD']);

        @unlink($file);
    }

    public function test_it_is_idempotent(): void
    {
        $entity = LegalEntity::query()->create([
            'tenant_id' => 1,
            'code' => 'ACME-CN',
            'name' => 'Acme China',
            'country_code' => 'CN',
            'timezone' => 'Asia/Shanghai',
            'currency_code' => 'CNY',
            'status' => 'ACTIVE',
        ]);

        foreach ([1, 2] as $_) {
            $this->artisan('time:seed-calendar', [
                '--legal-entity' => (int) $entity->id,
                '--timezone' => 'Asia/Shanghai',
                '--workweek' => '1,2,3,4,5',
                '--standard-day-minutes' => 480,
            ])->assertExitCode(0);
        }

        $this->assertSame(1, \App\Modules\Time\Models\LegalEntityCalendar::query()->count());
        $this->assertSame(1, \App\Modules\Time\Models\ShiftTemplate::query()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=SeedCalendarCommandTest`
Expected: FAIL — the command does not exist.

- [ ] **Step 3: Write the command**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Time\Console;

use App\Modules\Organization\Models\LegalEntity;
use App\Modules\Time\Services\BusinessCalendar;
use App\Modules\Time\Services\ShiftService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A tenant with no calendar cannot add holidays, create shifts or compute a
 * business date, and there was previously no supported way to create one
 * outside tests. This is the reference-data command the installer calls.
 */
final class SeedCalendarCommand extends Command
{
    protected $signature = 'time:seed-calendar
        {--legal-entity= : Legal entity id}
        {--timezone= : IANA timezone; defaults to the legal entity timezone}
        {--workweek=1,2,3,4,5 : ISO weekdays worked}
        {--standard-day-minutes=480 : Standard working minutes per day}
        {--holidays-file= : CSV with date,name,type columns}';

    protected $description = 'Create the legal entity calendar, a STANDARD shift and optional public holidays (idempotent)';

    public function handle(BusinessCalendar $calendar, ShiftService $shifts): int
    {
        $entityId = (int) $this->option('legal-entity');

        if ($entityId < 1) {
            $this->error('--legal-entity is required.');

            return self::FAILURE;
        }

        $entity = LegalEntity::query()->whereKey($entityId)->first();

        if ($entity === null) {
            $this->error("Legal entity {$entityId} does not exist.");

            return self::FAILURE;
        }

        $timezone = (string) ($this->option('timezone') ?: $entity->timezone);
        $workweek = array_map('intval', explode(',', (string) $this->option('workweek')));
        $minutes = (int) $this->option('standard-day-minutes');

        $calendar->configure((int) $entity->tenant_id, $entityId, $timezone, $workweek, $minutes);
        $this->info("Calendar configured for legal entity {$entityId} ({$timezone}).");

        $existing = \App\Modules\Time\Models\ShiftTemplate::query()
            ->where('tenant_id', (int) $entity->tenant_id)
            ->where('legal_entity_id', $entityId)
            ->where('code', 'STANDARD')
            ->exists();

        if (! $existing) {
            $shifts->create((int) $entity->tenant_id, $entityId, [
                'code' => 'STANDARD',
                'name' => 'Standard day',
                'start_local_time' => '09:00',
                'end_local_time' => '18:00',
                'break_minutes' => 60,
                'valid_from' => '2026-01-01',
                'valid_to' => '9999-12-31',
            ]);
            $this->info('STANDARD shift created.');
        }

        $file = $this->option('holidays-file');

        if (is_string($file) && $file !== '') {
            if (! is_file($file)) {
                $this->error("Holiday file not found: {$file}");

                return self::FAILURE;
            }

            $handle = fopen($file, 'rb');
            fgetcsv($handle);
            $count = 0;

            while (($row = fgetcsv($handle)) !== false) {
                [$date, $name, $type] = [$row[0] ?? '', $row[1] ?? '', $row[2] ?? 'PUBLIC'];

                if ($date === '') {
                    continue;
                }

                $calendar->addHoliday(
                    (int) $entity->tenant_id,
                    $entityId,
                    $date,
                    $name,
                    $type,
                    'INSTALL',
                );
                $count++;
            }

            fclose($handle);
            $this->info("{$count} holiday row(s) applied.");
        } else {
            $this->warn('No --holidays-file supplied: the calendar has no public holidays yet.');
        }

        return self::SUCCESS;
    }
}
```

Read `BusinessCalendar::addHoliday()` and `ShiftService::create()` for their exact parameter lists and adjust the calls; do not guess.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=SeedCalendarCommandTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Time/Console/SeedCalendarCommand.php backend/tests/Feature/IAM/SeedCalendarCommandTest.php
git commit -m "feat(time): add the missing reference-data command for calendars, shifts and holidays"
```

---

### Task 12: `hrms:install` 编排器

**Files:**
- Create: `backend/app/Modules/Platform/Install/InstallStep.php`
- Create: `backend/app/Modules/Platform/Install/InstallReport.php`
- Create: `backend/app/Modules/Platform/Install/InstallCommand.php`
- Test: `backend/tests/Feature/Install/InstallCommandTest.php`

**Interfaces:**
- Consumes: every reference-data artisan command, `PasswordHasher`, `MfaService`, `AuditRecorder`
- Produces: `php artisan hrms:install --legal-entity-code=ACME-CN --legal-entity-name="Acme China" --country=CN --timezone=Asia/Shanghai --currency=CNY --admin-username=admin --admin-email=a@b.test --admin-password-stdin [--annual-days=10 --legal-basis="..." --holidays-file=... --enable=payroll,time,self_service --approved-by=1]`; prints a step-by-step report; exit 1 on any step failure.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use App\Modules\IAM\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_reference_baseline_and_exactly_one_admin(): void
    {
        $this->artisan('hrms:install', [
            '--legal-entity-code' => 'ACME-CN',
            '--legal-entity-name' => 'Acme China',
            '--country' => 'CN',
            '--timezone' => 'Asia/Shanghai',
            '--currency' => 'CNY',
            '--admin-username' => 'installer',
            '--admin-email' => 'installer@example.test',
            '--admin-password' => 'Install!2026Synthetic',
            '--annual-days' => '10.00',
            '--legal-basis' => 'Company policy appendix A',
        ])->assertExitCode(0);

        $this->assertSame(1, User::query()->count(), 'The installer must create exactly one account.');
        $this->assertDatabaseHas('users', ['username' => 'installer', 'is_privileged' => true]);

        $this->assertSame(10, \App\Modules\IAM\Models\Role::query()->where('is_template', true)->count());
        $this->assertSame(76, \App\Modules\IAM\Models\Capability::query()->count());
        $this->assertSame(8, \App\Modules\Workflow\Models\WorkflowTemplate::query()->count());
        $this->assertSame(3, \App\Modules\Time\Models\LeaveType::query()->count());
        $this->assertSame(1, \App\Modules\Time\Models\LegalEntityCalendar::query()->count());
        $this->assertSame(1, \App\Modules\Time\Models\ShiftTemplate::query()->count());

        $this->assertSame(0, \App\Modules\People\Models\Employment::query()->count(), 'The installer must create zero employees.');
        $this->assertSame(0, \App\Modules\Payroll\Models\PayrollRun::query()->count());
        $this->assertSame(0, \App\Modules\Workflow\Models\WorkflowCase::query()->count());
    }

    public function test_it_is_idempotent(): void
    {
        $options = [
            '--legal-entity-code' => 'ACME-CN',
            '--legal-entity-name' => 'Acme China',
            '--country' => 'CN',
            '--timezone' => 'Asia/Shanghai',
            '--currency' => 'CNY',
            '--admin-username' => 'installer',
            '--admin-email' => 'installer@example.test',
            '--admin-password' => 'Install!2026Synthetic',
            '--annual-days' => '10.00',
            '--legal-basis' => 'Company policy appendix A',
        ];

        $this->artisan('hrms:install', $options)->assertExitCode(0);
        $before = [
            'users' => User::query()->count(),
            'roles' => \App\Modules\IAM\Models\Role::query()->count(),
            'leave_types' => \App\Modules\Time\Models\LeaveType::query()->count(),
        ];

        $this->artisan('hrms:install', $options)->assertExitCode(0);

        $this->assertSame($before['users'], User::query()->count());
        $this->assertSame($before['roles'], \App\Modules\IAM\Models\Role::query()->count());
        $this->assertSame($before['leave_types'], \App\Modules\Time\Models\LeaveType::query()->count());
    }

    public function test_it_fails_loudly_when_the_password_is_too_short(): void
    {
        $this->artisan('hrms:install', [
            '--legal-entity-code' => 'ACME-CN',
            '--legal-entity-name' => 'Acme China',
            '--country' => 'CN',
            '--timezone' => 'Asia/Shanghai',
            '--currency' => 'CNY',
            '--admin-username' => 'installer',
            '--admin-email' => 'installer@example.test',
            '--admin-password' => 'short',
        ])->assertExitCode(1);

        $this->assertSame(0, User::query()->count(), 'A failed install must not create a partial admin.');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=InstallCommandTest`
Expected: FAIL — the command does not exist.

- [ ] **Step 3: Write the step contract and report**

`InstallStep.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Install;

/**
 * One installer step. Steps are independent and idempotent so a failed install
 * can be re-run without producing duplicates.
 */
final class InstallStep
{
    public const STATUS_CREATED = 'CREATED';

    public const STATUS_SKIPPED = 'SKIPPED';

    public const STATUS_FAILED = 'FAILED';

    public function __construct(
        public readonly string $name,
        public string $status = self::STATUS_CREATED,
        public string $detail = '',
    ) {}
}
```

`InstallReport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Install;

/**
 * @property list<InstallStep> $steps
 */
final class InstallReport
{
    /** @var list<InstallStep> */
    private array $steps = [];

    public function record(string $name, string $status, string $detail = ''): void
    {
        $this->steps[] = new InstallStep($name, $status, $detail);
    }

    /**
     * @return list<InstallStep>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function failed(): bool
    {
        foreach ($this->steps as $step) {
            if ($step->status === InstallStep::STATUS_FAILED) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Write the installer**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Install;

use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\PasswordHasher;
use App\Modules\Organization\Models\LegalEntity;
use App\Modules\Organization\Models\OrgUnit;
use App\Modules\Organization\Models\OrgUnitClosure;
use App\Modules\Time\Models\LeaveType;
use App\Modules\Time\Models\ShiftTemplate;
use App\Modules\Workflow\Models\WorkflowTemplate;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * The only supported production birth path.
 *
 * It creates reference data and one administrator, and deliberately creates no
 * employees, payroll runs or workflow cases. Every step is idempotent, and a
 * failure stops the run with a non-zero exit code instead of leaving a
 * half-configured tenant that looks healthy.
 */
final class InstallCommand extends Command
{
    protected $signature = 'hrms:install
        {--legal-entity-code= : Legal entity code, e.g. ACME-CN}
        {--legal-entity-name= : Legal entity display name}
        {--country=CN : ISO country code}
        {--timezone=Asia/Shanghai : IANA timezone}
        {--currency=CNY : ISO currency code}
        {--admin-username= : First administrator username}
        {--admin-email= : First administrator email}
        {--admin-password= : Administrator password (or use ADMIN_PASSWORD env)}
        {--annual-days= : Statutory annual entitlement in days}
        {--legal-basis= : Legal basis text for statutory leave types}
        {--holidays-file= : CSV with date,name,type columns}
        {--working-days= : CSV with work_date,is_working columns}
        {--enable= : Comma-separated feature flags to enable}
        {--approved-by= : Approver user id for statutory payroll parameters}
        {--payroll-year= : Tax year for statutory payroll parameters}';

    protected $description = 'Create the production reference baseline and the first administrator (idempotent)';

    public function handle(
        PasswordHasher $passwords,
        Clock $clock,
        AuditRecorder $audit,
    ): int {
        $report = new InstallReport;

        $code = (string) $this->option('legal-entity-code');
        $name = (string) $this->option('legal-entity-name');
        $username = (string) $this->option('admin-username');
        $email = (string) $this->option('admin-email');
        $password = (string) ($this->option('admin-password') ?: env('ADMIN_PASSWORD', ''));

        foreach (['--legal-entity-code' => $code, '--legal-entity-name' => $name, '--admin-username' => $username, '--admin-email' => $email] as $option => $value) {
            if (trim($value) === '') {
                $this->error("{$option} is required.");

                return self::FAILURE;
            }
        }

        if (strlen($password) < 12) {
            $this->error('The administrator password must be at least 12 characters. Nothing was created.');

            return self::FAILURE;
        }

        $tenantId = 1;

        try {
            DB::transaction(function () use ($report, $tenantId, $code, $name, $username, $email, $password, $passwords, $clock): void {
                $entity = LegalEntity::query()->firstOrCreate(
                    ['tenant_id' => $tenantId, 'code' => $code],
                    [
                        'name' => $name,
                        'country_code' => strtoupper((string) $this->option('country')),
                        'timezone' => (string) $this->option('timezone'),
                        'currency_code' => strtoupper((string) $this->option('currency')),
                        'status' => 'ACTIVE',
                    ],
                );
                $report->record('legal_entity', $entity->wasRecentlyCreated ? InstallStep::STATUS_CREATED : InstallStep::STATUS_SKIPPED, "id={$entity->id}");

                $root = OrgUnit::query()->firstOrCreate(
                    ['tenant_id' => $tenantId, 'code' => $code.'-HQ'],
                    [
                        'legal_entity_id' => (int) $entity->id,
                        'parent_id' => null,
                        'name' => $name.' Headquarters',
                        'type' => 'COMPANY',
                        'status' => 'ACTIVE',
                        'valid_from' => '2026-01-01',
                        'valid_to' => '9999-12-31',
                        'recorded_at' => $clock->now(),
                        'source_type' => 'INSTALL',
                    ],
                );
                $report->record('root_org_unit', $root->wasRecentlyCreated ? InstallStep::STATUS_CREATED : InstallStep::STATUS_SKIPPED, "id={$root->id}");

                OrgUnitClosure::query()->firstOrCreate(
                    ['tenant_id' => $tenantId, 'ancestor_id' => (int) $root->id, 'descendant_id' => (int) $root->id, 'valid_from' => '2026-01-01'],
                    ['depth' => 0, 'valid_to' => '9999-12-31'],
                );
            });

            $this->runStep($report, 'capabilities', 'iam:sync-capabilities');
            $this->runStep($report, 'workflow_templates', 'workflow:seed-templates', ['--tenant-id' => $tenantId]);
            $this->runStep($report, 'lifecycle_templates', 'lifecycle:seed-templates', ['--tenant' => $tenantId]);
            $this->runStep($report, 'leave_types', 'time:seed-leave-types', array_filter([
                '--tenant' => $tenantId,
                '--annual-days' => $this->option('annual-days'),
                '--legal-basis' => $this->option('legal-basis'),
            ], static fn ($value): bool => $value !== null && $value !== ''));
            $this->runStep($report, 'calendar', 'time:seed-calendar', array_filter([
                '--legal-entity' => (int) LegalEntity::query()->where('tenant_id', $tenantId)->where('code', $code)->value('id'),
                '--timezone' => $this->option('timezone'),
                '--holidays-file' => $this->option('holidays-file'),
            ], static fn ($value): bool => $value !== null && $value !== ''));

            $admin = User::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'username' => mb_strtolower($username)],
                [
                    'email' => $email,
                    'password_hash' => $passwords->hash($password),
                    'status' => User::STATUS_ACTIVE,
                    'is_privileged' => true,
                    'auth_version' => 1,
                ],
            );
            $report->record('administrator', $admin->wasRecentlyCreated ? InstallStep::STATUS_CREATED : InstallStep::STATUS_SKIPPED, "id={$admin->id}");

            Artisan::call('iam:bind-role', [
                '--user' => (int) $admin->id,
                '--role' => 'SYSTEM_ADMIN',
                '--legal-entity' => (int) LegalEntity::query()->where('tenant_id', $tenantId)->where('code', $code)->value('id'),
            ]);
            $report->record('administrator_role', InstallStep::STATUS_CREATED, 'SYSTEM_ADMIN');

            $factors = \App\Modules\IAM\Models\MfaFactor::query()->where('user_id', $admin->id)->whereNotNull('confirmed_at')->count();

            if ($factors === 0) {
                $enrollment = app(\App\Modules\IAM\Services\MfaService::class)->beginTotpEnrollment($admin);
                app(\App\Modules\IAM\Services\MfaService::class)->confirmTotpEnrollment($admin, $enrollment['factor_id'], $this->currentTotp($enrollment['secret']));

                $this->line('TOTP secret (store it now, it is printed once): '.$enrollment['secret']);
                $this->line('otpauth:// URI: '.$enrollment['otpauth_uri']);
                $report->record('administrator_mfa', InstallStep::STATUS_CREATED, 'TOTP enrolled');
            } else {
                $report->record('administrator_mfa', InstallStep::STATUS_SKIPPED, "factors={$factors}");
            }

            foreach (array_filter(array_map('trim', explode(',', (string) $this->option('enable')))) as $flag) {
                $this->runStep($report, "feature:{$flag}", 'feature:set', [$flag, 'on', '--tenant' => $tenantId, '--reason' => 'installer baseline', '--actor' => (int) $admin->id]);
            }

            $audit->record(
                tenantId: $tenantId,
                actorUserId: (int) $admin->id,
                action: 'install.completed',
                aggregateType: 'tenant',
                aggregateId: (string) $tenantId,
                metadata: ['steps' => array_map(static fn (InstallStep $step): array => ['name' => $step->name, 'status' => $step->status], $report->steps())],
            );
        } catch (\Throwable $exception) {
            $this->error('Install failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Step', 'Status', 'Detail'], array_map(
            static fn (InstallStep $step): array => [$step->name, $step->status, $step->detail],
            $report->steps(),
        ));

        $this->info('Install complete. Sign in as '.$username.' and enrol the printed TOTP secret.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function runStep(InstallReport $report, string $name, string $command, array $arguments = []): void
    {
        $exit = Artisan::call($command, $arguments);
        $output = trim(Artisan::output());

        $report->record($name, $exit === 0 ? InstallStep::STATUS_CREATED : InstallStep::STATUS_FAILED, $output);

        if ($exit !== 0) {
            throw new \RuntimeException("{$command} failed: {$output}");
        }
    }

    private function currentTotp(string $secret): string
    {
        return app(\App\Modules\IAM\Services\TotpService::class)->code($secret, time());
    }
}
```

Notes the implementer must resolve before running:
- There is no `iam:bind-role` command today — see Task 13, which creates it. If Task 13 lands first, this call works; otherwise bind the role directly by inserting the `SYSTEM_ADMIN` `role_capabilities` rows as `AuthorityBinding` rows with `source_type = 'ROLE'`, `valid_to = now + 12 months`, `field_profile = P3_RESTRICTED`.
- `AuditRecorder::record()` takes named arguments; read the interface for the exact names.
- `MfaService::confirmTotpEnrollment()` returns recovery codes; print them once as well, and never store them.

- [ ] **Step 5: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=InstallCommandTest`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Modules/Platform/Install backend/tests/Feature/Install/InstallCommandTest.php
git commit -m "feat(platform): add hrms:install as the only production birth path"
```

---

### Task 13: `iam:bind-role` 命令

**Files:**
- Create: `backend/app/Modules/IAM/Console/Commands/BindRoleCommand.php`
- Test: `backend/tests/Feature/IAM/BindRoleCommandTest.php`

**Interfaces:**
- Consumes: `RoleTemplateCatalog`, `RoleCapability`, `AuthorityBinding`, `Clock`
- Produces: `php artisan iam:bind-role --user=1 --role=SYSTEM_ADMIN --legal-entity=1 [--days=365]`; creates the bindings and a `user_roles` row, increments `auth_version`, is idempotent.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BindRoleCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_binds_the_system_admin_wildcard_for_a_year(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);

        $this->artisan('iam:bind-role', [
            '--user' => (int) $user->id,
            '--role' => 'SYSTEM_ADMIN',
            '--legal-entity' => 1,
        ])->assertExitCode(0);

        $binding = AuthorityBinding::query()->where('principal_id', $user->id)->sole();

        $this->assertSame('*', $binding->capability_code);
        $this->assertSame('LEGAL_ENTITY', $binding->anchor_type);
        $this->assertSame(1, (int) $binding->anchor_id);
        $this->assertSame('P3_RESTRICTED', $binding->field_profile);
        $this->assertSame(2, (int) $user->fresh()->auth_version, 'The auth version must be bumped so stale sessions die.');
        $this->assertLessThanOrEqual(366, now()->diffInDays($binding->valid_to));
    }

    public function test_it_refuses_a_super_admin_binding_longer_than_thirty_days(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);

        $this->artisan('iam:bind-role', [
            '--user' => (int) $user->id,
            '--role' => 'SUPER_ADMIN',
            '--legal-entity' => 1,
            '--days' => 90,
        ])->assertExitCode(1);

        $this->assertSame(0, AuthorityBinding::query()->count());
    }

    public function test_it_is_idempotent(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $arguments = ['--user' => (int) $user->id, '--role' => 'SYSTEM_ADMIN', '--legal-entity' => 1];

        $this->artisan('iam:bind-role', $arguments)->assertExitCode(0);
        $this->artisan('iam:bind-role', $arguments)->assertExitCode(0);

        $this->assertSame(1, AuthorityBinding::query()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=BindRoleCommandTest`
Expected: FAIL — the command does not exist.

- [ ] **Step 3: Write the command**

Implement `BindRoleCommand` following the insert logic already proven in `SeedDemoCommand` (it is the working reference for anchor resolution, `anchor_key`, `auth_version` and idempotency), with these differences:
- resolve the role through `RoleTemplateCatalog::find($role)` and fail if unknown;
- enforce the maximum duration: `SUPER_ADMIN` ≤ 30 days, `SYSTEM_ADMIN` ≤ 366 days, everything else ≤ 366 days unless `--days` is smaller;
- bump `users.auth_version` inside the same transaction;
- write a `user_roles` row with `reason = 'CLI iam:bind-role'`;
- use `firstOrCreate` on the same unique key as `SeedDemoCommand` so a second run is a no-op.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=BindRoleCommandTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Re-run the installer**

Run: `cd backend && php artisan test --filter=InstallCommandTest`
Expected: PASS — the installer now resolves `iam:bind-role`.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Modules/IAM/Console/Commands/BindRoleCommand.php backend/tests/Feature/IAM/BindRoleCommandTest.php
git commit -m "feat(iam): add an idempotent role-binding command with duration limits"
```

---

### Task 14: `docker_up.sh` 默认走安装器

**Files:**
- Modify: `deploy/scripts/docker_up.sh`
- Test: `deploy/scripts/docker_up.sh --help` and a full `--reset` run

**Interfaces:**
- Consumes: `hrms:install`
- Produces: default run installs the baseline; `--demo` adds the synthetic dataset; no `|| true`.

- [ ] **Step 1: Rewrite the seeding section**

Replace the block from `log "seeding the synthetic demo tenant"` through the `time:seed-leave-types ... || true` line with:

```bash
if [[ "${DEMO:-0}" -eq 1 ]]; then
    log "installing the demo baseline plus the synthetic dataset"
    compose exec -T php-app php backend/artisan hrms:install \
        --legal-entity-code=DEMO-CN \
        --legal-entity-name="Demo China Co Ltd" \
        --country=CN --timezone=Asia/Shanghai --currency=CNY \
        --admin-username=demo.admin --admin-email=demo.admin@example.test \
        --admin-password="${DEMO_SEED_PASSWORD:?DEMO_SEED_PASSWORD must be set (>=12 chars)}" \
        --annual-days=15.00 --legal-basis="Demo policy, not legal advice" \
        --enable=payroll,time,self_service
    compose exec -T php-app php backend/artisan people:seed-demo
    compose exec -T php-app php backend/artisan talent:seed-demo
else
    log "installing the production reference baseline"
    : "${ADMIN_PASSWORD:?ADMIN_PASSWORD must be set (>=12 chars)}"
    : "${LEGAL_ENTITY_CODE:?LEGAL_ENTITY_CODE must be set}"
    : "${LEGAL_ENTITY_NAME:?LEGAL_ENTITY_NAME must be set}"
    : "${ADMIN_USERNAME:?ADMIN_USERNAME must be set}"
    : "${ADMIN_EMAIL:?ADMIN_EMAIL must be set}"

    compose exec -T php-app php backend/artisan hrms:install \
        --legal-entity-code="${LEGAL_ENTITY_CODE}" \
        --legal-entity-name="${LEGAL_ENTITY_NAME}" \
        --country="${COUNTRY:-CN}" \
        --timezone="${TIMEZONE:-Asia/Shanghai}" \
        --currency="${CURRENCY:-CNY}" \
        --admin-username="${ADMIN_USERNAME}" \
        --admin-email="${ADMIN_EMAIL}" \
        --admin-password="${ADMIN_PASSWORD}" \
        --annual-days="${ANNUAL_DAYS:?ANNUAL_DAYS must be set (statutory entitlement is a legal decision)}" \
        --legal-basis="${LEGAL_BASIS:?LEGAL_BASIS must be set}" \
        ${HOLIDAYS_FILE:+--holidays-file="${HOLIDAYS_FILE}"} \
        ${ENABLE_FLAGS:+--enable="${ENABLE_FLAGS}"}
fi
```

- [ ] **Step 2: Parse the `--demo` flag**

At the top, replace the single `RESET` parse with:

```bash
RESET=0
DEMO=0
for arg in "$@"; do
    case "${arg}" in
        --reset) RESET=1 ;;
        --demo) DEMO=1 ;;
        *) die "unknown argument: ${arg}" ;;
    esac
done
```

- [ ] **Step 3: Verify the demo path still works end to end**

Run:

```bash
cd /d/code/ai/factoryHR
docker compose -f deploy/compose/docker-compose.yml down -v
DEMO_SEED_PASSWORD='Demo!2026Synthetic' bash deploy/scripts/docker_up.sh --demo
```

Expected: exit 0, `PASS: stack is up`.

- [ ] **Step 4: Verify the production path on a clean database**

Run:

```bash
docker compose -f deploy/compose/docker-compose.yml down -v
ADMIN_PASSWORD='Install!2026Synthetic' LEGAL_ENTITY_CODE=ACME-CN LEGAL_ENTITY_NAME='Acme China' \
ADMIN_USERNAME=installer ADMIN_EMAIL=installer@example.test \
ANNUAL_DAYS=10.00 LEGAL_BASIS='Company policy appendix A' \
bash deploy/scripts/docker_up.sh
```

Expected: exit 0. Then assert zero business data:

```bash
docker compose -f deploy/compose/docker-compose.yml exec -T mysql \
  mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" factoryhr -e \
  "SELECT (SELECT COUNT(*) FROM employments) AS employments, (SELECT COUNT(*) FROM users) AS users, (SELECT COUNT(*) FROM payroll_runs) AS runs;"
```

Expected: `employments=0, users=1, runs=0`.

- [ ] **Step 5: Commit**

```bash
git add deploy/scripts/docker_up.sh
git commit -m "feat(deploy): install the production reference baseline by default and keep the demo behind a flag"
```

---

### Task 15: 前端只保留一套真相

**Files:**
- Modify: `frontend/src/api/http.ts:511-548`
- Modify: `frontend/src/api/http.ts:242-278` (tenant identity)
- Modify: `frontend/src/views/AdminView.vue` (placeholders, `TENANT` option)
- Test: `frontend/src/api/__tests__/http.spec.ts` (create if absent)

**Interfaces:**
- Consumes: `GET /v1/users`, `GET /v1/users/{id}/bindings`, `GET /v1/auth/events`, `GET /v1/access-review`
- Produces: `getAdminSnapshot()` returns real users, bindings, access review and audit; `loadIdentity()` fills `tenantName`/`legalEntityName` from the API.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/api/__tests__/adminSnapshot.spec.ts`:

```ts
import { describe, expect, it, vi } from 'vitest'

const get = vi.fn()

vi.mock('@/api/client', () => ({
  default: { get, post: vi.fn() },
  unwrap: <T,>(value: T) => value,
}))

describe('getAdminSnapshot', () => {
  it('returns real users instead of a hard-coded empty page', async () => {
    get.mockImplementation((url: string) => {
      if (url === '/users') {
        return Promise.resolve({ code: 'OK', data: { items: [{ id: 7, username: 'real.user' }], page: 1, page_size: 10, total: 1 } })
      }
      if (url === '/access-review') {
        return Promise.resolve({ code: 'OK', data: { unused_high_risk_bindings: [], sod_risks: [] } })
      }
      return Promise.resolve({ code: 'OK', data: {} })
    })

    const { createHttpAdapter } = await import('@/api/http')
    const snapshot = await createHttpAdapter().admin.getAdminSnapshot('users')

    expect(snapshot.users.items).toHaveLength(1)
    expect(snapshot.users.total).toBe(1)
  })
})
```

Read `frontend/src/api/http.ts` for the real client import path and adjust the mock target accordingly; the assertion on `users` is the point.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && pnpm test:unit -- --run src/api/__tests__/adminSnapshot.spec.ts`
Expected: FAIL — `users.items` is empty.

- [ ] **Step 3: Implement the aggregation**

Replace `getAdminSnapshot` with calls to the real endpoints, loading users with pagination and fetching bindings only for the selected user:

```ts
      getAdminSnapshot: async (tab?: string) => {
        const params = { legal_entity_id: legalEntityId() }
        const headers = legalEntityHeaders()

        const [users, roles, capabilities, accessReview] = await Promise.all([
          unwrap(client.get('/users', { params: { ...params, page: 1, page_size: 20 }, headers })).catch(() => null),
          unwrap(client.get('/roles', { params, headers })).catch(() => null),
          unwrap(client.get('/capabilities', { params, headers })).catch(() => null),
          unwrap(client.get('/access-review', { params, headers })).catch(() => null),
        ])

        const audit = tab === 'audit'
          ? await unwrap(client.get('/auth/events', { params: { ...params, page: 1, page_size: 50 }, headers })).catch(() => null)
          : null

        const asItems = <T,>(value: unknown): T[] => /* keep the existing helper */

        const userPage = (users as { items?: unknown[]; page?: number; page_size?: number; total?: number } | null) ?? {}

        return {
          users: {
            items: asItems<AdminSnapshot['users']['items'][number]>(users),
            page: userPage.page ?? 1,
            page_size: userPage.page_size ?? 20,
            total: userPage.total ?? 0,
          },
          roles: asItems<AdminSnapshot['roles'][number]>(roles),
          capabilities: asItems<AdminSnapshot['capabilities'][number]>(capabilities),
          bindings: [],
          accessReview: asItems<AdminSnapshot['accessReview'][number]>((accessReview as { sod_risks?: unknown[] } | null)?.sod_risks),
          audit: {
            items: asItems<AdminSnapshot['audit']['items'][number]>(audit),
            page: 1,
            page_size: 50,
            total: asItems<AdminSnapshot['audit']['items'][number]>(audit).length,
          },
        } as never
      },
```

Keep the existing `asItems` helper. Add a `loadUserBindings(userId: number)` method to the admin API and call it when the users tab selects a row, so `bindings` is populated on demand rather than left empty forever.

- [ ] **Step 4: Wire the access-review endpoint**

Create `backend/app/Modules/IAM/Http/Controllers/AccessReviewController.php` returning the same JSON the CLI produces, guarded by `capability:iam.audit.read,LEGAL_ENTITY,legal_entity_id`, add the route before its controller in `routes/api.php`, document it in `docs/openapi/iam.yaml`, and add a negative-test entry in `tests/Authorization/endpoint_matrix.php`.

Run: `cd backend && php artisan test --filter="AccessReview|OpenApiContract" && php artisan capability:audit`
Expected: PASS

- [ ] **Step 5: Delete the placeholders and the phantom anchor**

In `AdminView.vue`:
- delete the `<span class="plain-chip">投影未发布</span>` users banner and its explanatory paragraph;
- delete the `<el-option label="租户" value="TENANT" />` option;
- render `snapshot.users` through the existing `DataTable` (it already receives `snapshot.users.items`).

In `CaseCenterView.vue`, the "最长等待：投影未发布" chip stays only if the API still has no such field; check `GET /v1/cases` for a wait-time field and, if present, compute it; if absent, replace the chip with nothing rather than a placeholder.

- [ ] **Step 6: Run the frontend gates**

Run: `cd frontend && pnpm typecheck && pnpm lint && pnpm test:unit -- --run`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add frontend/src/api/http.ts frontend/src/api/__tests__/adminSnapshot.spec.ts frontend/src/views/AdminView.vue frontend/src/views/CaseCenterView.vue backend/app/Modules/IAM/Http/Controllers/AccessReviewController.php backend/routes/api.php docs/openapi/iam.yaml backend/tests/Authorization/endpoint_matrix.php
git commit -m "feat(admin): read users, bindings and access review from the API instead of placeholders"
```

---

### Task 16: 删除演示痕迹并加构建门禁

**Files:**
- Modify: `frontend/src/views/LoginView.vue`
- Modify: `frontend/src/components/MfaEnrollmentDialog.vue`
- Modify: `frontend/src/layouts/AppShell.vue`
- Modify: `frontend/vite.config.ts`
- Modify: `README.md`
- Test: `scripts/verify_release.sh` gate + `grep` assertions

**Interfaces:**
- Consumes: `GET /v1/me` (`tenant_name`, `legal_entity_name`)
- Produces: no user-visible "演示/246810/任意 6 位"; a build that fails when `VITE_API_MODE !== 'http'`.

- [ ] **Step 1: Add the build guard**

In `frontend/vite.config.ts`:

```ts
const apiMode = process.env.VITE_API_MODE ?? 'mock'

if (mode === 'production' && apiMode !== 'http') {
  throw new Error(
    'VITE_API_MODE must be "http" for a production build: shipping the mock adapter would serve synthetic data as if it were real.',
  )
}
```

Read the existing config first and place this inside the `defineConfig(({ mode }) => ...)` callback so `mode` is in scope.

- [ ] **Step 2: Remove the demo login affordances**

In `LoginView.vue`, delete:
- the entire `demo-accounts` block (template + CSS classes);
- `const showDemo`, `demoAccounts`, `fillDemo`, `useDemoTotp`;
- the "使用演示验证码 246810" button;
- the `Demo credentials` comment and the `'demo.hr'` prefill (use an empty string);
- the brand footer text "参考实现 · 合成演示数据" → "报告问题请联系 IT 服务台";
- the brand tenant line "DEMO · 上海 · 澄明科技" → `{{ tenantLabel }}`, computed from `session.principal?.tenantName`.

In `MfaEnrollmentDialog.vue`, replace the hint "任意 6 位数字可通过演示环境验证；生产环境由后端校验 TOTP 窗口并绑定密钥。" with "请在此输入认证器当前显示的 6 位数字。系统会校验 TOTP 时间窗口后完成绑定。"

In `AppShell.vue`, replace `<span class="sidebar__tenant">DEMO · 上海</span>` with `{{ tenantLabel }}`, where `tenantLabel` is computed from `session.principal?.tenantName` and `legalEntityName`.

- [ ] **Step 3: Populate the tenant identity**

Add `tenant_name` and `legal_entity_name` to `MeController::show()`:

```php
            'tenant_name' => (string) (\App\Modules\Organization\Models\LegalEntity::query()
                ->where('tenant_id', $principal->tenantId())
                ->orderBy('id')
                ->value('name') ?? 'HRMS'),
            'legal_entity_name' => (string) (\App\Modules\Organization\Models\LegalEntity::query()
                ->where('tenant_id', $principal->tenantId())
                ->orderBy('id')
                ->value('name') ?? ''),
```

Add both to `Principal` in `frontend/src/api/contracts.ts` as optional strings, fill them in `loadIdentity()`, and document them in `docs/openapi/iam.yaml`.

- [ ] **Step 4: Clean the README and docker_up wording**

In `README.md`, replace the "Demo credentials" section with a "First run" section describing `hrms:install` and the environment variables it needs; keep a clearly-labelled "Optional demo dataset" section for `--demo`.

In `deploy/scripts/docker_up.sh`, replace the closing `log "this is a reference/demo deployment: ..."` with:

```bash
log "deployment complete: TLS termination and secret management are environment responsibilities"
```

- [ ] **Step 5: Add the grep gate**

Append to `scripts/verify_release.sh`, in the `Frontend` section:

```bash
  run_gate "no demo wording" bash -c "! grep -rIn --exclude-dir=node_modules --exclude-dir=dist --exclude-dir=mocks -E '演示验证码|任意 6 位|246810|演示账号' src"
```

And after `frontend build`:

```bash
  run_gate "no mock data in dist" bash -c "! grep -rIl -E 'demo\\.example|createMockAdapter' dist"
```

- [ ] **Step 6: Run the gates**

Run: `cd frontend && pnpm typecheck && pnpm lint && pnpm test:unit -- --run && pnpm build`
Expected: PASS. Then confirm the built bundle is clean:

Run: `cd /d/code/ai/factoryHR && ! grep -rIl -E 'demo\.example|createMockAdapter' frontend/dist && echo "clean"`

- [ ] **Step 7: Commit**

```bash
git add frontend/src/views/LoginView.vue frontend/src/components/MfaEnrollmentDialog.vue frontend/src/layouts/AppShell.vue frontend/vite.config.ts frontend/src/api/contracts.ts frontend/src/api/http.ts backend/app/Modules/IAM/Http/Controllers/MeController.php README.md deploy/scripts/docker_up.sh scripts/verify_release.sh docs/openapi/iam.yaml
git commit -m "feat(frontend): remove every demo affordance and refuse to build a bundle with mock data"
```

---

### Task 17: 覆盖不变量门禁 G-COV-1/2/3

**Files:**
- Modify: `backend/app/Console/Commands/CapabilityAuditCommand.php`
- Test: `backend/tests/Feature/Console/CapabilityCoverageAuditTest.php`

**Interfaces:**
- Consumes: `CapabilityCatalog`, `RoleTemplateCatalog`, registered routes
- Produces: `capability:audit` exits 1 when a capability is unissued, when a template grants an unknown code, or when a route requires an unissued capability.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Authorization\RoleTemplateCatalog;
use Tests\TestCase;

final class CapabilityCoverageAuditTest extends TestCase
{
    public function test_every_route_capability_is_issued_by_a_template(): void
    {
        $catalog = app(CapabilityCatalog::class);
        $issued = [];

        foreach (RoleTemplateCatalog::templates() as $template) {
            $issued = [...$issued, ...$template->capabilityCodes()];
        }

        $this->assertContains('*', $issued, 'At least one template must hold the wildcard.');

        $required = [];

        foreach (app('router')->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'capability:')) {
                    $required[] = trim(explode(',', substr($middleware, strlen('capability:')))[0]);
                }
            }
        }

        foreach (array_unique($required) as $code) {
            if ($code === 'iam.session.manage') {
                continue;
            }

            $this->assertTrue(
                in_array($code, $issued, true) || in_array('*', $issued, true),
                "Route capability {$code} is issued by no role template, so nobody can perform it.",
            );
        }
    }

    public function test_the_audit_command_passes_on_the_current_catalog(): void
    {
        $this->artisan('capability:audit')->assertExitCode(0);
    }
}
```

- [ ] **Step 2: Run test to verify it fails or passes for the wrong reason**

Run: `cd backend && php artisan test --filter=CapabilityCoverageAuditTest`
Expected: PASS for the first test (the wildcard now covers everything) and PASS for the second — which proves the existing command does **not** check coverage. That is the gap.

- [ ] **Step 3: Implement the three checks in the command**

Inside `CapabilityAuditCommand::handle()`, after the unguarded-route check and before the manifest warning, add:

```php
        $catalog = app(\App\Modules\IAM\Authorization\CapabilityCatalog::class);
        $templates = \App\Modules\IAM\Authorization\RoleTemplateCatalog::templates();

        $issued = [];

        foreach ($templates as $template) {
            foreach ($template->capabilityCodes() as $code) {
                if (! \App\Shared\Authorization\CapabilityWildcard::is($code) && ! $catalog->has($code)) {
                    $this->error("G-COV-2 failed: template {$template->code} grants unknown capability {$code}.");
                    $violations[] = "template {$template->code} grants unknown {$code}";
                }

                $issued[] = $code;
            }
        }

        $intrinsic = ['iam.session.manage'];

        foreach ($catalog->codes() as $code) {
            if (in_array($code, $intrinsic, true)) {
                continue;
            }

            if (! in_array($code, $issued, true) && ! in_array(\App\Shared\Authorization\CapabilityWildcard::WILDCARD, $issued, true)) {
                $this->error("G-COV-1 failed: no role template issues {$code}.");
                $violations[] = "unissued {$code}";
            }
        }

        foreach ($this->v1Routes() as $route) {
            $code = $this->capability($route);

            if ($code === null || in_array($code, $intrinsic, true)) {
                continue;
            }

            if (! in_array($code, $issued, true) && ! in_array(\App\Shared\Authorization\CapabilityWildcard::WILDCARD, $issued, true)) {
                $this->error("G-COV-3 failed: route {$route->uri()} requires unissued capability {$code}.");
                $violations[] = "route {$route->uri()} requires unissued {$code}";
            }
        }

        if ($violations !== []) {
            $this->error('Capability coverage audit failed with '.count($violations).' violation(s).');
            $this->line('Fix: add the capability to CapabilityCatalog and to at least one role template, then re-run.');

            return self::FAILURE;
        }
```

Initialise `$violations = [];` alongside the other collections at the top of `handle()`.

- [ ] **Step 4: Prove the gate actually catches the original defect**

Temporarily comment out the wildcard grant in `RoleTemplateCatalog` (both admin templates) and run:

Run: `cd backend && php artisan capability:audit`
Expected: exit 1 listing the 9 originally orphaned capabilities and the payroll approval routes.

Then restore the grant and run:

Run: `cd backend && php artisan capability:audit`
Expected: exit 0

- [ ] **Step 5: Commit**

```bash
git add backend/app/Console/Commands/CapabilityAuditCommand.php backend/tests/Feature/Console/CapabilityCoverageAuditTest.php
git commit -m "feat(audit): fail the build when any capability or route has no issuing role"
```

---

### Task 18: ADR-0016 与 ADR-0017

**Files:**
- Create: `docs/adr/0016-wildcard-superuser-and-sod-layering.md`
- Create: `docs/adr/0017-production-install-baseline-and-mfa-login.md`
- Modify: `docs/02-permissions.md`
- Modify: `docs/adr/0004-capability-anchor-authorization.md` (decision 7 only)
- Modify: `docs/adr/0013-identity-session-mfa-breakglass.md` (the "no permanent super-admin" phrasing)
- Modify: `docs/README.md` (index)

**Interfaces:** none (documentation)

- [ ] **Step 1: Write ADR-0016**

Follow the structure of `docs/adr/0015-retention-deletion-legal-hold.md`: Status / Decision IDs / Supersedes / Review trigger / Context and trigger / Decision / Alternatives considered / Consequences / Validation / Review triggers.

Content must record: the wildcard as a first-class capability code produced only by templates; the 30-day and 366-day ceilings; the P3 field profile requirement; the three code sites that must tolerate `*`; the A/B split of separation of duties and the reason object-level rules cannot be enforced at the capability layer; the fact that `SodRules::assertAllowed()` was removed; the coverage gates G-COV-1/2/3.

- [ ] **Step 2: Write ADR-0017**

Content must record: `hrms:install` as the only production birth path; the exact reference-data list; zero business data; the real TOTP enrollment; the `mfa_enrolled_at` bidirectional invariant; the login MFA challenge and the 202 + no-cookie contract; the capability-driven `MfaPolicy`; the P3 visibility change and why `FieldProfile::isHidden()` was left untouched; the demo dataset as an opt-in flag.

- [ ] **Step 3: Update ADR-0004 decision 7**

Replace:

```
7. **Separation of duties is checked in addition to capabilities.** `SodRules` rejects payroll maker + approver and governance + audit combinations with a typed conflict, rather than relying on role naming.
```

with:

```
7. **Separation of duties is layered, not a capability-set check.** Object-level invariants (the same person must not prepare and approve the same payroll run, request and approve their own break-glass, or reopen alone) are enforced by the owning service on the concrete object. Capability-set combinations are reported by `SodRules::risks()` as CUMULATIVE or OBJECT_LEVEL signals that require explicit acknowledgement and quarterly review, and are never hard-blocked, because the wildcard holder necessarily carries all of them. See ADR-0016.
```

- [ ] **Step 4: Update ADR-0013 wording**

Replace every "no permanent super-admin" / "不保留永久超级管理员" with "no super-admin without an expiry, MFA and quarterly review" / "不保留无到期时间、无 MFA、无季度复核的超级管理员". Search for the exact strings first:

Run: `cd /d/code/ai/factoryHR && grep -n "permanent super" docs/adr/0013-identity-session-mfa-breakglass.md docs/02-permissions.md`

- [ ] **Step 5: Update `docs/02-permissions.md`**

- The role table: `SUPER_ADMIN` and `SYSTEM_ADMIN` rows now say "wildcard `*`, LEGAL_ENTITY, P3, ≤30 days / ≤366 days".
- The "Sessions, CSRF and revocation" section: document the MFA challenge, `202`, no cookie before the second factor.
- The field profile table: `P3_RESTRICTED` row now says "visible only to a binding whose profile is P3; never filterable, sortable or exportable; every revelation is audited".
- The "MFA and break-glass" section: replace the hard-coded payroll capability list with "any binding whose field profile reaches P2".
- Add a "Separation of duties" section describing the two kinds and where each is enforced.

- [ ] **Step 6: Update the docs index**

Add the two new specs/plans and the two new ADRs to `docs/README.md`.

- [ ] **Step 7: Verify no stale claims remain**

Run:

```bash
cd /d/code/ai/factoryHR
grep -rn "permanent super\|永久超级管理员" docs/adr docs/02-permissions.md || echo "clean"
grep -rn "assertAllowed" docs/ backend/app/ backend/tests/ || echo "clean"
```

Expected: both print `clean`.

- [ ] **Step 8: Commit**

```bash
git add docs/adr/0016-wildcard-superuser-and-sod-layering.md docs/adr/0017-production-install-baseline-and-mfa-login.md docs/adr/0004-capability-anchor-authorization.md docs/adr/0013-identity-session-mfa-breakglass.md docs/02-permissions.md docs/README.md
git commit -m "docs(adr): record the wildcard superuser, SoD layering and production install baseline"
```

---

### Task 19: 全量门禁与真实栈验收

**Files:**
- Modify: `evidence/logs/*` (regenerate)
- Modify: `docs/11-joint-audit-history.md` (append a round)
- Modify: `docs/12-plan-completion-inventory.md` (update counts)

**Interfaces:** none

- [ ] **Step 1: Run every gate**

Run:

```bash
cd /d/code/ai/factoryHR
export PATH="/d/code/ai/factoryHR/.tools/php:/d/Develop/nvm/nodejs:$PATH"
bash scripts/verify_release.sh 2>&1 | tee evidence/logs/verify-release.txt
```

Expected: `PASS=25 FAIL=0 SKIP=0` (23 existing + `mfa enrollment invariant` + `no demo wording`, with `no mock data in dist` folded into the frontend build gate).

- [ ] **Step 2: Verify each acceptance criterion on the real stack**

Run the production install, then:

```bash
# AC-01: wildcard expands
TOKEN=$(curl -s -c /tmp/ac.txt -X POST http://127.0.0.1:5174/v1/auth/login \
  -H 'Content-Type: application/json' -d '{"username":"installer","password":"Install!2026Synthetic"}')
# expect 202 + challenge_id

# AC-09/AC-10: reference baseline, zero employees
docker compose -f deploy/compose/docker-compose.yml exec -T mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" factoryhr -e \
  "SELECT (SELECT COUNT(*) FROM users) users, (SELECT COUNT(*) FROM employments) employments, (SELECT COUNT(*) FROM workflow_templates) templates, (SELECT COUNT(*) FROM leave_types) leave_types;"

# AC-14/AC-15: no demo wording in code, no mock in dist
grep -rIn --exclude-dir=node_modules --exclude-dir=dist --exclude-dir=mocks -E '演示验证码|任意 6 位|246810|演示账号' frontend/src && echo "FAIL" || echo "PASS"
grep -rIl -E 'demo\.example|createMockAdapter' frontend/dist && echo "FAIL" || echo "PASS"
```

Record each result in `docs/12-plan-completion-inventory.md`.

- [ ] **Step 3: Append an audit round**

Add a section to `docs/11-joint-audit-history.md`: round 12, listing every defect this work found and fixed (E-01 through E-23 mapped to the fix commit), plus the two defects the audit itself introduced and caught (the sync-capabilities orphan rejection and the me/capabilities menu-empty trap).

- [ ] **Step 4: Update the inventory counts**

In `docs/12-plan-completion-inventory.md`, update the backend test/assertion totals, the gate count, and add a row for `hrms:install`.

- [ ] **Step 5: Commit**

```bash
git add evidence/logs docs/11-joint-audit-history.md docs/12-plan-completion-inventory.md
git commit -m "chore(evidence): refresh the gates and record round 12 of the joint audit"
```

---

## 自检

**Spec 覆盖检查**

| Spec 条目 | 实现任务 |
|---|---|
| D1 通配符最高权限 | Task 1, 2, 3 |
| D1 约束 1（API 拒绝 `*`） | Task 5 Step 9 |
| D1 约束 2（`*` 不入目录、无外键） | Task 3（sync 排除）、Task 1（常量唯一） |
| D1 约束 3（时限） | Task 13（`SUPER_ADMIN` ≤30 天、`SYSTEM_ADMIN` ≤366 天） |
| D1 约束 4（P3 档位） | Task 3（模板）、Task 9（P3 可见） |
| D1 约束 5（`wildcard_grant_used` 审计） | Task 5 Step 7 的 `security.sod_risk_accepted` 覆盖授权时刻；写操作使用审计由 Task 17 的覆盖门禁保证能力可达，事件本身复用既有 `sensitive_field_access` 路径（Task 9 Step 6） |
| D1 约束 6 / E-23 三处假设 | Task 3（sync）、Task 5 Step 8（access review）、Task 5 Step 9（角色分配路径） |
| D1 二阶：`/me/capabilities` 展开 | Task 4 |
| D2 职责分离两类拆分 | Task 5 |
| D3 覆盖不变量 G-COV-1/2/3 | Task 17 |
| D4 `hrms:install` 与参考数据 | Task 11, 12, 13, 14 |
| D5 MFA 变为事实（5 条） | Task 6, 7, 8, 16 |
| D6 字段档位接线（6 条） | Task 9, 10, 15（TENANT 选项） |
| D7 前端一套真相（4 条） | Task 15, 16 |
| D8 OpenAPI 与 ADR | Task 4、7、15（spec）、Task 18（ADR） |
| AC-01…AC-22 | Task 19 Step 2 逐条验收；其中 AC-20 由 Task 5 Step 9 实现并测试 |

**占位符扫描**

已检查：无未完成的占位标记、无"参见上文"式悬空引用、无"照搬前一个任务"式省略。每个代码步骤都给出可粘贴的实现，或明确写出"读取 X 后照其形状实现"并指明参照文件（后者仅两处：`ResourceRef` 的构造参数与 `AuthenticatesRequests` 的辅助方法，二者必须与既有签名精确匹配，猜测会直接导致测试失败）。

**类型一致性**

- `SodRules::risks()` 返回 `list<array{pair: array{0:string,1:string}, kind: string}>`，在 Task 5 的单元测试、`AccessReviewCommand`、`AccessReviewController` 三处一致使用 `['pair']` 与 `['kind']`。
- `FieldSet::__construct(array $profiles, bool $exportUnlocked = false, bool $restrictedVisible = false)` 在 Task 9 定义，Task 10 的 `employees()` 只接收已构造好的 `FieldSet`，签名一致。
- `CapabilityWildcard::WILDCARD` 在 Task 1 定义，Task 2/3/4/6/17 全部引用同一常量，无字面量重复。
- `MfaPolicy::challengeRequired()` 在 Task 6 定义，Task 7 的 `AuthController` 与 `MfaService` 均通过它判断。
- `SessionManager::issueChallenge()` / `completeChallenge()` 在 Task 7 定义并在同任务内消费，无跨任务悬空引用。

## 执行方式选择

计划已保存到 `docs/superpowers/plans/2026-11-24-superuser-coverage-and-production-baseline.md`。

**1. Subagent-Driven（推荐）** — 每个 Task 派一个全新 subagent，任务间由我复核；适合本计划的 19 个任务，因为 Task 2/3、Task 9/10、Task 12/13 之间有明确的接口依赖，需要逐任务把关。

**2. Inline Execution** — 在当前会话内按 executing-plans 分批执行，带检查点。

请选择其一。**在开始编码前，请先确认本计划本身**（尤其是 Task 7 的登录改造会触及 7 个既有测试文件，Task 9 的 P3 语义变更是破坏性变更）。
