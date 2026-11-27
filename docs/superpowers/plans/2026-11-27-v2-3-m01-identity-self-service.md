# FactoryHR V2.3 M01｜登录、个人中心与员工自助 — 详细实施计划

> **状态：** Proposed — 待用户确认；本计划不得在规格批准前执行。

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把登录、账号安全、会话管理和员工自助收敛为“登录页 + 我的工作台 + 账号安全”，删除 `/account/mfa`、`/account/sessions`、`/self-service` 三个独立入口，并修复工作台内部断链。

**Architecture:** 身份事实由 IAM 拥有；工作台只组合本人可读的授权投影，不写任何业务事实；账号安全四类动作（改密、会话撤销、MFA 可选、退出）使用现有 IAM 命令；所有入口统一以 `/workspace` 和 `/account` 为落点。

**Tech Stack:** Laravel 13 / PHP 8.3 / Vue 3.5 / TypeScript / Element Plus / PHPUnit / Vitest / Playwright。

## Global Constraints

- 旧 URL 不做重定向；`/account/mfa`、`/account/sessions`、`/self-service` 必须从 registry、pageComponents、pages barrel 与测试中删除。
- 单公司内网、密码登录唯一主流程；MFA 可选且默认关闭，不得成为 API 前提。
- 工作台不得伪造员工身份；无任职绑定时只显示管理任务，不提供“本人申请”。
- 本人申请的 subject 必须由服务端 session 解析，不能由前端选择。
- 未执行测试写 `NOT RUN`，不得写“通过”。

## 0. 模块事实

### 0.1 保留页面

| 页面 | 路由 | 当前文件 | 处置 |
|---|---|---|---|
| 登录 | `/login` | `frontend/src/views/LoginView.vue` | KEEP，补全六态与中文错误 |
| 我的工作台 | `/workspace` | `frontend/src/views/WorkspaceView.vue` | KEEP，合并自助入口并修断链 |
| 无权限 | `/forbidden` | `frontend/src/views/ForbiddenView.vue` | KEEP |
| 页面不存在 | `/not-found` | `frontend/src/views/NotFoundView.vue` | KEEP |
| 账号安全 | `/account` | `frontend/src/modules/platform/pages/AccountSecurityPage.vue` | KEEP，吸收 MFA/会话 |

### 0.2 删除页面

| 原路由 | 当前文件 | 能力去向 |
|---|---|---|
| `/account/mfa` | `registry` 的 `account-mfa` + `views/AccountSecurityView.vue` 共用段 | `/account` 的“双因素认证”分区 |
| `/account/sessions` | `registry` 的 `account-sessions` + 同一共用段 | `/account` 的“登录会话”分区 |
| `/self-service` | `frontend/src/modules/platform/pages/SelfServicePage.vue`、`frontend/src/modules/platform/self-service/pages/SelfServicePage.vue` | `/workspace` 的“员工自助”区与快捷入口 |

### 0.3 当前缺陷

1. `frontend/src/views/WorkspaceView.vue` 跳转 `/employments`、`/payroll/my-payslips` 等不存在的路径；注册表中员工列表为 `/hr/employees`、工资为 `/payroll`。
2. `/self-service` 与 `/workspace` 都展示本人任务、公告、工资条，职责重叠。
3. `AccountSecurityPage.vue` 已同时列出账号、会话、MFA，但旧 registry 仍保留两个独立页面码。
4. `WorkspaceController` 返回 `phone.display = null`，页面虽不显示明文，但字段契约与字段策略需明确。
5. `WorkspaceController` 返回 `contract.type` 原始枚举，页面直接展示。

## 1. 目标契约

### 1.1 页面身份

```ts
'/login'   : PageKind = 'base', capability = 'public'
'/workspace': PageKind = 'base', capability = 'iam.session.manage'
'/account' : PageKind = 'governance', capability = 'iam.session.manage'
```

### 1.2 工作台读模型

`GET /v1/me/workspace` 返回：

```json
{
  "employment_linked": true,
  "profile": { "employmentId": 1, "name": "…", "employeeNo": "…", "jobTitle": "…", "orgName": "…" },
  "contract": { "contractNo": "…", "type": "固定期限", "startDate": "…", "endDate": null, "status": "生效中" },
  "attendanceSummary": { "expectedDays": 20, "presentDays": 18, "lateCount": 1, "absentCount": 0, "leaveDays": 1, "remoteDays": 2 },
  "attendanceException": null,
  "leaveBalances": [],
  "myRequests": [],
  "payslips": [],
  "notices": [],
  "businessDate": "2026-11-27",
  "freshness": "2026-11-27T05:00:00Z"
}
```

约束：

- `contract.type`、`contract.status` 必须由服务端返回中文显示值或稳定 code + 前端字典；禁止直接输出 `FULL_TIME`。
- `phone` 保留 `{display,masked,profile,revealable,exportable}`，display 为服务端遮罩结果。
- 无任职时 `employment_linked=false`，不返回伪造 profile。

### 1.3 账号安全动作

| 动作 | API | capability | 目标 |
|---|---|---|---|
| 修改密码 | `POST /v1/auth/password` | `iam.session.manage` | `/account` 对话框 |
| 撤销会话 | `DELETE /v1/auth/sessions/{id}` | `iam.session.manage` | `/account` 会话列表 |
| 退出全部 | `POST /v1/auth/logout-all` | `iam.session.manage` | `/account` 会话列表 |
| 绑定 TOTP | `POST /v1/auth/mfa/totp/enroll` | `iam.session.manage` | `/account` 可选分区 |
| 确认 TOTP | `POST /v1/auth/mfa/totp/confirm` | `iam.session.manage` | `/account` 可选分区 |
| 停用 TOTP | `POST /v1/auth/mfa/totp/disable` | `iam.session.manage` | `/account` 可选分区 |

## 2. 任务

### Task 1: 修复工作台内部断链并冻结路由常量

**Files:**
- Create: `frontend/src/shared/navigation/internalRoutes.ts`
- Modify: `frontend/src/views/WorkspaceView.vue`
- Modify: `frontend/src/modules/platform/self-service/pages/SelfServicePage.vue` 或删除该文件
- Test: `frontend/tests/unit/internalRoutes.spec.ts`
- Test: `frontend/tests/unit/workspaceLinks.spec.ts`

**Interfaces:**
- Produces: `INTERNAL_ROUTES = { employees: '/hr/employees', payroll: '/payroll', leave: '/leave', attendance: '/attendance', notices: '/notices', account: '/account' } as const`
- Consumes: 现有 PAGE_REGISTRY 路径。

- [ ] **Step 1: 写失败测试**

```ts
import { describe, expect, it } from 'vitest'
import { INTERNAL_ROUTES } from '@/shared/navigation/internalRoutes'
import { PAGE_REGISTRY } from '@/router/registry'

describe('internal route constants', () => {
  it('point only at registered pages', () => {
    const registered = new Set(PAGE_REGISTRY.map((page) => page.path))
    for (const path of Object.values(INTERNAL_ROUTES)) {
      expect(registered.has(path), path).toBe(true)
    }
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

```bash
cd frontend
pnpm vitest run tests/unit/internalRoutes.spec.ts
```

Expected: FAIL，模块不存在。

- [ ] **Step 3: 创建常量并替换硬编码**

```ts
export const INTERNAL_ROUTES = {
  workspace: '/workspace',
  account: '/account',
  employees: '/hr/employees',
  payroll: '/payroll',
  attendance: '/attendance',
  leave: '/leave',
  overtime: '/overtime',
  travel: '/travel',
  notices: '/notices',
  training: '/training',
} as const
```

将 `WorkspaceView.vue` 中 `/employments`、`/payroll/my-payslips`、`/employees` 等字符串替换为常量；工资条入口改为 `/payroll` 并在工资页定位“我的工资条”Tab。

- [ ] **Step 4: 运行测试确认通过**

```bash
cd frontend
pnpm vitest run tests/unit/internalRoutes.spec.ts tests/unit/workspaceLinks.spec.ts
```

Expected: PASS。

- [ ] **Step 5: 提交**

```bash
git add frontend/src/shared/navigation/internalRoutes.ts frontend/src/views/WorkspaceView.vue frontend/tests/unit/internalRoutes.spec.ts frontend/tests/unit/workspaceLinks.spec.ts
git commit -m "fix(m01): use registered routes for workspace links"
```

### Task 2: 将员工自助并入工作台

**Files:**
- Modify: `frontend/src/views/WorkspaceView.vue`
- Delete: `frontend/src/modules/platform/pages/SelfServicePage.vue`
- Delete: `frontend/src/modules/platform/self-service/pages/SelfServicePage.vue`
- Modify: `frontend/src/modules/platform/pages/index.ts`（移除 `self-service`）
- Modify: `frontend/src/router/registry.ts`（移除 `/self-service`）
- Modify: `frontend/src/router/pageComponents.ts`（如仍引用）
- Test: `frontend/tests/unit/selfServiceMerge.spec.ts`
- Test: `frontend/tests/e2e/real/login-workspace.spec.ts`

**Interfaces:**
- Consumes: `GET /v1/me/workspace`、`GET /v1/tasks/my`。
- Produces: 工作台“我的待办 / 我的申请 / 消息公告 / 快捷业务”四区。

- [ ] **Step 1: 写失败测试**

```ts
import { readFileSync } from 'node:fs'
import { expect, it } from 'vitest'

it('removes the duplicate self-service route and page', () => {
  const registry = readFileSync('src/router/registry.ts', 'utf8')
  const barrel = readFileSync('src/modules/platform/pages/index.ts', 'utf8')
  expect(registry).not.toContain("path: '/self-service'")
  expect(barrel).not.toContain("'self-service'")
})
```

- [ ] **Step 2: 运行测试确认失败**

```bash
cd frontend
pnpm vitest run tests/unit/selfServiceMerge.spec.ts
```

Expected: FAIL，仍存在 `/self-service`。

- [ ] **Step 3: 迁移工作台四区并删除旧页**

工作台模板新增四个区块，使用真实接口：

```vue
<section data-testid="workspace-tasks">
  <h2>我的待办</h2>
  <EmptyState v-if="tasks.length === 0" title="暂无待办" description="当前没有需要你处理的审批或任务。" />
  <ul v-else><li v-for="task in tasks" :key="task.id">{{ task.title }}</li></ul>
</section>
```

员工自助页中的“我的考勤/我的请假/我的加班/我的工资条/我的培训”改为工作台快捷入口，不再单独渲染整套页面。

删除旧文件、页面映射与 registry 条目；运行 `rg "self-service|SelfServicePage" frontend/src` 必须无结果（测试文件除外）。

- [ ] **Step 4: 运行单元与真实 E2E**

```bash
cd frontend
pnpm vitest run tests/unit/selfServiceMerge.spec.ts tests/unit/workspaceLinks.spec.ts
pnpm playwright test --config=playwright.real.config.ts tests/e2e/real/login-workspace.spec.ts
```

Expected: PASS，工作台可见四区，无 404。

- [ ] **Step 5: 提交**

```bash
git add -A frontend/src/views/WorkspaceView.vue frontend/src/modules/platform frontend/src/router frontend/tests
git commit -m "refactor(m01): fold self-service into workspace"
```

### Task 3: 合并账号安全入口

**Files:**
- Modify: `frontend/src/router/registry.ts`（删除 `/account/mfa`、`/account/sessions`）
- Modify: `frontend/src/router/pageComponents.ts`（删除 `base/AccountMfaView`、`base/AccountSessionsView`）
- Delete: `frontend/src/views/AccountSecurityView.vue`
- Modify: `frontend/src/modules/platform/pages/AccountSecurityPage.vue`
- Test: `frontend/tests/unit/accountSecurity.spec.ts`
- Test: `frontend/tests/e2e/real/login-workspace.spec.ts`

**Interfaces:**
- Consumes: `frontend/src/modules/platform/api/account.ts` 的六个动作。
- Produces: `/account` 单页三分区（资料、会话、双因素）。

- [ ] **Step 1: 写失败测试**

```ts
it('renders account security as one page with three sections', async () => {
  const wrapper = await mountPage()
  expect(wrapper.find('[data-testid="account-profile"]').exists()).toBe(true)
  expect(wrapper.find('[data-testid="account-sessions"]').exists()).toBe(true)
  expect(wrapper.find('[data-testid="account-mfa"]').exists()).toBe(true)
})
```

- [ ] **Step 2: 运行测试确认失败**

```bash
cd frontend && pnpm vitest run tests/unit/accountSecurity.spec.ts
```

Expected: FAIL，缺少 section test id。

- [ ] **Step 3: 给 AccountSecurityPage 加分区分界并删除旧页**

模板结构：

```vue
<section data-testid="account-profile">…</section>
<section data-testid="account-sessions">…</section>
<section data-testid="account-mfa">…</section>
```

MFA 默认关闭时只显示“当前部署未启用双因素认证；可联系管理员开启”，不渲染绑定表单。删除两个旧 registry 条目与共用 View。

- [ ] **Step 4: 运行测试与门禁**

```bash
cd frontend
pnpm vitest run tests/unit/accountSecurity.spec.ts tests/unit/AccountSecurityPage.spec.ts
pnpm typecheck
```

Expected: PASS。

- [ ] **Step 5: 提交**

```bash
git add -A frontend/src/modules/platform/pages/AccountSecurityPage.vue frontend/src/router frontend/tests
git commit -m "refactor(m01): merge account security routes"
```

### Task 4: 工作台中文与无任职边界

**Files:**
- Modify: `backend/app/Modules/IAM/Http/Controllers/WorkspaceController.php`
- Modify: `frontend/src/views/WorkspaceView.vue`
- Modify: `frontend/src/shared/display/dictionary.ts`
- Test: `backend/tests/Feature/IAM/WorkspaceProjectionTest.php`
- Test: `frontend/tests/unit/workspaceCopy.spec.ts`

**Interfaces:**
- Produces: `contract.type`/`contract.status` 中文值；`employment_linked=false` 时无本人申请入口。

- [ ] **Step 1: 写失败测试**

```php
public function test_workspace_returns_display_labels_not_enum_tokens(): void
{
    $response = $this->actingAsPrincipal($this->hr())->getJson('/v1/me/workspace');
    $response->assertOk();
    $contract = $response->json('data.contract');
    $this->assertNotContains($contract['type'] ?? null, ['FULL_TIME', 'PART_TIME']);
    $this->assertNotContains($contract['status'] ?? null, ['ACTIVE', 'EXPIRING']);
}
```

- [ ] **Step 2: 运行测试确认失败**

```bash
cd backend && ../.tools/php/php.exe vendor/bin/phpunit --filter=WorkspaceProjectionTest
```

Expected: FAIL，仍返回枚举。

- [ ] **Step 3: 在 Controller 中映射显示值**

```php
'type' => $this->employmentTypeLabel($profile->employmentType),
'status' => $this->contractStatusLabel($profile->termsEffectiveTo, $asOf),
```

并补：

```php
private function employmentTypeLabel(?string $type): string
{
    return match ($type) {
        'FULL_TIME' => '全职',
        'PART_TIME' => '兼职',
        'FIXED_TERM' => '固定期限',
        'INTERN' => '实习',
        'CONTRACTOR' => '外包/顾问',
        default => '未知用工类型',
    };
}
```

- [ ] **Step 4: 运行测试**

```bash
cd backend && ../.tools/php/php.exe vendor/bin/phpunit --filter=WorkspaceProjectionTest
cd ../frontend && pnpm vitest run tests/unit/workspaceCopy.spec.ts
```

Expected: PASS。

- [ ] **Step 5: 提交**

```bash
git add backend/app/Modules/IAM/Http/Controllers/WorkspaceController.php backend/tests/Feature/IAM/WorkspaceProjectionTest.php frontend/src/views/WorkspaceView.vue frontend/tests/unit/workspaceCopy.spec.ts
git commit -m "fix(m01): localize workspace contract labels"
```

### Task 5: 删除残留与模块验收

**Files:**
- Modify: `frontend/tests/unit/shellMenu.spec.ts`
- Modify: `frontend/tests/unit/pageComponentUniqueness.spec.ts`
- Create: `docs/implementation/M01/04-evidence.md`

- [ ] **Step 1: 残留扫描**

```bash
rg -n "self-service|SelfServicePage|account-mfa|account-sessions|AccountMfaView|AccountSessionsView|/payroll/my-payslips|/employments" frontend/src backend/routes backend/app docs/generated docs/openapi
```

Expected: 仅剩历史文档或测试夹具；运行时代码 0 命中。

- [ ] **Step 2: 运行模块门禁**

```bash
cd frontend
pnpm vitest run tests/unit/internalRoutes.spec.ts tests/unit/workspaceLinks.spec.ts tests/unit/selfServiceMerge.spec.ts tests/unit/accountSecurity.spec.ts tests/unit/workspaceCopy.spec.ts
pnpm playwright test --config=playwright.real.config.ts tests/e2e/real/login-workspace.spec.ts
cd ..
pnpm gate:all
```

- [ ] **Step 3: 写证据**

`docs/implementation/M01/04-evidence.md` 记录每条命令、退出码、关键输出、截图路径；未执行项写 `NOT RUN`。

- [ ] **Step 4: 提交**

```bash
git add -A frontend backend docs/implementation/M01
git commit -m "chore(m01): close identity module evidence"
```

## 3. 权限负测

| 场景 | 预期 |
|---|---|
| 未登录访问 `/workspace` | 302 到 `/login` |
| 无 `iam.session.manage` 访问 `/account` | 403 页面，不泄露资料 |
| 撤销他人会话 | 404/403，不能泄露存在性 |
| 无任职用户打开工作台 | 显示管理任务与“无员工档案”说明，不出现本人请假 |
| 撤销权限后旧标签页提交改密 | 403 |

## 4. 退出检查

- [ ] `/account/mfa`、`/account/sessions`、`/self-service` 运行时代码 0 残留。
- [ ] 工作台全部内部链接指向已注册路由。
- [ ] 账号安全三分区可用，MFA 关闭时无阻断。
- [ ] 工作台合同类型/状态为中文，无英文枚举。
- [ ] M01 的真实 E2E 与 `pnpm gate:all` 通过。
- [ ] 证据文件完整，未执行项明确标注。
