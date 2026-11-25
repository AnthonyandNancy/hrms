# ADR-0024: 管理员至高权限与 P3 薪资审计

- **状态：** Accepted — 2026-11-25
- **决策：** C6（管理员全权）
- **取代：** ADR-0016 中“wildcard 不隐含 P3”的默认建议；保留其 SoD 分层与审计机制
- **复审触发：** 管理员角色被拆分、P3 导出被外部监管限制、内网边界变化、或出现内部滥用事件

## 背景

V1.1 的 03A.14 与 IAM-06 写“普通 HR 和系统管理员不得读取工资”，而当前实现中
`SUPER_ADMIN` / `SYSTEM_ADMIN` 持有 `*` + `P3_RESTRICTED`，且 `capability:audit`
与真实栈均按 wildcard 展开。此前设计建议“wildcard 只覆盖治理能力，P3 独立授予”，
但这会阻止管理员完成应急排障、工资对账与授权审计，生产上表现为“管理员看不到工资”。

项目负责人确认：**管理员拥有至高权限，可以读工资；系统仅内网部署，环境变量/密钥不构成本轮门槛。**

## 决定

1. **`SUPER_ADMIN` 与 `SYSTEM_ADMIN` 的 wildcard 展开全部能力码**，包括 `payroll.*` 与
   `P3_RESTRICTED` 字段。管理员可以进入所有 140 个业务页与 14 个平台模块。
2. **P3 读取、导出、授权变更写不可变审计**：`audit_events` 记录 actor、capability、
   resource type/id、字段名（不记录金额明文）、request_id、时间、IP。
3. **SoD 风险提示但不阻断管理员**：管理员执行自我提权、自批工资、发布与复核同人时，
   写 `security.sod_risk_accepted`；界面显示风险提示；操作继续。
4. **非管理员仍按能力/锚点/字段档位严格限制**：HR 默认无工资；薪资专员按批次/法人；
   员工仅本人工资条。
5. **wildcard 不可通过 API 授予**：只能来自代码拥有的角色模板；管理员仍需在
   `/system/users` 或 CLI 绑定时显式选择角色。
6. **管理员不是员工**：SELF 页面在无任职时返回 `NO_ACTIVE_EMPLOYMENT` 空态，
   不返回 404，不白屏；管理员通过治理视图查看员工数据。

## 备选方案

| 方案 | 拒绝原因 |
|---|---|
| wildcard 只覆盖治理能力 | 管理员无法排障/对账；与用户决定冲突 |
| P3 永久隐藏 | 工资发布/更正/审计需要管理员看到金额 |
| 给管理员单独“薪资审计”角色 | 多一次分配步骤；应急时容易漏授；仍可用 SoD 提示+审计补偿 |
| 不记录 P3 审计 | 无法追责；与不可变审计基线冲突 |

## 后果

- 管理员登录后不再出现“工资批次/工资条 403”；`/me/capabilities` 返回全部能力。
- `CapabilityCatalog` 必须补齐蓝皮书引用的全部能力码；`capability:audit` 不能只检查
  “路由有守卫”，还要检查“能力码可授予”。
- `PayrollPayslipController`、`PayrollRunController` 等 P3 投影必须记录
  `payroll.read` 审计；导出记录 `payroll.export` 审计。
- `AccessReviewController` 的 wildcard 行风险标记为 `critical`，季度复核可见。
- 安全评审的关注点从“最小权限”转为“全权 + 审计 + 内网边界”。

## 验证

- `SuperuserFullAuthorityTest`：wildcard 授予 `payroll.payslips.read`/`payroll.runs.read`；
  P3 读取写审计。
- `MeCapabilitiesWildcardTest`：`/me/capabilities` 返回全部能力码。
- `CapabilityCatalogCoverageTest`：蓝皮书所有能力码存在。
- `PayrollFieldProjectionTest`：非管理员无 P3 时不返回金额键。
- 真实栈：管理员打开 `/payroll`、`/payroll/payslips`、`/reports/payroll` 均为 200。

## 复审触发

- 管理员角色被拆分为“系统管理员/薪资管理员/审计员”。
- 内网边界变化（接入外网、远程办公、第三方运维）。
- 出现管理员滥用或审计缺口。
- P3 导出的法规要求变化。
