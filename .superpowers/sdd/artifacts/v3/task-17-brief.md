### Task 17: 平台与系统管理工作台（功能实现）

**Files:**
- Create: `frontend/src/modules/platform/iam/pages/UserAdminPage.vue`
- Create: `frontend/src/modules/platform/iam/pages/RoleAdminPage.vue`
- Create: `frontend/src/modules/platform/iam/pages/MenuAdminPage.vue`
- Create: `frontend/src/modules/platform/iam/pages/PermissionCatalogPage.vue`
- Create: `frontend/src/modules/platform/iam/pages/DataScopeAdminPage.vue`
- Create: `frontend/src/modules/platform/iam/pages/FieldPolicyAdminPage.vue`
- Create: `frontend/src/modules/platform/workflow/pages/WorkflowCenterPage.vue`
- Create: `frontend/src/modules/platform/workflow/pages/WorkflowCaseDetailPage.vue`
- Create: `frontend/src/modules/platform/settings/pages/CalendarSettingsPage.vue`
- Create: `frontend/src/modules/platform/settings/pages/DictionarySettingsPage.vue`
- Create: `frontend/src/modules/platform/settings/pages/NotificationCenterPage.vue`
- Create: `frontend/src/modules/platform/settings/pages/AuditLogPage.vue`
- Test: `frontend/tests/e2e/real/platform-workspaces.spec.ts`
- Test: `backend/tests/Feature/IAM/PlatformWorkspaceApiTest.php`

**Interfaces:**
- Consumes: 第 2 册的用户/角色/菜单/数据范围/字段策略 API、Workflow Case/Decision API、日历/字典/参数/通知/审计 API、`PageShell`/`DataTable`/`FormDialog`/`ActionGate`。
- Produces: 12 个独立平台工作台页面；每页有真实读写、动作权限、中文展示和五态；不再依赖 `PlatformWorkspace` 的 `mode` 分支。

- [ ] **Step 1: 写平台页面清单测试**

创建 `frontend/tests/unit/platformWorkspaces.spec.ts`：

```ts
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const pages = [
  'src/modules/platform/iam/pages/UserAdminPage.vue',
  'src/modules/platform/iam/pages/RoleAdminPage.vue',
  'src/modules/platform/iam/pages/MenuAdminPage.vue',
  'src/modules/platform/iam/pages/PermissionCatalogPage.vue',
  'src/modules/platform/iam/pages/DataScopeAdminPage.vue',
  'src/modules/platform/iam/pages/FieldPolicyAdminPage.vue',
  'src/modules/platform/workflow/pages/WorkflowCenterPage.vue',
  'src/modules/platform/workflow/pages/WorkflowCaseDetailPage.vue',
  'src/modules/platform/settings/pages/CalendarSettingsPage.vue',
  'src/modules/platform/settings/pages/DictionarySettingsPage.vue',
  'src/modules/platform/settings/pages/NotificationCenterPage.vue',
  'src/modules/platform/settings/pages/AuditLogPage.vue',
]

describe('platform workspaces', () => {
  it('each page uses PageShell and contains no mode-based workspace switch', () => {
    const root = resolve(__dirname, '../..')
    for (const page of pages) {
      const source = readFileSync(resolve(root, page), 'utf8')
      expect(source, page).toContain('PageShell')
      expect(source, page).not.toMatch(/PlatformWorkspace|mode\s*===/)
    }
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

```bash
cd frontend && pnpm exec vitest run tests/unit/platformWorkspaces.spec.ts
```

Expected: FAIL，12 个页面尚不存在。

- [ ] **Step 3: 实现 12 个独立页面**

每个页面必须：

- 使用 `PageShell` + `DataTable`/`ElTree`/`ElDescriptions`。
- 页头动作通过 `ActionGate` 绑定动作清单；无权限时不渲染可点击按钮。
- 列表/表单/详情均处理加载、空、错误、无权、部分失败五态。
- 不回显能力码、接口路径、表名或字段档位；技术信息仅写入审计/日志。
- 以真实 API 驱动，不使用静态数组伪装数据。

重点页面要求：

| 页面 | 必须具备 | 独立能力 |
|---|---|---|
| `UserAdminPage` | 列表、创建、编辑、启停、重置密码、角色分配、权限预览 | `iam.user.read/manage`、`iam.role.manage` |
| `RoleAdminPage` | 角色列表、创建/复制/停用、四 Tab、差异保存、成员 | `iam.role.manage`、`iam.scope.manage`、`iam.field.manage` |
| `MenuAdminPage` | 菜单树、名称/图标/排序/显示隐藏、关联已有页面 | `iam.menu.manage` |
| `PermissionCatalogPage` | 页面—动作—API—能力核对，只读+跳转 | `iam.permission.read` |
| `DataScopeAdminPage` | 角色动作范围、组织树选择、影响预览 | `iam.scope.manage` |
| `FieldPolicyAdminPage` | 字段可见/编辑/导出、P0–P3 中文、差异 | `iam.field.manage` |
| `WorkflowCenterPage` | 待办/已办/我发起、类型筛选、批量处理 | `workflow.case.read`、`workflow.decision.manage` |
| `WorkflowCaseDetailPage` | 业务摘要、审批链、决策、时间线、附件 | `workflow.case.read`、`workflow.decision.manage` + 对象范围 |
| `CalendarSettingsPage` | 年份、工作日、节假日、发布 | `time.calendar.manage` |
| `DictionarySettingsPage` | 字典/参数列表与编辑、审计 | `system.dictionary.manage`、`system.parameter.manage` |
| `NotificationCenterPage` | 未读/全部、已读、业务跳转 | `notification.read` |
| `AuditLogPage` | 事件筛选、详情、导出 | `iam.audit.read` |

- [ ] **Step 4: 补后端平台 API 测试**

创建 `backend/tests/Feature/IAM/PlatformWorkspaceApiTest.php`，至少覆盖：

```php
public function test_menu_admin_cannot_create_unknown_page(): void
{
    $this->actingAs($this->menuAdmin())
        ->postJson('/v1/system/menus', ['page_code' => 'fake', 'path' => '/fake', 'label' => '伪造'])
        ->assertStatus(422);
}

public function test_audit_page_requires_audit_capability(): void
{
    $this->actingAs($this->hrAdmin())->getJson('/v1/auth/events')->assertStatus(403);
    $this->actingAs($this->auditAdmin())->getJson('/v1/auth/events')->assertOk();
}

public function test_workflow_decision_requires_object_scope(): void
{
    $case = $this->caseInOtherDepartment();
    $this->actingAs($this->manager())
        ->postJson("/v1/cases/{$case->id}/decisions", ['decision' => 'APPROVE'])
        ->assertStatus(403);
}
```

- [ ] **Step 5: 写 E2E 冒烟**

`platform-workspaces.spec.ts` 至少验证：

- 管理员可打开 12 个页面且无空白页；
- 用户/角色页可完成一次真实保存；
- 菜单页伪造 URL 被拒；
- 审计页对无权限角色不可见；
- 每个页面不出现 `GET /v1/`、`ACTIVE`、`P0_` 等内部文案。

```ts
test('admin can open every platform workspace', async ({ page }) => {
  await loginAs(page, 'demo.admin')
  for (const path of ['/system/users', '/system/roles', '/system/menus', '/system/permissions', '/system/data-scopes', '/system/field-policies', '/workflow', '/system/calendars', '/system/settings', '/notifications', '/system/audit']) {
    await page.goto(path)
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  }
})
```

- [ ] **Step 6: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=PlatformWorkspaceApiTest
cd ../frontend
pnpm exec vitest run tests/unit/platformWorkspaces.spec.ts
HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/platform-workspaces.spec.ts
```

Expected: 12 页面加载、真实保存、越权拒绝、文案门禁通过。若某 API 尚缺，补后端契约，不得用静态数据占位。

- [ ] **Step 7: Commit**

```bash
git add frontend/src/modules/platform/iam/pages frontend/src/modules/platform/workflow/pages frontend/src/modules/platform/settings/pages frontend/tests/unit/platformWorkspaces.spec.ts frontend/tests/e2e/real/platform-workspaces.spec.ts backend/tests/Feature/IAM/PlatformWorkspaceApiTest.php
git commit -m "feat(platform): implement independent workspace pages"
```

---

