# 信息架构、业务页面统一性与生产化文案 — 详细修改计划

> **状态：Superseded。** 本计划不再单独执行；有效内容已并入 FactoryHR V2.1 第 1 册（契约与门禁）与第 3 册（业务页面闭环）。执行入口为 `2026-11-26-v2-1-remediation-master-plan.md`。

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把“160 个页面条目”的交付模型改为“一个用户任务一个工作区”，以员工模块为样板完成列表 + 独立详情页 + 弹窗动作，再统一系统管理页与全站展示契约，最后用产品级门禁防止跨模块 Tab、碎片化 CRUD、英文枚举和调试文案回潮。

**Architecture:** 页面（Page）只保留主工作区与上下文详情两类；视图（View）和动作（Action）不再注册为页面。全局导航来自注册表且只展示主入口；详情页 `menu: null` 并通过列表行/深链进入。展示层统一使用 `PageShell`、`DataTable`、`FormDialog`/`FormDrawer`、`DetailTabs` 和共享字典。权限继续使用 Capability × Anchor × FieldProfile 内核，页面权限与动作权限分离。门禁从“页面数量/组件唯一”升级为“任务闭环/导航单一/展示原语/文案/动作形态”产品级检查。

**Tech Stack:** Vue 3.5 / TypeScript 5.9 / Vue Router / Pinia / Element Plus 2.14 / Vitest / Playwright / Laravel 11 / PHP 8.4 / MySQL 8.4 / OpenAPI 3.1。

## Global Constraints

- 员工详情采用“列表 + 独立上下文详情页”，不是抽屉；创建/编辑使用弹窗。
- 页面内 Tab 只允许同一业务对象、同一用户任务、同一数据范围；禁止跨模块导航。
- 详情路由必须 `menu: null`，不得出现在侧栏；列表必须提供可达入口。
- 创建/编辑/归档/恢复不得注册为独立页面；使用 `FormDialog`、`FormDrawer` 或确认对话框。
- 员工主档当前没有 PATCH 端点；“编辑”必须通过追加任职/条款版本或既有异动接口实现，不得伪造 PATCH。
- 批量导入/导出当前没有员工级端点；在补口完成前不得渲染可点击的假入口。
- 主界面不得出现接口路径、表名、字段档位、数据库键名、英文枚举或“演示/缺口/尚未实现”文案。
- 稳定枚举必须通过共享中文字典渲染；未知值显示“未知”并记录开发告警。
- 页面权限与动作权限分离；页面可见性仍以 `role_pages` 与 `/me/permissions` 为真相。
- 每个 `/v1` 路由必须有 capability 中间件或位于公开白名单；不得新增隐藏管理员捷径。
- 金额使用整数最小货币单位；时间 UTC 存储、公司时区展示；业务日期按日历日处理。
- 迁移 forward-only；不修改已合并迁移。
- 禁止真实个人数据；测试/种子只用合成数据。
- 旧 URL 保留至少一个发布周期，并覆盖重定向 E2E。
- 未运行的门禁不得声称通过。

---

## 0. 执行顺序与依赖

```text
Task 1   ADR：页面/视图/动作/权限边界与员工详情形态
Task 2   页面模型与注册表契约
Task 3   导航单一性与详情入口规则
Task 4   共享展示层骨架与字典
Task 5   员工列表工作区（新建、查看、归档、恢复；详情内维护任职/条款版本）
Task 6   员工详情页与区域页签
Task 7   员工旧 URL/旧页面码迁移
Task 8   系统用户页重建
Task 9   系统角色页重建
Task 10  部门/岗位工作区迁移
Task 11  异动/合同工作区迁移
Task 12  文案门禁与裸控件门禁
Task 13  产品级任务闭环门禁
Task 14  E2E、无障碍与回归
Task 15  旧页面码清理与文档收尾
```

依赖关系：

- Task 1 必须先完成，否则 Task 2/3 没有裁决依据。
- Task 4 是 Task 5/6/8/9 的前置。
- Task 5/6 是 Task 7 的前置。
- Task 10/11 复用 Task 5/6 的模式。
- Task 12/13 最后落地，但测试用例应在 Task 2 开始同步编写。

---

### Task 1: ADR 裁决页面模型与员工详情形态

**Files:**
- Create: `docs/adr/0035-page-view-action-boundaries.md`
- Modify: `docs/05-ui-guidelines.md`
- Modify: `HRMS-V1.1实施书.md` §03A.5、§09.2
- Test: `docs/adr/0035-page-view-action-boundaries.md` 人工评审

**Interfaces:**
- Consumes: 用户裁定“列表 + 独立详情页”。
- Produces: 页面/视图/动作/权限四层定义；员工详情形态；旧 URL 策略；页面内 Tab 判定规则。

- [ ] **Step 1: 写 ADR 背景与问题**

写入 `docs/adr/0035-page-view-action-boundaries.md`：

> ```markdown
> # ADR-0035: Page, View, Action and Permission Boundaries
>
> - Status: Proposed
> - Date: 2026-11-26
> - Related: ADR-0026, ADR-0027, ADR-0033, ADR-0034
>
> ## Context
>
> V1.1 registers create/detail subviews as pages. The employee module has 11 page codes
> for one object, and PeopleWorkspace re-introduces cross-module navigation inside the page.
> The UI therefore has two navigation systems and no single task boundary.
> ```

- [ ] **Step 2: 写决策**

追加到同一 ADR：

> ```markdown
> ## Decision
>
> 1. A page is a task workspace, not a field group.
> 2. Create, archive and restore are actions, never page codes.
> 3. Detail subviews are tabs/anchors in one context detail route, not page codes.
> 4. A context detail route has menu: null and is entered from its list or a deep link.
> 5. Page-internal tabs must share the same business object, task and data scope.
> 6. Employee detail is a standalone context route, not a drawer. This supersedes V1.1 §09.2.
> 7. Page permission controls entry; action capability controls state changes.
> 8. Old URLs redirect for at least one release and old page codes map to new ones during migration.
> ```

- [ ] **Step 3: 写替代方案与后果**

追加到同一 ADR：

> ```markdown
> ## Alternatives
>
> - Keep all routes and only restyle: rejected; fragmentation and permission growth remain.
> - Collapse everything into one long page: rejected; deep links, mobile and permissions suffer.
> - Drawer-only detail: rejected by the product owner; complex records need a stable URL and print context.
>
> ## Consequences
>
> - Page codes and menu entries shrink.
> - Old bookmarks and role grants need migration.
> - Detail pages must own their tab state and return context.
> ```

- [ ] **Step 4: 修改 UI 规范**

在 `docs/05-ui-guidelines.md` 追加：

> ```markdown
> ## 12. 页面、视图与动作
>
> - 侧栏只展示主工作区；详情页不进入侧栏。
> - 创建/归档/恢复使用弹窗或抽屉；不得为动作注册页面路由。
> - 同一对象内部的视图切换使用页签；跨对象切换使用全局导航。
> - 页面内不得出现第二套模块导航。
> ```

- [ ] **Step 5: 修正 V1.1 实施书冲突**

将 §09.2 员工档案行由“员工详情抽屉”改为：

```markdown
| 员工档案 | 顶部筛选、导入导出、表格、员工详情页 | 头像/工号/姓名、在职状态、入职/合同/履历分栏；详情为隐藏上下文路由。导入导出在后端补口完成前不显示入口 |
```

在 §03A.5 表格前增加说明：

```markdown
> 本表中的“新增员工”“教育经历”“工作经历”等是员工工作区内的动作或视图，不单独进入侧栏；
> 仅“员工列表”和“档案详情”保留为页面。其他模块按同一规则解释。
```

- [ ] **Step 6: 人工评审 ADR**

Run: 打开 `docs/adr/0035-page-view-action-boundaries.md`，确认状态、决策、替代方案、后果、验证、复审触发齐全。

Expected: 评审通过后把 Status 改为 Accepted。

- [ ] **Step 7: Commit**

```bash
git add docs/adr/0035-page-view-action-boundaries.md docs/05-ui-guidelines.md HRMS-V1.1实施书.md
git commit -m "docs(adr): define page, view and action boundaries"
```

---

### Task 2: 页面模型与注册表契约

**Files:**
- Create: `frontend/src/shared/navigation/pageModel.ts`
- Modify: `frontend/src/router/registry.ts`
- Modify: `frontend/src/router/navigation.ts`
- Test: `frontend/tests/unit/pageModel.spec.ts`
- Test: `frontend/tests/unit/navigation.spec.ts`

**Interfaces:**
- Consumes: ADR-0035。
- Produces:

```ts
export type PageKind = 'workspace' | 'detail' | 'governance' | 'base'
export type PageMenu = { group: string; label: string; order: number; icon: string } | null

export type PageSpec = {
  path: string
  name: string
  componentKey: string
  capability: string
  kind: PageKind
  menu: PageMenu
  primaryObject?: string
  detailOf?: string
}
```

> 现有 `PageSpec` 的 `layer`、`wave`、`requiredApis`、`actions`、`states`、`e2e`、`negativeTests` 保持不变；本任务只增加页面类型字段。

- [ ] **Step 1: 写失败测试**

```ts
import { describe, expect, it } from 'vitest'
import { validatePageModel } from '@/shared/navigation/pageModel'

const base = {
  path: '/hr/employees', name: 'hr-employees', componentKey: 'hr/employees/index',
  capability: 'people.employee.read', kind: 'workspace', menu: { group: '员工档案', label: '员工列表', order: 16, icon: 'User' },
  primaryObject: 'employee',
} as const

describe('page model', () => {
  it('rejects a create action registered as a page', () => {
    const result = validatePageModel([{ ...base, path: '/hr/employees/create', name: 'hr-employees-create', kind: 'workspace' }])
    expect(result.ok).toBe(false)
    expect(result.errors.join('\n')).toContain('action-like path')
  })

  it('requires detail pages to have menu null and a list parent', () => {
    const result = validatePageModel([
      base,
      { ...base, path: '/hr/employees/:id', name: 'employee-detail', componentKey: 'hr/employees/by-id', kind: 'detail', detailOf: 'hr-employees', menu: null },
    ])
    expect(result.ok).toBe(true)
  })

  it('rejects a menu entry for a detail page', () => {
    const result = validatePageModel([
      base,
      { ...base, path: '/hr/employees/:id', name: 'employee-detail', kind: 'detail', detailOf: 'hr-employees', menu: { group: '员工档案', label: '档案详情', order: 18, icon: 'User' } },
    ])
    expect(result.ok).toBe(false)
    expect(result.errors.join('\n')).toContain('detail page must not have a menu')
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/pageModel.spec.ts
```

Expected: FAIL，`Cannot find module '@/shared/navigation/pageModel'`。

- [ ] **Step 3: 实现页面模型**

```ts
// frontend/src/shared/navigation/pageModel.ts
import type { PageSpec as RegistryPageSpec } from '@/router/registry'

export type PageKind = 'workspace' | 'detail' | 'governance' | 'base'
export type PageMenu = { group: string; label: string; order: number; icon: string } | null

export type PageSpec = RegistryPageSpec & {
  kind: PageKind
  primaryObject?: string
  detailOf?: string
}

const ACTION_LIKE = /(?:^|\/)(create|new|editor|edit|imports?|exports?|renewals?|changes?|move|onboarding|probation|transfer|salary-change|secondment|offboarding)(?:\/|$)/

export function validatePageModel(pages: readonly PageSpec[]): { ok: boolean; errors: string[] } {
  const errors: string[] = []
  const names = new Set<string>()
  const byName = new Map(pages.map((page) => [page.name, page]))

  for (const page of pages) {
    if (names.has(page.name)) errors.push(`${page.name}: duplicate page name`)
    names.add(page.name)

    if (page.kind !== 'detail' && ACTION_LIKE.test(page.path)) {
      errors.push(`${page.path}: action-like path must be a view or action, not a page`)
    }
    if (page.kind === 'detail') {
      if (page.menu !== null) errors.push(`${page.name}: detail page must not have a menu`)
      if (page.detailOf === undefined) {
        errors.push(`${page.name}: detail page requires detailOf`)
      } else if (byName.get(page.detailOf)?.kind !== 'workspace') {
        errors.push(`${page.name}: detailOf must reference a workspace page`)
      }
    }
    if (page.kind === 'workspace' && page.menu === null) {
      errors.push(`${page.name}: workspace page requires a menu entry`)
    }
  }

  return { ok: errors.length === 0, errors }
}
```

- [ ] **Step 4: 更新注册表类型**

在 `frontend/src/router/registry.ts` 的 `PageSpec` 增加 `kind`、`primaryObject`、`detailOf`，并给每条记录加 `kind`：

```ts
// 示例：员工列表
kind: 'workspace',
primaryObject: 'employee',

// 示例：员工详情
kind: 'detail',
detailOf: 'hr-employees',
menu: null,
```

- [ ] **Step 5: 更新导航只展示主入口**

在 `frontend/src/router/navigation.ts` 中，把遍历循环改为显式跳过详情页，同时保留现有 pageSet、capability 过滤和排序逻辑：

```ts
for (const page of PAGE_REGISTRY) {
  if (page.menu === null || page.kind === 'detail') {
    continue
  }
  if (pageSet !== null && page.capability !== 'public' && !pageSet.has(page.name) && !pageSet.has(page.path)) {
    continue
  }
  const group = menuGroupKey(page.menu)
  const children = groups.get(group) ?? []
  children.push({
    key: page.name,
    label: page.menu.label,
    path: page.path,
    capability: page.capability,
    order: page.menu.order,
    icon: page.menu.icon,
  })
  groups.set(group, children)
  groupOrder.set(group, Math.min(groupOrder.get(group) ?? Number.MAX_SAFE_INTEGER, page.menu.order))
}
```

- [ ] **Step 6: 运行测试**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/pageModel.spec.ts tests/unit/navigation.spec.ts
```

Expected: PASS。

- [ ] **Step 7: Commit**

```bash
git add frontend/src/shared/navigation/pageModel.ts frontend/src/router/registry.ts frontend/src/router/navigation.ts frontend/tests/unit/pageModel.spec.ts frontend/tests/unit/navigation.spec.ts
git commit -m "feat(navigation): model pages, details and actions explicitly"
```

---

### Task 3: 导航单一性与详情入口规则

**Files:**
- Modify: `frontend/src/modules/people/components/PeopleWorkspace.vue`
- Modify: `frontend/src/modules/people/catalog.ts`
- Modify: `frontend/src/modules/people/pages/index.ts`
- Test: `frontend/tests/unit/peopleNavigation.spec.ts`
- Test: `frontend/tests/unit/routeReachability.spec.ts`

**Interfaces:**
- Consumes: Task 2 的 `PageSpec.kind`、`PageSpec.detailOf`。
- Produces: 页面内无跨模块导航；详情路由可从列表到达；旧入口有重定向。

- [ ] **Step 1: 写失败测试**

```ts
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

describe('people page navigation', () => {
  it('does not hardcode cross-module navigation in PeopleWorkspace', () => {
    const source = readFileSync('src/modules/people/components/PeopleWorkspace.vue', 'utf8')
    expect(source).not.toContain('people-local-nav')
    expect(source).not.toContain("onNavClick('/hr/departments')")
    expect(source).not.toContain("onNavClick('/hr/positions')")
    expect(source).not.toContain("onNavClick('/hr/movements')")
    expect(source).not.toContain("onNavClick('/hr/contracts')")
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/peopleNavigation.spec.ts
```

Expected: FAIL，`PeopleWorkspace.vue` 仍包含 `people-local-nav`。

- [ ] **Step 3: 删除页面内跨模块导航**

从 `PeopleWorkspace.vue` 删除整段：

```html
<nav class="people-local-nav" aria-label="员工与组织快捷入口">
  <button type="button" class="people-local-nav__item" :class="{ 'people-local-nav__item--active': config.key.startsWith('hr/employees') }" @click="onNavClick('/hr/employees')">员工档案</button>
  <button type="button" class="people-local-nav__item" :class="{ 'people-local-nav__item--active': config.key.startsWith('hr/departments') }" @click="onNavClick('/hr/departments')">部门管理</button>
  <button type="button" class="people-local-nav__item" :class="{ 'people-local-nav__item--active': config.key.startsWith('hr/positions') }" @click="onNavClick('/hr/positions')">岗位管理</button>
  <button type="button" class="people-local-nav__item" :class="{ 'people-local-nav__item--active': config.key.startsWith('hr/movements') }" @click="onNavClick('/hr/movements')">员工异动</button>
  <button type="button" class="people-local-nav__item" :class="{ 'people-local-nav__item--active': config.key.startsWith('hr/contracts') }" @click="onNavClick('/hr/contracts')">劳动合同</button>
</nav>
```

同时删除 `onNavClick` 函数，以及 `<style>` 中全部 `.people-local-nav*` 规则。保留 `useRouter`，因为 `openEmployment()` 仍需跳转详情页。

- [ ] **Step 4: 修正 People 目录页面模型**

把 `catalog.ts` 中的 11 个员工条目收敛为下面 2 个。`PeoplePageConfig` 只描述页面自身的标题、读取类型、列和详情字段；页面之间的 `detailOf` 关系只写在 `frontend/src/router/registry.ts`，避免两个真相源。列表条目的 `primaryObject` 放在注册表 `PageSpec` 中，不放入 `PeoplePageConfig`。

```ts
const EMPLOYEE_PAGES: PeoplePageConfig[] = [
  {
    ...EMPLOYEE_DOMAIN,
    key: 'hr/employees/index',
    title: '员工列表',
    description: '查询、筛选并维护员工档案。',
    readLabel: '员工',
    read: { kind: 'employee-list' },
    columns: EMPLOYEE_COLUMNS,
  },
  {
    ...EMPLOYEE_DOMAIN,
    key: 'hr/employees/by-id',
    title: '员工详情',
    description: '查看员工档案、任职、经历、附件与变更。',
    readLabel: '员工',
    read: { kind: 'employee-detail' },
    columns: [],
    detailFields: [
      { key: 'employee_no', label: '工号' },
      { key: 'status', label: '状态' },
      { key: 'employment_type', label: '用工类型' },
      { key: 'current_assignment.org_unit_id', label: '当前组织' },
      { key: 'current_assignment.position_id', label: '当前岗位' },
      { key: 'manager_employment_id', label: '直属主管' },
    ],
  },
]
```

其余条目改为详情页内的 `tab` 配置，不再注册页面：

```ts
export const EMPLOYEE_DETAIL_TABS = [
  { key: 'overview', label: '概览' },
  { key: 'assignments', label: '任职与合同' },
  { key: 'education', label: '教育经历' },
  { key: 'experiences', label: '工作经历' },
  { key: 'contacts', label: '联系人' },
  { key: 'certificates', label: '证照与资格' },
  { key: 'timeline', label: '履历' },
  { key: 'files', label: '附件' },
  { key: 'changes', label: '变更审批' },
] as const
```

- [ ] **Step 5: 更新页面清单**

`frontend/src/modules/people/pages/index.ts` 先只删除员工域的旧页面，其他域继续保留：

```ts
export const PAGE_COMPONENTS = {
  'hr/employees/index': () => import('./EmployeeListPage.vue'),
  'hr/employees/by-id': () => import('./EmployeeDetailPage.vue'),
  'hr/departments/index': () => import('./DepartmentTreePage.vue'),
  'hr/departments/list': () => import('./DepartmentListPage.vue'),
  'hr/departments/editor': () => import('./DepartmentEditorPage.vue'),
  'hr/departments/members': () => import('./DepartmentMembersPage.vue'),
  'hr/departments/move': () => import('./DepartmentMovePage.vue'),
  'hr/departments/staffing': () => import('./DepartmentStaffingPage.vue'),
  'hr/positions/index': () => import('./PositionCatalogPage.vue'),
  'hr/positions/editor': () => import('./PositionEditorPage.vue'),
  'hr/positions/headcount': () => import('./PositionHeadcountPage.vue'),
  'hr/positions/requirements': () => import('./PositionRequirementsPage.vue'),
  'hr/positions/grades': () => import('./PositionGradesPage.vue'),
  'hr/positions/staff': () => import('./PositionStaffPage.vue'),
  'hr/movements/index': () => import('./MovementListPage.vue'),
  'hr/movements/onboarding': () => import('./MovementOnboardingPage.vue'),
  'hr/movements/probation': () => import('./MovementProbationPage.vue'),
  'hr/movements/transfer': () => import('./MovementTransferPage.vue'),
  'hr/movements/salary-change': () => import('./MovementSalaryChangePage.vue'),
  'hr/movements/secondment': () => import('./MovementSecondmentPage.vue'),
  'hr/movements/offboarding': () => import('./MovementOffboardingPage.vue'),
  'hr/movements/by-id': () => import('./MovementDetailPage.vue'),
  'hr/movements/calendar': () => import('./MovementCalendarPage.vue'),
  'hr/contracts/index': () => import('./ContractLedgerPage.vue'),
  'hr/contracts/editor': () => import('./ContractEditorPage.vue'),
  'hr/contracts/by-id': () => import('./ContractDetailPage.vue'),
  'hr/contracts/renewals': () => import('./ContractRenewalsPage.vue'),
  'hr/contracts/changes': () => import('./ContractChangesPage.vue'),
  'hr/contracts/expiry': () => import('./ContractExpiryPage.vue'),
  'hr/contracts/templates': () => import('./ContractTemplatesPage.vue'),
} as const
```

- [ ] **Step 6: 增加详情可达性测试**

```ts
import { describe, expect, it } from 'vitest'
import { PAGE_REGISTRY } from '@/router/registry'

describe('detail route reachability', () => {
  it('every detail page has a workspace parent and is not in the menu', () => {
    const details = PAGE_REGISTRY.filter((page) => page.kind === 'detail')
    expect(details.length).toBeGreaterThan(0)
    for (const detail of details) {
      expect(detail.menu, detail.name).toBeNull()
      const parent = PAGE_REGISTRY.find((page) => page.name === detail.detailOf)
      expect(parent, detail.name).toBeDefined()
      expect(parent?.kind, detail.name).toBe('workspace')
    }
  })
})
```

- [ ] **Step 7: 运行测试**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/peopleNavigation.spec.ts tests/unit/routeReachability.spec.ts
```

Expected: PASS。

- [ ] **Step 8: Commit**

```bash
git add frontend/src/modules/people/components/PeopleWorkspace.vue frontend/src/modules/people/catalog.ts frontend/src/modules/people/pages/index.ts frontend/tests/unit/peopleNavigation.spec.ts frontend/tests/unit/routeReachability.spec.ts
git commit -m "refactor(people): remove cross-module navigation and collapse employee pages"
```

---

### Task 4: 共享展示层骨架与字典

**Files:**
- Create: `frontend/src/shared/ui/PageShell.vue`
- Create: `frontend/src/shared/ui/FormDialog.vue`
- Create: `frontend/src/shared/domain/labels.ts`
- Modify: `frontend/src/components/DataTable.vue`
- Modify: `frontend/src/components/PageHeader.vue`
- Test: `frontend/tests/unit/labels.spec.ts`
- Test: `frontend/tests/unit/formDialog.spec.ts`
- Test: `frontend/tests/unit/pageShell.spec.ts`

**Interfaces:**
- Produces:

```ts
// labels.ts
export function employeeStatusLabel(value: unknown): string
export function employmentTypeLabel(value: unknown): string
export function assignmentTypeLabel(value: unknown): string
export function contractTypeLabel(value: unknown): string
export function metricUnitLabel(value: unknown): string
export function anchorTypeLabel(value: unknown): string
export function fieldProfileLabel(value: unknown): string
export function userStatusLabel(value: unknown): string
```

```vue
<!-- FormDialog.vue -->
<script setup lang="ts">
defineProps<{ modelValue: boolean; title: string; width?: string; submitting?: boolean }>()
defineEmits<{ 'update:modelValue': [value: boolean]; submit: []; cancel: [] }>()
</script>
```

- [ ] **Step 1: 写字典失败测试**

```ts
import { describe, expect, it } from 'vitest'
import { employeeStatusLabel, employmentTypeLabel, metricUnitLabel, userStatusLabel } from '@/shared/domain/labels'

describe('domain labels', () => {
  it('translates stable employee enums', () => {
    expect(employeeStatusLabel('ACTIVE')).toBe('在职')
    expect(employeeStatusLabel('PROBATION')).toBe('试用期')
    expect(employmentTypeLabel('FULL_TIME')).toBe('全职')
  })

  it('never returns a raw machine enum', () => {
    expect(employeeStatusLabel('SOMETHING_NEW')).toBe('未知状态')
    expect(employmentTypeLabel('SOMETHING_NEW')).toBe('未知用工类型')
  })

  it('translates metric units without exposing MINOR_UNITS', () => {
    expect(metricUnitLabel('COUNT')).toBe('人')
    expect(metricUnitLabel('MINOR_UNITS')).toBe('元')
    expect(metricUnitLabel('SOMETHING_NEW')).toBe('未知单位')
  })

  it('translates account status without exposing ACTIVE', () => {
    expect(userStatusLabel('ACTIVE')).toBe('启用')
    expect(userStatusLabel('LOCKED')).toBe('锁定')
    expect(userStatusLabel('SOMETHING_NEW')).toBe('未知状态')
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/labels.spec.ts
```

Expected: FAIL，模块不存在。

- [ ] **Step 3: 实现字典**

```ts
// frontend/src/shared/domain/labels.ts
type LabelMap = Readonly<Record<string, string>>

const EMPLOYEE_STATUS: LabelMap = {
  ACTIVE: '在职', PROBATION: '试用期', ON_LEAVE: '休假中', TERMINATED: '已离职',
}
const EMPLOYMENT_TYPE: LabelMap = {
  FULL_TIME: '全职', PART_TIME: '兼职', FIXED_TERM: '固定期限', INTERN: '实习', CONTRACTOR: '外包/顾问',
}
const ASSIGNMENT_TYPE: LabelMap = {
  PRIMARY: '主任职', SECONDMENT: '借调', MATRIX: '矩阵任职', PROJECT: '项目任职',
}
const CONTRACT_TYPE: LabelMap = {
  FIXED_TERM: '固定期限', OPEN_ENDED: '无固定期限', INTERNSHIP: '实习', CONSULTANT: '顾问',
}
const METRIC_UNIT: LabelMap = {
  COUNT: '人', MINUTES: '分钟', MINOR_UNITS: '元', CENTS: '元', RATIO: '%',
}
const ANCHOR_TYPE: LabelMap = {
  SELF: '本人', MANAGED: '下属', ORG_UNIT: '本部门', ORG_SUBTREE: '本部门及子部门',
  CASE: '指定案例', PAYROLL_RUN: '指定工资批次', COMPANY: '本公司',
}
const FIELD_PROFILE: LabelMap = {
  P0_PUBLIC: 'P0 公开', P1_INTERNAL: 'P1 内部', P2_SENSITIVE: 'P2 敏感', P3_RESTRICTED: 'P3 受限',
}
const USER_STATUS: LabelMap = {
  ACTIVE: '启用', LOCKED: '锁定', DISABLED: '停用', SUSPENDED: '停用',
}

function labelOf(value: unknown, map: LabelMap, unknown: string): string {
  if (typeof value !== 'string' || value === '') return unknown
  return map[value] ?? unknown
}

export const employeeStatusLabel = (value: unknown): string => labelOf(value, EMPLOYEE_STATUS, '未知状态')
export const employmentTypeLabel = (value: unknown): string => labelOf(value, EMPLOYMENT_TYPE, '未知用工类型')
export const assignmentTypeLabel = (value: unknown): string => labelOf(value, ASSIGNMENT_TYPE, '未知任职类型')
export const contractTypeLabel = (value: unknown): string => labelOf(value, CONTRACT_TYPE, '未知合同类型')
export const metricUnitLabel = (value: unknown): string => labelOf(value, METRIC_UNIT, '未知单位')
export const anchorTypeLabel = (value: unknown): string => labelOf(value, ANCHOR_TYPE, '未知范围')
export const fieldProfileLabel = (value: unknown): string => labelOf(value, FIELD_PROFILE, '未知字段档位')
export const userStatusLabel = (value: unknown): string => labelOf(value, USER_STATUS, '未知状态')
```

- [ ] **Step 4: 写 FormDialog 失败测试**

```ts
import { mount } from '@vue/test-utils'
import ElementPlus from 'element-plus'
import { describe, expect, it } from 'vitest'
import FormDialog from '@/shared/ui/FormDialog.vue'

describe('FormDialog', () => {
  it('emits submit and disables the button while submitting', async () => {
    const wrapper = mount(FormDialog, {
      props: { modelValue: true, title: '新建员工', submitting: true },
      slots: { default: '<p>表单</p>' },
      global: { plugins: [ElementPlus] },
    })
    expect(wrapper.text()).toContain('新建员工')
    expect(wrapper.get('[data-testid="form-dialog-submit"]').attributes('disabled')).toBeDefined()
  })
})
```

- [ ] **Step 5: 实现 FormDialog**

```vue
<script setup lang="ts">
withDefaults(defineProps<{
  modelValue: boolean
  title: string
  width?: string
  submitting?: boolean
}>(), { width: '640px', submitting: false })

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  submit: []
  cancel: []
}>()

function cancel(): void {
  emit('update:modelValue', false)
  emit('cancel')
}
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    :title="title"
    :width="width"
    :close-on-click-modal="false"
    destroy-on-close
    @update:model-value="emit('update:modelValue', $event)"
  >
    <slot />
    <template #footer>
      <el-button @click="cancel">取消</el-button>
      <el-button type="primary" :loading="submitting" :disabled="submitting" data-testid="form-dialog-submit" @click="emit('submit')">
        保存
      </el-button>
    </template>
  </el-dialog>
</template>
```

- [ ] **Step 6: 实现 PageShell**

```vue
<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue'

defineProps<{ title: string; description?: string; breadcrumb?: string }>()
</script>

<template>
  <div class="page-shell">
    <PageHeader :title="title" :description="description" :breadcrumb="breadcrumb">
      <template #actions><slot name="actions" /></template>
    </PageHeader>
    <slot name="filters" />
    <slot />
    <slot name="dialogs" />
  </div>
</template>
```

- [ ] **Step 7: 运行测试**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/labels.spec.ts tests/unit/formDialog.spec.ts tests/unit/pageShell.spec.ts
```

Expected: PASS。

- [ ] **Step 8: Commit**

```bash
git add frontend/src/shared/ui frontend/src/shared/domain/labels.ts frontend/tests/unit/labels.spec.ts frontend/tests/unit/formDialog.spec.ts frontend/tests/unit/pageShell.spec.ts
git commit -m "feat(ui): add shared page shell, form dialog and domain labels"
```

---

### Task 5: 员工列表工作区

**Files:**
- Modify: `frontend/src/modules/people/pages/EmployeeListPage.vue`
- Create: `frontend/src/modules/people/components/EmployeeListWorkspace.vue`
- Create: `frontend/src/modules/people/components/EmployeeCreateDialog.vue`
- Modify: `frontend/src/api/resourceClient.ts`
- Test: `frontend/tests/unit/employeeListWorkspace.spec.ts`

**Interfaces:**
- Consumes: Task 4 的 `PageShell`、`FormDialog`、`labels.ts`；`POST /v1/persons`、`POST /v1/employments`、`POST /v1/employments/{id}/assignments`、`POST /v1/employments/{id}/terms`、`DELETE /v1/employees/{id}`、`POST /v1/employees/{id}/restore`。
- Produces:

```ts
export type EmployeeListAction =
  | { type: 'create' }
  | { type: 'archive'; employmentId: number }
  | { type: 'restore'; employmentId: number }

export function openEmployeeDetail(employmentId: number): { name: 'employee-detail'; params: { id: string } }
```

> 当前没有员工主档 PATCH 和批量导入/导出端点。列表首版只提供创建、查看、版本化任职/条款维护、归档、恢复；
> 导入/导出按钮必须等后端契约完成后才出现，不能渲染假入口。

- [ ] **Step 1: 写失败测试**

```ts
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'

const resource = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() }))
vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
  resourcePatch: resource.patch,
  resourceDelete: resource.delete,
}))

import EmployeeListPage from '@/modules/people/pages/EmployeeListPage.vue'

beforeEach(() => {
  resource.get.mockReset()
  resource.get.mockResolvedValue({ items: [], total: 0, page: 1, page_size: 20 })
})

async function mountPage() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/hr/employees', name: 'hr-employees', component: EmployeeListPage },
      { path: '/hr/employees/:id', name: 'employee-detail', component: { template: '<div />' } },
    ],
  })
  await router.push('/hr/employees')
  await router.isReady()
  return mount(EmployeeListPage, { global: { plugins: [pinia, router, ElementPlus] } })
}

describe('employee list workspace', () => {
  it('renders Chinese headers and does not link to other modules', async () => {
    const wrapper = await mountPage()
    await flushPromises()
    expect(wrapper.text()).toContain('员工列表')
    expect(wrapper.text()).toContain('工号')
    expect(wrapper.text()).toContain('姓名')
    expect(wrapper.text()).not.toContain('部门管理')
    expect(wrapper.text()).not.toContain('岗位管理')
    expect(wrapper.text()).not.toContain('劳动合同')
  })

  it('opens a create dialog instead of navigating to a create page', async () => {
    const wrapper = await mountPage()
    await flushPromises()
    await wrapper.get('[data-testid="employee-create"]').trigger('click')
    expect(wrapper.text()).toContain('新建员工')
    expect(wrapper.find('[data-testid="employee-create-form"]').exists()).toBe(true)
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/employeeListWorkspace.spec.ts
```

Expected: FAIL，页面仍渲染 `PeopleWorkspace` 和跨模块导航。

- [ ] **Step 3: 实现列表工作区**

`EmployeeListPage.vue` 改为直接组合：

```vue
<script setup lang="ts">
import EmployeeListWorkspace from '../components/EmployeeListWorkspace.vue'
</script>

<template>
  <EmployeeListWorkspace />
</template>
```

`EmployeeListWorkspace.vue` 骨架：

```vue
<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import PageShell from '@/shared/ui/PageShell.vue'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import ActionGate from '@/components/ActionGate.vue'
import EmployeeCreateDialog from './EmployeeCreateDialog.vue'
import { employeeStatusLabel, employmentTypeLabel } from '@/shared/domain/labels'
import { resourceDelete, resourceGet, resourcePost } from '@/api/resourceClient'

const route = useRoute()
const router = useRouter()
const loading = ref(false)
const rows = ref<Array<Record<string, unknown>>>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const search = ref('')
const createOpen = ref(false)
const includeArchived = ref(false)

const columns: DataTableColumn[] = [
  { key: 'employee_no', label: '工号', width: '120px' },
  { key: 'person_name', label: '姓名', minWidth: '140px' },
  { key: 'current_assignment.org_unit_id', label: '组织', minWidth: '120px' },
  { key: 'current_assignment.position_id', label: '岗位', minWidth: '120px' },
  { key: 'status', label: '状态', width: '100px' },
  { key: 'employment_type', label: '用工类型', width: '110px' },
  { key: 'actions', label: '操作', width: '190px' },
]

async function load(): Promise<void> {
  loading.value = true
  try {
    const payload = await resourceGet<{ items?: Array<Record<string, unknown>>; total?: number }>('/v1/employees', {
      page: page.value,
      page_size: pageSize.value,
      q: search.value || undefined,
      include_archived: includeArchived.value || undefined,
    })
    rows.value = payload.items ?? []
    total.value = payload.total ?? rows.value.length
  } finally {
    loading.value = false
  }
}

function openDetail(id: number): void {
  void router.push({ name: 'employee-detail', params: { id: String(id) } })
}

async function archive(id: number): Promise<void> {
  const { value } = await ElMessageBox.prompt('归档后员工将从默认列表移除，历史记录保留且工号不复用。请填写归档原因。', '归档员工', {
    confirmButtonText: '归档',
    cancelButtonText: '取消',
    inputPattern: /^.{3,500}$/,
    inputErrorMessage: '归档原因至少 3 个字。',
    type: 'warning',
  })
  await resourceDelete(`/v1/employees/${id}`, {
    idempotencyKey: `employee-archive-${id}-${Date.now()}`,
    body: { reason: value },
  })
  ElMessage.success('员工已归档；历史记录仍可按权限查看。')
  await load()
}

async function restore(id: number): Promise<void> {
  await resourcePost(`/v1/employees/${id}/restore`, {}, { idempotencyKey: `employee-restore-${id}-${Date.now()}` })
  ElMessage.success('员工已恢复；默认列表将重新显示该员工。')
  await load()
}

onMounted(() => {
  if (route.query.action === 'create') createOpen.value = true
  return load()
})
</script>

<template>
  <PageShell title="员工列表" description="查询、筛选并维护员工档案。" breadcrumb="员工档案">
    <template #actions>
      <ActionGate capability="people.employee.manage">
        <el-button type="primary" data-testid="employee-create" @click="createOpen = true">新建员工</el-button>
      </ActionGate>
    </template>
    <el-checkbox v-model="includeArchived" @change="load">包含已归档员工</el-checkbox>
    <DataTable
      v-model:search-value="search"
      v-model:page="page"
      v-model:page-size="pageSize"
      :columns="columns"
      :rows="rows"
      :total="total"
      :loading="loading"
      show-search
      search-placeholder="搜索姓名或工号"
      @update:search-value="load"
      @update:page="load"
      @update:page-size="load"
    >
      <template #cell-status="{ row }">{{ employeeStatusLabel(row.status) }}</template>
      <template #cell-employment_type="{ row }">{{ employmentTypeLabel(row.employment_type) }}</template>
      <template #cell-actions="{ row }">
        <el-button text size="small" @click="openDetail(Number(row.employment_id ?? row.id))">查看</el-button>
        <ActionGate v-if="row.status !== 'TERMINATED'" capability="people.employee.archive">
          <el-button text size="small" type="danger" @click="archive(Number(row.employment_id ?? row.id))">归档</el-button>
        </ActionGate>
        <ActionGate v-else capability="people.employee.restore">
          <el-button text size="small" @click="restore(Number(row.employment_id ?? row.id))">恢复</el-button>
        </ActionGate>
      </template>
    </DataTable>
    <EmployeeCreateDialog v-model="createOpen" @created="load" />
  </PageShell>
</template>
```

> 归档和恢复在后端还要求 `mfa.step-up`。前端不自行伪造二次认证；当 API 返回 `MFA_STEP_UP_REQUIRED` 时，使用既有 MFA step-up 对话流程，完成后重试原命令。

- [ ] **Step 4: 实现员工创建弹窗**

`EmployeeCreateDialog.vue` 复用 `EmployeeCreateForm` 的字段与提交逻辑，但改为：

```vue
<FormDialog :model-value="modelValue" title="新建员工" :submitting="submitting" @update:model-value="emit('update:modelValue', $event)" @submit="submit">
  <form data-testid="employee-create-form" class="form-grid" @submit.prevent="submit">
    <el-form-item label="姓名" required><el-input v-model="draft.legal_name" /></el-form-item>
    <el-form-item label="邮箱"><el-input v-model="draft.email" type="email" /></el-form-item>
    <el-form-item label="工号" required><el-input v-model="draft.employee_no" /></el-form-item>
    <el-form-item label="组织" required>
      <el-tree-select v-model="draft.org_unit_id" :data="orgOptions" check-strictly :render-after-expand="false" />
    </el-form-item>
    <el-form-item label="岗位">
      <el-select v-model="draft.position_id" clearable filterable>
        <el-option v-for="position in positionOptions" :key="position.id" :label="position.label" :value="position.id" />
      </el-select>
    </el-form-item>
    <el-form-item label="用工类型" required>
      <el-select v-model="draft.employment_type">
        <el-option label="全职" value="FULL_TIME" />
        <el-option label="兼职" value="PART_TIME" />
        <el-option label="固定期限" value="FIXED_TERM" />
        <el-option label="实习" value="INTERN" />
        <el-option label="外包/顾问" value="CONTRACTOR" />
      </el-select>
    </el-form-item>
    <el-form-item label="初始状态">
      <el-select v-model="draft.status">
        <el-option label="试用期" value="PROBATION" />
        <el-option label="在职" value="ACTIVE" />
      </el-select>
    </el-form-item>
    <el-form-item label="入职日期" required><el-date-picker v-model="draft.hire_date" type="date" value-format="YYYY-MM-DD" /></el-form-item>
    <el-form-item label="任职生效日" required><el-date-picker v-model="draft.valid_from" type="date" value-format="YYYY-MM-DD" /></el-form-item>
    <el-form-item label="工作地点" required><el-input v-model="draft.work_location" /></el-form-item>
    <el-form-item label="工作安排" required><el-input v-model="draft.work_schedule" placeholder="例如 5x8" /></el-form-item>
  </form>
</FormDialog>
```

约束：

- 不展示“法人编号”输入；单公司由服务端上下文决定。
- 组织和岗位使用选择器，不要求用户输入 ID。
- 创建过程严格复用现有四步：Person → Employment → Assignment → Terms。
- 任一步失败时，弹窗保留已填内容并显示“已创建到第几步”；不得重复创建 Person。
- 成功后发出 `created`，列表刷新；用户可再点“查看”进入详情。
- 失败保留输入并在字段旁显示中文错误。

- [ ] **Step 5: 扩展 resourceDelete 支持请求体**

在 `frontend/src/api/resourceClient.ts` 中，把删除选项扩展为：

```ts
export type ResourceDeleteOptions = ResourceRequestOptions & {
  /** DELETE endpoints that require a reason carry a JSON body. */
  body?: Record<string, unknown>
}

export async function resourceDelete<T = unknown>(
  path: string,
  options: ResourceDeleteOptions = {},
): Promise<T> {
  try {
    const headers: Record<string, string> = { ...(options.headers ?? {}) }
    if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey

    const response = await http().delete<ApiEnvelope<T>>(apiPath(path), {
      headers,
      ...(options.body === undefined ? {} : { data: options.body }),
    })
    return response.data.data
  } catch (error) {
    translate(error)
  }
}
```

同时更新现有调用与测试：现有 `SystemUsersPage.vue` 的撤角色调用继续只传 `idempotencyKey`，不受影响；新增单测断言 `data.reason` 被发送。

- [ ] **Step 6: 运行测试**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/employeeListWorkspace.spec.ts tests/unit/systemUsersPage.spec.ts
```

Expected: PASS。

- [ ] **Step 7: Commit**

```bash
git add frontend/src/modules/people/pages/EmployeeListPage.vue frontend/src/modules/people/components/EmployeeListWorkspace.vue frontend/src/modules/people/components/EmployeeCreateDialog.vue frontend/src/api/resourceClient.ts frontend/tests/unit/employeeListWorkspace.spec.ts frontend/tests/unit/systemUsersPage.spec.ts
git commit -m "feat(people): rebuild employee list as a task workspace"
```

---

### Task 6: 员工详情页与区域页签

**Files:**
- Modify: `frontend/src/modules/people/pages/EmployeeDetailPage.vue`
- Create: `frontend/src/modules/people/components/EmployeeDetailWorkspace.vue`
- Create: `frontend/src/shared/ui/DetailTabs.vue`
- Create: `frontend/src/modules/people/components/detail/EmployeeOverviewTab.vue`
- Create: `frontend/src/modules/people/components/detail/EmployeeEducationTab.vue`
- Create: `frontend/src/modules/people/components/detail/EmployeeExperiencesTab.vue`
- Create: `frontend/src/modules/people/components/detail/EmployeeContactsTab.vue`
- Create: `frontend/src/modules/people/components/detail/EmployeeCertificatesTab.vue`
- Create: `frontend/src/modules/people/components/detail/EmployeeTimelineTab.vue`
- Create: `frontend/src/modules/people/components/detail/EmployeeFilesTab.vue`
- Create: `frontend/src/modules/people/components/detail/EmployeeChangesTab.vue`
- Test: `frontend/tests/unit/employeeDetailWorkspace.spec.ts`

**Interfaces:**
- Consumes: `GET /v1/employees/{employment_id}`；`GET|POST|PATCH /v1/employees/{employmentId}/education`；`GET|POST|PATCH /v1/employees/{employmentId}/experiences`；`GET|POST|PATCH /v1/employees/{employmentId}/contacts`；`GET|POST|PATCH /v1/employees/{employmentId}/certificates`；`GET /v1/employments/{employment_id}/document-files`；`GET /v1/employments/{employment_id}/timeline`；`GET|POST|PATCH /v1/employees/{employmentId}/change_requests`。
- Produces: `EmployeeDetailWorkspace`，支持 `?tab=education` 深链与返回列表。

- [ ] **Step 1: 写失败测试**

```ts
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'

const resource = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@/api/resourceClient', () => ({ resourceGet: resource.get }))

import EmployeeDetailPage from '@/modules/people/pages/EmployeeDetailPage.vue'

describe('employee detail workspace', () => {
  it('renders tabs inside the same route and deep-links to education', async () => {
    resource.get.mockResolvedValue({
      employment_id: 1, employee_no: 'E1001', person_name: 'Synthetic Employee',
      status: 'ACTIVE', employment_type: 'FULL_TIME',
    })
    const pinia = createPinia()
    setActivePinia(pinia)
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/hr/employees', name: 'hr-employees', component: { template: '<div />' } },
        { path: '/hr/employees/:id', name: 'employee-detail', component: EmployeeDetailPage },
      ],
    })
    await router.push('/hr/employees/1?tab=education')
    await router.isReady()
    const wrapper = mount(EmployeeDetailPage, { global: { plugins: [pinia, router, ElementPlus] } })
    await flushPromises()
    expect(wrapper.text()).toContain('概览')
    expect(wrapper.text()).toContain('教育经历')
    expect(wrapper.text()).not.toContain('部门管理')
    expect(wrapper.text()).toContain('在职')
    expect(wrapper.text()).not.toContain('ACTIVE')
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/employeeDetailWorkspace.spec.ts
```

Expected: FAIL，详情页仍使用 `PeopleWorkspace`，且显示原始枚举。

- [ ] **Step 3: 实现详情工作区**

```vue
<!-- frontend/src/modules/people/components/EmployeeDetailWorkspace.vue -->
<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageShell from '@/shared/ui/PageShell.vue'
import DetailTabs from '@/shared/ui/DetailTabs.vue'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'
import { resourceGet } from '@/api/resourceClient'
import { employeeStatusLabel, employmentTypeLabel } from '@/shared/domain/labels'
import { EMPLOYEE_DETAIL_TABS } from '../catalog'
import EmployeeOverviewTab from './detail/EmployeeOverviewTab.vue'
import EmployeeEducationTab from './detail/EmployeeEducationTab.vue'
import EmployeeExperiencesTab from './detail/EmployeeExperiencesTab.vue'
import EmployeeContactsTab from './detail/EmployeeContactsTab.vue'
import EmployeeCertificatesTab from './detail/EmployeeCertificatesTab.vue'
import EmployeeTimelineTab from './detail/EmployeeTimelineTab.vue'
import EmployeeFilesTab from './detail/EmployeeFilesTab.vue'
import EmployeeChangesTab from './detail/EmployeeChangesTab.vue'

const route = useRoute()
const router = useRouter()
const loading = ref(false)
const error = ref('')
const detail = ref<Record<string, unknown> | null>(null)

const employmentId = computed(() => {
  const value = Number(route.params.id)
  return Number.isSafeInteger(value) && value > 0 ? value : null
})

const activeTab = computed({
  get: () => (typeof route.query.tab === 'string' ? route.query.tab : 'overview'),
  set: (tab: string) => void router.replace({ query: { ...route.query, tab } }),
})

async function load(): Promise<void> {
  if (employmentId.value === null) {
    error.value = '员工编号无效。'
    return
  }
  loading.value = true
  error.value = ''
  try {
    detail.value = await resourceGet<Record<string, unknown>>(`/v1/employees/${employmentId.value}`)
  } catch (reason) {
    error.value = reason instanceof Error ? reason.message : '员工档案暂时无法加载。'
  } finally {
    loading.value = false
  }
}

function backToList(): void {
  void router.push({ name: 'hr-employees' })
}

onMounted(load)
watch(employmentId, load)
</script>

<template>
  <PageShell
    :title="detail ? String(detail.person_name ?? '员工详情') : '员工详情'"
    :description="detail ? `工号 ${String(detail.employee_no ?? '—')} · ${employeeStatusLabel(detail.status)} · ${employmentTypeLabel(detail.employment_type)}` : ''"
    breadcrumb="员工档案"
  >
    <template #actions>
      <el-button @click="backToList">返回列表</el-button>
    </template>
    <LoadingState v-if="loading" :rows="8" label="正在加载员工详情" />
    <ErrorState v-else-if="error" title="无法加载员工详情" :description="error" @retry="load" />
    <template v-else-if="detail && employmentId !== null">
      <DetailTabs v-model="activeTab" :tabs="EMPLOYEE_DETAIL_TABS" />
      <EmployeeOverviewTab v-if="activeTab === 'overview'" :detail="detail" />
      <EmployeeEducationTab v-else-if="activeTab === 'education'" :employment-id="employmentId" />
      <EmployeeExperiencesTab v-else-if="activeTab === 'experiences'" :employment-id="employmentId" />
      <EmployeeContactsTab v-else-if="activeTab === 'contacts'" :employment-id="employmentId" />
      <EmployeeCertificatesTab v-else-if="activeTab === 'certificates'" :employment-id="employmentId" />
      <EmployeeTimelineTab v-else-if="activeTab === 'timeline'" :employment-id="employmentId" />
      <EmployeeFilesTab v-else-if="activeTab === 'files'" :employment-id="employmentId" />
      <EmployeeChangesTab v-else-if="activeTab === 'changes'" :employment-id="employmentId" />
    </template>
  </PageShell>
</template>
```

`EmployeeDetailPage.vue` 只负责组合：

```vue
<script setup lang="ts">
import EmployeeDetailWorkspace from '../components/EmployeeDetailWorkspace.vue'
</script>

<template>
  <EmployeeDetailWorkspace />
</template>
```

- [ ] **Step 4: 实现 DetailTabs**

```vue
<script setup lang="ts">
defineProps<{ modelValue: string; tabs: readonly { key: string; label: string }[] }>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
  <div class="detail-tabs" role="tablist" aria-label="员工档案区域">
    <button
      v-for="tab in tabs"
      :key="tab.key"
      type="button"
      role="tab"
      :aria-selected="modelValue === tab.key"
      :class="{ 'detail-tabs__item--active': modelValue === tab.key }"
      @click="emit('update:modelValue', tab.key)"
    >
      {{ tab.label }}
    </button>
  </div>
</template>

<style scoped>
.detail-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 2px;
  padding: 4px;
  background: var(--color-surface);
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.detail-tabs button {
  min-height: 34px;
  padding: 0 14px;
  color: var(--color-ink-secondary);
  background: transparent;
  border: 0;
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.detail-tabs button[aria-selected='true'] {
  color: #fff;
  font-weight: 600;
  background: var(--color-brand);
}
</style>
```

- [ ] **Step 5: 实现概览页签**

`EmployeeOverviewTab.vue` 用 `el-descriptions`，不写裸 `<table>`：

```vue
<script setup lang="ts">
import { computed } from 'vue'
import { employeeStatusLabel, employmentTypeLabel, assignmentTypeLabel } from '@/shared/domain/labels'
import { formatBusinessDate } from '@/utils/format'
import MaskedField from '@/components/MaskedField.vue'

const props = defineProps<{ detail: Record<string, unknown> }>()

const currentAssignment = computed(() =>
  typeof props.detail.current_assignment === 'object' && props.detail.current_assignment !== null
    ? props.detail.current_assignment as Record<string, unknown>
    : null,
)
</script>

<template>
  <section class="panel">
    <div class="panel__header"><h2 class="panel__title">基本信息</h2></div>
    <div class="panel__body">
      <el-descriptions :column="2" border>
        <el-descriptions-item label="姓名">{{ detail.person_name ?? '—' }}</el-descriptions-item>
        <el-descriptions-item label="工号">{{ detail.employee_no ?? '—' }}</el-descriptions-item>
        <el-descriptions-item label="状态">{{ employeeStatusLabel(detail.status) }}</el-descriptions-item>
        <el-descriptions-item label="用工类型">{{ employmentTypeLabel(detail.employment_type) }}</el-descriptions-item>
        <el-descriptions-item label="入职日期">{{ formatBusinessDate(String(detail.hire_date ?? '')) }}</el-descriptions-item>
        <el-descriptions-item label="任职类型">
          {{ currentAssignment ? assignmentTypeLabel(currentAssignment.assignment_type) : '—' }}
        </el-descriptions-item>
        <el-descriptions-item label="当前组织">{{ currentAssignment?.org_unit_id ?? '—' }}</el-descriptions-item>
        <el-descriptions-item label="当前岗位">{{ currentAssignment?.position_id ?? '—' }}</el-descriptions-item>
      </el-descriptions>
      <MaskedField
        v-if="detail.phone && typeof detail.phone === 'object'"
        :value="detail.phone as never"
        label="手机"
        :can-reveal="false"
      />
    </div>
  </section>
</template>
```

约束：

- 组织/岗位 ID 在详情页应通过组织与岗位接口解析为中文名称；本节先展示 ID，Task 5 的 org/position options 复用解析结果。
- 合同摘要位于“任职与合同”页签，不在概览重复。
- 敏感字段使用 `MaskedField`，查看需填写目的并调用服务端 reveal 端点。

- [ ] **Step 6: 实现子资源页签**

每个页签复用 `ProfileResourceForm` 的读写逻辑，但改为：

- 顶部 `DataTable` 展示现有记录，列定义来自 `catalog-columns.ts`。
- “新增”按钮打开 `FormDialog`。
- 行操作“编辑”打开 `FormDialog`。
- 空态说明“尚未登记教育经历”，并提供新增按钮。
- 成功后只刷新当前页签，不整页刷新。

以教育经历为例：

```vue
<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from '@/components/DataTable.vue'
import FormDialog from '@/shared/ui/FormDialog.vue'
import { resourceGet, resourcePost, resourcePatch } from '@/api/resourceClient'
import { EDUCATION_COLUMNS } from '../../catalog-columns'

const props = defineProps<{ employmentId: number }>()
const rows = ref<Array<Record<string, unknown>>>([])
const loading = ref(false)
const open = ref(false)
const submitting = ref(false)
const editingId = ref<number | null>(null)
const draft = ref({ school: '', degree: '', major: '', start_date: '', end_date: '' })

async function load(): Promise<void> {
  loading.value = true
  try {
    const payload = await resourceGet<{ items?: Array<Record<string, unknown>> }>(
      `/v1/employees/${props.employmentId}/education`,
    )
    rows.value = payload.items ?? []
  } finally {
    loading.value = false
  }
}

function create(): void {
  editingId.value = null
  draft.value = { school: '', degree: '', major: '', start_date: '', end_date: '' }
  open.value = true
}

function edit(row: Record<string, unknown>): void {
  editingId.value = Number(row.id)
  draft.value = {
    school: String(row.school ?? ''),
    degree: String(row.degree ?? ''),
    major: String(row.major ?? ''),
    start_date: String(row.start_date ?? ''),
    end_date: String(row.end_date ?? ''),
  }
  open.value = true
}

async function submit(): Promise<void> {
  submitting.value = true
  try {
    if (editingId.value === null) {
      await resourcePost(`/v1/employees/${props.employmentId}/education`, draft.value, {
        idempotencyKey: `education-create-${props.employmentId}-${Date.now()}`,
      })
    } else {
      await resourcePatch(`/v1/employees/${props.employmentId}/education/${editingId.value}`, draft.value, {
        idempotencyKey: `education-update-${editingId.value}-${Date.now()}`,
      })
    }
    open.value = false
    await load()
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="panel">
    <div class="panel__header">
      <div><h2 class="panel__title">教育经历</h2><p class="panel__subtitle">共 {{ rows.length }} 条</p></div>
      <el-button type="primary" @click="create">新增教育经历</el-button>
    </div>
    <DataTable :columns="[...EDUCATION_COLUMNS]" :rows="rows" :total="rows.length" :page="1" :page-size="rows.length || 10" :loading="loading" />
    <FormDialog v-model="open" :title="editingId === null ? '新增教育经历' : '编辑教育经历'" :submitting="submitting" @submit="submit">
      <el-form label-width="90px">
        <el-form-item label="院校" required><el-input v-model="draft.school" /></el-form-item>
        <el-form-item label="学历" required><el-input v-model="draft.degree" /></el-form-item>
        <el-form-item label="专业"><el-input v-model="draft.major" /></el-form-item>
        <el-form-item label="开始日期"><el-date-picker v-model="draft.start_date" type="date" value-format="YYYY-MM-DD" /></el-form-item>
        <el-form-item label="结束日期"><el-date-picker v-model="draft.end_date" type="date" value-format="YYYY-MM-DD" /></el-form-item>
      </el-form>
    </FormDialog>
  </section>
</template>
```

其余页签按同一模式实现：

- `EmployeeExperiencesTab.vue` → `/v1/employees/{id}/experiences`
- `EmployeeContactsTab.vue` → `/v1/employees/{id}/contacts`
- `EmployeeCertificatesTab.vue` → `/v1/employees/{id}/certificates`
- `EmployeeTimelineTab.vue` → `/v1/employments/{id}/timeline`，只读 `Timeline`
- `EmployeeFilesTab.vue` → `/v1/employments/{id}/document-files`，上传/下载使用既有接口
- `EmployeeChangesTab.vue` → `/v1/employees/{id}/change_requests`，提交/编辑使用既有接口

- [ ] **Step 7: 运行测试**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/employeeDetailWorkspace.spec.ts
```

Expected: PASS。

- [ ] **Step 8: Commit**

```bash
git add frontend/src/modules/people/pages/EmployeeDetailPage.vue frontend/src/modules/people/components/EmployeeDetailWorkspace.vue frontend/src/modules/people/components/detail frontend/src/shared/ui/DetailTabs.vue frontend/tests/unit/employeeDetailWorkspace.spec.ts
git commit -m "feat(people): add context detail route with in-object tabs"
```

---

### Task 7: 员工旧 URL 与旧页面码迁移

**Files:**
- Modify: `frontend/src/router/index.ts`
- Modify: `frontend/src/router/registry.ts`
- Modify: `frontend/src/stores/session.ts`
- Create: `frontend/src/shared/navigation/legacyRedirects.ts`
- Create: `frontend/src/shared/navigation/legacyPageCodes.ts`
- Test: `frontend/tests/unit/legacyRedirects.spec.ts`
- Test: `frontend/tests/unit/legacyPageCodes.spec.ts`

**Interfaces:**
- Consumes: Task 2 的 `redirectFrom`。
- Produces:

```ts
export type LegacyRedirect = {
  from: string
  to: '/hr/employees' | '/hr/employees/:id'
  query: { action?: 'create'; tab?: string }
}

/**
 * Single source of truth for removed employee URLs.
 * Keep this file import-safe: router/index imports it at boot time.
 */
export const LEGACY_EMPLOYEE_REDIRECTS: readonly LegacyRedirect[] = [
  { from: '/hr/employees/create', to: '/hr/employees', query: { action: 'create' } },
  { from: '/hr/employees/:id/education', to: '/hr/employees/:id', query: { tab: 'education' } },
]
```

- [ ] **Step 1: 写失败测试**

```ts
import { describe, expect, it } from 'vitest'
import { LEGACY_EMPLOYEE_REDIRECTS } from '@/shared/navigation/legacyRedirects'

describe('legacy employee redirects', () => {
  it('maps every removed employee page to a workspace or detail tab', () => {
    const from = LEGACY_EMPLOYEE_REDIRECTS.map((item) => item.from)
    expect(from).toContain('/hr/employees/create')
    expect(from).toContain('/hr/employees/:id/education')
    expect(from).toContain('/hr/employees/:id/files')
    for (const redirect of LEGACY_EMPLOYEE_REDIRECTS) {
      expect(redirect.to).toMatch(/^\/hr\/employees(?:\/:id)?$/)
      expect(redirect.query.action ?? redirect.query.tab).toBeTruthy()
    }
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/legacyRedirects.spec.ts tests/unit/legacyPageCodes.spec.ts
```

Expected: FAIL，模块不存在。

- [ ] **Step 3: 实现重定向表**

```ts
// frontend/src/shared/navigation/legacyRedirects.ts
export type LegacyRedirect = {
  from: string
  to: '/hr/employees' | '/hr/employees/:id'
  query: { action?: 'create'; tab?: string }
}

export const LEGACY_EMPLOYEE_REDIRECTS: readonly LegacyRedirect[] = [
  { from: '/hr/employees/create', to: '/hr/employees', query: { action: 'create' } },
  { from: '/hr/employees/:id/education', to: '/hr/employees/:id', query: { tab: 'education' } },
  { from: '/hr/employees/:id/work-history', to: '/hr/employees/:id', query: { tab: 'experiences' } },
  { from: '/hr/employees/:id/contacts', to: '/hr/employees/:id', query: { tab: 'contacts' } },
  { from: '/hr/employees/:id/credentials', to: '/hr/employees/:id', query: { tab: 'certificates' } },
  { from: '/hr/employees/:id/history', to: '/hr/employees/:id', query: { tab: 'timeline' } },
  { from: '/hr/employees/:id/files', to: '/hr/employees/:id', query: { tab: 'files' } },
  { from: '/hr/employees/:id/changes', to: '/hr/employees/:id', query: { tab: 'changes' } },
]
```

- [ ] **Step 4: 接入路由**

在 `frontend/src/router/index.ts` 生成重定向路由：

```ts
...LEGACY_EMPLOYEE_REDIRECTS.map((item) => ({
  path: item.from,
  redirect: (to: RouteLocationNormalized) => ({
    path: item.to.replace(':id', String(to.params.id ?? '')),
    query: { ...item.query },
  }),
})),
```

- [ ] **Step 5: 处理 `action=create`**

在 `EmployeeListWorkspace.vue` 中读取路由查询并在挂载时打开弹窗：

```ts
const route = useRoute()

onMounted(() => {
  if (route.query.action === 'create') createOpen.value = true
  return load()
})
```

当前不存在员工级导入端点，因此旧 `/hr/employees/imports` 路径的迁移在 Task 7 中只做 404 监控与说明，不重定向到假导入弹窗。

- [ ] **Step 6: 页面码弃用映射**

在 `frontend/src/shared/navigation/legacyPageCodes.ts` 建立：

```ts
export const LEGACY_PAGE_CODE_MAP: Record<string, string> = {
  'hr-employees-create': 'hr-employees',
  'hr-employees-by-id-education': 'employee-detail',
  'hr-employees-by-id-work-history': 'employee-detail',
  'hr-employees-by-id-contacts': 'employee-detail',
  'hr-employees-by-id-credentials': 'employee-detail',
  'hr-employees-by-id-history': 'employee-detail',
  'hr-employees-by-id-files': 'employee-detail',
  'hr-employees-by-id-changes': 'employee-detail',
}
```

迁移期在 `frontend/src/stores/session.ts` 的 `canPage()` 中先映射旧码，再判断新码；不要改写服务端返回的 `pages` 数组，否则导航仍会丢失：

```ts
import { LEGACY_PAGE_CODE_MAP } from '@/shared/navigation/legacyPageCodes'

const canPage = (pageCode: string | undefined): boolean => {
  if (!pageCode) return true
  const mapped = LEGACY_PAGE_CODE_MAP[pageCode] ?? pageCode
  return pages.value.some((page) => page.pageCode === mapped || page.pageCode === pageCode || page.path === pageCode)
}
```

同时增加单测：旧码 `hr-employees-create` 在新页面 `hr-employees` 已授权时返回 `true`，新页面未授权时返回 `false`。

- [ ] **Step 7: 运行测试**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/legacyRedirects.spec.ts
```

Expected: PASS。

- [ ] **Step 8: Commit**

```bash
git add frontend/src/router/index.ts frontend/src/router/registry.ts frontend/src/stores/session.ts frontend/src/shared/navigation/legacyRedirects.ts frontend/src/shared/navigation/legacyPageCodes.ts frontend/src/modules/people/components/EmployeeListWorkspace.vue frontend/tests/unit/legacyRedirects.spec.ts frontend/tests/unit/legacyPageCodes.spec.ts
git commit -m "feat(navigation): redirect legacy employee routes to workspaces and tabs"
```

---

### Task 8: 系统用户页重建

**Files:**
- Modify: `frontend/src/modules/platform/pages/SystemUsersPage.vue`
- Create: `frontend/src/modules/platform/components/UserCreateDialog.vue`
- Create: `frontend/src/modules/platform/components/UserRoleDrawer.vue`
- Modify: `frontend/src/shared/domain/labels.ts`
- Test: `frontend/tests/unit/systemUsersPage.spec.ts`

**Interfaces:**
- Consumes: `/v1/users`、`/v1/roles`、`/v1/users/{id}/permissions-preview`、`/v1/users/{id}/roles`。
- Produces: 用户列表 + 新建用户弹窗 + 角色分配抽屉；状态中文化。

- [ ] **Step 1: 写失败测试**

```ts
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ElementPlus from 'element-plus'

const resource = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), delete: vi.fn() }))
vi.mock('@/api/resourceClient', () => ({ resourceGet: resource.get, resourcePost: resource.post, resourceDelete: resource.delete }))

import SystemUsersPage from '@/modules/platform/pages/SystemUsersPage.vue'

describe('system users page', () => {
  it('uses a dialog for creation and Chinese status labels', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/users') {
        return Promise.resolve({
          items: [{ id: 1, username: 'demo.admin', email: 'demo@example.test', status: 'ACTIVE', roles: [{ role_code: 'SYSTEM_ADMIN' }], mfa_enrolled: true }],
          total: 1,
          page: 1,
          page_size: 50,
        })
      }
      if (path === '/v1/roles') {
        return Promise.resolve({ roles: [{ code: 'SYSTEM_ADMIN', name: '系统管理员' }] })
      }
      return Promise.resolve({})
    })
    const wrapper = mount(SystemUsersPage, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(wrapper.text()).toContain('启用')
    expect(wrapper.text()).not.toContain('ACTIVE')
    expect(wrapper.text()).not.toContain('GET /v1/roles')
    expect(wrapper.find('[data-testid="user-create-form"]').exists()).toBe(false)
    await wrapper.get('[data-testid="user-create"]').trigger('click')
    expect(wrapper.find('[data-testid="user-create-form"]').exists()).toBe(true)
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/systemUsersPage.spec.ts
```

Expected: FAIL，页面仍内联创建表单且显示 `ACTIVE`。

- [ ] **Step 3: 重写页面骨架**

```vue
<script setup lang="ts">
import { onMounted, ref } from 'vue'
import PageShell from '@/shared/ui/PageShell.vue'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import UserCreateDialog from '../components/UserCreateDialog.vue'
import UserRoleDrawer from '../components/UserRoleDrawer.vue'
import { userStatusLabel } from '@/shared/domain/labels'
import { resourceGet, resourcePost, resourceDelete } from '@/api/resourceClient'

const users = ref<Array<Record<string, unknown>>>([])
const roles = ref<Array<Record<string, unknown>>>([])
const loading = ref(false)
const createOpen = ref(false)
const roleOpen = ref(false)
const selectedUser = ref<Record<string, unknown> | null>(null)

const columns: DataTableColumn[] = [
  { key: 'username', label: '用户名', minWidth: '140px' },
  { key: 'email', label: '邮箱', minWidth: '200px' },
  { key: 'status', label: '状态', width: '90px' },
  { key: 'roles', label: '角色', minWidth: '160px' },
  { key: 'mfa', label: 'MFA', width: '90px' },
  { key: 'actions', label: '操作', width: '220px' },
]

async function load(): Promise<void> {
  loading.value = true
  try {
    const [userPayload, rolePayload] = await Promise.all([
      resourceGet<{ items?: Array<Record<string, unknown>> }>('/v1/users', { page: 1, page_size: 50 }),
      resourceGet<{ roles?: Array<Record<string, unknown>> }>('/v1/roles'),
    ])
    users.value = userPayload.items ?? []
    roles.value = rolePayload.roles ?? []
  } finally {
    loading.value = false
  }
}

function openRoles(user: Record<string, unknown>): void {
  selectedUser.value = user
  roleOpen.value = true
}

onMounted(load)
</script>

<template>
  <PageShell title="用户管理" description="管理账号、角色与访问权限。" breadcrumb="系统管理">
    <template #actions>
      <el-button type="primary" data-testid="user-create" @click="createOpen = true">新建用户</el-button>
    </template>
    <DataTable :columns="columns" :rows="users" :total="users.length" :page="1" :page-size="50" :loading="loading">
      <template #cell-status="{ row }">{{ userStatusLabel(row.status) }}</template>
      <template #cell-mfa="{ row }">{{ row.mfa_enrolled ? '已启用' : '未启用' }}</template>
      <template #cell-actions="{ row }">
        <el-button text size="small" @click="openRoles(row)">权限预览 / 分配角色</el-button>
      </template>
    </DataTable>
    <UserCreateDialog v-model="createOpen" @created="load" />
    <UserRoleDrawer v-model="roleOpen" :user="selectedUser" :roles="roles" @changed="load" />
  </PageShell>
</template>
```

- [ ] **Step 4: 实现 UserCreateDialog**

```vue
<FormDialog v-model="open" title="新建用户" :submitting="submitting" @submit="submit">
  <form data-testid="user-create-form" class="form-grid" @submit.prevent="submit">
    <el-form-item label="用户名" required><el-input v-model="form.username" /></el-form-item>
    <el-form-item label="邮箱"><el-input v-model="form.email" type="email" /></el-form-item>
    <el-form-item label="初始密码" required><el-input v-model="form.password" type="password" show-password /></el-form-item>
    <el-form-item label="特权账号"><el-switch v-model="form.isPrivileged" active-text="需要 MFA" /></el-form-item>
  </form>
</FormDialog>
```

校验：

- 用户名必填，长度 3–64。
- 密码至少 12 位。
- 邮箱格式。
- 提交失败保留输入。

- [ ] **Step 5: 实现 UserRoleDrawer**

抽屉内分三块：

- 当前角色：表格 + 撤销。
- 分配角色：角色选择、原因、有效期、职责分离风险确认。
- 权限预览：有效页面、有效动作、拒绝项。

- [ ] **Step 6: 运行测试**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/systemUsersPage.spec.ts
```

Expected: PASS。

- [ ] **Step 7: 补充 E2E**

`frontend/tests/e2e/system-users.spec.ts`：

```ts
import { test, expect } from '@playwright/test'

test('create user in a dialog and see Chinese status', async ({ page }) => {
  await page.goto('/system/users')
  await expect(page.getByRole('heading', { name: '用户管理' })).toBeVisible()
  await page.getByRole('button', { name: '新建用户' }).click()
  await expect(page.getByRole('dialog')).toBeVisible()
  await expect(page.getByText('ACTIVE')).toHaveCount(0)
})
```

- [ ] **Step 8: Commit**

```bash
git add frontend/src/modules/platform/pages/SystemUsersPage.vue frontend/src/modules/platform/components/UserCreateDialog.vue frontend/src/modules/platform/components/UserRoleDrawer.vue frontend/tests/unit/systemUsersPage.spec.ts frontend/tests/e2e/system-users.spec.ts
git commit -m "feat(system): rebuild user management with dialogs and shared table"
```

---

### Task 9: 系统角色页重建

**Files:**
- Modify: `frontend/src/modules/platform/pages/SystemRolesPage.vue`
- Create: `frontend/src/modules/platform/components/RolePageMatrix.vue`
- Create: `frontend/src/modules/platform/components/RoleDiffDialog.vue`
- Test: `frontend/tests/unit/rolePermissionMatrix.spec.ts`

**Interfaces:**
- Consumes: `/v1/roles`、`/v1/pages/catalog`、`/v1/roles/{id}/pages`、`/v1/roles/{id}/capabilities`。
- Produces: 角色选择 + 四 Tab 授权工作区 + 差异预览对话框。

- [ ] **Step 1: 写失败测试**

```ts
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ElementPlus from 'element-plus'

const resource = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }))
vi.mock('@/api/resourceClient', () => ({ resourceGet: resource.get, resourcePut: resource.put }))

import SystemRolesPage from '@/modules/platform/pages/SystemRolesPage.vue'

describe('role permission matrix', () => {
  it('shows four tabs and no implementation copy', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/roles') return Promise.resolve({ roles: [{ code: 'SYSTEM_ADMIN', name: '系统管理员', capabilities: [] }] })
      if (path === '/v1/capabilities') return Promise.resolve({ capabilities: [] })
      if (path === '/v1/pages/catalog') return Promise.resolve({ pages: [] })
      return Promise.resolve({ pages: [] })
    })
    const wrapper = mount(SystemRolesPage, { global: { plugins: [ElementPlus] } })
    await flushPromises()
    expect(wrapper.text()).toContain('页面权限')
    expect(wrapper.text()).toContain('按钮权限')
    expect(wrapper.text()).toContain('数据范围')
    expect(wrapper.text()).toContain('字段权限')
    expect(wrapper.text()).not.toContain('role_pages')
    expect(wrapper.text()).not.toContain('(capability, anchor_type, field_profile)')
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/rolePermissionMatrix.spec.ts
```

Expected: FAIL，页面仍显示 `role_pages` 与技术元组。

- [ ] **Step 3: 重写页面说明与布局**

```vue
<PageShell title="角色管理" description="配置角色可见页面、可执行动作、数据范围与字段权限。" breadcrumb="系统管理">
  <div class="role-workspace">
    <aside class="role-workspace__list">
      <el-input v-model="roleSearch" placeholder="搜索角色" clearable />
      <button
        v-for="role in filteredRoles"
        :key="String(role.code)"
        type="button"
        :class="{ 'role-workspace__item--active': role.code === selectedRoleCode }"
        @click="selectRole(role)"
      >
        {{ String(role.name ?? role.code) }}
      </button>
    </aside>
    <section class="role-workspace__content">
      <el-tabs v-model="activeTab">
        <el-tab-pane label="页面权限" name="pages">
          <RolePageMatrix v-model="selectedPageCodes" :groups="pageGroups" />
        </el-tab-pane>
        <el-tab-pane label="按钮权限" name="buttons">
          <DataTable :columns="actionColumns" :rows="actionRows" :total="actionRows.length" :page="1" :page-size="actionRows.length || 10">
            <template #cell-enabled="{ row }">
              <el-switch :model-value="selectedCapabilities.includes(String(row.capability))" @change="toggleCapability(String(row.capability), Boolean($event))" />
            </template>
          </DataTable>
        </el-tab-pane>
        <el-tab-pane label="数据范围" name="scopes">
          <el-table :data="scopedCapabilities" border>
            <el-table-column prop="label" label="动作" min-width="180" />
            <el-table-column label="数据范围" min-width="200">
              <template #default="{ row }">
                <el-select v-model="anchorByCapability[row.capability]">
                  <el-option v-for="anchor in anchorOptions(String(row.capability))" :key="anchor" :label="anchorTypeLabel(anchor)" :value="anchor" />
                </el-select>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>
        <el-tab-pane label="字段权限" name="fields">
          <el-table :data="scopedCapabilities" border>
            <el-table-column prop="label" label="动作" min-width="180" />
            <el-table-column label="字段档位" min-width="200">
              <template #default="{ row }">
                <el-select v-model="fieldProfileByCapability[row.capability]">
                  <el-option v-for="profile in profileOptions(String(row.capability))" :key="profile" :label="fieldProfileLabel(profile)" :value="profile" />
                </el-select>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>
      </el-tabs>
      <el-button type="primary" :disabled="!hasDiff" @click="diffOpen = true">保存授权</el-button>
    </section>
  </div>
  <RoleDiffDialog v-model="diffOpen" :diff="diff" :high-risk="needsHighRiskReason" @confirm="confirmSave" />
</PageShell>
```

页面脚本必须从共享字典导入：

```ts
import { anchorTypeLabel, fieldProfileLabel } from '@/shared/domain/labels'
```

- [ ] **Step 4: 实现 RolePageMatrix**

- 使用 `el-tree` 或分组复选列表。
- 每个页面显示中文菜单名；路径放在技术详情折叠区。
- 父节点全选/半选使用 Element Plus 原生能力。
- 只展示主工作区页面，不展示详情页和已弃用页面。

- [ ] **Step 5: 实现 RoleDiffDialog**

```vue
<FormDialog v-model="modelValue" title="保存前确认" width="720px" :submitting="saving" @submit="emit('confirm')">
  <el-descriptions :column="1" border>
    <el-descriptions-item label="新增页面">{{ diff.pagesAdded.length }} 项</el-descriptions-item>
    <el-descriptions-item label="移除页面">{{ diff.pagesRemoved.length }} 项</el-descriptions-item>
    <el-descriptions-item label="新增动作">{{ diff.capabilitiesAdded.length }} 项</el-descriptions-item>
    <el-descriptions-item label="移除动作">{{ diff.capabilitiesRemoved.length }} 项</el-descriptions-item>
  </el-descriptions>
  <el-input v-if="highRisk" v-model="reason" type="textarea" placeholder="请填写高风险变更原因（至少 8 个字）" />
</FormDialog>
```

- [ ] **Step 6: 运行测试**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/rolePermissionMatrix.spec.ts
```

Expected: PASS。

- [ ] **Step 7: Commit**

```bash
git add frontend/src/modules/platform/pages/SystemRolesPage.vue frontend/src/modules/platform/components/RolePageMatrix.vue frontend/src/modules/platform/components/RoleDiffDialog.vue frontend/tests/unit/rolePermissionMatrix.spec.ts
git commit -m "feat(system): rebuild role management as a governed workspace"
```

---

### Task 10: 部门/岗位工作区迁移

**Files:**
- Modify: `frontend/src/modules/people/pages/index.ts`
- Modify: `frontend/src/modules/people/catalog.ts`
- Create: `frontend/src/modules/people/components/DepartmentWorkspace.vue`
- Create: `frontend/src/modules/people/components/PositionWorkspace.vue`
- Test: `frontend/tests/unit/departmentWorkspace.spec.ts`
- Test: `frontend/tests/unit/positionWorkspace.spec.ts`

**Interfaces:**
- Consumes: Task 3 的页面模型。
- Produces: 部门/岗位各一个主工作区；编辑、成员、迁移、编制、资格、职级、任职人员作为视图/动作。

- [ ] **Step 1: 写失败测试**

```ts
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

describe('department and position workspace model', () => {
  it('does not register action pages', () => {
    const source = readFileSync('src/modules/people/pages/index.ts', 'utf8')
    expect(source).not.toContain('hr/departments/editor')
    expect(source).not.toContain('hr/departments/move')
    expect(source).not.toContain('hr/positions/editor')
    expect(source).not.toContain('hr/positions/headcount')
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/departmentWorkspace.spec.ts
```

Expected: FAIL。

- [ ] **Step 3: 部门工作区**

```text
/hr/departments
├─ 左：组织树
├─ 右：选中部门概览
├─ 动作：新建部门、编辑、移动、添加成员、查看编制
└─ 视图：成员列表 / 编制 / 变更历史
```

- 新建/编辑部门使用 `FormDialog`。
- 移动部门使用 `FormDialog` + 影响预览。
- 成员列表使用 `DataTable`。
- 编制使用表格 + 指标卡。

- [ ] **Step 4: 岗位工作区**

```text
/hr/positions
├─ 岗位目录表格
├─ 动作：新建岗位、编辑、调整编制
└─ 行展开/详情抽屉：任职资格、职级、任职人员
```

- 岗位编辑使用 `FormDialog`。
- 任职资格、职级、任职人员使用行展开或详情抽屉。
- 不再注册 `/editor`、`/headcount`、`/requirements`、`/grades`、`/staff` 页面。

- [ ] **Step 5: 旧 URL 重定向**

在 `legacyRedirects.ts` 增加部门/岗位映射：

```ts
{ from: '/hr/departments/editor', to: '/hr/departments', query: { action: 'create' } },
{ from: '/hr/departments/move', to: '/hr/departments', query: { action: 'move' } },
{ from: '/hr/positions/editor', to: '/hr/positions', query: { action: 'create' } },
{ from: '/hr/positions/headcount', to: '/hr/positions', query: { view: 'headcount' } },
```

- [ ] **Step 6: 运行测试**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/departmentWorkspace.spec.ts tests/unit/positionWorkspace.spec.ts tests/unit/legacyRedirects.spec.ts
```

Expected: PASS。

- [ ] **Step 7: Commit**

```bash
git add frontend/src/modules/people/pages/index.ts frontend/src/modules/people/catalog.ts frontend/src/modules/people/components/DepartmentWorkspace.vue frontend/src/modules/people/components/PositionWorkspace.vue frontend/src/shared/navigation/legacyRedirects.ts frontend/tests/unit/departmentWorkspace.spec.ts frontend/tests/unit/positionWorkspace.spec.ts
git commit -m "refactor(people): collapse department and position action pages into workspaces"
```

---

### Task 11: 异动/合同工作区迁移

**Files:**
- Modify: `frontend/src/modules/people/pages/index.ts`
- Modify: `frontend/src/modules/people/catalog.ts`
- Create: `frontend/src/modules/people/components/MovementWorkspace.vue`
- Create: `frontend/src/modules/people/components/ContractWorkspace.vue`
- Test: `frontend/tests/unit/movementWorkspace.spec.ts`
- Test: `frontend/tests/unit/contractWorkspace.spec.ts`

**Interfaces:**
- Consumes: `/v1/lifecycle/cases`、`/v1/employments/contracts`、`/v1/contract-templates`。
- Produces: 异动台账 + 发起异动弹窗；合同台账 + 录入/续签/变更弹窗。

- [ ] **Step 1: 写失败测试**

```ts
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

describe('movement and contract workspace model', () => {
  it('does not register movement type or contract action pages', () => {
    const source = readFileSync('src/modules/people/pages/index.ts', 'utf8')
    for (const path of [
      'hr/movements/onboarding', 'hr/movements/probation', 'hr/movements/transfer',
      'hr/movements/salary-change', 'hr/movements/secondment', 'hr/movements/offboarding',
      'hr/contracts/editor', 'hr/contracts/renewals', 'hr/contracts/changes', 'hr/contracts/templates',
    ]) {
      expect(source, path).not.toContain(path)
    }
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/movementWorkspace.spec.ts
```

Expected: FAIL。

- [ ] **Step 3: 异动工作区**

```text
/hr/movements
├─ 台账：单号、员工、异动类型、申请状态、生效状态、拟生效日
├─ 筛选：类型、状态、生效日期
├─ 动作：发起异动（选择类型后打开对应表单弹窗）
├─ 行操作：查看详情抽屉、撤回（有权限时）
└─ 视图：日历、待办
```

异动类型：

- 入职办理
- 转正
- 调岗/调部门
- 调薪
- 借调/兼岗
- 离职

每种类型使用同一个 `MovementFormDialog`，通过 `type` 参数切换字段，不新增页面。

- [ ] **Step 4: 合同工作区**

```text
/hr/contracts
├─ 台账：合同编号、员工、类型、起止日期、状态、剩余天数
├─ 筛选：到期窗口、状态、组织
├─ 动作：录入合同、续签、变更/终止
├─ 行操作：查看详情抽屉、下载附件
└─ 视图：到期预警、合同模板
```

- 合同录入/续签/变更使用 `ContractFormDialog`。
- 合同模板使用独立治理页或抽屉，不进入合同台账的菜单。
- 合同详情使用抽屉，避免为单个合同再增加路由。

- [ ] **Step 5: 旧 URL 重定向**

```ts
{ from: '/hr/movements/onboarding', to: '/hr/movements', query: { action: 'onboarding' } },
{ from: '/hr/movements/transfer', to: '/hr/movements', query: { action: 'transfer' } },
{ from: '/hr/contracts/editor', to: '/hr/contracts', query: { action: 'create' } },
{ from: '/hr/contracts/renewals', to: '/hr/contracts', query: { view: 'renewals' } },
```

- [ ] **Step 6: 运行测试**

Run:

```bash
cd frontend && pnpm vitest run tests/unit/movementWorkspace.spec.ts tests/unit/contractWorkspace.spec.ts tests/unit/legacyRedirects.spec.ts
```

Expected: PASS。

- [ ] **Step 7: Commit**

```bash
git add frontend/src/modules/people/pages/index.ts frontend/src/modules/people/catalog.ts frontend/src/modules/people/components/MovementWorkspace.vue frontend/src/modules/people/components/ContractWorkspace.vue frontend/src/shared/navigation/legacyRedirects.ts frontend/tests/unit/movementWorkspace.spec.ts frontend/tests/unit/contractWorkspace.spec.ts
git commit -m "refactor(people): collapse movement and contract action pages into workspaces"
```

---

### Task 12: 文案门禁与裸控件门禁

**Files:**
- Create: `scripts/audit_user_facing_copy.mjs`
- Create: `scripts/audit_ui_primitives.mjs`
- Modify: `package.json`
- Test: `scripts/tests/audit_user_facing_copy.test.mjs`
- Test: `scripts/tests/audit_ui_primitives.test.mjs`

**Interfaces:**
- Produces:

```bash
pnpm copy:check
pnpm ui-primitives:check
```

- [ ] **Step 1: 写文案门禁失败测试**

```js
import { describe, expect, it } from 'node:test'
import assert from 'node:assert/strict'
import { scanUserFacingCopy } from '../audit_user_facing_copy.mjs'

describe('user-facing copy audit', () => {
  it('flags API paths, table names and raw enums', () => {
    const result = scanUserFacingCopy([
      { file: 'A.vue', text: '<p>字段由 GET /v1/reporting/definitions 返回</p>' },
      { file: 'B.vue', text: '<td>{{ row.status }}</td>' },
      { file: 'C.vue', text: '<span>MINOR_UNITS</span>' },
    ])
    assert.equal(result.ok, false)
    assert.match(result.findings.join('\n'), /GET \/v1\//)
    assert.match(result.findings.join('\n'), /raw enum/)
    assert.match(result.findings.join('\n'), /MINOR_UNITS/)
  })
})
```

- [ ] **Step 2: 实现文案扫描**

规则：

- `GET /v1/`、`POST /v1/`、`PUT /v1/`、`DELETE /v1/`
- `role_pages`、`field_profile`、`tenant_id`、`legal_entity_id`
- 裸枚举：`ACTIVE`、`FULL_TIME`、`MINOR_UNITS`、`P2_SENSITIVE` 等
- 实现状态文案：`尚未实现`、`计划路径`、`实时接口`、`演示`、`demo`
- 允许清单：`docs/`、`scripts/`、管理员技术详情组件；必须逐文件说明原因。

- [ ] **Step 3: 写裸控件门禁失败测试**

```js
import { describe, expect, it } from 'node:test'
import assert from 'node:assert/strict'
import { scanUiPrimitives } from '../audit_ui_primitives.mjs'

describe('ui primitive audit', () => {
  it('flags a page that renders a raw table as its main layout', () => {
    const result = scanUiPrimitives([
      { file: 'frontend/src/modules/platform/pages/SystemUsersPage.vue', text: '<template><table class="platform-table">...</table></template>' },
    ])
    assert.equal(result.ok, false)
    assert.match(result.findings.join('\n'), /raw table/)
  })
})
```

- [ ] **Step 4: 实现裸控件扫描**

规则：

- 页面模板中出现 `<table`、`<input`、`<select`、`<details` 时失败。
- 允许：
  - `DataTable.vue` 内部的 `<table>`；
  - `FormDialog`/`FormDrawer` 内部的 Element Plus 组件；
  - 第三方只读渲染组件，并有 allowlist。
- 页面必须使用 `PageShell`、`DataTable`、`el-form`、`el-dialog`/`el-drawer`。

- [ ] **Step 5: 加入 package.json**

```json
{
  "scripts": {
    "copy:check": "node scripts/audit_user_facing_copy.mjs",
    "ui-primitives:check": "node scripts/audit_ui_primitives.mjs"
  }
}
```

- [ ] **Step 6: 运行门禁**

Run:

```bash
pnpm copy:check
pnpm ui-primitives:check
```

Expected: 初始失败并列出所有待修页面；在 Task 5/6/8/9/10/11 完成后逐项清零。

- [ ] **Step 7: Commit**

```bash
git add scripts/audit_user_facing_copy.mjs scripts/audit_ui_primitives.mjs scripts/tests/audit_user_facing_copy.test.mjs scripts/tests/audit_ui_primitives.test.mjs package.json
git commit -m "test(ui): add user-facing copy and UI primitive gates"
```

---

### Task 13: 产品级任务闭环门禁

**Files:**
- Create: `scripts/audit_task_closures.mjs`
- Modify: `frontend/src/router/registry.ts`
- Modify: `package.json`
- Test: `scripts/tests/audit_task_closures.test.mjs`

**Interfaces:**
- Produces:

```ts
export type TaskClosure = {
  page: string
  primaryObject: string
  list: string
  createAction?: string
  detailRoute?: string
  /** Only declare when a real mutation endpoint exists. */
  editAction?: string
  archiveAction?: string
  restoreAction?: string
}
```

- [ ] **Step 1: 写失败测试**

```js
import { describe, expect, it } from 'node:test'
import assert from 'node:assert/strict'
import { auditTaskClosures } from '../audit_task_closures.mjs'

describe('task closure audit', () => {
  it('fails when an employee workspace has no detail route', () => {
    const result = auditTaskClosures([
      { page: 'hr-employees', primaryObject: 'employee', list: '/v1/employees' },
    ])
    assert.equal(result.ok, false)
    assert.match(result.findings.join('\n'), /employee.*detailRoute/)
  })
})
```

- [ ] **Step 2: 实现门禁**

规则：

- 每个 `workspace` 页面必须有 `primaryObject`。
- 有详情数据的对象必须有 `detailRoute`。
- 有创建能力的对象必须有 `createAction`，且该能力必须存在于能力目录。
- `editAction` 只能指向真实存在的写端点；当前员工主档没有 PATCH，因此不得伪造 `people.employee.update`。
- 有归档能力的对象必须有 `archiveAction` 和 `restoreAction`。
- 动作必须是 capability 字符串，不得是页面路径。
- 详情路由必须 `menu: null` 且 `detailOf` 指向该工作区。

- [ ] **Step 3: 在注册表补充任务闭环声明**

```ts
{
  path: '/hr/employees',
  name: 'hr-employees',
  kind: 'workspace',
  primaryObject: 'employee',
  taskClosure: {
    list: '/v1/employees',
    createAction: 'people.employee.manage',
    detailRoute: 'employee-detail',
    archiveAction: 'people.employee.archive',
    restoreAction: 'people.employee.restore',
  },
}
```

- [ ] **Step 4: 加入 package.json**

```json
{
  "scripts": {
    "task-closures:check": "node scripts/audit_task_closures.mjs"
  }
}
```

- [ ] **Step 5: 运行门禁**

Run:

```bash
pnpm task-closures:check
```

Expected: 初始失败并列出缺失声明；在 Task 3/5/6/10/11 完成后清零。

- [ ] **Step 6: Commit**

```bash
git add scripts/audit_task_closures.mjs scripts/tests/audit_task_closures.test.mjs frontend/src/router/registry.ts package.json
git commit -m "test(navigation): require executable task closures"
```

---

### Task 14: E2E、无障碍与回归

**Files:**
- Modify: `frontend/tests/e2e/smoke.spec.ts`
- Modify: `frontend/tests/e2e/a11y.spec.ts`
- Create: `frontend/tests/e2e/employee-workspace.spec.ts`
- Create: `frontend/tests/e2e/system-governance.spec.ts`
- Create: `frontend/tests/e2e/legacy-redirects.spec.ts`

**Interfaces:**
- Consumes: Task 5–11 的页面。
- Produces: 列表 → 详情 → 返回、弹窗创建、旧 URL 重定向、字符中文、键盘可达。

- [ ] **Step 1: 员工工作区 E2E**

```ts
import { test, expect } from '@playwright/test'

test('employee list, detail tabs and create dialog', async ({ page }) => {
  await page.goto('/hr/employees')
  await expect(page.getByRole('heading', { name: '员工列表' })).toBeVisible()
  await expect(page.getByText('部门管理')).toHaveCount(0)
  await page.getByRole('button', { name: '新建员工' }).click()
  await expect(page.getByRole('dialog')).toBeVisible()
  await page.getByRole('button', { name: '取消' }).click()
  await page.getByRole('button', { name: '查看' }).first().click()
  await expect(page.getByRole('heading', { name: /Synthetic Employee/ })).toBeVisible()
  await page.getByRole('tab', { name: '教育经历' }).click()
  await expect(page).toHaveURL(/tab=education/)
  await page.getByRole('button', { name: '返回列表' }).click()
  await expect(page.getByRole('heading', { name: '员工列表' })).toBeVisible()
})
```

- [ ] **Step 2: 系统治理 E2E**

```ts
test('system users and roles use dialogs and Chinese labels', async ({ page }) => {
  await page.goto('/system/users')
  await expect(page.getByRole('heading', { name: '用户管理' })).toBeVisible()
  await expect(page.getByText('ACTIVE')).toHaveCount(0)
  await page.getByRole('button', { name: '新建用户' }).click()
  await expect(page.getByRole('dialog')).toBeVisible()
  await page.goto('/system/roles')
  await expect(page.getByRole('tab', { name: '页面权限' })).toBeVisible()
  await expect(page.getByText('role_pages')).toHaveCount(0)
})
```

- [ ] **Step 3: 旧 URL 重定向 E2E**

```ts
for (const [from, to] of [
  ['/hr/employees/create', '/hr/employees?action=create'],
  ['/hr/employees/1/education', '/hr/employees/1?tab=education'],
  ['/hr/positions/editor', '/hr/positions?action=create'],
]) {
  test(`redirects ${from}`, async ({ page }) => {
    await page.goto(from)
    await expect(page).toHaveURL(new RegExp(to.replace(/[?]/g, '\\?')))
  })
}
```

- [ ] **Step 4: 无障碍检查**

```ts
import AxeBuilder from '@axe-core/playwright'

test('employee workspace has no critical accessibility violations', async ({ page }) => {
  await page.goto('/hr/employees')
  const results = await new AxeBuilder({ page }).analyze()
  expect(results.violations.filter((v) => ['critical', 'serious'].includes(v.impact ?? ''))).toEqual([])
})
```

- [ ] **Step 5: 运行 E2E**

Run:

```bash
cd frontend && pnpm test:e2e
```

Expected: PASS；若真实栈未启动，先按 `deploy/scripts/docker_up.sh` 启动并设置 `VITE_API_MODE=http`。

- [ ] **Step 6: Commit**

```bash
git add frontend/tests/e2e
git commit -m "test(e2e): cover employee workspaces, governance pages and legacy redirects"
```

---

### Task 15: 旧页面码清理与文档收尾

**Files:**
- Modify: `frontend/src/router/registry.ts`
- Modify: `frontend/src/router/navigation.ts`
- Modify: `docs/audit/2026-11-25-v11-page-inventory.md`（由生成器重新生成）
- Modify: `docs/05-ui-guidelines.md`
- Modify: `docs/02-permissions.md`
- Modify: `HRMS-V1.1实施书.md`
- Test: `pnpm typecheck && pnpm lint && pnpm test:unit && pnpm test:e2e`

**Interfaces:**
- Consumes: Task 7 的弃用映射与 Task 13 的任务闭环声明。
- Produces: 页面码清单、菜单、权限矩阵、文档一致。

- [ ] **Step 1: 重新生成页面清单**

Run:

```bash
python scripts/docs/generate_v11_inventory.py
```

Expected: 页面清单只包含主工作区与上下文详情页；动作/视图不再列为页面。

- [ ] **Step 2: 更新权限文档**

在 `docs/02-permissions.md` 增加：

> ```markdown
> ## Page and Action Permission Split
>
> - Page permission controls entry to a workspace or context detail route.
> - Action capability controls create, archive, restore, sub-resource edit and assignment; employee bulk import/export await dedicated endpoints.
> - Detail tabs are not independently granted.
> - Removed page codes map to their replacement during one release cycle.
> ```

- [ ] **Step 3: 更新 UI 规范**

在 `docs/05-ui-guidelines.md` 增加：

> ```markdown
> ## 13. 页面任务闭环
>
> - 每个主工作区必须提供列表、创建、详情、归档/恢复（如适用）；主档编辑仅在真实写端点存在时提供。
> - 创建/子档案编辑使用弹窗或抽屉，不得注册为页面。
> - 详情页必须有返回列表和稳定 URL。
> - 页面内页签不得跨模块。
> ```

- [ ] **Step 4: 清理旧页面码**

删除 `LEGACY_PAGE_CODE_MAP` 中已过期的映射，保留一个发布周期的记录在 `docs/audit/`。

- [ ] **Step 5: 运行全量门禁**

Run:

```bash
cd frontend
pnpm typecheck
pnpm lint
pnpm test:unit
pnpm test:e2e
cd ..
pnpm copy:check
pnpm ui-primitives:check
pnpm task-closures:check
pnpm page-components:check
pnpm modules:check
pnpm openapi:validate
node deploy/scripts/openapi-lint.mjs
```

Expected: 全部 PASS；任何一项失败都不得标记完成。

- [ ] **Step 6: Commit**

```bash
git add frontend/src/router/registry.ts frontend/src/router/navigation.ts docs/audit/2026-11-25-v11-page-inventory.md docs/05-ui-guidelines.md docs/02-permissions.md HRMS-V1.1实施书.md
git commit -m "docs(ui): align page inventory, permissions and task-closure rules"
```

---

## 4. 验收矩阵

| 用户问题 | 对应任务 | 自动化验收 | 人工验收 |
|---|---|---|---|
| 跨模块 Tab | Task 3、12 | `peopleNavigation.spec.ts`、`ui-primitives:check` | 员工页无部门/岗位/合同入口 |
| CURD 拆页、无详情入口 | Task 2、3、5、6、7、13 | `employeeListWorkspace.spec.ts`、`employeeDetailWorkspace.spec.ts`、`task-closures:check` | 列表可新建/查看/归档；详情可返回并维护子档案 |
| 英文枚举 | Task 4、12 | `labels.spec.ts`、`copy:check` | 列表显示“在职/全职”，不显示 `ACTIVE/FULL_TIME` |
| 技术说明 | Task 4、8、9、12 | `copy:check` | 主界面无接口路径、表名、字段档位 |
| 无样式、非弹窗 | Task 4、8、9、12 | `ui-primitives:check`、`systemUsersPage.spec.ts`、`rolePermissionMatrix.spec.ts` | 用户/角色页使用统一表格、Tab、弹窗 |
| 旧链接失效 | Task 7、10、11、14 | `legacyRedirects.spec.ts`、`legacy-redirects.spec.ts` | 旧 URL 可打开新工作区 |

---

## 5. 风险与回滚

| 风险 | 触发信号 | 回滚/缓解 |
|---|---|---|
| 旧页面码映射遗漏 | 用户报告旧书签 404 | 保留映射表；增加 404 监控；逐条补 E2E |
| 权限矩阵重排导致角色失去入口 | 登录后菜单减少 | 迁移期旧码映射到新码；发布前对比角色页面数 |
| 统一表单弹窗不适合复杂表单 | 用户反馈字段过多 | 使用 `FormDrawer` 或分步表单；不退回独立页面 |
| 详情页过大 | 单文件超过 600 行 | 按页签拆子组件；共享读取 composable |
| 门禁误报 | 合理页面使用原生表格 | 逐文件 allowlist + ADR 说明；禁止全局关闭 |
| 移动端弹窗体验差 | 360px 下按钮不可见 | 弹窗宽度 `calc(100vw - 24px)`；必要时全屏抽屉 |

回滚策略：

- 每个 Task 独立提交，可按提交回滚。
- 旧 URL 重定向表和旧页面码映射保留，回滚时不立即删除。
- 页面权限迁移使用双写/并集，确认后再清理旧码。
- 门禁新增规则先以 warning 运行一个迭代，再升级为 error；升级必须有 ADR。

---

## 6. 完成定义

- [ ] 员工模块只有 2 个页面码：列表和详情。
- [ ] 员工列表无跨模块 Tab。
- [ ] 员工列表可完成新建、查看、归档、恢复；详情内可维护任职/条款版本和子档案。
- [ ] 员工详情支持 `?tab=` 深链和返回列表。
- [ ] 系统用户/角色页使用 `PageShell`、`DataTable`、`el-tabs`、`el-dialog`/`el-drawer`。
- [ ] 主界面无英文枚举、接口路径、表名、字段档位、演示/缺口文案。
- [ ] 旧 URL 全部有重定向 E2E。
- [ ] 员工级导入/导出在补口前无假入口；补口后按同一工作区模式上线。
- [ ] `copy:check`、`ui-primitives:check`、`task-closures:check`、`page-components:check`、`modules:check` 全绿。
- [ ] `pnpm typecheck`、`pnpm lint`、`pnpm test:unit`、`pnpm test:e2e` 全绿。
- [ ] 文档、页面清单、权限矩阵与实现一致。

---

## 7. 执行建议

推荐使用 **Subagent-Driven**：

1. Task 1–4 由一个子代理完成契约与共享层；
2. Task 5–7 由一个子代理完成员工样板；
3. Task 8–9 由一个子代理完成系统管理；
4. Task 10–11 由一个子代理完成其余 People 工作区；
5. Task 12–15 由一个子代理完成门禁、E2E 与文档。

每个 Task 完成后必须：

- 运行该 Task 的测试命令；
- 记录实际输出；
- 由另一个子代理做代码评审；
- 通过后再进入下一个 Task。

不得把“页面文件存在”当作完成；不得把“测试通过”当作产品验收；不得在真实栈 E2E 未通过时宣称生产就绪。
