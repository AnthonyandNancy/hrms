# HRMS V1.1 生产完整性 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在不降低生产标准的前提下，把当前 V1.0 骨架完整落地为 V1.1 生产系统：17 个截图菜单、140 个业务页、14 个平台模块、6 个基础页（共 160 条注册表）、26 个工作包全部具备真实 API、权限负测、真实栈 E2E 与可追溯发布证据。

**Architecture:** 先修内核（授权版本重放、User/Employment 边界、三层错误隔离），再建立单一契约（OpenAPI → 生成类型 → Zod 运行时校验）与页面注册表，然后按生产主链路分波交付：组织人事 → 假勤薪酬 → 绩效/招聘/培训 → 平台与系统管理 → 生产加固。保留 V1.0 的时间化事实、账本、Case–Decision–Effect 与能力锚点内核；V1.1 作为产品层与页面层需求落地。

**Tech Stack:** PHP 8.4 / Laravel 13 / PHPUnit / MySQL 8.4 / Redis 7 / MinIO；Vue 3.5 + TypeScript 5.9 strict + Vite 7 + Pinia + Element Plus + Vitest + Zod；Playwright；Docker Compose；OpenAPI 3.1 + openapi-typescript。

## Global Constraints

- 工具链：宿主机 PHP 使用 `.tools/php/php.exe`（命令前 `export PATH="/d/code/ai/factoryHR/.tools/php:$PATH"`）；容器内命令用 `docker compose -f deploy/compose/docker-compose.yml exec -T php-app ...`。
- 禁止修改已批准的 `docs/HRMS_V1.0_解决方案_第一性原理重构.md` 与 `docs/HRMS_V1.0_实施计划_30周路线图.md`；V1.1 以 `HRMS-V1.1实施书.md` 为主，冲突按设计文档 C1–C8 的 ADR 裁决执行。
- 禁止提交 `.env`、`vendor/`、`node_modules/`、SQLite 数据库、真实个人数据、任何密钥；所有种子与测试使用合成数据。
- 迁移只增不改；每次 schema 变更必须有 forward-only migration + 回滚说明 + 数据迁移/对账脚本。
- 金额只用整数最小单位；薪酬代码禁止浮点；费率使用十进制字符串并至少 1e6 缩放。
- 每个 `/v1` 路由必须能力守卫；公开白名单仅 `v1/health`、`v1/health/live`、`v1/health/ready`、`v1/meta`、`v1/auth/login`、`v1/auth/mfa/challenge`。
- 禁止客户端可信能力位、隐藏管理快捷方式；`*` 只能来自代码拥有的角色模板，且按用户决定展开**全部能力（含 P3 工资）**；管理员拥有至高权限。
- 每个页面必须有：注册表条目、真实 API、至少 4 类权限负测、五态 UI、组件测试、真实栈 E2E、审计（敏感读取/导出/状态变更）。
- 每个 Wave 结束必须通过：后端全测、Pint、PHPStan、capability:audit、arch:test、OpenAPI 校验、前端 typecheck/lint/unit/build、真实 Compose E2E、覆盖矩阵 diff。
- 不按日期压缩测试；门禁不通过不进入下一 Wave；必要时延长工期而不是降低标准。
- 只改本计划列出的文件；发现需要改其他文件时暂停并输出 Plan Adjustment。
- 每个任务结束提交一次；提交信息使用 `feat(v11): ...` / `fix(v11): ...` / `test(v11): ...` / `docs(v11): ...`。

---

## 0. 文件结构与覆盖清单

**契约与生成（Wave 1）**
- `docs/openapi/openapi.yaml` — 修改：每个操作补 request/response schema、错误响应、`x-capability`。
- `frontend/scripts/generate-api.mjs` — 重写：调用 `openapi-typescript` 生成 `src/generated/api.ts`；`--check` 做 diff。
- `frontend/src/generated/api.ts` — 生成（不手改）。
- `frontend/src/api/schemas.ts` — 新建：Zod 运行时 schema（登录、me/permissions、工资条、工资批次、审批详情、导出）。
- `frontend/src/api/http.ts` — 重写映射：删除 `as never`，每个操作显式 normalizer + Zod 校验。
- `frontend/src/api/contracts.ts` — 缩减为从生成类型派生的视图模型，不再手写完整镜像。
- `frontend/src/api/normalise.ts` — 扩展：工资条、假期、考勤、审批、管理、报表全部显式映射。

**授权与身份（Wave 0–1）**
- `backend/app/Shared/Authorization/AuthorizationSnapshot.php` — 新建：授权快照版本重放服务。
- `backend/app/Modules/IAM/Authorization/EloquentAuthorityBindingRepository.php` — 修改：按当前版本读取；历史版本可审计。
- `backend/app/Modules/IAM/Console/Commands/BindRoleCommand.php` — 修改：已绑定判定纳入 `auth_version`，重放而非跳过。
- `backend/app/Modules/IAM/Http/Controllers/AuthController.php` — 修改：改密只吊销会话，不提升授权版本。
- `backend/app/Modules/IAM/Http/Controllers/UserAdminController.php`、`AuthorizationAdminController.php` — 修改：角色/绑定变更走快照重放。
- `backend/app/Modules/IAM/Services/SessionManager.php` — 修改：会话版本与授权版本分离（新增 `session_epoch` 或复用 `auth_version` 仅作会话校验，按 ADR）。
- `backend/app/Modules/Time/Http/TimeRequestScope.php`、`LeaveController.php`、`AttendanceController.php`、`PayrollPayslipController.php`、`WorkspaceController.php` — 修改：无任职返回 `409 NO_ACTIVE_EMPLOYMENT`。
- `backend/app/Modules/IAM/Authorization/CapabilityCatalog.php`、`RoleTemplateCatalog.php` — 修改：补齐 96 个能力码；wildcard 展开全部能力（含 P3）；管理员保持至高权限。
- `backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php` — 新建：V1.1 UI 资源别名 ↔ 执行能力码映射。
- `backend/app/Modules/IAM/Http/Controllers/MeController.php` — 修改：返回 `menus/actions/field_capabilities/scope_labels`。

**前端骨架（Wave 0–1）**
- `frontend/src/router/registry.ts` — 新建：140 业务页 + 14 平台模块 + 6 基础页 = 160 条受控注册表。
- `frontend/src/router/index.ts` — 重写：从注册表生成路由。
- `frontend/src/router/navigation.ts` — 重写：菜单树来自 `/me/permissions` + 注册表交集。
- `frontend/src/App.vue`、`layouts/AppShell.vue`、`components/PageErrorBoundary.vue` — 修改/新建：三层错误边界。
- `frontend/src/composables/useApiFeedback.ts` — 修改：统一错误呈现、401/403/422/5xx 处理、request_id 展示、去重。
- `frontend/src/components/MoneyText.vue`、`utils/format.ts` — 修改：金额缺失显示“—”，渲染期不抛异常。
- `frontend/src/views/**` — 按 Wave 2–5 新建/补齐页面。

**测试与门禁**
- `scripts/verify_release.sh` — 修改：增加 OpenAPI 生成 diff、真实 Compose E2E、路由覆盖审计、`as never` 扫描、build SHA 校验。
- `frontend/tests/unit/**` — 增加组件测试（真实 payload）与 adapter 契约测试。
- `frontend/tests/e2e/real/**` — 增加全量真实栈旅程。
- `backend/tests/**` — 增加授权快照、身份边界、契约、页面覆盖对应的后端测试。
- `deploy/**` — 修改：build SHA、镜像 tag/digest、smoke 检查 SPA/API 同版本。

**文档**
- `docs/adr/0019-*.md` … `docs/adr/0026-*.md` — C1–C8 裁决。
- `docs/superpowers/specs/2026-11-25-v11-production-completion-design.md` — 设计（已写）。
- `docs/superpowers/plans/2026-11-25-v11-production-completion-implementation-plan.md` — 本计划。
- `docs/audit/2026-11-25-v11-gap-audit.md` — 证据审计（已写）。

---

## 1. Wave 0：止血与基线（P0）

### Task 0.1：授权快照重放

**不变量：** I2 授权版本可重放。

**Files:**
- Create: `backend/app/Shared/Authorization/AuthorizationSnapshot.php`
- Modify: `backend/app/Modules/IAM/Console/Commands/BindRoleCommand.php`
- Modify: `backend/app/Modules/IAM/Http/Controllers/UserAdminController.php`
- Modify: `backend/app/Modules/IAM/Http/Controllers/AuthorizationAdminController.php`
- Modify: `backend/app/Modules/IAM/Http/Controllers/AuthController.php`
- Modify: `backend/app/Modules/IAM/Authorization/EloquentAuthorityBindingRepository.php`
- Test: `backend/tests/Feature/Authorization/AuthorizationSnapshotReplayTest.php`

**Interfaces:**
- Consumes: `Clock`, `DB`, `AuthorityBinding`, `UserRole`, `User`, `BindingValidity`.
- Produces: `AuthorizationSnapshot::replay(User $user, string $reason): int` — 返回新版本号；在同一事务内锁定用户、复制全部当前有效绑定与角色到新版本、写审计、更新 `users.auth_version`。

- [ ] **Step 1：写失败测试**

```php
public function test_password_change_does_not_revoke_existing_capability_bindings(): void
{
    $user = User::factory()->create(['auth_version' => 1]);
    AuthorityBinding::factory()->forPrincipal(1, (int) $user->id)->create([
        'capability_code' => 'people.employee.read',
        'auth_version' => 1,
        'valid_from' => '2026-01-01 00:00:00',
        'valid_to' => '2030-01-01 00:00:00',
    ]);

    $this->changePassword($user, 'New-Correct-Horse-10!');

    $this->assertSame(1, (int) $user->fresh()->auth_version);
    $this->assertSame(1, AuthorityBinding::query()->where('principal_id', $user->id)->count());
    $this->getJson('/v1/me/capabilities')->assertOk()->assertJsonPath('data.capabilities.0.capability', 'people.employee.read');
}

public function test_binding_change_replays_all_current_bindings_to_the_new_version(): void
{
    $user = User::factory()->create(['auth_version' => 1]);
    $first = AuthorityBinding::factory()->forPrincipal(1, (int) $user->id)->create([
        'capability_code' => 'people.employee.read',
        'auth_version' => 1,
        'valid_from' => '2026-01-01 00:00:00',
        'valid_to' => '2030-01-01 00:00:00',
    ]);

    $this->assignRole($user, 'HR_SPECIALIST');

    $this->assertSame(2, (int) $user->fresh()->auth_version);
    $this->assertSame(2, (int) $first->fresh()->auth_version);
    $this->assertDatabaseHas('authority_bindings', [
        'principal_id' => $user->id,
        'capability_code' => 'people.employee.read',
        'auth_version' => 2,
    ]);
}
```

- [ ] **Step 2：运行测试确认失败**

Run: `cd backend && PATH="/d/code/ai/factoryHR/.tools/php:$PATH" php artisan test --filter=AuthorizationSnapshotReplayTest`
Expected: FAIL，改密后 `auth_version` 被提升、绑定未迁移、能力为空。

- [ ] **Step 3：实现 `AuthorizationSnapshot::replay()`**

```php
final class AuthorizationSnapshot
{
    public function __construct(private readonly Clock $clock) {}

    public function replay(User $user, string $reason): int
    {
        return DB::transaction(function () use ($user, $reason): int {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $newVersion = (int) $locked->auth_version + 1;

            AuthorityBinding::query()
                ->where('tenant_id', $locked->tenant_id)
                ->where('principal_id', $locked->id)
                ->where('auth_version', $locked->auth_version)
                ->where('valid_to', '>', $this->clock->now())
                ->orderBy('id')
                ->each(function (AuthorityBinding $binding) use ($newVersion): void {
                    $copy = $binding->replicate();
                    $copy->auth_version = $newVersion;
                    $copy->save();
                });

            UserRole::query()
                ->where('tenant_id', $locked->tenant_id)
                ->where('user_id', $locked->id)
                ->where('auth_version', $locked->auth_version)
                ->where('valid_to', '>', $this->clock->now())
                ->each(function (UserRole $role) use ($newVersion): void {
                    $copy = $role->replicate();
                    $copy->auth_version = $newVersion;
                    $copy->save();
                });

            $locked->forceFill(['auth_version' => $newVersion])->save();

            app(AuditRecorder::class)->record(
                tenantId: (int) $locked->tenant_id,
                actorUserId: (int) $locked->id,
                action: 'authorization.snapshot.replayed',
                aggregateType: 'user',
                aggregateId: (string) $locked->id,
                metadata: ['from_version' => $newVersion - 1, 'to_version' => $newVersion, 'reason' => $reason],
            );

            return $newVersion;
        });
    }
}
```

- [ ] **Step 4：接入所有授权写路径**

- `UserAdminController::assignRole()`：删除事务内手写 `$newVersion` 与 `AuthorityBinding::create()` 循环，改为先创建角色/绑定（版本 = 当前版本），再调用 `AuthorizationSnapshot::replay($user, 'role.assigned')`。
- `AuthorizationAdminController::storeBinding()` / `destroyBinding()`：同上。
- `BindRoleCommand`：已绑定判定改为同时匹配 `auth_version`；若绑定存在但版本旧，调用 `replay()` 迁移，不返回“nothing changed”。
- `AuthController::changePassword()` / `logoutAll()` / MFA enroll：只吊销会话，不提升 `auth_version`；若需要强制全端重认证，使用 `SessionManager` 的会话版本/`session_epoch`。

- [ ] **Step 5：运行测试确认通过**

Run: `cd backend && PATH="/d/code/ai/factoryHR/.tools/php:$PATH" php artisan test --filter=AuthorizationSnapshotReplayTest`
Expected: PASS；随后跑 `php artisan test --filter=Authorization` 全绿。

- [ ] **Step 6：提交**

```bash
git add backend/app/Shared/Authorization/AuthorizationSnapshot.php backend/app/Modules/IAM backend/tests/Feature/Authorization/AuthorizationSnapshotReplayTest.php
git commit -m "fix(v11): replay authorization snapshot on version changes"
```

### Task 0.2：身份边界与 NO_ACTIVE_EMPLOYMENT

**不变量：** I3 身份边界显式。

**Files:**
- Create: `backend/app/Shared/Exceptions/NoActiveEmploymentException.php`
- Modify: `backend/app/Modules/Time/Http/TimeRequestScope.php`
- Modify: `backend/app/Modules/Time/Http/Controllers/LeaveController.php`
- Modify: `backend/app/Modules/Time/Http/Controllers/AttendanceController.php`
- Modify: `backend/app/Modules/Payroll/Http/Controllers/PayrollPayslipController.php`
- Modify: `backend/app/Modules/IAM/Http/Controllers/WorkspaceController.php`
- Test: `backend/tests/Feature/Authorization/NoActiveEmploymentTest.php`

**Interfaces:**
- Produces: `NoActiveEmploymentException::__construct(?string $capability = null)`；`ExceptionRenderer` 映射为 HTTP 409 + `code=NO_ACTIVE_EMPLOYMENT` + `data.reason=NO_ACTIVE_EMPLOYMENT`。

- [ ] **Step 1：写失败测试**

```php
public function test_admin_without_employment_gets_explicit_business_error_on_self_pages(): void
{
    $admin = User::factory()->create(['is_privileged' => true, 'auth_version' => 1]);
    $this->grantWildcard($admin);
    $auth = $this->loginAs($admin);

    $this->getJson('/v1/time/leave/balances')->assertStatus(409)->assertJsonPath('code', 'NO_ACTIVE_EMPLOYMENT');
    $this->getJson('/v1/time/attendance?month=2026-11')->assertStatus(409)->assertJsonPath('code', 'NO_ACTIVE_EMPLOYMENT');
    $this->getJson('/v1/payroll/payslips')->assertStatus(409)->assertJsonPath('code', 'NO_ACTIVE_EMPLOYMENT');
}
```

- [ ] **Step 2：运行测试确认失败**

Run: `cd backend && PATH="/d/code/ai/factoryHR/.tools/php:$PATH" php artisan test --filter=NoActiveEmploymentTest`
Expected: FAIL，当前返回 404 或空列表。

- [ ] **Step 3：实现异常与渲染**

```php
final class NoActiveEmploymentException extends \RuntimeException
{
    public function __construct(public readonly ?string $capability = null)
    {
        parent::__construct('The current account has no active employment.');
    }
}
```

`ExceptionRenderer` 增加：

```php
if ($exception instanceof NoActiveEmploymentException) {
    return ApiResponse::error('NO_ACTIVE_EMPLOYMENT', 'The current account is not linked to an active employment.', 409, [
        'reason' => 'NO_ACTIVE_EMPLOYMENT',
        'capability' => $exception->capability,
    ]);
}
```

- [ ] **Step 4：替换所有 `employmentId ?? 0`**

- `TimeRequestScope::employment()` 调用方在 `employmentId <= 0` 时抛 `NoActiveEmploymentException`。
- `LeaveController::balances/requests/ledger` 在 `employmentId === 0` 时抛。
- `AttendanceController::month` 在 `actor->employmentId === null` 时抛。
- `PayrollPayslipController::mine` 在无任职时抛。
- `WorkspaceController` 无任职时返回空投影 + `employmentLinked=false`，不抛（工作台仍可展示管理员治理入口）。

- [ ] **Step 5：前端处理 409**

- `useApiFeedback` 识别 `NO_ACTIVE_EMPLOYMENT`，显示专用空态：“当前账号未绑定员工，员工自助功能不可用”，并提供“前往系统管理”“前往员工档案”两个安全跳转。
- 工资条/假期/考勤页面把该错误渲染为 `EmptyState`，不是错误边界。

- [ ] **Step 6：运行测试确认通过**

Run: `cd backend && PATH="/d/code/ai/factoryHR/.tools/php:$PATH" php artisan test --filter=NoActiveEmploymentTest`
Expected: PASS；`php artisan test --filter=Time`、`--filter=Payroll` 全绿。

- [ ] **Step 7：提交**

```bash
git add backend/app/Shared/Exceptions/NoActiveEmploymentException.php backend/app/Modules/Time backend/app/Modules/Payroll backend/app/Modules/IAM/Http/Controllers/WorkspaceController.php backend/tests/Feature/Authorization/NoActiveEmploymentTest.php
git commit -m "fix(v11): return explicit no-active-employment instead of 404"
```

### Task 0.3：三层错误隔离与统一错误反馈

**不变量：** I4 错误隔离。

**Files:**
- Create: `frontend/src/components/PageErrorBoundary.vue`
- Modify: `frontend/src/App.vue`
- Modify: `frontend/src/layouts/AppShell.vue`
- Modify: `frontend/src/composables/useApiFeedback.ts`
- Modify: `frontend/src/components/MoneyText.vue`
- Modify: `frontend/src/utils/format.ts`
- Test: `frontend/tests/unit/errorBoundary.spec.ts`
- Test: `frontend/tests/unit/moneyText.spec.ts`

- [ ] **Step 1：写失败测试**

```ts
it('renders an em dash instead of throwing when cents is missing', () => {
  const wrapper = mount(MoneyText, { props: { cents: undefined as unknown as number } })
  expect(wrapper.text()).toContain('—')
  expect(wrapper.text()).not.toContain('Money must be')
})

it('keeps the shell navigation when a page throws', async () => {
  const wrapper = mount(AppShell, { global: { stubs: { RouterView: { template: '<div />' } } } })
  const page = wrapper.findComponent(PageErrorBoundary)
  page.vm.$emit('error', new Error('boom'))
  await nextTick()
  expect(wrapper.find('.sidebar').exists()).toBe(true)
  expect(wrapper.text()).toContain('页面暂时无法显示')
})
```

- [ ] **Step 2：运行测试确认失败**

Run: `cd frontend && pnpm vitest run tests/unit/errorBoundary.spec.ts tests/unit/moneyText.spec.ts`
Expected: FAIL，`MoneyText` 抛 TypeError，AppShell 无局部边界。

- [ ] **Step 3：实现三层边界**

```vue
<!-- PageErrorBoundary.vue -->
<script setup lang="ts">
import { onErrorCaptured, ref } from 'vue'
import ErrorState from '@/components/ErrorState.vue'
const error = ref<Error | null>(null)
onErrorCaptured((e) => { error.value = e instanceof Error ? e : new Error(String(e)); return false })
function reset(): void { error.value = null }
</script>

<template>
  <ErrorState v-if="error" title="页面暂时无法显示" :description="error.message" @retry="reset" />
  <slot v-else />
</template>
```

- `AppShell` 的 `<RouterView>` 外包 `PageErrorBoundary`；`App.vue` 只在 shell 自身失败时兜底。
- `App.vue` 监听 `route.fullPath` 清除 `boundaryError`；同一错误在 1 秒内去重。
- `useApiFeedback` 统一处理：401 → 登录；403 → 安全页 + 可复制 request_id；422 → 字段错误；5xx → 页面级错误 + request_id。
- 移除视图内直接 `ElMessage.error`（保留成功提示）；所有 API 错误走 `useApiFeedback`。
- `formatMoney(cents: number | null | undefined)` 缺失返回 `—`；adapter/normalizer 负责校验非法金额。

- [ ] **Step 4：运行测试确认通过**

Run: `cd frontend && pnpm vitest run tests/unit/errorBoundary.spec.ts tests/unit/moneyText.spec.ts`
Expected: PASS。

- [ ] **Step 5：提交**

```bash
git add frontend/src/components/PageErrorBoundary.vue frontend/src/App.vue frontend/src/layouts/AppShell.vue frontend/src/composables/useApiFeedback.ts frontend/src/components/MoneyText.vue frontend/src/utils/format.ts frontend/tests/unit
git commit -m "fix(v11): isolate page errors and unify API feedback"
```

### Task 0.4：真实栈基线巡检

**不变量：** I5 覆盖可执行。

**Files:**
- Create: `scripts/audit_real_stack.mjs`
- Create: `frontend/tests/e2e/real/baseline.spec.ts`
- Modify: `scripts/verify_release.sh`

- [ ] **Step 1：写巡检脚本**

脚本必须：
1. 读取 `HRMS_FRONTEND_URL`（默认 `http://127.0.0.1:5174`）与账号/密码环境变量。
2. 登录，保存 cookie + CSRF。
3. 遍历 `/workspace`、`/cases`、`/employees`、`/organization`、`/leave`、`/attendance`、`/payroll`、`/payroll/my-payslips`、`/dashboard`、`/reports`、`/admin`、`/notices`。
4. 断言：HTTP 200、无“页面暂时无法渲染”、无未捕获 console error、无 401/403/404（除预期的无任职 409）。
5. 输出 JSON 到 `evidence/logs/real-stack-audit-<stamp>.json`；任一失败退出非零。

- [ ] **Step 2：写 Playwright 基线**

```ts
test('all existing routes render without the global error boundary', async ({ page }) => {
  await login(page, process.env.HRMS_ADMIN_USERNAME!, process.env.HRMS_ADMIN_PASSWORD!)
  for (const path of ['/workspace','/cases','/employees','/organization','/leave','/attendance','/payroll','/dashboard','/reports','/admin','/notices']) {
    await page.goto(path)
    await expect(page.getByText('页面暂时无法渲染')).toHaveCount(0)
    await expect(page.locator('h1').first()).toBeVisible()
  }
})
```

- [ ] **Step 3：运行基线**

Run: `cd frontend && HRMS_ADMIN_USERNAME=installer HRMS_ADMIN_PASSWORD=... pnpm exec playwright test --config playwright.real.config.ts tests/e2e/real/baseline.spec.ts`
Expected: 所有现有路由通过；`/payroll/my-payslips` 在无任职时显示专用空态而非错误边界。

- [ ] **Step 4：接入 `verify_release.sh`**

```bash
section "Real stack"
if [ -n "${HRMS_REAL_STACK:-}" ]; then
  run_gate "real stack baseline" node "$ROOT/scripts/audit_real_stack.mjs"
else
  skip_gate "real stack baseline" "HRMS_REAL_STACK not set"
fi
```

- [ ] **Step 5：提交**

```bash
git add scripts/audit_real_stack.mjs frontend/tests/e2e/real/baseline.spec.ts scripts/verify_release.sh
git commit -m "test(v11): add real-stack baseline route audit"
```

---

## 2. Wave 1：契约与身份底座（P0）

### Task 1.1：OpenAPI 生成链

**Files:**
- Rewrite: `frontend/scripts/generate-api.mjs`
- Modify: `package.json`、`frontend/package.json`
- Modify: `docs/openapi/openapi.yaml`
- Test: `frontend/tests/unit/openapiGeneration.spec.ts`
- Test: `backend/tests/Contract/OpenApiSchemaContractTest.php`

- [ ] **Step 1：写失败测试**

```php
public function test_every_operation_has_a_response_schema(): void
{
    $spec = $this->parseSpec();
    foreach ($spec['paths'] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            if (! in_array($method, ['get','post','put','patch','delete'], true)) continue;
            $this->assertArrayHasKey('200', $operation['responses'] + $operation['responses']['201'] ?? [], "$method $path needs success response");
            $schema = $operation['responses']['200']['content']['application/json']['schema'] ?? null;
            $this->assertNotNull($schema, "$method $path needs a JSON response schema");
        }
    }
}
```

- [ ] **Step 2：运行测试确认失败**

Run: `cd backend && PATH="/d/code/ai/factoryHR/.tools/php:$PATH" php artisan test --filter=OpenApiSchemaContractTest`
Expected: FAIL，大量操作只有通用 `Envelope` 或无 schema。

- [ ] **Step 3：重写生成脚本**

```js
import { execFileSync } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
// ...
const generated = execFileSync('pnpm', ['exec', 'openapi-typescript', source, '--output', outFile], { stdio: 'inherit' })
if (check) {
  const current = existsSync(outFile) ? readFileSync(outFile, 'utf8') : ''
  if (current !== readFileSync(outFile + '.tmp', 'utf8')) process.exit(1)
}
```

- [ ] **Step 4：补齐 OpenAPI schema**

- 所有列表操作返回 `{items,page,page_size,total}` schema。
- 所有详情/命令返回具体 schema；错误响应 401/403/404/409/422/429/5xx 统一 `ErrorEnvelope`。
- 每个操作加 `x-capability`，与路由中间件一致。
- 工资条、工资批次、审批详情、导出、me/permissions 必须有完整字段。

- [ ] **Step 5：接入 CI**

```bash
cd frontend
pnpm openapi:generate
pnpm openapi:check
cd ..
node deploy/scripts/openapi-lint.mjs
pnpm openapi:validate
```

- [ ] **Step 6：提交**

```bash
git add frontend/scripts/generate-api.mjs frontend/src/generated/api.ts docs/openapi/openapi.yaml package.json frontend/package.json backend/tests/Contract/OpenApiSchemaContractTest.php
git commit -m "feat(v11): generate frontend types from OpenAPI and enforce schemas"
```

### Task 1.2：运行时 schema 校验与 adapter 清理

**不变量：** I1 单一契约事实源、I4 错误隔离。

**Files:**
- Create: `frontend/src/api/schemas.ts`
- Rewrite: `frontend/src/api/http.ts`
- Modify: `frontend/src/api/normalise.ts`
- Modify: `frontend/src/api/contracts.ts`
- Test: `frontend/tests/unit/apiContract.spec.ts`

- [ ] **Step 1：写失败测试**

```ts
it('maps a real payslip list page into view models', async () => {
  const raw = { items: [{ id: 1, run_id: 7, employment_id: 9, gross_cents: 120000, deductions_cents: 20000, net_cents: 100000, employer_cost_cents: 130000, published_at: '2026-11-01T00:00:00Z' }], page: 1, page_size: 20, total: 1 }
  const result = normalisePayslipPage(raw)
  expect(result.items[0]).toMatchObject({ id: 1, runId: 7, grossCents: 120000, netCents: 100000 })
  expect(result.items[0]?.period).toBe('')
  expect(result.items[0]?.employeeName).toBe('—')
})

it('rejects a contract mismatch with a stable code and request id', async () => {
  const get = vi.fn().mockResolvedValue({ data: { code: 'OK', data: { items: 'not-an-array' }, request_id: 'req-1' } })
  await expect(createHttpAdapter().payroll.listPayslips()).rejects.toMatchObject({ code: 'CONTRACT_MISMATCH', requestId: 'req-1' })
})
```

- [ ] **Step 2：运行测试确认失败**

Run: `cd frontend && pnpm vitest run tests/unit/apiContract.spec.ts`
Expected: FAIL，`normalisePayslipPage` 不存在；`listPayslips` 返回原始对象。

- [ ] **Step 3：实现 schema 与 normalizer**

```ts
export const PayslipPageSchema = z.object({
  items: z.array(z.object({
    id: z.number(),
    run_id: z.number(),
    employment_id: z.number(),
    gross_cents: z.number().int().optional(),
    deductions_cents: z.number().int().optional(),
    net_cents: z.number().int().optional(),
    employer_cost_cents: z.number().int().optional(),
    published_at: z.string().nullable(),
  })),
  page: z.number(),
  page_size: z.number(),
  total: z.number(),
})
```

`normalisePayslipPage()` 显式映射 snake_case → camelCase；缺失字段显示 `—`/`0`，非法金额抛 `CONTRACT_MISMATCH`，不在渲染期抛。

- [ ] **Step 4：删除 `as never`**

- `http.ts` 每个操作使用 `unwrap<T>(client.get<ApiEnvelope<T>>(...))`，`T` 来自生成类型。
- 关键响应先 `Schema.parse(data)`，失败抛 `ApiError('CONTRACT_MISMATCH', ..., { requestId })`。
- `contracts.ts` 删除手写完整镜像，改为 `type X = components['schemas']['X']` + 少量视图模型。
- ESLint 自定义规则扫描 `as never`，CI 阻断新增。

- [ ] **Step 5：运行测试确认通过**

Run: `cd frontend && pnpm vitest run tests/unit/apiContract.spec.ts && pnpm typecheck && pnpm lint`
Expected: PASS；`rg -n 'as never' frontend/src` 计数为 0。

- [ ] **Step 6：提交**

```bash
git add frontend/src/api frontend/tests/unit/apiContract.spec.ts
git commit -m "fix(v11): replace unsafe adapter casts with generated types and schema checks"
```

### Task 1.3：页面注册表与覆盖门禁

**不变量：** I5 覆盖可执行；**对应裁决：** C8 页面范围。

**Files:**
- Create: `frontend/src/router/registry.ts`
- Rewrite: `frontend/src/router/index.ts`
- Rewrite: `frontend/src/router/navigation.ts`
- Create: `scripts/audit_route_coverage.mjs`
- Create: `frontend/tests/unit/routeRegistry.spec.ts`

- [ ] **Step 1：写失败测试**

```ts
it('registers exactly the V1.1 page manifest', () => {
  expect(PAGE_REGISTRY).toHaveLength(160) // 140 业务页 + 14 平台模块 + 6 基础页
  const paths = new Set(PAGE_REGISTRY.map((page) => page.path))
  expect(paths.size).toBe(PAGE_REGISTRY.length)
  expect(PAGE_REGISTRY.every((page) => page.capability.length > 0)).toBe(true)
})
```

- [ ] **Step 2：运行测试确认失败**

Run: `cd frontend && pnpm vitest run tests/unit/routeRegistry.spec.ts`
Expected: FAIL，registry 不存在。

- [ ] **Step 3：生成注册表**

- 用脚本从 `HRMS-V1.1实施书.md` 03A/03B 提取 140 + 14 个页面，人工归一为路径/能力/组件/API。
- 每项必须有 `component`、`capability`、`requiredApis`、`states`、`e2e`。
- 组件文件不存在时，先创建占位组件 `PagePlaceholder.vue`，但 CI 覆盖审计必须标记 `missing`，直到真实实现完成。

- [ ] **Step 4：路由/导航从注册表生成**

- `router/index.ts` 遍历 `PAGE_REGISTRY` 生成路由；禁止手写 route 数组。
- `navigation.ts` 按 `menu.group` 分组；能力过滤来自 `/me/permissions`；菜单项与注册表同源。
- `audit_route_coverage.mjs` 对比 registry 与 V1.1 清单；输出 `pass/missing/partial`；CI 任一 missing 失败。

- [ ] **Step 5：运行测试确认通过**

Run: `cd frontend && pnpm vitest run tests/unit/routeRegistry.spec.ts && node ../scripts/audit_route_coverage.mjs`
Expected: registry 160 项；覆盖审计输出 `missing=0`（Wave 1 结束时可能仍为 160 占位，但注册表完整）。

- [ ] **Step 6：提交**

```bash
git add frontend/src/router scripts/audit_route_coverage.mjs frontend/tests/unit/routeRegistry.spec.ts
git commit -m "feat(v11): add controlled page registry and coverage audit"
```

### Task 1.4：C1–C8 ADR 裁决

**不变量：** I1/I2/I3/I5；**对应裁决：** C1 权限词汇、C2 API 前缀、C3 实体词汇、C4 公司边界、C5 数据范围、C6 管理员全权、C7 内网 MFA 策略、C8 页面范围。

**Files:**
- Create: `docs/adr/0019-permission-code-vocabulary.md`
- Create: `docs/adr/0020-api-prefix-and-resource-paths.md`
- Create: `docs/adr/0021-entity-vocabulary-and-domain-model.md`
- Create: `docs/adr/0022-tenant-legal-entity-company-boundary.md`
- Create: `docs/adr/0023-anchor-scope-taxonomy.md`
- Create: `docs/adr/0024-superuser-full-authority-and-p3-audit.md`
- Create: `docs/adr/0025-intranet-mfa-and-operational-posture.md`
- Create: `docs/adr/0026-page-coverage-registry.md`
- Modify: `docs/README.md`

- [ ] **Step 1：逐份写 ADR**

每份包含：Context、Decision、Alternatives、Consequences、Validation、Review trigger。内容按设计文档 C1–C8 与用户四条决定；每份必须写“为什么不是另一套”。C6 要明确写“管理员拥有至高权限、可读工资、P3 审计补偿”；C7 要明确写“内网、环境变量不构成本轮门槛、MFA 可选但推荐”。

- [ ] **Step 2：更新文档索引**

`docs/README.md` 增加 ADR-0019–0027；说明 V1.1 与 V1.0 的优先级和冲突裁决。

- [ ] **Step 3：提交**

```bash
git add docs/adr/0019-*.md docs/adr/0020-*.md docs/adr/0021-*.md docs/adr/0022-*.md docs/adr/0023-*.md docs/adr/0024-*.md docs/adr/0025-*.md docs/adr/0026-*.md docs/README.md
git commit -m "docs(v11): decide permission, API, entity, scope, superuser and intranet posture"
```

### Task 1.5：管理员全权、能力码补齐与 P3 审计

**不变量：** I2 授权版本可重放；**对应裁决：** C6 管理员全权。

**Files:**
- Modify: `backend/app/Modules/IAM/Authorization/CapabilityCatalog.php`
- Modify: `backend/app/Modules/IAM/Authorization/RoleTemplateCatalog.php`
- Modify: `backend/app/Modules/IAM/Authorization/EloquentPrincipalAuthorityReader.php`
- Modify: `backend/app/Modules/IAM/Http/Controllers/MeController.php`
- Modify: `docs/audit/2026-11-25-v11-page-access-blueprint.md`
- Test: `backend/tests/Feature/Authorization/SuperuserFullAuthorityTest.php`
- Test: `backend/tests/Unit/Authorization/CapabilityCatalogCoverageTest.php`

- [ ] **Step 1：写失败测试**

```php
public function test_superuser_wildcard_grants_payroll_p3_reads_and_exports(): void
{
    $user = User::factory()->create(['is_privileged' => true, 'auth_version' => 1]);
    $this->grantWildcard($user);

    $this->actingAsPrincipal($user)->getJson('/v1/payroll/payslips')->assertOk();
    $this->actingAsPrincipal($user)->getJson('/v1/payroll/runs')->assertOk();
    $this->assertDatabaseHas('audit_events', ['action' => 'payroll.read', 'actor_user_id' => $user->id]);
}

public function test_system_admin_has_every_catalogued_capability(): void
{
    $user = User::factory()->create(['is_privileged' => true, 'auth_version' => 1]);
    $this->grantWildcard($user);
    $capabilities = app(CapabilityCatalog::class)->codes();

    $this->actingAsPrincipal($user)->getJson('/v1/me/capabilities')
        ->assertOk()
        ->assertJsonCount(count($capabilities), 'data.capabilities');

    foreach ($capabilities as $code) {
        $this->assertTrue($this->authorizer->permits($this->principal($user), $code, '2026-11-25'), $code);
    }
}

public function test_every_blueprint_capability_exists_in_the_catalog(): void
{
    $blueprint = file_get_contents(base_path('../docs/audit/2026-11-25-v11-page-access-blueprint.md'));
    preg_match_all('/`((?:iam|org|people|workflow|lifecycle|time|payroll|performance|recruitment|training|engagement|reporting|benefit|notification|system)\.[a-z_.]+)`/', $blueprint, $matches);

    foreach (array_unique($matches[1]) as $code) {
        $this->assertTrue(app(CapabilityCatalog::class)->has($code), "Missing capability: {$code}");
    }
}
```

- [ ] **Step 2：运行测试确认失败**

Run: `cd backend && PATH="/d/code/ai/factoryHR/.tools/php:$PATH" php artisan test --filter=SuperuserFullAuthorityTest --filter=CapabilityCatalogCoverageTest`
Expected: FAIL；蓝皮书引用的 `org.position.*`、`time.overtime.*`、`time.travel.*`、`iam.menu.manage`、`benefit.*` 等尚未登记。

- [ ] **Step 3：补齐能力目录**

按 `docs/audit/2026-11-25-v11-page-access-blueprint.md` §3A 一次性登记 30+ 个能力码。每条必须包含：

```php
new CapabilityDefinition(
    code: 'payroll.export',
    resource: 'payroll_run',
    actions: ['export'],
    anchorTypes: [AnchorType::PAYROLL_RUN, AnchorType::LEGAL_ENTITY],
    maxFieldProfile: FieldProfile::P3_RESTRICTED,
    risk: 'critical',
    sodGroup: 'payroll_publish',
    description: 'Export payroll data with an explicit export capability.',
),
```

- `org.position.read/manage/export`
- `time.overtime.read/request/manage/settle`
- `time.travel.read/request/manage/expense`
- `performance.calibration.manage`、`performance.report.read`
- `iam.menu.manage`、`iam.permission.read`、`iam.scope.manage`、`iam.field.manage`
- `workflow.template.manage`
- `benefit.read`、`benefit.manage`
- `time.calendar.manage`
- `system.settings.manage`
- `notification.read`、`notification.manage`
- `engagement.notice.publish`、`engagement.notice.export`
- `people.employee.export`、`people.contract.export`、`people.document.download`、`org.unit.export`、`recruitment.requisition.export`
- `reporting.report.read`、`reporting.report.create`、`reporting.subscription.manage`

- [ ] **Step 4：管理员全权展开**

- `EloquentPrincipalAuthorityReader` 与 `MeController` 的 wildcard 展开为 **全部能力码**（包括 `payroll.*`、P3）。
- `RoleTemplateCatalog` 的 `SUPER_ADMIN`/`SYSTEM_ADMIN` 保持 wildcard + P3。
- P3 读取、导出、授权变更写 `audit_events`；SoD 风险写 `security.sod_risk_accepted` 但**不阻断**管理员。
- 新增 `PAYROLL_SPECIALIST`/`PAYROLL_APPROVER`/`REPORT_VIEWER` 等角色模板，供非管理员使用。

- [ ] **Step 5：运行测试确认通过**

Run: `cd backend && PATH="/d/code/ai/factoryHR/.tools/php:$PATH" php artisan test --filter=SuperuserFullAuthorityTest --filter=CapabilityCatalogCoverageTest && php artisan capability:audit`
Expected: PASS；能力码覆盖蓝皮书全部引用；capability:audit 无缺失。

- [ ] **Step 6：提交**

```bash
git add backend/app/Modules/IAM backend/tests/Feature/Authorization/SuperuserFullAuthorityTest.php backend/tests/Unit/Authorization/CapabilityCatalogCoverageTest.php docs/audit/2026-11-25-v11-page-access-blueprint.md
git commit -m "feat(v11): grant superuser full authority and complete capability catalog"
```

### Task 1.5A：页面/功能/权限蓝皮书落地

**不变量：** I5 覆盖可执行；**对应裁决：** C8 页面范围、C1 权限词汇、C5 数据范围。

**Files:**
- Create: `docs/audit/2026-11-25-v11-page-access-blueprint.md`（已写）
- Create: `docs/audit/2026-11-25-v11-page-inventory.md`（已机器生成）
- Create: `scripts/docs/generate_v11_inventory.py`（已写）
- Create: `docs/adr/0027-page-permission-and-role-allocation.md`
- Test: `backend/tests/Contract/PagePermissionCoverageTest.php`
- Test: `frontend/tests/unit/pageAccessMatrix.spec.ts`

- [ ] **Step 1：写失败测试**

```php
public function test_every_blueprint_page_has_a_capability_and_a_role_template(): void
{
    $blueprint = file_get_contents(base_path('../docs/audit/2026-11-25-v11-page-access-blueprint.md'));
    preg_match_all('/\| `(\/[^`]+)` \| `([a-z.]+)` \|/', $blueprint, $matches, PREG_SET_ORDER);
    $catalog = app(CapabilityCatalog::class);

    foreach ($matches as [$row, $path, $capability]) {
        $this->assertTrue($catalog->has($capability), "{$path} uses unknown capability {$capability}");
    }
}
```

```ts
it('maps every registered page to at least one role template', () => {
  const assigned = new Set(ROLE_PAGE_ASSIGNMENTS.flatMap((role) => role.pages))
  for (const page of PAGE_REGISTRY) {
    expect(assigned.has(page.path), `${page.path} is not assigned to any role`).toBe(true)
  }
})
```

- [ ] **Step 2：运行测试确认失败**

Run: `cd backend && PATH="/d/code/ai/factoryHR/.tools/php:$PATH" php artisan test --filter=PagePermissionCoverageTest`
Run: `cd frontend && pnpm vitest run tests/unit/pageAccessMatrix.spec.ts`
Expected: FAIL；部分页面能力码未登记，部分页面未映射角色。

- [ ] **Step 3：实现角色-页面矩阵**

- 在 `backend/app/Modules/IAM/Authorization/RolePageAssignmentCatalog.php` 定义 14 个角色模板 → 页面列表 → 动作能力。
- 每个页面至少映射一个角色；`EMPLOYEE` 映射 SELF 页面；`SUPER_ADMIN`/`SYSTEM_ADMIN` 映射全部页面。
- `PermissionResourceCatalog` 为每个页面输出 `page_code`（路由注册名）、`path`、`required_capability`、`actions`。
- `/system/roles` 的授权树直接消费该目录，避免前后端各写一套菜单。

- [ ] **Step 4：实现权限预览与分配**

- `/system/users/{id}/permissions-preview` 返回：有效角色、可见页面、动作、数据范围、字段档位、来源绑定、过期日。
- `/system/roles/{code}/pages` 返回可勾选页面与动作；保存时写 `role_capabilities` + `authority_bindings`。
- 分配用户时支持多角色、生效/到期日、授权说明；保存后触发 `AuthorizationSnapshot::replay()`。

- [ ] **Step 5：更新蓝皮书**

- 将 §2 的 140 行、§3 的 14 行、§4 的 14 角色矩阵，与 `RolePageAssignmentCatalog` 逐项对齐。
- 蓝皮书增加“角色 → 页面 → 动作”总表，作为可视化授权页的数据源。
- 任何页面缺少角色映射，CI 失败。

- [ ] **Step 6：运行测试确认通过**

Run: `cd backend && PATH="/d/code/ai/factoryHR/.tools/php:$PATH" php artisan test --filter=PagePermissionCoverageTest && php artisan capability:audit`
Run: `cd frontend && pnpm vitest run tests/unit/pageAccessMatrix.spec.ts`
Expected: PASS；140 业务页 + 14 平台模块 + 6 基础页全部有能力和角色映射。

- [ ] **Step 7：提交**

```bash
git add docs/audit/2026-11-25-v11-page-access-blueprint.md docs/audit/2026-11-25-v11-page-inventory.md scripts/docs/generate_v11_inventory.py docs/adr/0027-page-permission-and-role-allocation.md backend/app/Modules/IAM/Authorization/RolePageAssignmentCatalog.php backend/tests/Contract/PagePermissionCoverageTest.php frontend/tests/unit/pageAccessMatrix.spec.ts
git commit -m "docs(v11): lock page inventory, role allocation and permission preview"
```

### Task 1.6：菜单树与权限资源 API

**不变量：** I1 单一契约、I5 覆盖可执行；**对应裁决：** C1 权限词汇、C8 页面范围。

**Files:**
- Create: `backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php`
- Modify: `backend/app/Modules/IAM/Http/Controllers/MeController.php`
- Modify: `backend/routes/api.php`
- Modify: `docs/openapi/openapi.yaml`
- Test: `backend/tests/Feature/IAM/MePermissionsTest.php`

- [ ] **Step 1：写失败测试**

```php
public function test_me_permissions_returns_menu_tree_actions_and_field_capabilities(): void
{
    $user = User::factory()->create(['auth_version' => 1]);
    $this->grant($user, 'people.employee.read', 'LEGAL_ENTITY', 1);

    $this->actingAsPrincipal($user)->getJson('/v1/me/permissions')
        ->assertOk()
        ->assertJsonStructure(['data' => ['menus', 'actions', 'field_capabilities', 'scope_labels', 'auth_version']]);
}
```

- [ ] **Step 2：运行测试确认失败**

Run: `cd backend && PATH="/d/code/ai/factoryHR/.tools/php:$PATH" php artisan test --filter=MePermissionsTest`
Expected: FAIL，端点不存在。

- [ ] **Step 3：实现**

- `PermissionResourceCatalog` 定义 V1.1 的 17 个菜单组 + 160 页面资源（140 业务 + 14 平台 + 6 基础）+ 能力码映射。
- `GET /v1/me/permissions` 返回：`menus`（只含有权页面）、`actions`、`field_capabilities`、`scope_labels`、`auth_version`。
- 前端注册表用 `menus` 过滤可见性；后端仍独立校验每个 API。

- [ ] **Step 4：运行测试确认通过**

Run: `cd backend && PATH="/d/code/ai/factoryHR/.tools/php:$PATH" php artisan test --filter=MePermissionsTest`
Expected: PASS；OpenAPI 契约测试通过。

- [ ] **Step 5：提交**

```bash
git add backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php backend/app/Modules/IAM/Http/Controllers/MeController.php backend/routes/api.php docs/openapi/openapi.yaml backend/tests/Feature/IAM/MePermissionsTest.php
git commit -m "feat(v11): expose menu tree, actions and field capabilities"
```

---

## 3. Wave 2：组织人事（P1）

### Task 2.1：员工档案全套

**Files:**
- Modify: `backend/app/Modules/People/Http/Controllers/EmployeeController.php`
- Create: `backend/app/Modules/People/Http/Controllers/EmployeeProfileController.php`
- Create: `backend/database/migrations/2026_11_25_100000_create_employee_profile_tables.php`
- Create: `frontend/src/views/hr/EmployeeListView.vue`
- Create: `frontend/src/views/hr/EmployeeCreateView.vue`
- Create: `frontend/src/views/hr/EmployeeDetailView.vue`
- Create: `frontend/src/views/hr/EmployeeEducationView.vue`
- Create: `frontend/src/views/hr/EmployeeWorkHistoryView.vue`
- Create: `frontend/src/views/hr/EmployeeContactsView.vue`
- Create: `frontend/src/views/hr/EmployeeCredentialsView.vue`
- Create: `frontend/src/views/hr/EmployeeHistoryView.vue`
- Create: `frontend/src/views/hr/EmployeeFilesView.vue`
- Create: `frontend/src/views/hr/EmployeeChangesView.vue`
- Create: `frontend/src/views/hr/EmployeeImportView.vue`
- Test: 每个写操作 4 类负测 + 组件测试 + 真实 E2E.

- [ ] **Step 1：写迁移**

```php
Schema::create('employee_educations', function (Blueprint $table): void {
    $table->id();
    $table->unsignedBigInteger('tenant_id')->index();
    $table->unsignedBigInteger('employment_id')->index();
    $table->string('school', 160);
    $table->string('degree', 80);
    $table->string('major', 160)->nullable();
    $table->date('start_date');
    $table->date('end_date')->nullable();
    $table->timestamps();
});
```

同样创建 `employee_experiences`、`employee_contacts`、`employee_certificates`、`employee_files`、`employee_change_requests`；全部 forward-only + 索引 + tenant 隔离。

- [ ] **Step 2：写 API 测试**

```php
public function test_hr_can_create_employee_and_read_full_profile_within_legal_entity(): void
{
    $this->actingAsHr(1);
    $employee = $this->postJson('/v1/employees', $this->employeePayload())->assertCreated()->json('data');
    $this->getJson('/v1/employees/'.$employee['id'])->assertOk()->assertJsonPath('data.employee_no', 'E1001');
}

public function test_manager_cannot_read_employee_outside_managed_org(): void
{
    $this->actingAsManager(1, org: 10);
    $this->getJson('/v1/employees/'.$this->employeeInOrg(20))->assertNotFound();
}
```

- [ ] **Step 3：实现控制器/服务/策略**

- `POST /v1/employees`：Person + Employment + Assignment + Terms 事务创建；工号唯一且不复用；审计。
- `GET /v1/employees/{id}`：as-of 详情 + 教育/履历/联系人/证照/附件/异动历史；P2 字段按 FieldSet 投影。
- `PATCH /v1/employees/{id}`：版本化更新；敏感字段写审计。
- `POST /v1/employees/{id}/files`：私有文件 + 短效下载 token。
- `POST /v1/employees/import`：模板下载 + 异步预检 + 事务分批 + 错误行报告 + 审计。
- 每个写操作要求 CSRF + capability + Idempotency-Key。

- [ ] **Step 4：实现前端 11 个页面**

- 列表：筛选/排序/分页/列设置/批量选择/导入导出。
- 建档/详情/编辑：表单校验、敏感字段说明、保存前差异预览。
- 子页：教育、履历、联系人、证照、历史、文件、异动、导入。
- 全部五态；API 错误统一 `useApiFeedback`；危险操作二次确认。

- [ ] **Step 5：写组件测试 + 真实 E2E**

- 用真实后端 payload 挂载列表/详情/建档页。
- E2E：HR 创建员工→上传附件→查看详情→发起异动；经理越权读被拒；员工仅本人可见。

- [ ] **Step 6：运行门禁并提交**

```bash
cd backend && PATH="/d/code/ai/factoryHR/.tools/php:$PATH" php artisan test --filter=Employee && php artisan capability:audit && php artisan arch:test
cd ../frontend && pnpm typecheck && pnpm lint && pnpm test:unit && pnpm build
cd .. && node scripts/audit_route_coverage.mjs
git add backend frontend/src/views/hr frontend/tests docs/openapi/openapi.yaml
git commit -m "feat(v11): complete employee profile, subresources and import"
```

### Task 2.2：部门管理与岗位管理

**Files:**
- Create: `backend/app/Modules/Organization/Http/Controllers/DepartmentController.php`
- Create: `backend/app/Modules/Organization/Http/Controllers/PositionController.php`
- Create: `frontend/src/views/hr/Department*.vue`（6 页）
- Create: `frontend/src/views/hr/Position*.vue`（6 页）
- Test: org move cycle/impact、headcount、position disable with incumbents、权限负测、E2E.

- [ ] **Step 1：写失败测试**

```php
public function test_moving_department_to_its_descendant_is_rejected(): void
{
    $this->actingAsHr(1);
    $this->postJson('/v1/org-units/10/move', ['new_parent_id' => 12])->assertStatus(422)->assertJsonPath('code', 'CYCLE_DETECTED');
}

public function test_disabling_position_with_active_incumbents_is_rejected(): void
{
    $this->actingAsHr(1);
    $this->patchJson('/v1/positions/5', ['status' => 'DISABLED'])->assertStatus(409)->assertJsonPath('code', 'POSITION_HAS_INCUMBENTS');
}
```

- [ ] **Step 2：实现 API**

- 部门：树/列表/创建/编辑/成员/迁移/编制/导出；迁移前影响预览；循环检测；跨法人显式授权。
- 岗位：目录/编辑/编制/资格/职级/在岗；停用前检查在岗；编制按有效任职计算；岗位与系统角色严格分离。

- [ ] **Step 3：实现前端 12 页**

- 部门：树 + 右详情 + 成员 + 迁移影响预览 + 编制。
- 岗位：目录 + 编辑 + 编制 + 资格 + 职级 + 在岗。
- 五态、权限负测、审计、E2E。

- [ ] **Step 4：提交**

```bash
git commit -am "feat(v11): complete department and position management"
```

### Task 2.3：员工异动与劳动合同

**Files:**
- Create: `frontend/src/views/hr/Movement*.vue`（9 页）
- Create: `frontend/src/views/hr/Contract*.vue`（7 页）
- Modify: `backend/app/Modules/Lifecycle/Http/Controllers/LifecycleController.php`
- Create: `backend/app/Modules/People/Http/Controllers/ContractController.php`
- Test: future-effective transfer、contract renewal chain、sensitive attachment denial、E2E.

- [ ] **Step 1：写失败测试**

```php
public function test_future_transfer_does_not_change_current_assignment_until_effective_date(): void
{
    $case = $this->submitTransfer(effective: '2026-12-01');
    $this->assertSame(10, $this->currentOrg($this->employmentId));
    $this->travelTo('2026-12-01');
    $this->assertSame(20, $this->currentOrg($this->employmentId));
}

public function test_contract_renewal_preserves_old_version_and_cancels_old_alert(): void
{
    $renewal = $this->renewContract($this->contractId, '2027-01-01', '2028-01-01');
    $this->assertDatabaseHas('employment_contract_versions', ['id' => $this->contractId, 'superseded_at' => null]);
    $this->assertDatabaseHas('employment_contract_versions', ['id' => $renewal->id]);
}
```

- [ ] **Step 2：实现 API + 页面**

- 异动：入职/转正/调岗/调薪/借调/离职/日历/详情；审批与生效分离；未来生效；执行失败补偿；审计。
- 合同：台账/录入/详情/续签/变更/到期/模板/附件；版本不可覆盖；到期提醒去重；附件短效下载。

- [ ] **Step 3：提交**

```bash
git commit -am "feat(v11): complete movements and labor contracts"
```

---

## 4. Wave 3：假勤与薪酬（P1）

### Task 3.1：考勤全套

**Files:**
- Create: `frontend/src/views/attendance/*.vue`（10 页）
- Modify: `backend/app/Modules/Time/Http/Controllers/AttendanceController.php`
- Create: `backend/app/Modules/Time/Http/Controllers/AttendanceAdminController.php`
- Test: overnight shift、idempotent punch import、month close lock、exception handling、E2E.

- [ ] **Step 1：写失败测试**

```php
public function test_after_month_close_normal_user_cannot_recalculate(): void
{
    $this->closeMonth('2026-11');
    $this->actingAsEmployee()->postJson('/v1/time/attendance/recalculate', ['month' => '2026-11'])
        ->assertStatus(409)->assertJsonPath('code', 'PERIOD_CLOSED');
}
```

- [ ] **Step 2：实现**

- 我的打卡/明细/日历/规则/班次/排班/补正/异常/月结/设备。
- 原始打卡不可变；重复设备流水幂等；跨夜归属；节假日；月结锁定；解锁审批。

- [ ] **Step 3：提交**

```bash
git commit -am "feat(v11): complete attendance rules, schedules, corrections and close"
```

### Task 3.2：请假、加班、出差

**Files:**
- Create: `frontend/src/views/leave/*.vue`（8 页）
- Create: `frontend/src/views/overtime/*.vue`（6 页）
- Create: `frontend/src/views/travel/*.vue`（7 页）
- Create: `backend/app/Modules/Time/Http/Controllers/OvertimeController.php`
- Create: `backend/app/Modules/Time/Http/Controllers/TravelController.php`
- Test: balance ledger、overlap conflict、overtime single settlement、travel attendance sync、E2E.

- [ ] **Step 1：写失败测试**

```php
public function test_same_overtime_minutes_cannot_be_paid_and_converted_to_timeoff(): void
{
    $case = $this->approveOvertime(minutes: 120);
    $this->settleOvertime($case, 'PAY');
    $this->settleOvertime($case, 'TIMEOFF')->assertStatus(409)->assertJsonPath('code', 'ALREADY_SETTLED');
}
```

- [ ] **Step 2：实现**

- 请假：类型/政策/余额/日历/销假/报表；不可变账本；冲突检测；重复提交阻止。
- 加班：申请/确认/调休/薪资单一结算；锁定后冲正。
- 出差：申请/审批/变更/费用/考勤联动；与请假重叠提示/阻止。

- [ ] **Step 3：提交**

```bash
git commit -am "feat(v11): complete leave, overtime and business travel"
```

### Task 3.3：薪资全套

**Files:**
- Create: `frontend/src/views/payroll/*.vue`（12 页）
- Modify: `backend/app/Modules/Payroll/Http/Controllers/PayrollRunController.php`
- Create: `backend/app/Modules/Payroll/Http/Controllers/PayrollPlanController.php`
- Test: payroll immutability、correction chain、field projection、export token、E2E.

- [ ] **Step 1：写失败测试**

```php
public function test_published_payroll_cannot_be_recalculated_in_place(): void
{
    $run = $this->publishRun();
    $this->postJson('/v1/payroll/runs/'.$run->id.'/calculate')->assertStatus(409)->assertJsonPath('code', 'RUN_IMMUTABLE');
}

public function test_payslip_list_returns_period_and_employee_projection(): void
{
    $this->actingAsEmployeeWithPayslip();
    $this->getJson('/v1/payroll/payslips')->assertOk()->assertJsonPath('data.items.0.period', '2026-11');
}
```

- [ ] **Step 2：实现**

- 薪资项/方案/员工薪资档案/社保参数/输入快照/批次/复核/发放/工资条/调整/报表。
- 发布后不可改；更正批次引用原批次；导出 token；P3 审计。
- 工资条列表/详情补齐 period/employeeName/employeeNo/orgName/legalEntity/payDate/state/lines。

- [ ] **Step 3：提交**

```bash
git commit -am "feat(v11): complete payroll plans, inputs, disbursement and payslips"
```

---

## 5. Wave 4：绩效、招聘、培训（P1）

### Task 4.1：绩效

**Files:**
- Create: `frontend/src/views/performance/*.vue`（10 页）
- Modify: `backend/app/Modules/Talent/Http/Controllers/PerformanceController.php`
- Test: weight 100%、calibration permission、appeal version、E2E.

- [ ] **Step 1：写失败测试**

```php
public function test_template_with_weights_not_summing_to_100_cannot_be_published(): void
{
    $this->postJson('/v1/performance/templates', ['sections' => [['weight' => 80]]])
        ->assertStatus(422)->assertJsonPath('code', 'INVALID_WEIGHT_TOTAL');
}
```

- [ ] **Step 2：实现**

- 周期/模板/指标/目标/评审/反馈/校准/结果/申诉/报表。
- 权重 100%；评分/校准分权；发布后更正版本化；员工仅见授权材料。

- [ ] **Step 3：提交**

```bash
git commit -am "feat(v11): complete performance cycles, calibration and appeals"
```

### Task 4.2：招聘

**Files:**
- Create: `frontend/src/views/recruitment/*.vue`（17 页）
- Modify: `backend/app/Modules/Talent/Http/Controllers/RecruitmentController.php`
- Test: requisition approval gate、candidate dedupe、offer version、convert idempotency、E2E.

- [ ] **Step 1：写失败测试**

```php
public function test_unapproved_requisition_cannot_publish_opening(): void
{
    $this->postJson('/v1/recruitment/requisitions/'.$this->draftRequisition->id.'/openings')->assertStatus(409)->assertJsonPath('code', 'REQUISITION_NOT_APPROVED');
}
```

- [ ] **Step 2：实现**

- 招聘需求/创建/审批/开放/看板/预算/报表。
- 候选人/详情/导入/漏斗/面试/评估/Offer/沟通/人才库/转入职。
- 候选人隐私独立；重复手机号仅提示；Offer 版本；转入职幂等。

- [ ] **Step 3：提交**

```bash
git commit -am "feat(v11): complete recruitment requisitions, candidates and offers"
```

### Task 4.3：培训

**Files:**
- Create: `frontend/src/views/training/*.vue`（9 页）
- Modify: `backend/app/Modules/Talent/Http/Controllers/TrainingController.php`
- Test: duplicate enrollment、capacity concurrency、score privacy、E2E.

- [ ] **Step 1：写失败测试**

```php
public function test_duplicate_enrollment_is_idempotent(): void
{
    $this->postJson('/v1/training/enrollments', $this->payload())->assertCreated();
    $this->postJson('/v1/training/enrollments', $this->payload())->assertStatus(409)->assertJsonPath('code', 'ALREADY_ENROLLED');
}
```

- [ ] **Step 2：实现**

- 计划/课程/场次/报名/签到/考试/反馈/记录/报表。
- 容量并发、截止时间、重复报名、成绩隐私、证书到期提醒。

- [ ] **Step 3：提交**

```bash
git commit -am "feat(v11): complete training programs, sessions and results"
```

---

## 6. Wave 5：平台与系统管理（P1）

### Task 5.1：系统管理（用户/角色/菜单/权限/数据范围/字段策略）

**Files:**
- Create: `frontend/src/views/system/UsersView.vue`
- Create: `frontend/src/views/system/RolesView.vue`
- Create: `frontend/src/views/system/MenusView.vue`
- Create: `frontend/src/views/system/PermissionsView.vue`
- Create: `frontend/src/views/system/DataScopesView.vue`
- Create: `frontend/src/views/system/FieldPoliciesView.vue`
- Modify: `backend/app/Modules/IAM/Http/Controllers/*`
- Test: self-escalation denied、role change immediate、menu without API denied、field policy enforced、E2E.

- [ ] **Step 1：写失败测试**

```php
public function test_admin_cannot_grant_super_admin_to_self_without_second_approval(): void
{
    $this->actingAsAdmin()->postJson('/v1/users/'.$this->admin->id.'/roles', ['role_code' => 'SUPER_ADMIN'])
        ->assertStatus(409)->assertJsonPath('code', 'SELF_ELEVATION_REQUIRES_APPROVAL');
}
```

- [ ] **Step 2：实现**

- 用户：列表/创建/停用/关联员工/分配角色/强制退出/权限预览。
- 角色：列表/复制/授权树/数据范围/字段策略/预览/成员/审计。
- 菜单：树/创建/排序/启停/图标；页面只能映射注册表组件。
- 权限：资源列表/按模块分组/覆盖检测；只读代码能力码，不允许 UI 新建。
- 数据范围：SELF/DEPT/DEPT_TREE/DEPT_SET/COMPANY/自定义白名单。
- 字段策略：敏感字段清单/明文/脱敏/不可见/可编辑/导出列约束。

- [ ] **Step 3：提交**

```bash
git commit -am "feat(v11): complete system management and visual authorization"
```

### Task 5.2：工作日历、字典、文件、通知、审计、报表、自助

**Files:**
- Create: `frontend/src/views/system/CalendarsView.vue`
- Create: `frontend/src/views/system/SettingsView.vue`
- Create: `frontend/src/views/system/FilesView.vue`
- Create: `frontend/src/views/notifications/NotificationsView.vue`
- Create: `frontend/src/views/system/AuditView.vue`
- Create: `frontend/src/views/self-service/*.vue`（9 页）
- Create: `frontend/src/views/benefits/*.vue`（5 页）
- Create: `frontend/src/views/reports/*.vue`（9 页）
- Test: file IDOR、notification dedupe、audit trace、export token、self-service ownership、E2E.

- [ ] **Step 1：写失败测试**

```php
public function test_guessing_file_id_cannot_download_contract(): void
{
    $this->actingAsEmployee()->getJson('/v1/files/'.$this->hrContractFile->id.'/download')->assertNotFound();
}
```

- [ ] **Step 2：实现**

- 日历：法定节假日/自定义休息日/调休工作日/公司日历/地区时区。
- 字典参数：稳定码/引用检查/全局参数/文件密级/访问审计。
- 通知：站内信/待办/阅读/失败重试/模板/投递日志；outbox 幂等。
- 审计：登录/授权/业务变更/筛选/异常/备份/任务监控。
- 报表：预置/构建器/订阅/导出 token；同源授权。
- 自助：我的档案/更正/考勤/假期/申请/工资条/绩效/培训/公告/待办。
- 社保福利：基数方案/缴费项/增减员/福利项目/发放台账。

- [ ] **Step 3：提交**

```bash
git commit -am "feat(v11): complete calendars, settings, notifications, audit, reports and self-service"
```

---

## 7. Wave 6：生产加固与全量验收（P0）

### Task 6.1：真实栈全量 E2E

**Files:**
- Create: `frontend/tests/e2e/real/v11-full.spec.ts`
- Create: `scripts/run_real_stack_e2e.sh`
- Modify: `scripts/verify_release.sh`

- [ ] **Step 1：写全量旅程**

覆盖：
- 17 菜单 × 全部子页：打开、无错误边界、标题/关键控件可见。
- 主链路：入职→合同→调岗→考勤→请假→工资→工资条；招聘→Offer→入职；绩效→申诉；培训→报名。
- 权限负测：员工访问管理页 403；经理越部门 404；无 P3 角色读工资 403；导出 token 失效 404。
- 导出：创建→下载→审计→短期链接过期。

- [ ] **Step 2：运行**

```bash
cd frontend
HRMS_ADMIN_USERNAME=... HRMS_ADMIN_PASSWORD=... pnpm exec playwright test --config playwright.real.config.ts
```

- [ ] **Step 3：提交**

```bash
git add frontend/tests/e2e/real/v11-full.spec.ts scripts/run_real_stack_e2e.sh scripts/verify_release.sh
git commit -m "test(v11): add full real-stack E2E journeys"
```

### Task 6.2：性能、混沌、备份、安全

**Files:**
- Create: `scripts/load_test.js`（k6 或 autocannon）
- Create: `scripts/chaos_scenarios.sh`
- Create: `deploy/scripts/backup_restore_drill.sh`
- Create: `.github/workflows/security.yml`
- Modify: `scripts/verify_release.sh`

- [ ] **Step 1：性能**

- 300 并发，P95<500ms，错误率<0.5%；至少覆盖登录、员工列表、工资批次、审批列表。
- 输出 `evidence/logs/load-<stamp>.json`。

- [ ] **Step 2：混沌**

- ≥12 场景：MySQL 短时不可用、Redis 重启、MinIO 超时、PHP worker 崩溃、队列积压、网络延迟、时钟偏移、磁盘满、迁移中断、并发审批、重复导出、旧 chunk。
- 每场景记录 RTO/RPO 与恢复步骤。

- [ ] **Step 3：备份恢复**

- 全量备份→恢复→对账（人数/工资/账本/审计）；RTO≤2h、RPO≤15min；输出证据。

- [ ] **Step 4：安全**

- CodeQL、依赖审计、gitleaks、容器扫描、渗透测试清单；高危 0 未修复。

- [ ] **Step 5：提交**

```bash
git commit -am "test(v11): add performance, chaos, backup and security gates"
```

### Task 6.3：可追溯发布

**Files:**
- Modify: `deploy/docker/php.Dockerfile`
- Modify: `deploy/compose/docker-compose.yml`
- Modify: `deploy/scripts/deploy_staging.sh`
- Modify: `backend/app/Modules/Platform/Http/Controllers/MetaController.php`
- Modify: `frontend/src/layouts/AppShell.vue`
- Modify: `deploy/scripts/smoke.sh`

- [ ] **Step 1：实现**

- Docker `ARG GIT_SHA`、`BUILD_TIME`；镜像 tag `${RELEASE_ID}-${GIT_SHA}`；记录 digest。
- `/v1/meta` 返回 `commit/build_time/api_version`。
- SPA 注入 `VITE_BUILD_SHA`；页面显示版本。
- smoke 检查 `/`、`/login`、`/workspace`、hashed asset、`/v1/health`、`/v1/meta`；三者 SHA 一致。

- [ ] **Step 2：验证**

```bash
GIT_SHA=$(git rev-parse --short HEAD) docker compose -f deploy/compose/docker-compose.yml build
bash deploy/scripts/smoke.sh --base-url http://127.0.0.1:5174
```

- [ ] **Step 3：提交**

```bash
git commit -am "feat(v11): make releases traceable from image to SPA to meta"
```

### Task 6.4：覆盖矩阵与最终验收

**Files:**
- Create: `docs/audit/v11-coverage-matrix.md`
- Create: `evidence/v11-acceptance-report.md`
- Modify: `README.md`
- Modify: `docs/12-plan-completion-inventory.md`

- [ ] **Step 1：生成矩阵**

- 17 菜单 × 140 业务页 + 14 平台模块 + 6 基础页 = 160 条注册表 × 26 工作包。
- 每项列：页面/API/权限/组件/五态/负测/E2E/证据链接。
- 任何一项缺失，状态必须是 `MISSING`，不得写“已完成”。

- [ ] **Step 2：最终门禁**

```bash
bash scripts/verify_release.sh
```

要求：`PASS` 全绿；`SKIP=0`（除外部系统明确不可用且已记录）；真实栈 E2E 全绿；覆盖矩阵 100%。

- [ ] **Step 3：提交**

```bash
git add docs/audit/v11-coverage-matrix.md evidence/v11-acceptance-report.md README.md docs/12-plan-completion-inventory.md
git commit -m "docs(v11): publish full coverage matrix and acceptance evidence"
```

---

## 8. 验证清单（每个 Wave 结束）

```bash
# 后端
cd backend
export PATH="/d/code/ai/factoryHR/.tools/php:$PATH"
php artisan test
vendor/bin/pint --test
phpstan analyse
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
node scripts/audit_route_coverage.mjs

# 真实栈
HRMS_REAL_STACK=1 bash scripts/verify_release.sh
```

**Wave 出口标准：**

| Wave | 必须通过 |
|---|---|
| 0 | 授权快照测试、NO_ACTIVE_EMPLOYMENT 测试、错误边界测试、真实栈基线 12 路由 |
| 1 | OpenAPI schema 测试、生成类型 diff、`as never=0`、注册表 160 项、96+ 能力码全登记、角色-页面矩阵 160/160、权限负测全绿、ADR-0019–0027 |
| 2 | 5 个组织人事菜单全部子页、员工/部门/岗位/异动/合同 E2E、权限负测 |
| 3 | 考勤/请假/加班/出差/薪资全部子页、主链路 E2E、封账/冲正 |
| 4 | 绩效/招聘/培训全部子页、招聘→Offer→入职、绩效申诉、培训幂等 |
| 5 | 14 个平台模块、可视化授权、审计可追溯、自助门户 |
| 6 | 140/140 业务页 + 14/14 平台模块 + 6/6 基础页、17/17 菜单、26/26 工作包、性能/混沌/备份/安全、可追溯发布 |

---

## 9. 风险与 Plan Adjustment 规则

- 任何任务发现需要改本计划之外的文件，暂停并输出 `Plan Adjustment`，说明原因、影响、替代方案；不得静默扩大范围。
- 任何门禁连续失败 3 次，停止修补，回到根因分析；不得用 skip/放宽断言换取绿色。
- 真实栈不可用时，记录 `SKIP` 与原因，不得伪造 PASS；Wave 6 不允许 SKIP。
- 文档冲突未裁决前，不进入 Wave 2；否则 160 条页面条目会各自解释一套权限和实体。
- 每个 Wave 完成后由独立 reviewer 做 code review + 安全 review + 覆盖审计；review 不通过不进入下一 Wave。

---

## 10. 执行方式

本计划默认使用 **Subagent-Driven Development**：每个 Task 派一个 fresh subagent，主 agent 在两阶段 review（spec compliance + code quality）后合并。也可选择 Inline Execution（当前会话批量执行 + checkpoint）。

**推荐顺序：** Wave 0 → Wave 1 → Wave 2 → Wave 3 → Wave 4 → Wave 5 → Wave 6。每个 Wave 内按 Task 顺序执行；Task 之间不并行修改同一文件。
