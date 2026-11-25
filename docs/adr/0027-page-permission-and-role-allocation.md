# ADR-0027: 页面权限与角色分配

- **状态：** Accepted — 2026-11-25
- **决策：** 权限页面与角色分配模型
- **关联：** `docs/audit/2026-11-25-v11-page-access-blueprint.md`、`docs/audit/2026-11-25-v11-page-inventory.md`、ADR-0024、ADR-0026
- **复审触发：** 角色模板变化、页面清单变化、数据范围枚举变化、SoD 规则变化

## 背景

用户要求明确：实现了哪些功能/页面、权限页面和角色权限如何分配。当前系统有
76 个能力码、10 个角色模板、若干零散绑定；前端管理页只展示用户/角色/能力，
但“角色 → 页面 → 动作 → 数据范围 → 字段档位 → 用户”没有一条可执行的分配链。
管理员新增绑定页在真实环境还会默认 `userId=0`，导致授权无法完成。

此前设计允许破坏性重构，并确认管理员拥有至高权限（含 P3 工资）。因此需要一份
可执行的页面/权限蓝皮书 + 代码拥有的角色-页面矩阵 + 可视化分配流程。

## 决定

### 1. 四层权限模型

| 层 | 事实源 | 作用 |
|---|---|---|
| 页面 Page | `PAGE_REGISTRY` + `PermissionResourceCatalog` | 菜单/路由/页面可见性 |
| 动作 Capability | `CapabilityCatalog` | 按钮/API/导出/审批 |
| 数据范围 Anchor | `AuthorityBinding` + Anchor 类型 | 行级 SQL 谓词 |
| 字段档位 FieldProfile | `FieldSet` + 字段策略 | 序列化时裁剪 P0–P3 |

**页面权限与动作权限分离**：页面权限只决定“能进入”，动作权限决定“能操作”；
父菜单授权不等于子页面授权；只有页面无动作时按钮隐藏、API 403；只有动作无页面
时菜单不显示、直接 URL 拦截。

### 2. 角色-页面矩阵

- 新增 `RolePageAssignmentCatalog`（代码拥有）：
  - 14 个角色模板：`SUPER_ADMIN`、`SYSTEM_ADMIN`、`HR_ADMIN`、`HR_SPECIALIST`、
    `RECRUITER`、`PAYROLL_SPECIALIST`、`PAYROLL_APPROVER`、`ATTENDANCE_SPECIALIST`、
    `LINE_MANAGER`、`EMPLOYEE`、`AUDITOR`、`TRAINING_ADMIN`、`BENEFITS_ADMIN`、
    `REPORT_VIEWER`。
  - 每个角色声明：页面列表、动作能力、默认锚点、字段上限、SoD 组。
  - `SUPER_ADMIN`/`SYSTEM_ADMIN` 映射全部 160 条注册表。
- `PermissionResourceCatalog` 为每个页面输出：
  `page_code`、`path`、`required_capability`、`actions`、`menu_group`、`order`。
- `/system/roles` 的授权树直接消费该目录，不再维护第二份菜单权限数组。

### 3. 可视化分配流程

1. **建角色**：`/system/roles` → 新建/复制；名称、编码、用途、owner、有效期。
2. **勾页面**：左侧菜单树；父节点只控制分组展开，不自动授予子页面。
3. **勾动作**：选中页面后列出查看/新建/编辑/删除/审批/导入/导出/发放等原子动作。
4. **设数据范围**：SELF / ORG_UNIT / ORG_SUBTREE / LEGAL_ENTITY / CASE /
   PAYROLL_RUN；指定部门用树多选；自定义只允许白名单模板。
5. **设字段档位**：P0–P3 可见/可编辑/可导出；P3 导出单独开关。
6. **分配用户**：`/system/users` → 选择用户 → 分配角色 → 生效/到期 → 授权说明。
7. **预览**：`/system/users/{id}/permissions-preview` 显示有效角色、可见页面、
   动作、数据范围、字段结果；支持“模拟访问指定员工”。
8. **提交**：差异预览；高风险二次确认；写审计（操作人/对象/前后值/request_id）。
9. **即时生效**：保存后 `AuthorizationSnapshot::replay()`；下一次请求按新权限执行。

### 4. 管理员全权

- `SUPER_ADMIN`/`SYSTEM_ADMIN` 的 wildcard 展开全部能力（含 P3 工资）。
- P3 读取、导出、授权变更写不可变审计；SoD 风险写 `security.sod_risk_accepted`
  但不阻断。
- 管理员不是员工：SELF 页面无任职时返回 `NO_ACTIVE_EMPLOYMENT` 空态，不返回 404。

### 5. 负测

每个页面至少 4 类：未登录 401、无动作 403、越锚点 403/404、越字段/导出拒绝。
高危页面至少 8 类（含自我提权、自批工资、导出 token 转发、撤权后旧标签页）。
管理员负测：全权必须通过；P3 审计必须存在。

## 备选方案

| 方案 | 拒绝原因 |
|---|---|
| 只做 RBAC 角色名判断 | 无法表达数据范围/字段/锚点；会重复出现越权 |
| 在 UI 里自由创建能力码 | 能力语义不可测试；capability:audit 失效 |
| 手工维护角色/页面矩阵 | 160 页面 × 14 角色会漂移 |
| 管理员不含 P3 | 与用户决定冲突；应急排障受阻 |
| 管理员不受审计 | 无法追责；与不可变审计基线冲突 |

## 后果

- 新增页面必须同时更新：注册表、`PermissionResourceCatalog`、
  `RolePageAssignmentCatalog`、OpenAPI、测试、E2E。
- 新增能力码必须走代码评审 + ADR + 负测；不能在 UI 创建。
- `/system/roles` 和 `/system/users` 成为真正的可视化授权入口；不再是只读展示。
- 角色模板数量从 10 增至 14；`capability:audit` 需覆盖新角色与页面矩阵。

## 验证

- `PagePermissionCoverageTest`：蓝皮书每个页面能力码存在。
- `pageAccessMatrix.spec.ts`：每个页面至少一个角色映射。
- `RoleTemplateCoverageTest`：14 个模板均可展开为有效绑定。
- `SuperuserFullAuthorityTest`：管理员全权 + P3 审计。
- `SodRiskAcknowledgementTest`：SoD 提示不阻断管理员。
- 真实栈：`/system/roles` 勾选页面/动作/范围/字段 → 保存 → 用户重新登录后菜单与 API
  同步变化。

## 复审触发

- 页面清单/角色模板变化。
- 数据范围枚举变化。
- SoD 规则变化。
- 出现授权漂移或管理员滥用。
