# ADR-0032: 数据范围与字段策略的写入模型

- **状态：** Proposed — 2026-11-25
- **关联：** ADR-0004（能力×锚点）、ADR-0024（超管全权与 P3 审计）、ADR-0027（页面权限）
- **复审触发：** 新增 Anchor 类型；自定义范围需求；字段档位变化

## 背景

`/system/data-scopes` 与 `/system/field-policies` 目前只读。要让管理员可视化配置，必须允许写入范围模板与字段策略，但任意 SQL/任意字段会破坏可解释性与安全边界。

## 决定

1. 数据范围只允许现有 Anchor 类型：`SELF`、`ORG_UNIT`、`ORG_SUBTREE`、`LEGAL_ENTITY`、`CASE`、`PAYROLL_RUN`。自定义范围只允许**白名单模板**（代码声明的 `ScopeTemplate`），不接受任意 SQL/JSON 条件。
2. 字段策略只能从 `FieldProfile` P0–P3 与代码声明的敏感字段清单中选择；不允许新增未登记字段，不允许把 P3 放宽为可见。
3. 写入必须走 `iam.scope.manage` / `iam.field.manage`，记录前后差异与 `request_id`；高风险变更要求二次确认与 MFA step-up（按配置）。
4. 修改后必须在同一事务内重放授权快照（`AuthorizationSnapshot::replay`），下一次请求即生效。
5. 超级管理员通配展开全部能力，但字段策略仍按 P3 审计记录读取/导出。

## 后果

- 管理员可配置，但不能创建任意条件，保证授权可解释、可测试。
- 字段策略写入不会成为提权后门：P3 不可被放宽，只能收紧。
- 范围模板变更需要代码发布，配置只选模板与参数。

## 验证

- `ScopePolicyWriteTest`：非法 Anchor/模板/字段拒绝；合法写入后快照重放生效。
- `FieldPolicyEscalationTest`：尝试把 P3 改为可见被拒绝；P3 读取仍写敏感审计。
