# ADR-0033: Page Authorization, Action Buttons and Page Module Boundaries

- **状态：** Accepted — 2026-11-25
- **关联：** ADR-0004、ADR-0016、ADR-0024、ADR-0026、ADR-0027、ADR-0034
- **复审触发：** 页面清单变化、角色模板变化、页面能力码变化、按钮动作变化、公司配置或授权锚点变化

## 背景

160 个 `page_code` 只有 41 个 Vue 文件，144 个业务/平台页面由四个工作区壳动态渲染；页面可见性由单一 `required_capability` 推导，角色没有页面授权；演示账号只有绑定没有 `user_roles`；菜单父项没有展开指示，当前路由不写入 `navKey`。

同时，权限只做到接口能力级：约 220 个按钮中只有 10/41 个 Vue 文件出现 `session.can()` 或 `CapabilityGate`，没有统一的按钮动作清单。用户要求权限细化到页面路由和每个按钮，并可按角色分配。

## 决策

1. **`role_pages` 是页面可见性的唯一数据源。** `page_code` 来自代码拥有的 `PermissionResourceCatalog`，UI 不得新建页面代码。
2. **页面权限只决定进入与菜单。** 动作权限仍由 `AuthorityBinding` 编译，不得用页面授权绕过 API 中间件。
3. **一页一组件（one page, one component）：每个 `page_code` 必须映射到一个唯一 `.vue` 页面组件。** 共享壳不得作为页面路由目标；共享组件不得携带页面身份或业务分支。
4. **前端以 `/me/permissions` 为唯一页面/动作投影。** 加载失败必须显式报错，不得降级为空能力。
5. **每个非 GET 操作必须由 `PageActionSpec` 声明能力。** 按钮通过 `Can`/`ActionGate` 渲染；按钮能力必须等于后端接口的 `x-capability`。
6. **角色授权页固定四个 Tab。** 页面权限、按钮权限、数据权限、字段权限；父菜单不隐含子页，按钮默认不勾选。
7. **演示/安装链路必须同时写 `user_roles`、`role_pages`（模板）与 `authority_bindings`。** 缺一不可。
8. **合同与合同模板使用 contract soft delete。** `deleted_at` + `deleted_by` + `delete_reason`；不得物理删除；合同号不复用。
9. **员工支持完整 CRUD，但删除采用归档。** `DELETE /v1/employees/{id}` 写归档字段，`POST /v1/employees/{id}/restore` 恢复；员工号永不复用。
10. **超管部署基线必须可重复生成（superadmin baseline）。** 160 页、全部能力、全部数据范围；安装结束运行 `iam:verify-superadmin`。
11. **单公司边界由 ADR-0034 定义。** 页面/动作/数据范围不使用 `LEGAL_ENTITY` 锚点；`COMPANY` 表示整个单公司实例。
12. **未单列的权限/UI/CRUD 细节按解决方案 §10D 的市场默认执行（market defaults）。**

## 后果

- 页面与动作可以独立授予；只授单页成为可能。
- 按钮可见性与 API 授权使用同一能力码，减少“前端显示、后端拒绝”的漂移。
- 页面拆分工作量增加，但可测试、可回滚。
- 角色/页面迁移需要回填与差异报告；历史共享壳在拆分完成前不得标记为成熟。
- 破坏性改造与数据迁移按 ADR-0034 执行。

## 验证

- `audit_page_components.mjs`：160 个 `page_code` → 160 个唯一 `.vue`。
- `actions:check`：每个页面的非 GET 按钮都有 `PageActionSpec.capability`。
- `modules:check`：共享组件不含页面身份 props，无跨模块页面 import。
- `role_pages`：只授单页时仅该页可见；同能力其他页 403。
- 有页面无动作：可进入、按钮隐藏、API 403；有动作无页面：菜单隐藏、URL 403。
- 超管：160/160 页、全部能力、`COMPANY` 绑定。
- 员工：创建 → 读取 → 更新 → 归档 → 恢复；归档后历史仍可查。
- 合同：软删除后默认列表不可见，`withTrashed()` 可审计/恢复；版本历史不删除。
