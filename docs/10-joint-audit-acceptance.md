# HRMS V1.0 联合审计与验收方案（Task 10）

> 本文件把已批准的《解决方案》与《30 周实施计划》中的质量门禁，转换为可重复执行的证据清单。原则：**证据优先于声明；未执行的测试不得标记通过；组织签认与代码通过分开记录。**

## 1. 验收边界

本仓库是**参考实现**（reference implementation），不是 30 周生产切换的完成态。以下两类结果分开报告：

| 类别 | 可由代码/测试证明 | 组织签认或外部环境 |
|---|---|---|
| 架构与业务规则 | 模块边界、时间语义、权限拒绝、账本平衡、工资计算、幂等、审计 | — |
| 生产就绪 | 本地/容器可启动、备份恢复脚本、迁移彩排（合成数据） | 真实 IdP、银行/税务回执、生产数据、HA 基础设施、渗透测试、8 项组织签认 |

任何“组织门禁”在验收报告中只能是 `GATE`（待组织完成），不得写成 `PASS`。

## 2. 联合审计维度

### A. 架构与边界
- 五层模型与模块依赖：`php artisan arch:test`。
- 每张表单一写入者；跨模块只经 Contracts。
- 无 `condition_json`、无 `CUSTOM` anchor、无任意 SQL 数据范围、无 BPMN 引擎、无微服务拆分。
- OpenAPI 为契约唯一事实源，路由全部入契约。

### B. 安全与权限
- 默认拒绝；每个受保护端点至少 4 类拒绝，高危 8 类。
- 7 类 Anchor 全部有测试；字段档位 P0–P3；HIDDEN 不进入查询/排序/导出/日志。
- 会话 Cookie 属性、CSRF、auth_version 即时撤权、MFA（TOTP/WebAuthn）、break-glass 双人 30 分钟。
- IDOR：改 ID 返回 404；导出与页面同权限。
- 审计只追加；敏感字段访问留痕；日志无 PII/密钥。

### C. 时间与账本
- as-of T-1/T/T+1；半开区间；跨夜、DST、法人时区。
- 假期账本恒等式；重复审批/重试不重复扣减；撤销冲正幂等。
- 工资输入快照不可变；封存批次不可改；更正批次可对账。

### D. 工资正确性
- 金额整数分；无 float；费率定点 ≥6 位。
- 累计预扣跨月、专项扣除、更正、年结转。
- 社保/公积金基数上下限；地区化舍入模式版本化。
- 平行运行命令：无旧系统文件时退出码 2（未执行），不得伪装通过。

### E. 迁移与运维
- 迁移 validate/apply 幂等；坏数据产生 issue 而非猜测；对账人数/组织/合同/工资总额。
- 备份恢复校验和；回滚演练时限；健康检查 liveness/readiness 区分。
- 保留期与法务保全；删除需审批与证据。

### F. 产品与体验
- 申请状态与生效状态分离显示。
- 仪表盘数字与下钻列表同口径、同权限。
- 空状态、错误状态、加载状态、360px/1440px 响应式。
- 可访问性：键盘、标签、对比度、非颜色唯一表达。

## 3. 验收证据包

| 编号 | 证据 | 生成方式 | 责任 | 阻断级别 |
|---|---|---|---|---|
| E-01 | 架构边界报告 | `php artisan arch:test` 输出 | 技术负责人 | 阻断 |
| E-02 | 能力覆盖报告 | `php artisan capability:audit` | 安全/后端 | 阻断 |
| E-03 | 后端测试报告 | `php artisan test` JUnit/文本 | QA | 阻断 |
| E-04 | 静态分析与风格 | pint + phpstan | 后端 | 阻断 |
| E-05 | 契约校验 | OpenAPI lint + 路由契约测试 | 后端/前端 | 阻断 |
| E-06 | 前端质量 | typecheck/lint/unit/build | 前端 | 阻断 |
| E-07 | E2E 报告 | Playwright（mock 与真实后端两套） | QA | 阻断 |
| E-08 | 权限负测矩阵 | endpoint_matrix + 测试报告 | 安全 | 阻断 |
| E-09 | 时间旅行报告 | T-1/T/T+1、DST、跨夜 | QA | 阻断 |
| E-10 | 假勤账本对账 | `php artisan ledger:reconcile` | QA/HR | 阻断 |
| E-11 | 工资计算与平行报告 | 单元测试 + `payroll:parallel-check` | 财务/QA | 阻断 |
| E-12 | 迁移彩排与对账 | `migrate:rehearsal` 输出 | QA/数据 | 阻断 |
| E-13 | 备份恢复与回滚演练 | 脚本输出 + 时长 | DevOps | 阻断 |
| E-14 | 安全扫描 | composer audit、secret scan、SAST | 安全 | 阻断 |
| E-15 | 审计样本 | 登录、撤权、导出、工资封存、break-glass | 安全 | 阻断 |
| E-16 | UAT 记录 | UAT-01–13 签字 | 业务 | 阻断 |
| E-17 | 组织签认 | S-01–S-08 | 发起人 | 对应 release 阻断 |

## 4. UAT 场景与自动化映射

| UAT | 场景 | 自动化证据 | 人工证据 |
|---|---|---|---|
| UAT-01 | 新员工入职到首月工资 | Onboarding→People→Time→Payroll 集成测试 | HR 走查 |
| UAT-02 | 调岗未来生效 | TransferFutureEffectTest + as-of E2E | 经理确认 |
| UAT-03 | 合同续签 | ContractRenewalTest | 法务确认 |
| UAT-04 | 跨夜班次打卡 | OvernightShiftTest + DST | 考勤专员 |
| UAT-05 | 请假占用/撤销/结算 | LeaveLedgerBalanceTest + concurrency | HR |
| UAT-06 | 工资封存后更正 | CorrectionRunTest | 财务 |
| UAT-07 | 离职结算 | OffboardingRevokesAccessTest + final payroll | HR/财务 |
| UAT-08 | 跨公司调动 | CrossEntityTransferTest | HR |
| UAT-09 | 权限越权 | DenyByDefault/Anchor/IDOR/session revocation | 安全 |
| UAT-10 | 仪表盘与下钻 | DashboardConsistency + E2E | 业务 |
| UAT-11 | 累计预扣与舍入 | CumulativeWithholdingTest + RoundingTest | 财务 |
| UAT-12 | 身份与会话 | MfaTest + BreakGlassTest + SessionRevocation | 安全 |
| UAT-13 | 保留期与删除 | RetentionTest + legal hold | 法务 |

## 5. 缺陷分级与结论规则

| 等级 | 定义 | 结论影响 |
|---|---|---|
| Sev1 | 数据泄露、工资错发、账本错账、不可用 | 一票否决 |
| Sev2 | 核心流程不可用、权限错误、迁移对账失败 | 一票否决 |
| Sev3 | 非核心错误、体验问题 | 可带计划，需 owner/日期 |
| Sev4 | 文案/样式 | 不阻断 |

联合验收结论只有三种：
- `ACCEPTED`：所有阻断证据通过，且无 Sev1/Sev2。
- `ACCEPTED_WITH_GATES`：代码证据通过，但组织/外部门禁未完成；明确列出未完成项与责任人。
- `REJECTED`：任一阻断证据失败或存在 Sev1/Sev2。

## 6. 审计方法

1. **独立重跑**：审计人从干净检出重跑全部命令，不采信开发者终端截图。
2. **抽样反证**：随机抽取 5 个受保护端点，手工构造越权请求；随机抽取 3 个工资用例手算复核。
3. **数据一致性**：同一 as-of 日期，列表、详情、组织树、仪表盘数字互相对账。
4. **不可逆检查**：尝试修改已封存工资、已发布工资条、审计事件，必须失败。
5. **证据固化**：命令、退出码、时间、提交哈希写入 `evidence/acceptance-report.md`。

## 7. 当前状态

见 `evidence/acceptance-report.md`（由联合审计阶段生成，禁止预填 PASS）。
