# FactoryHR V2.1 整改第 4 册：平台收敛、真实栈验收与发布 — 详细实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 对第 3 册已独立实现的 14 个平台模块与治理页做一致性确认和必要补强，退役巨型共享壳与死代码，在真实 MySQL/Redis/队列/浏览器栈上通过权限、性能、迁移、备份恢复与 UAT，形成可发布或明确阻断的最终验收结论。

**Architecture:** 本册不重复实现业务页；以第 3 册的独立页面为对象，补足账号安全、自助门户、设置、通知审计等跨页能力，验证平台目录与页面映射一致，然后按“全量门禁 → 真实栈 UAT → 性能/故障 → 备份回滚 → 发布结论”验收。旧共享壳只做退出，不保留业务分支。

**Tech Stack:** Laravel 11 / PHP 8.4 / MySQL 8.4 / Redis / Docker Compose / Vue 3.5 / TypeScript 5.9 / Element Plus 2.14 / Playwright / k6 或现有 load probe / OpenAPI 3.1。

## Global Constraints

- 规范优先级：用户裁决 > `04 专项补充书` > `01/02 V2.1` > `03 路由矩阵` > V1.1/历史 ADR。
- 平台模块必须有真实 API 与真实交互；不得保留“接口说明板”或静态假页面。
- 巨型共享壳只允许保留布局职责；不得以 `mode`/`config` 分支承载多业务域。
- 每个 `/v1` 路由必须有 capability 守卫；公开白名单仅限明确条目。
- 用户界面禁止英文枚举、接口路径、表名、字段档位、开发缺口或演示文案。
- 真实栈必须使用 MySQL 8.4、Redis、队列与浏览器；mock 通过不能替代真实栈。
- 迁移/备份/恢复/发布必须保留原始日志；未执行或失败标 `NOT RUN`/`FAIL`。
- 金额整数最小单位；时间 UTC 存储；业务日期按 `company_profile.timezone`。
- 测试与种子只用合成数据；禁止提交 `.env`、凭据、SQLite 数据库或真实员工数据。

---

## 0. 执行顺序与依赖

```text
Task 1  平台页面与目录一致性确认
Task 2  账号安全、会话与 MFA 可选能力
Task 3  审批流程中心一致性确认
Task 4  自助门户与员工工作任务
Task 5  福利、日历、字典/参数/文件一致性确认
Task 6  消息通知与日志审计一致性确认
Task 7  平台共享壳退役与死代码清理
Task 8  全量门禁与契约复核
Task 9  真实栈部署与五角色 UAT
Task 10 性能、并发、故障恢复
Task 11 备份恢复与迁移回滚演练
Task 12 发布检查单、UAT 签认与最终结论
```

依赖关系：

- Task 1 是 Task 2–7 的边界依据，输入是第 3 册 Task 17 的平台页交付。
- Task 2/4 补足第 3 册未覆盖的账号安全与自助门户；其余页面只做一致性确认与缺口修复，不重复实现。
- Task 7 必须在 Task 1–6 确认页面独立后执行；否则会拆出空壳。
- Task 8 依赖第 1–3 册与 Task 1–7 全部产物。
- Task 9 依赖真实镜像、种子、发布脚本与五类账号。
- Task 10/11 依赖 Task 9 的稳定环境。
- Task 12 只能使用 Task 8–11 的实际输出。

---

### Task 1: 平台页面与目录一致性确认

**Files:**
- Create: `docs/audit/2026-11-26-platform-module-disposition.md`
- Create: `frontend/src/modules/platform/README.md`
- Create: `backend/tests/Support/PlatformScenario.php`（`userWithRole(string)`、`manager()`、`auditAdmin()`、`settingsAdmin()`；真实 User + Role + AuthorityBinding）
- Modify: `frontend/src/modules/platform/catalog.ts`
- Modify: `frontend/src/modules/platform/pages/index.ts`
- Test: `frontend/tests/unit/platformCatalog.spec.ts`

**Interfaces:**
- Consumes: 第 3 册 Task 17 的 12 个平台页面、第 1 册 160 条处置表、当前 36 个 platform 页面、`PlatformWorkspace.vue`。
- Produces: 14 个平台条目 + 仪表盘/报表/公告的最终模块归属；每个条目到第 3 册真实页面的映射；共享壳退场顺序；不再新增 `mode` 分支。

- [ ] **Step 1: 写模块清单失败测试**

```ts
import { describe, expect, it } from 'vitest'
import { PLATFORM_MODULES } from '@/modules/platform/catalog'

describe('platform catalog', () => {
  it('has one unique entry per governance task and no mode-based container', () => {
    const keys = PLATFORM_MODULES.map((module) => module.key)
    expect(new Set(keys).size).toBe(keys.length)
    expect(keys).toContain('system-users')
    expect(keys).toContain('system-roles')
    expect(keys).toContain('workflow')
    expect(keys).not.toContain('platform-workspace')
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

```bash
cd frontend && pnpm exec vitest run tests/unit/platformCatalog.spec.ts
```

Expected: FAIL，当前目录仍以共享 `mode` 容器或旧键组织。

- [ ] **Step 3: 写处置表**

`docs/audit/2026-11-26-platform-module-disposition.md` 逐项列出：

```markdown
| 原条目 | 第 3 册实现页面 | 独立 API | 权限 | 退场动作 | 证据 |
|---|---|---|---|---|---|
| /system/users | `modules/platform/iam/pages/UserAdminPage.vue` | `/v1/users...` | `iam.user.*` | 删除 PlatformWorkspace 分支 | UserRoleAssignmentApiTest |
| /system/roles | `modules/platform/iam/pages/RoleAdminPage.vue` | `/v1/roles...` | `iam.role.manage` | 删除 PlatformWorkspace 分支 | RolePagesApiTest |
| /system/audit | `modules/platform/settings/pages/AuditLogPage.vue` | `/v1/auth/events` | `iam.audit.read` | 删除 PlatformWorkspace 分支 | PlatformWorkspaceApiTest |
```

至少覆盖：账号安全、用户、角色、菜单/页面、操作权限、数据范围、字段策略、审批流程、自助门户、福利、日历、字典/参数/文件、通知、审计，以及仪表盘、报表、公告的归属。每行的“第 3 册实现页面”必须指向真实文件；账号安全与自助门户若尚未实现，标为 `Task 2`/`Task 4` 并在该任务完成时回填。

- [ ] **Step 4: 定义模块接口**

在 `frontend/src/modules/platform/catalog.ts` 中每个模块只声明：

```ts
export type PlatformModule = {
  key: string
  label: string
  pageCode: string
  path: string
  capability: string
  owner: 'iam' | 'workflow' | 'settings' | 'reporting' | 'engagement'
}
```

不得声明 `mode`、`configKey` 或大段业务分支。

- [ ] **Step 5: 运行测试**

```bash
cd frontend && pnpm exec vitest run tests/unit/platformCatalog.spec.ts
```

Expected: PASS，1 test。

- [ ] **Step 6: Commit**

```bash
git add docs/audit/2026-11-26-platform-module-disposition.md frontend/src/modules/platform/README.md frontend/src/modules/platform/catalog.ts frontend/src/modules/platform/pages/index.ts frontend/tests/unit/platformCatalog.spec.ts
git commit -m "docs(platform): define module dispositions and shell exit"
```

---

### Task 2: 账号安全、会话与 MFA 可选能力

**Files:**
- Modify: `frontend/src/modules/platform/pages/AccountSecurityPage.vue`
- Create: `frontend/src/modules/platform/components/ChangePasswordDialog.vue`
- Create: `frontend/src/modules/platform/components/SessionList.vue`
- Modify: `backend/app/Modules/IAM/Http/Controllers/AuthController.php`
- Modify: `backend/app/Modules/IAM/Http/Controllers/MfaController.php`
- Test: `backend/tests/Feature/Authorization/AccountSecurityTest.php`
- Test: `frontend/tests/unit/AccountSecurityPage.spec.ts`

**Interfaces:**
- Consumes: 改密、登出全部、会话列表、可选 MFA 开关 API。
- Produces: 独立账号安全页；默认不显示 MFA 入口；会话可撤销；改密后旧会话失效。

- [ ] **Step 1: 写改密失效测试**

```php
public function test_password_change_revokes_other_sessions(): void
{
    $user = $this->userWithRole('HR_ADMIN');
    $this->actingAs($user)->postJson('/v1/auth/password', [
        'current_password' => 'OldPassword!2026',
        'password' => 'NewPassword!2026',
        'password_confirmation' => 'NewPassword!2026',
    ])->assertOk();

    $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->where('revoked_at', null)->count());
}
```

- [ ] **Step 2: 实现页面**

- 账号信息、修改密码、当前会话、登出全部。
- 仅当 `iam.mfa.enabled=true` 时显示 MFA 启用/恢复码入口。
- 不显示内部 token、能力码或接口路径。
- 密码错误、会话过期、CSRF 失败显示中文可操作提示。

- [ ] **Step 3: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=AccountSecurityTest
cd ../frontend && pnpm exec vitest run tests/unit/AccountSecurityPage.spec.ts
```

Expected: PASS；改密后其他会话失效；MFA 默认隐藏。

- [ ] **Step 4: Commit**

```bash
git add frontend/src/modules/platform/pages/AccountSecurityPage.vue frontend/src/modules/platform/components/ChangePasswordDialog.vue frontend/src/modules/platform/components/SessionList.vue backend/app/Modules/IAM/Http/Controllers/AuthController.php backend/app/Modules/IAM/Http/Controllers/MfaController.php backend/tests/Feature/Authorization/AccountSecurityTest.php frontend/tests/unit/AccountSecurityPage.spec.ts
git commit -m "feat(platform): rebuild account security page"
```

---

### Task 3: 审批流程中心一致性确认

**Files:**
- Verify: `frontend/src/modules/platform/workflow/pages/WorkflowCenterPage.vue`
- Verify: `frontend/src/modules/platform/workflow/pages/WorkflowCaseDetailPage.vue`
- Verify: `frontend/src/modules/platform/workflow/components/TaskList.vue`
- Verify: `frontend/src/modules/platform/workflow/components/CaseTimeline.vue`
- Modify（仅补缺口）: `backend/app/Modules/Workflow/Http/Controllers/CaseController.php`
- Modify（仅补缺口）: `backend/app/Modules/Workflow/Http/Controllers/DecisionController.php`
- Test: `backend/tests/Feature/Workflow/WorkflowCenterTest.php`（extends `Tests\Support\WorkflowCenterScenario`）
- Test: `frontend/tests/e2e/real/workflow-center.spec.ts`

**Interfaces:**
- Consumes: 第 3 册 Task 17 已实现的 `/workflow` 工作台与详情。
- Produces: 审批中心一致性确认；批准/驳回独立能力；重复决策幂等；缺口修复记录。

- [ ] **Step 1: 写重复决策测试**

```php
public function test_same_decision_is_idempotent_and_conflicting_decision_is_rejected(): void
{
    $case = $this->pendingCaseFor($this->manager());
    $key = 'decision-'.Str::uuid();

    $this->actingAs($this->manager())->postJson("/v1/cases/{$case->id}/decisions", ['decision' => 'APPROVE'], ['Idempotency-Key' => $key])->assertOk();
    $this->actingAs($this->manager())->postJson("/v1/cases/{$case->id}/decisions", ['decision' => 'APPROVE'], ['Idempotency-Key' => $key])->assertOk();
    $this->actingAs($this->manager())->postJson("/v1/cases/{$case->id}/decisions", ['decision' => 'REJECT'], ['Idempotency-Key' => 'other'])->assertStatus(409);
}
```

- [ ] **Step 2: 实现中心**

- 待办/已办/我发起三个视图；按业务类型筛选。
- 详情显示业务摘要、审批链、决策、时间线、附件（按权限）。
- 批准/驳回/撤回/委派分别受能力与对象范围约束。
- 不显示原始 Case JSON 或能力码。

- [ ] **Step 3: E2E**

```ts
test('manager approves a leave case and it leaves pending list', async ({ page }) => {
  await loginAs(page, 'demo.manager')
  await page.goto('/workflow')
  await page.getByRole('button', { name: '批准' }).first().click()
  await page.getByRole('button', { name: '确认' }).click()
  await expect(page.getByText('已批准')).toBeVisible()
})
```

- [ ] **Step 4: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=WorkflowCenterTest
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/workflow-center.spec.ts
```

Expected: 批准/驳回/撤回/委派/重复决策通过；越权 403。

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/platform/workflow/pages/WorkflowCenterPage.vue frontend/src/modules/platform/workflow/pages/WorkflowCaseDetailPage.vue frontend/src/modules/platform/workflow/components/TaskList.vue frontend/src/modules/platform/workflow/components/CaseTimeline.vue backend/app/Modules/Workflow/Http/Controllers/CaseController.php backend/app/Modules/Workflow/Http/Controllers/DecisionController.php backend/tests/Feature/Workflow/WorkflowCenterTest.php frontend/tests/e2e/real/workflow-center.spec.ts
git commit -m "feat(workflow): rebuild approval center"
```

---

### Task 4: 自助门户与员工工作任务

**Files:**
- Create: `frontend/src/modules/platform/self-service/pages/SelfServicePage.vue`
- Create: `frontend/src/modules/platform/self-service/components/MyTasks.vue`
- Create: `frontend/src/modules/platform/self-service/components/MyProfile.vue`
- Modify: `backend/app/Modules/IAM/Http/Controllers/WorkspaceController.php`
- Test: `backend/tests/Feature/IAM/SelfServiceWorkspaceTest.php`
- Test: `frontend/tests/e2e/real/self-service.spec.ts`

**Interfaces:**
- Consumes: `/v1/me/workspace`、本人档案、假勤/工资条/培训等自助入口。
- Produces: 普通员工只看到本人任务与授权字段；不暴露后台菜单；不显示他人数据。

- [ ] **Step 1: 写自助数据范围测试**

```php
public function test_employee_workspace_only_returns_self_data(): void
{
    $employee = $this->employeeWithUser();
    $response = $this->actingAs($employee->user)->getJson('/v1/me/workspace');

    $response->assertOk();
    $this->assertSame($employee->id, $response->json('data.employee.id'));
    $this->assertArrayNotHasKey('salary_minor', $response->json('data.employee'));
}
```

- [ ] **Step 2: 实现自助门户**

- 我的任务：待办、待读公告、待确认加班。
- 我的档案：只显示授权字段；敏感字段遮罩。
- 我的假勤/工资条/培训入口按能力显示。
- 不出现“系统管理”菜单；不显示能力码/接口路径。

- [ ] **Step 3: E2E**

```ts
test('employee sees only self service entries', async ({ page }) => {
  await loginAs(page, 'demo.employee')
  await page.goto('/self-service')
  await expect(page.getByText('我的待办')).toBeVisible()
  await expect(page.getByText('系统管理')).toHaveCount(0)
})
```

- [ ] **Step 4: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=SelfServiceWorkspaceTest
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/self-service.spec.ts
```

Expected: 仅本人数据；无后台菜单；敏感字段不返回。

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/platform/self-service/pages/SelfServicePage.vue frontend/src/modules/platform/self-service/components/MyTasks.vue frontend/src/modules/platform/self-service/components/MyProfile.vue backend/app/Modules/IAM/Http/Controllers/WorkspaceController.php backend/tests/Feature/IAM/SelfServiceWorkspaceTest.php frontend/tests/e2e/real/self-service.spec.ts
git commit -m "feat(platform): rebuild self-service portal"
```

---

### Task 5: 福利、日历、字典/参数/文件一致性确认

**Files:**
- Verify: `frontend/src/modules/platform/settings/pages/CalendarSettingsPage.vue`
- Verify: `frontend/src/modules/platform/settings/pages/DictionarySettingsPage.vue`
- Create（若第 3 册未覆盖）: `frontend/src/modules/platform/settings/pages/BenefitsPage.vue`
- Create: `backend/tests/Feature/Platform/SettingsWorkspaceTest.php`
- Modify（仅补缺口）: `backend/app/Modules/Platform/Http/Controllers/DictionaryAdminController.php`
- Modify（仅补缺口）: `backend/app/Modules/Time/Http/Controllers/CalendarAdminController.php`
- Test: `frontend/tests/e2e/real/settings-workspace.spec.ts`

**Interfaces:**
- Consumes: 第 3 册 Task 17 的日历/字典页；福利、参数、文件 API。
- Produces: 设置工作台一致性确认；公司日历不出现法人/租户参数；字典/参数变更审计；未覆盖项补口。

- [ ] **Step 1: 写公司日历单例测试**

```php
public function test_calendar_publish_uses_single_company_profile_without_legal_entity(): void
{
    // SettingsWorkspaceTest extends TimeHttpTestCase and uses its real actor seam.
    // Add settingsAdmin(): TimeActor (SYSTEM_ADMIN with time.calendar.manage)
    // plus `use Illuminate\Support\Facades\Schema;` to that test class.
    $this->actAsHttp($this->settingsAdmin());

    // V2 Task 4 removes the path parameter; the body keeps the real
    // CalendarPublishRequest shape (timezone/workweek are required).
    $this->postJson('/v1/system/calendars/publish', [
        'timezone' => 'Asia/Shanghai',
        'workweek' => [1, 2, 3, 4, 5],
        'standard_day_minutes' => 480,
        'holidays' => [],
    ], ['Idempotency-Key' => 'calendar-publish-single-company'])
        ->assertOk()
        ->assertJsonPath('data.timezone', 'Asia/Shanghai')
        ->assertJsonMissingPath('data.legal_entity_id');

    // The response change is owned by V2 Task 4: CalendarVersion must drop the
    // required legal_entity_id and CalendarAdminController must stop returning it.
    // OpenAPI and the generated frontend types must be updated in that task.

    $this->assertFalse(Schema::hasTable('legal_entities'));
}
```

- [ ] **Step 2: 核对并补齐设置页**

- 核对第 3 册的日历页：年份、工作日、节假日、发布；时区来自 `company_profile`。
- 核对第 3 册的字典/参数页：列表 + 编辑弹窗；变更写审计；文件管理独立区域。
- 若福利页尚未实现，按“方案、参保、员工分配、字段策略显示金额”补齐。
- 所有保存动作受 `system.*.manage` 或 `time.calendar.manage` 控制。

缺口必须落到第 3 册 Task 17 的同一页面文件，不新建第二套设置组件。

- [ ] **Step 3: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=SettingsWorkspaceTest
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/settings-workspace.spec.ts
```

Expected: 单公司日历、字典/参数/福利闭环；无旧概念。

- [ ] **Step 4: Commit**

```bash
git add frontend/src/modules/platform/settings/pages/CalendarSettingsPage.vue frontend/src/modules/platform/settings/pages/DictionarySettingsPage.vue backend/tests/Feature/Platform/SettingsWorkspaceTest.php backend/app/Modules/Platform/Http/Controllers/DictionaryAdminController.php backend/app/Modules/Time/Http/Controllers/CalendarAdminController.php frontend/tests/e2e/real/settings-workspace.spec.ts
# 若第 3 册未实现福利页，再追加：git add frontend/src/modules/platform/settings/pages/BenefitsPage.vue
git commit -m "feat(platform): rebuild settings workspaces"
```

---

### Task 6: 消息通知与日志审计一致性确认

**Files:**
- Verify: `frontend/src/modules/platform/settings/pages/NotificationCenterPage.vue`
- Verify: `frontend/src/modules/platform/settings/pages/AuditLogPage.vue`
- Modify（仅补缺口）: `backend/app/Modules/Platform/Http/Controllers/NotificationAdminController.php`
- Modify（仅补缺口）: `backend/app/Modules/IAM/Http/Controllers/AuthorizationAdminController.php`
- Test: `backend/tests/Feature/Platform/NotificationAuditTest.php`
- Test: `frontend/tests/e2e/real/notification-audit.spec.ts`

**Interfaces:**
- Consumes: 第 3 册 Task 17 的通知中心与审计页、通知/审计 API。
- Produces: 通知与审计一致性确认；敏感操作可检索；导出审计受独立能力；缺口修复记录。

- [ ] **Step 1: 写审计检索权限测试**

```php
public function test_only_audit_capability_can_read_sensitive_events(): void
{
    $this->actingAs($this->hrAdmin())->getJson('/v1/auth/events')->assertStatus(403);
    $this->actingAs($this->auditAdmin())->getJson('/v1/auth/events')->assertOk();
}
```

- [ ] **Step 2: 核对并补齐页面**

- 通知：未读/全部、业务类型、阅读状态；不显示内部事件 ID 给普通员工。
- 审计：时间、操作者、动作、对象、结果、traceId；筛选与导出按能力。
- 敏感字段访问、权限变更、登录失败必须可检索。

若第 3 册页面缺字段或缺少导出，直接修改同一页面文件，不另建平行页面。

- [ ] **Step 3: E2E**

```ts
test('audit admin filters permission change events', async ({ page }) => {
  await loginAs(page, 'demo.admin')
  await page.goto('/system/audit')
  await page.getByLabel('事件类型').click()
  await page.getByRole('option', { name: '权限变更' }).click()
  await expect(page.getByText('role.authorization.changed')).toHaveCount(0)
  await expect(page.getByText('权限变更')).toBeVisible()
})
```

- [ ] **Step 4: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=NotificationAuditTest
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/notification-audit.spec.ts
```

Expected: 审计权限隔离；通知与审计可检索；无内部代码外露。

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/platform/settings/pages/NotificationCenterPage.vue frontend/src/modules/platform/settings/pages/AuditLogPage.vue backend/app/Modules/Platform/Http/Controllers/NotificationAdminController.php backend/app/Modules/IAM/Http/Controllers/AuthorizationAdminController.php backend/tests/Feature/Platform/NotificationAuditTest.php frontend/tests/e2e/real/notification-audit.spec.ts
git commit -m "feat(platform): rebuild notification and audit pages"
```

---

### Task 7: 平台共享壳退役与死代码清理

**Files:**
- Delete: `frontend/src/modules/platform/components/PlatformWorkspace.vue`
- Delete: `frontend/src/modules/people/components/PeopleWorkspace.vue`
- Delete: `frontend/src/modules/talent/components/TalentWorkspacePage.vue`
- Delete: `frontend/src/modules/timepay/legacyBridge.ts`
- Delete: `frontend/src/views/people/*`、`frontend/src/views/talent/*`、`frontend/src/views/timepay/*`（确认无引用后）
- Modify: `frontend/src/modules/*/pages/index.ts`
- Modify: `frontend/src/router/pageComponents.ts`
- Test: `scripts/audit_frontend_modules.mjs`

**Interfaces:**
- Consumes: Task 2–6 独立页面、第 3 册业务页。
- Produces: 无巨型业务壳、无旧 views 副本、无 `mode` 业务分支；页面组件映射唯一。

- [ ] **Step 1: 写死代码扫描测试**

创建 `scripts/tests/no_dead_workspaces.test.mjs`：

```js
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { readFileSync, existsSync } from 'node:fs'

test('legacy workspace containers are gone', () => {
  for (const file of [
    'frontend/src/modules/platform/components/PlatformWorkspace.vue',
    'frontend/src/modules/people/components/PeopleWorkspace.vue',
    'frontend/src/modules/talent/components/TalentWorkspacePage.vue',
  ]) {
    assert.equal(existsSync(file), false, `${file} still exists`)
  }
})
```

- [ ] **Step 2: 运行测试确认失败**

```bash
node --test scripts/tests/no_dead_workspaces.test.mjs
```

Expected: FAIL，三个文件仍存在。

- [ ] **Step 3: 逐页迁移剩余引用**

在删除前运行：

```bash
rg -n 'PlatformWorkspace|PeopleWorkspace|TalentWorkspacePage|legacyBridge|views/people|views/talent|views/timepay' frontend/src frontend/tests
```

每个引用必须落到独立页面或删除；不得用 re-export 保留旧壳。

- [ ] **Step 4: 删除与更新映射**

删除旧文件；更新 `pageComponents.ts` 与各 `pages/index.ts`；运行：

```bash
node scripts/audit_page_components.mjs
node scripts/audit_frontend_modules.mjs
node --test scripts/tests/no_dead_workspaces.test.mjs
```

Expected: 三个检查通过；没有悬空 import。

- [ ] **Step 5: 跑前端全量**

```bash
cd frontend && pnpm typecheck && pnpm lint && pnpm test:unit
```

Expected: 全绿；若失败，修复引用而不是恢复旧壳。

- [ ] **Step 6: Commit**

```bash
git add -A frontend/src/modules frontend/src/views frontend/src/router/pageComponents.ts scripts/tests/no_dead_workspaces.test.mjs
git commit -m "refactor(ui): retire legacy workspace containers"
```

---

### Task 8: 全量门禁与契约复核

**Files:**
- Create: `docs/audit/2026-11-26-v2-full-gates.md`
- Modify: `package.json`
- Modify: `AGENTS.md`

**Interfaces:**
- Consumes: 第 1–3 册、Task 1–7 的全部门禁。
- Produces: 一次完整门禁运行记录；新增门禁纳入 AGENTS.md 提交前检查。

- [ ] **Step 1: 运行后端全部门禁**

```bash
cd backend
../.tools/php/php.exe artisan test --compact
../.tools/php/php.exe vendor/bin/pint --test
../.tools/php/php.exe vendor/bin/phpstan analyse --no-progress
../.tools/php/php.exe artisan capability:audit
../.tools/php/php.exe artisan arch:test
```

Expected: 全部通过。任何失败记录到 `docs/audit/2026-11-26-v2-full-gates.md`，不得跳过。

- [ ] **Step 2: 运行前端与契约门禁**

```bash
cd ..
pnpm typecheck
pnpm lint
pnpm test:unit
node scripts/audit_page_components.mjs
node scripts/audit_frontend_modules.mjs
node scripts/audit_action_manifest.mjs
node scripts/audit_single_company.mjs
node scripts/audit_page_model.mjs
node scripts/audit_ui_primitives.mjs
node scripts/audit_user_copy.mjs
node deploy/scripts/openapi-lint.mjs
pnpm openapi:validate
pnpm openapi:check
```

Expected: 全部通过；若某个旧页面仍失败，修复或记录阻断，不得放宽容忍。

- [ ] **Step 3: 更新 AGENTS.md 提交前门禁**

把新增门禁追加到第 9 条：

```bash
node scripts/audit_single_company.mjs
node scripts/audit_action_manifest.mjs
node scripts/audit_page_model.mjs
node scripts/audit_ui_primitives.mjs
node scripts/audit_user_copy.mjs
node scripts/audit_frontend_modules.mjs
```

- [ ] **Step 4: 写门禁记录**

`docs/audit/2026-11-26-v2-full-gates.md` 必须包含：

- 每条命令、退出码、关键输出尾部；
- 失败项与修复提交；
- 未执行项与原因；
- 最终 `PASS/FAIL/NOT RUN` 汇总。

- [ ] **Step 5: Commit**

```bash
git add docs/audit/2026-11-26-v2-full-gates.md package.json AGENTS.md
git commit -m "test(gates): record full V2.1 gate run"
```

---

### Task 9: 真实栈部署与五角色 UAT

**Files:**
- Create: `deploy/scripts/v2_real_stack_uat.sh`
- Create: `docs/runbooks/2026-11-26-v2-uat.md`
- Modify: `deploy/scripts/verify_real_stack.sh`
- Test: `frontend/tests/e2e/real/v2-uat.spec.ts`

**Interfaces:**
- Consumes: Docker Compose、MySQL 8.4、Redis、队列、合成种子、五类账号。
- Produces: 固定版本真实栈；五角色 UAT 记录；可重跑脚本。

- [ ] **Step 1: 写 UAT 脚本**

```bash
#!/usr/bin/env bash
set -euo pipefail

: "${HRMS_FRONTEND_URL:?set HRMS_FRONTEND_URL}"
: "${DEMO_SEED_PASSWORD:?set DEMO_SEED_PASSWORD (docker_up.sh contract, >=12 chars)}"

export DEMO_SEED_PASSWORD="${DEMO_SEED_PASSWORD}"
export HRMS_DEMO_PASSWORD="${DEMO_SEED_PASSWORD}"
bash deploy/scripts/docker_up.sh --demo
bash deploy/scripts/verify_real_stack.sh --base-url "${HRMS_FRONTEND_URL}"
cd frontend
pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/v2-uat.spec.ts
```

脚本必须失败即停；不得用 `|| true` 吞掉失败。

- [ ] **Step 2: 准备合成账号**

UAT 种子至少包含：

| 用户名 | 角色 | 数据范围 | 用途 |
|---|---|---|---|
| `demo.admin` | 系统管理员 | COMPANY | 系统配置/用户角色 |
| `demo.hr` | 人事管理员 | COMPANY | 员工/组织/合同 |
| `demo.manager` | 部门经理 | ORG_SUBTREE | 本部门审批/查看 |
| `demo.employee` | 普通员工 | SELF | 自助 |
| `demo.payroll` | 工资专员 | PAYROLL_RUN | 薪资 |

- [ ] **Step 3: 写五角色 E2E**

`v2-uat.spec.ts` 至少覆盖：

1. 管理员登录 → 创建用户 → 分配角色 → 修改角色权限 → 权限预览。
2. HR 登录 → 新增员工 → 详情 → 异动 → 合同 → 请假。
3. 经理登录 → 审批下属请假 → 不能查看其他部门。
4. 员工登录 → 自助申请 → 仅本人数据。
5. 工资专员登录 → 薪资试算/复核 → 不能发放（若未授权）→ 导出受字段限制。

- [ ] **Step 4: 运行真实栈**

```bash
HRMS_FRONTEND_URL=http://127.0.0.1:5174 \
DEMO_SEED_PASSWORD='<合成测试密码>' \
bash deploy/scripts/v2_real_stack_uat.sh
```

Expected: 所有服务就绪；五角色 UAT 通过；失败时保留容器日志、截图与 trace。

- [ ] **Step 5: 写 UAT 记录**

`docs/runbooks/2026-11-26-v2-uat.md` 记录：

- 镜像版本、commit、数据库版本；
- 种子账号（不写密码）；
- 每条 UAT 场景结果与证据路径；
- 未通过项、影响与修复负责人。

- [ ] **Step 6: Commit**

```bash
git add deploy/scripts/v2_real_stack_uat.sh deploy/scripts/verify_real_stack.sh docs/runbooks/2026-11-26-v2-uat.md frontend/tests/e2e/real/v2-uat.spec.ts
git commit -m "test(deploy): add real-stack five-role UAT"
```

---

### Task 10: 性能、并发与故障恢复

**Files:**
- Create: `docs/audit/2026-11-26-v2-performance.md`
- Create: `deploy/scripts/v2_load_probe.mjs`
- Modify: `deploy/scripts/load_probe.mjs`
- Test: `deploy/scripts/v2_load_probe.mjs`

**Interfaces:**
- Consumes: 真实栈、1k/10k 合成员工、薪资批次、报表导出。
- Produces: 列表、导出、试算、审批的延迟/吞吐基线；并发与故障恢复结论。

- [ ] **Step 1: 写负载探针**

```js
#!/usr/bin/env node
const base = process.env.HRMS_API_URL ?? 'http://127.0.0.1:8080'
// OpenAPI declares sessionCookie + csrfHeader, not bearer tokens.
const cookie = process.env.HRMS_SESSION_COOKIE
const csrf = process.env.HRMS_CSRF_TOKEN
if (!cookie || !csrf) throw new Error('set HRMS_SESSION_COOKIE and HRMS_CSRF_TOKEN')

const scenarios = [
  { name: 'employee-list-page-1', url: '/v1/employees?page=1&page_size=20', method: 'GET' },
  { name: 'report-export', url: '/v1/reporting/exports', method: 'POST',
    body: JSON.stringify({
      reportCode: 'WORKFORCE_HEADCOUNT',
      purpose: 'BUSINESS_OPERATION',
      fields: ['employee_no', 'org_path'],
    }) },
]

for (const scenario of scenarios) {
  const started = performance.now()
  const response = await fetch(`${base}${scenario.url}`, {
    method: scenario.method,
    headers: {
      Accept: 'application/json',
      Cookie: cookie,
      'X-CSRF-Token': csrf,
      ...(scenario.body === undefined ? {} : { 'Content-Type': 'application/json' }),
    },
    ...(scenario.body === undefined ? {} : { body: scenario.body }),
  })
  const elapsed = Math.round(performance.now() - started)
  if (scenario.name === 'report-export' && response.status !== 202) {
    throw new Error(`report-export expected 202, got ${response.status}`)
  }
  console.log(JSON.stringify({ scenario: scenario.name, status: response.status, ms: elapsed }))
}
```

- [ ] **Step 2: 运行性能基线**

```bash
HRMS_API_URL=http://127.0.0.1:8080 \
HRMS_SESSION_COOKIE='hrms_session=<真实会话>' \
HRMS_CSRF_TOKEN='<真实 CSRF>' \
node deploy/scripts/v2_load_probe.mjs
```

记录：
- 员工列表 1k/10k 分页 P50/P95；
- 报表导出 1k/10k 行耗时；
- 薪资 1k 人试算耗时；
- 数据库慢查询与索引命中。

- [ ] **Step 3: 并发与故障测试**

覆盖：

- 同一员工重复建档幂等键并发；
- 同一角色授权并发保存；
- 薪资批次重复发布；
- Redis 不可用时会话/限流降级；
- 队列积压时 Outbox 重试与幂等。

- [ ] **Step 4: 写报告**

`docs/audit/2026-11-26-v2-performance.md` 必须区分：

- 已测量；
- 未测量与原因；
- 是否满足既定 SLO；
- 瓶颈与后续容量建议。

不得用开发环境小数据推断生产性能。

- [ ] **Step 5: Commit**

```bash
git add docs/audit/2026-11-26-v2-performance.md deploy/scripts/v2_load_probe.mjs deploy/scripts/load_probe.mjs
git commit -m "test(perf): record V2.1 performance and failure baselines"
```

---

### Task 11: 备份恢复与迁移回滚演练

**Files:**
- Create: `docs/runbooks/2026-11-26-v2-backup-restore.md`
- Modify: `deploy/scripts/single_company_rehearsal.sh`
- Create: `deploy/scripts/v2_rollback_drill.sh`
- Test: `deploy/scripts/v2_rollback_drill.sh`

**Interfaces:**
- Consumes: Task 9 真实栈、Task 2 单公司迁移、现有备份脚本。
- Produces: 备份、恢复、回滚的可重跑证据；关键表行数与校验和。

- [ ] **Step 1: 扩展演练脚本**

`v2_rollback_drill.sh` 必须：

1. 记录迁移前关键表行数与校验和；
2. 生成 MySQL 备份；
3. 执行迁移；
4. 运行关键烟测；
5. 恢复备份；
6. 再次校验行数与校验和；
7. 输出 PASS/FAIL 与日志路径。

- [ ] **Step 2: 执行演练**

```bash
MYSQL_DATABASE=factoryhr_uat \
MYSQL_USER=factoryhr \
MYSQL_PASSWORD='<测试库密码>' \
BACKUP_DIR=evidence/v2-backup \
bash deploy/scripts/v2_rollback_drill.sh
```

Expected: 备份生成、迁移成功、恢复成功、前后校验和一致；输出 PASS。

- [ ] **Step 3: 写运行手册**

`docs/runbooks/2026-11-26-v2-backup-restore.md` 必须包含：

- 备份频率、保留期、加密与访问控制；
- 恢复步骤与负责人；
- 迁移回滚步骤与不可逆边界；
- 演练结果与 RTO/RPO 说明；
- 失败时的升级路径。

- [ ] **Step 4: 运行并记录**

```bash
bash deploy/scripts/v2_rollback_drill.sh 2>&1 | tee evidence/v2-backup/rollback-drill.log
```

Expected: 日志完整；失败不得删日志或重试掩盖。

- [ ] **Step 5: Commit**

```bash
git add docs/runbooks/2026-11-26-v2-backup-restore.md deploy/scripts/single_company_rehearsal.sh deploy/scripts/v2_rollback_drill.sh evidence/v2-backup
git commit -m "test(ops): prove backup, restore and rollback for V2.1"
```

---

### Task 12: 发布检查单、UAT 签认与最终结论

**Files:**
- Create: `docs/runbooks/2026-11-26-v2-release-checklist.md`
- Create: `docs/audit/2026-11-26-v2-final-acceptance.md`
- Modify: `docs/superpowers/plans/2026-11-26-v2-1-remediation-master-plan.md`
- Modify: `docs/README.md`

**Interfaces:**
- Consumes: Task 8–11 的门禁、UAT、性能、备份恢复证据。
- Produces: 发布/阻断结论；ADR 状态；文档索引；剩余风险与复审触发。

- [ ] **Step 1: 写发布检查单**

`docs/runbooks/2026-11-26-v2-release-checklist.md` 至少列出：

```markdown
- [ ] 后端全量测试通过
- [ ] Pint / PHPStan / capability:audit / arch:test 通过
- [ ] 前端 typecheck / lint / unit / build / E2E 通过
- [ ] 页面模型 / 动作清单 / 原语 / 文案门禁通过
- [ ] OpenAPI lint / validate / generate check 通过
- [ ] 单公司迁移演练与备份恢复通过
- [ ] 五角色真实栈 UAT 通过
- [ ] 性能基线在 SLO 内
- [ ] 审计、限流、CSRF、会话撤销验证通过
- [ ] 无未关闭 Critical/Important 评审项
- [ ] 回滚手册与负责人确认
```

- [ ] **Step 2: 写最终验收**

`docs/audit/2026-11-26-v2-final-acceptance.md`：

```markdown
# FactoryHR V2.1 最终验收

## 结论
- 发布建议：GO / NO-GO
- 依据：逐条引用实际证据
- 阻断项：无 / 列表
- 剩余风险与复审触发：

## 证据索引
（门禁、UAT、性能、迁移、备份恢复、评审）
```

结论必须由 Task 8–11 输出支撑；不得以计划完成代替验收。

- [ ] **Step 3: 核对 ADR 状态**

ADR-0035–0038 已在第 1 册 Task 1 由用户批准并标记 `Accepted`。此处只核对状态与签认记录仍存在，不得把 Accepted 降回 Proposed，也不得等到发布才接受不可逆决策。ADR-0025 修订记录保留。

- [ ] **Step 4: 更新总控与文档索引**

总控计划状态改为 `Complete`；`docs/README.md` 增加四册计划、最终验收与运行手册链接。

- [ ] **Step 5: 最终门禁复跑**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact && ../.tools/php/php.exe artisan capability:audit && ../.tools/php/php.exe artisan arch:test
cd .. && pnpm typecheck && pnpm lint && pnpm test:unit && node scripts/audit_single_company.mjs && node scripts/audit_page_model.mjs && node scripts/audit_ui_primitives.mjs && node scripts/audit_user_copy.mjs && node scripts/audit_action_manifest.mjs
```

Expected: 全部通过；任何失败都把最终结论改为 NO-GO 并列出阻断项。

- [ ] **Step 6: Commit**

```bash
git add docs/runbooks/2026-11-26-v2-release-checklist.md docs/audit/2026-11-26-v2-final-acceptance.md docs/superpowers/plans/2026-11-26-v2-1-remediation-master-plan.md docs/README.md
git commit -m "docs(release): publish V2.1 final acceptance"
```

---

## 第 4 册退出条件

- 14 个平台条目与仪表盘/报表/公告的独立真实页面已在第 3 册完成；本册确认无接口说明板、无残留共享壳引用。
- `PlatformWorkspace`、`PeopleWorkspace`、`TalentWorkspacePage` 与旧 views 副本已退役；无 `mode` 业务分支。
- 全量门禁通过；OpenAPI、动作清单、页面模型、原语、文案一致。
- 真实栈五角色 UAT 通过；权限、字段、导出、审批、薪资边界有证据。
- 性能基线记录完整，达到既定 SLO 或明确容量限制。
- 备份恢复与迁移回滚演练通过；关键表行数与校验和一致。
- 发布检查单逐项有结果；最终结论为 GO 或带明确阻断项的 NO-GO。
- ADR-0035–0038 已 Accepted；总控、README、运行手册与实现一致。
