# FactoryHR V2.1 产品级门禁存量基线（2026-11-26）

- 状态：**存量违规未清零**。本文件是第 2/3 册的输入，不是第 1 册的通过证据。
- 基线命令执行时 HEAD：`7e3553f`（本文件与三个门禁随后同一提交）。
- Node：`v24.19.0`；运行目录：仓库根。
- 注册表存量：`PAGE_REGISTRY` 160 条，`actions: []` 158 条（实测命令 `rg -c "actions: \[\]" frontend/src/router/registry.ts`）。
- 归属规则：平台/系统管理页的违规归 **Volume 2**；People 与 Talent 业务页、以及报表/公告业务页归 **Volume 3**；共享壳文件按其第一个必须消费它的业务页归属，最终删除由 Volume 4 Task 7 执行。

> 失败列表由三个 CLI 的真实输出原样复制；本次三个门禁均退出 `1`。三个门禁的反例 fixture 测试全部通过，但这不表示真实仓库门禁通过。

> 输出口径：`ui-primitives` 按“文件 + 标签类型”各报一条，`copy` 按“文件 + 规则”各报一条，均报告首次命中行。`PlatformWorkspace.vue`、`TalentForm.vue`、`TalentWorkspacePage.vue` 为多模式共享文件，一条失败代表该文件内同类违规的整体清理责任；归属表已将首个命中行映射到具体业务段与关闭册。

> 确定性：三个 CLI 各连续执行两次，`diff` 均为空。

## 1. 页面模型门禁

- 命令：`node scripts/audit_page_model.mjs`
- 退出码：`1`
- 失败数：**12**（路径类 7，跨模块 Tab 类 5）
- 原始输出：

```json
{
  "ok": false,
  "failures": [
    "hr-employees-create: action-like path /hr/employees/create",
    "hr-departments-editor: action-like path /hr/departments/editor",
    "hr-positions-editor: action-like path /hr/positions/editor",
    "hr-contracts-editor: action-like path /hr/contracts/editor",
    "recruitment-requests-create: action-like path /recruitment/requests/create",
    "notices-editor: action-like path /notices/editor",
    "notices-archive: action-like path /notices/archive",
    "frontend/src/modules/people/components/PeopleWorkspace.vue: cross-module tab 员工档案",
    "frontend/src/modules/people/components/PeopleWorkspace.vue: cross-module tab 部门管理",
    "frontend/src/modules/people/components/PeopleWorkspace.vue: cross-module tab 岗位管理",
    "frontend/src/modules/people/components/PeopleWorkspace.vue: cross-module tab 员工异动",
    "frontend/src/modules/people/components/PeopleWorkspace.vue: cross-module tab 劳动合同"
  ]
}
```

### 逐条归属

| # | 失败 | 归属 | 关闭动作 |
| ---: | --- | --- | --- |
| 1 | `hr-employees-create` action-like path `/hr/employees/create` | Volume 3 Task 2 | 新建并入 `employees` 主工作台抽屉，删除独立路由/页面码。 |
| 2 | `hr-departments-editor` action-like path `/hr/departments/editor` | Volume 3 Task 5 | 新建/编辑并入部门工作台弹窗。 |
| 3 | `hr-positions-editor` action-like path `/hr/positions/editor` | Volume 3 Task 6 | 新建/编辑并入岗位工作台弹窗。 |
| 4 | `hr-contracts-editor` action-like path `/hr/contracts/editor` | Volume 3 Task 8 | 合同草稿/续签/变更/终止并入合同工作台抽屉。 |
| 5 | `recruitment-requests-create` action-like path `/recruitment/requests/create` | Volume 3 Task 14 | 招聘需求新建并入 `recruitment-requests` 工作台创建抽屉。 |
| 6 | `notices-editor` action-like path `/notices/editor` | Volume 3 Task 16 | 公告编辑并入 `notices` 工作台编辑抽屉。 |
| 7 | `notices-archive` action-like path `/notices/archive` | Volume 3 Task 16 | 公告归档并入 `notices` 归档动作。 |
| 8 | `PeopleWorkspace.vue` cross-module tab 员工档案 | Volume 3 Task 2（壳退役 Volume 4 Task 7） | 员工列表独立页面；删除页内模块快捷导航，导航只来自全局侧栏。 |
| 9 | `PeopleWorkspace.vue` cross-module tab 部门管理 | Volume 3 Task 5（壳退役 Volume 4 Task 7） | 部门工作台独立；删除员工页内部门入口。 |
| 10 | `PeopleWorkspace.vue` cross-module tab 岗位管理 | Volume 3 Task 6（壳退役 Volume 4 Task 7） | 岗位工作台独立；删除员工页内岗位入口。 |
| 11 | `PeopleWorkspace.vue` cross-module tab 员工异动 | Volume 3 Task 7（壳退役 Volume 4 Task 7） | 异动工作台独立；删除员工页内异动入口。 |
| 12 | `PeopleWorkspace.vue` cross-module tab 劳动合同 | Volume 3 Task 8（壳退役 Volume 4 Task 7） | 合同工作台独立；删除员工页内合同入口。 |

## 2. 裸控件门禁

- 命令：`node scripts/audit_ui_primitives.mjs`
- 退出码：`1`
- 失败数：**11**（`SystemRolesPage` 3、`SystemUsersPage` 3、`TalentForm` 3、`TalentWorkspacePage` 2）
- 原始输出：

```json
{
  "ok": false,
  "failures": [
    "frontend/src/modules/platform/pages/SystemRolesPage.vue: raw input (line 277)",
    "frontend/src/modules/platform/pages/SystemRolesPage.vue: raw select (line 302)",
    "frontend/src/modules/platform/pages/SystemRolesPage.vue: raw details (line 274)",
    "frontend/src/modules/platform/pages/SystemUsersPage.vue: raw table (line 149)",
    "frontend/src/modules/platform/pages/SystemUsersPage.vue: raw input (line 173)",
    "frontend/src/modules/platform/pages/SystemUsersPage.vue: raw select (line 193)",
    "frontend/src/modules/talent/TalentForm.vue: raw table (line 1163)",
    "frontend/src/modules/talent/TalentForm.vue: raw input (line 1013)",
    "frontend/src/modules/talent/TalentForm.vue: raw select (line 1069)",
    "frontend/src/modules/talent/components/TalentWorkspacePage.vue: raw table (line 262)",
    "frontend/src/modules/talent/components/TalentWorkspacePage.vue: raw input (line 243)"
  ]
}
```

### 逐条归属

| # | 失败 | 归属 | 关闭动作 |
| ---: | --- | --- | --- |
| 1 | `SystemRolesPage.vue` raw input | Volume 2 Task 7 | 四个 Tab 使用 `ElTree`/`ElTable`/`ElCheckbox`/`ElDialog`，删除裸 checkbox/input。 |
| 2 | `SystemRolesPage.vue` raw select | Volume 2 Task 7 | 数据范围/字段策略使用统一选择组件，删除裸 select。 |
| 3 | `SystemRolesPage.vue` raw details | Volume 2 Task 7 | 页面权限分组改用 `ElTree`（或统一 Tabs/折叠组件），删除裸 details。 |
| 4 | `SystemUsersPage.vue` raw table | Volume 2 Task 6 | 使用 `PageShell` + `DataTable`。 |
| 5 | `SystemUsersPage.vue` raw input | Volume 2 Task 6 | 新建/编辑/角色分配表单使用 `FormDialog`/`ElForm`。 |
| 6 | `SystemUsersPage.vue` raw select | Volume 2 Task 6 | 角色选择使用 `ElSelect`。 |
| 7 | `TalentForm.vue` raw table | Volume 3 Task 14 | 候选人导入结果使用 `DataTable`。 |
| 8 | `TalentForm.vue` raw input | Volume 3 Task 13 | 绩效/招聘/培训表单改用 `ElForm` + 共享表单控件。 |
| 9 | `TalentForm.vue` raw select | Volume 3 Task 13 | 同上，枚举选择使用 `ElSelect` + 中文字典。 |
| 10 | `TalentWorkspacePage.vue` raw table | Volume 3 Task 13 | 列表改用 `DataTable`；壳由 Volume 4 Task 7 删除。 |
| 11 | `TalentWorkspacePage.vue` raw input | Volume 3 Task 14 | 候选人子资源范围改用 `ElInput`/组件；壳由 Volume 4 Task 7 删除。 |

## 3. 用户文案门禁

- 命令：`node scripts/audit_user_copy.mjs`
- 退出码：`1`
- 失败数：**12**（API path 4、English enum 5、development marker 1、field profile code 2）
- 原始输出：

```json
{
  "ok": false,
  "failures": [
    "frontend/src/modules/people/components/ContractForm.vue: English enum 'ACTIVE' (line 29)",
    "frontend/src/modules/people/components/EmployeeCreateForm.vue: English enum 'FULL_TIME' (line 16)",
    "frontend/src/modules/people/components/MovementForms.vue: API path 'POST /v1/' (line 240)",
    "frontend/src/modules/people/components/MovementForms.vue: English enum 'FULL_TIME' (line 49)",
    "frontend/src/modules/people/components/PeopleWorkspace.vue: development marker 'Wave' (line 144)",
    "frontend/src/modules/people/components/PositionForms.vue: API path 'POST /v1/' (line 77)",
    "frontend/src/modules/people/components/PositionForms.vue: English enum 'ACTIVE' (line 40)",
    "frontend/src/modules/platform/components/PlatformWorkspace.vue: API path 'GET /v1/' (line 2230)",
    "frontend/src/modules/platform/components/PlatformWorkspace.vue: English enum 'ACTIVE' (line 152)",
    "frontend/src/modules/platform/components/PlatformWorkspace.vue: field profile code 'P1_INTERNAL' (line 170)",
    "frontend/src/modules/platform/pages/SystemRolesPage.vue: field profile code 'P0_PUBLIC' (line 15)",
    "frontend/src/modules/platform/pages/SystemUsersPage.vue: API path 'GET /v1/' (line 137)"
  ]
}
```

### 逐条归属

| # | 失败 | 归属 | 关闭动作 |
| ---: | --- | --- | --- |
| 1 | `ContractForm.vue` English enum `ACTIVE`（line 29，脚本内默认状态值） | Volume 3 Task 8 | 合同状态经 `translate('people','contractStatus',…)` 中文渲染；默认值可保留码，但不得出现在用户可见文案/模板；合同表单重构后复核。 |
| 2 | `EmployeeCreateForm.vue` English enum `FULL_TIME` | Volume 3 Task 2 | 用工类型/初始状态使用中文字典与 `ElSelect`，码只作提交值。 |
| 3 | `MovementForms.vue` API path | Volume 3 Task 7 | 删除用户可见的 `POST /v1/...` 说明，改为业务语言。 |
| 4 | `MovementForms.vue` English enum `FULL_TIME` | Volume 3 Task 7 | 用工类型中文显示。 |
| 5 | `PeopleWorkspace.vue` development marker `Wave` | Volume 3 Task 2（壳退役 Volume 4 Task 7） | 删除 `Wave 2` 面包屑；页面归属文字改为业务分组。 |
| 6 | `PositionForms.vue` API path | Volume 3 Task 6 | 删除接口路径说明。 |
| 7 | `PositionForms.vue` English enum `ACTIVE` | Volume 3 Task 6 | 岗位状态中文显示。 |
| 8 | `PlatformWorkspace.vue` API path（line 2230 为报表定义说明；同文件另有其他业务段路径） | Volume 3 Task 16（报表/公告）；共享壳由 Volume 4 Task 7 删除 | 删除主界面接口路径，技术信息只放显式标注的管理员“技术详情”折叠区。 |
| 9 | `PlatformWorkspace.vue` English enum `ACTIVE` | Volume 2 Task 6 | 用户管理状态中文显示；表单初始值不作为可见文案。 |
| 10 | `PlatformWorkspace.vue` field profile code `P1_INTERNAL` | Volume 2 Task 7 | 字段档位只用中文标签（P1 内部），码只在提交值/技术详情中。 |
| 11 | `SystemRolesPage.vue` field profile code `P0_PUBLIC` | Volume 2 Task 7 | 同上；四 Tab 重建后使用中文档位标签。 |
| 12 | `SystemUsersPage.vue` API path | Volume 2 Task 6 | 用户页主文案改为业务语言。 |

## 4. 与第 2/3 册退出条件的关系

- Volume 2 退出时必须清零 `ui-primitives` 与 `copy` 中所有**平台页**失败，以及 `page-model` 中平台相关失败（本基线平台相关路径/跨模块 Tab 失败均为 0 条；平台页违规为 `ui-primitives` 6 条 + `copy` 4 条 = 10 条；另有 `PlatformWorkspace.vue` 报表段 1 条 `copy` 违规归 Volume 3 Task 16）。
- Volume 3 退出时必须清零 `page-model`、`ui-primitives`、`copy` 中全部 People/Talent/Reporting/Engagement 业务页失败（本基线业务页违规为 `page-model` 12 条 + `ui-primitives` 5 条 + `copy` 8 条 = 25 条；其中含 `PlatformWorkspace.vue` 报表段 1 条 `copy` 违规）；`PlatformWorkspace.vue` 的报表/公告段违规由 Volume 3 Task 16 处理，账号安全/用户/角色段由 Volume 2 Task 2/6/7 处理，整文件删除由 Volume 4 Task 7 验收。
- Volume 4 Task 7 负责删除 `PlatformWorkspace`、`PeopleWorkspace`、`TalentWorkspacePage` 共享壳；即使业务页已独立，壳文件仍会重复触发文案/原语失败，因此删除后必须重跑三个门禁。
- 本文件不改变任何门禁规则，也未添加容忍清单。三个门禁当前**均未通过真实仓库**，只通过构造反例的 fixture 测试。

## 5. 复现

```bash
node --test scripts/tests/audit_page_model.test.mjs scripts/tests/audit_ui_primitives.test.mjs scripts/tests/audit_user_copy.test.mjs
node scripts/audit_page_model.mjs; echo $?
node scripts/audit_ui_primitives.mjs; echo $?
node scripts/audit_user_copy.mjs; echo $?
```

预期（本基线）：fixture 3/3 通过；三个 CLI 均输出 `ok: false` 并退出 `1`，失败数分别为 12 / 11 / 12。
