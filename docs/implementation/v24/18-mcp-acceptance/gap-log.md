# FactoryHR V2.4 T18｜验收基线缺口与冲突日志

- **状态：** OPEN
- **日期：** 2026-11-28
- **HEAD：** `a5c90a199cb2b4d067495d4560dc517ec6684295`
- **原则：** 本文件只记录现有基线之间的缺失、冲突、不可确认项。**不得自行编造需求、卡片、入口、动作或任务**；任何补写必须有权威源和用户确认。
- **凡标记 `BLOCKED`/`UNMAPPED` 的项，均不得以静态审计、自动测试、API 调用或“应该”判 PASS。**

---

## 1. 编号与总表

| ID | 类型 | 严重度 | 问题摘要 | 影响 AC/交付 | 当前状态 |
|---|---|---|---|---|---|
| GAP-01 | 缺失源 | 阻断 | 458 功能卡明细不在仓库 | AC-01/AC-21 | OPEN |
| GAP-02 | 缺失源 | 阻断 | master plan 只有 T00–T19；AC/计划要求 T01–T24 | AC-01/AC-21 | OPEN |
| GAP-03 | 依赖 | 阻断 | T05–T17 在 master plan 仍为 PENDING | AC-01/AC-21 | OPEN |
| GAP-04 | 状态冲突 | 高 | T06 计划表 PENDING vs `06-time-facts` COMPLETE | AC-08/AC-10/AC-21 | OPEN |
| GAP-05 | 功能缺口 | 阻断 | 角色分配 UI 无 `binding_anchors` | AC-03/AC-05/AC-21 | OPEN |
| GAP-06 | 入口缺失 | 阻断 | M21 无备份/恢复/升级 UI | AC-02/AC-21/AC-22 | OPEN |
| GAP-07 | 入口缺失 | 高 | M17 员工档案无培训记录 Tab | AC-15/AC-21 | OPEN |
| GAP-08 | 入口缺失 | 高 | M09 无本人打卡写端点/入口 | AC-10/AC-21 | OPEN |
| GAP-09 | UI/契约 | 高 | M08 manifest 声明 activate，页面无按钮 | AC-04/AC-13/AC-21 | OPEN |
| GAP-10 | 计数冲突 | 中 | M19 入口数摘要 6 vs 附录 A 7 页 | AC-01 | OPEN |
| GAP-11 | 计数冲突 | 中 | M01、M19 附录 A 页数与 155 矩阵不一致 | AC-01 | OPEN |
| GAP-12 | 契约漂移 | 高 | Dashboard metric OpenAPI schema 与实现 DTO 不一致 | AC-16/AC-19 | OPEN |
| GAP-13 | 文档过期 | 低 | `registry.ts` 头注释仍写 155，实际 37 | AC-01 | OPEN |
| GAP-14 | 环境命名 | 中 | `.env.example` 的代理变量名与 `vite.config.ts` 不一致 | AC-21 环境准备 | OPEN |
| GAP-15 | action 缺失 | 高 | 大量页面可见写动作未入 generated action manifest | AC-05/AC-21 | OPEN |
| GAP-16 | 任务状态 | 中 | T00–T04 evidence 引用 `gate-all.log`，但文件不存在 | 证据可复核性 | OPEN |
| GAP-17 | 角色缺失 | 高 | `ATTENDANCE_SPECIALIST` / `PAYROLL_APPROVER` / `RECRUITER` / `AUDITOR` 无 demo 账号 | AC-03/AC-21 | OPEN |
| GAP-18 | 环境/账号 | 中 | MFA 默认关闭与 `iam:seed-demo` 打印 TOTP 的行为需明确 | AC-03/AC-21 | OPEN |
| GAP-19 | 入口计数 | 中 | 当前 37 页面中 `/performance/cycles/:id` 不在 V2.3 目标 36 页面清单 | AC-01 | OPEN |
| GAP-20 | 卡片映射 | 阻断 | 无可确认的“卡号→当前页面/动作”映射 | AC-21 | OPEN |

---

## 2. 逐项详情

### GAP-01｜458 功能卡明细缺失

**事实：**

- 设计规格 `2026-11-27-factoryhr-v2-3-module-closure-design.md` 与 V2.4 设计说明书都声明存在 “458 张功能卡”，但仓库只有：
  - V2.4 附录 A 的 21 行模块汇总（每行 1 个卡数）；
  - 各模块汇总合计确实为 458（18+13+26+29+11+14+30+19+37+21+18+17+49+2+30+43+36+17+15+7+6 = 458）。
- 未找到 `FactoryHR_V2.3整改包.zip`、卡清单、卡号、每卡验收文本或卡→页面/动作映射。
- 本 worktree 的 `docs/implementation/M*/integration.json` 是页面/API 移交记录，不是 458 卡清单。

**影响：**

- AC-01 要求 458 卡唯一覆盖；AC-21 要求按卡验收。
- 不能从 21 行汇总“反推”出 458 条具体卡片文本；那将是伪造需求。

**本任务处理：**

- 每个模块保留“卡片汇总数”并标 `CARD-DETAIL BLOCKED`；
- 矩阵只引用模块汇总和现有页面/动作/API；
- 不创建 `458-card-matrix` 以免伪造。

**解除条件：**

1. 用户提供 V2.3 整改包/458 卡权威清单；或
2. 用户正式修订 AC-01/AC-21，明确以“模块汇总+当前页面动作+155 入口”为最低覆盖口径，并把 458 卡标为历史基线，不要求逐卡 UI 证据。

---

### GAP-02｜T20–T24 无任务卡

**事实：**

- V2.4 设计说明书 2.1/3.2/AC-01/AC-21 均写 “T01–T24”。
- master plan 的执行状态表和任务卡只有：
  - T00 基线与 ADR
  - T01–T19
  - 没有 T20–T24 的标题、修改位置、验证方式或证据要求。

**影响：**

- AC-01/AC-21 的“V2.4 T01–T24 唯一覆盖”无法成立。
- 如果强行把 T20–T24 解释成“最终验收后的补充”，就是编造任务。

**本任务处理：**

- 矩阵 §7 明确 T01–T19 可映射，T20–T24 = `UNMAPPED`；
- 不写 T20–T24 的验收内容。

**解除条件：**

- 提供 T20–T24 权威任务卡；或
- 用户正式修订口径为 T01–T19。

---

### GAP-03｜T05–T17 仍 PENDING

**事实：**

`docs/superpowers/plans/2026-11-28-v2-4-implementation-master-plan.md` §0.5：

- T00–T04 COMPLETE；
- T05–T19 PENDING（包括 T18）。

T18 任务卡明写：依赖 T01–T17 的功能行为，T18 只允许在功能基本完成后开始，不允许以 MCP 验收替代缺失实现。

**影响：**

- T18 的 MCP 业务执行前置不成立。
- 任何 M13–M17 等模块的 PASS 都可能是假的。
- 但 T18 的**验收资产**（矩阵/runbook/模板/索引）仍可在不执行 MCP 的前提下交付。

**本任务处理：**

- 交付资产；
- 所有执行结论 `NOT RUN`；
- evidence index §6 列明 T05–T17 阻塞。

**解除条件：**

- T05–T17 每个任务完成，附 evidence 和 gate 记录；
- 或用户明确接受“当前只交付资产、不执行 MCP”，并将 T18 保持 `PENDING/NOT RUN`。

---

### GAP-04｜T06 状态冲突

**事实：**

- master plan §0.5 把 T06 标 `PENDING`。
- `docs/implementation/v24/06-time-facts/implementation-report.md` 标 `COMPLETE`，并有实现内容和自述门禁。
- 两者对同一个 T06 的状态不一致。

**影响：**

- M09/M10/M11/M12 的 MCP 前置是否成立不唯一。
- 不能仅凭 implementation-report 判 PASS，也不能无视其内容重复实现。

**本任务处理：**

- 在矩阵和 evidence index 中把它列为 `CONFLICT`，相关模块保持 `NOT RUN`；
- 不合并状态、不修改计划。

**解除条件：**

- lead 或用户确认 T06 的权威状态，并把 master plan/evidence 统一；
- 若确认 COMPLETE，补对应 gate/证据；若确认 PENDING，忽略 implementation-report 的完成声明。

---

### GAP-05｜角色分配 UI 无锚点

**事实：**

- `POST /v1/users/{id}/roles` OpenAPI 请求体有 `binding_anchors`（`PAYROLL_RUN`、`ORG_UNIT`、`CASE` 等），`UserAdminController::resolveRoleAnchors()` 对需要 anchor id 的角色返回 `ROLE_ANCHOR_REQUIRED`。
- `frontend/src/modules/platform/components/UserRoleDrawer.vue` 的保存只发送 `{ role_code, reason }`，没有 `binding_anchors` 输入。
- 因此 `PAYROLL_APPROVER`（PAYROLL_RUN）、`RECRUITER`（CASE + ORG_UNIT）、`ATTENDANCE_SPECIALIST`（ORG_UNIT + COMPANY）无法通过当前 UI 分配。
- `demo.hr` / `demo.payroll` / `demo.manager` / `demo.employee` 由 seed 产生，但缺少部分锚点角色账号。

**影响：**

- AC-03 七类角色中的考勤专员、薪酬复核人、招聘专员无法仅靠 UI 构造；
- AC-21 五+角色验收无法覆盖完整；
- M19 的“角色分配”主流程本身也不完整。

**本任务处理：**

- 标 `BLOCKED`（前置角色无法通过 UI 构造）；
- runbook 允许“受控 CLI/seed 仅构造前置数据”，但验收仍必须用 UI；
- 不把 CLI/seed 当作角色分配 UI 的 PASS。

**解除条件：**

- 补角色分配 UI 的锚点选择/校验（推荐）；或
- 用户批准“受控 CLI 造前置角色 + UI 验收”并接受角色分配 UI 本身仍为已知缺口。

---

### GAP-06｜M21 无备份/恢复/升级 UI

**事实：**

- 附录 A M21 卡片汇总 6 张，设计 5.2 关键功能包含“安装、升级、备份恢复”。
- 当前 `/system/settings` 的 5 Tab 是公司/日历/字典/参数/文件；`/system/audit` 有审计、健康、导出；`AuditHealthPanel` 明确显示备份状态 unavailable。
- 没有备份/恢复/升级的后台页面或动作。

**影响：**

- T18 不能声称这 3 张卡已通过 MCP。
- 只能由 T19 的运维/真实栈证据覆盖。

**本任务处理：**

- 矩阵 M21 标 `BLOCKED/UNMAPPED`；
- runbook 禁止用脚本/API 冒充 MCP；
- evidence index GAP-06 明列。

**解除条件：**

- 补后台 UI；或
- 用户接受 T19 用运维证据覆盖这三项，T18 不判其 PASS。

---

### GAP-07｜员工档案无培训记录 Tab

**事实：**

- V2.3 M17 计划 Task 5 要求员工详情有只读培训记录入口（`EmployeeTrainingRecordsPanel`）。
- 当前 `EmployeeDetailPage.vue` 的 `TAB_KEYS` 只有 8 个：overview/employment/contracts/credentials/education/experience/contacts/changes。
- `EmployeeTabs.vue` 也只渲染这 8 个。
- `EmployeeTrainingRecordsPanel.vue` 存在，但未挂在员工详情。

**影响：**

- E2E-09“培训到员工档案”无法从员工档案侧完成；
- M17 的一部分卡片无页面入口；
- 不能把“组件存在”当作入口存在。

**本任务处理：**

- M17/E2E-09 标 `UNMAPPED/BLOCKED`；
- 不新建页面、不手工路由。

**解除条件：**

- 补员工详情培训 Tab（只读）；或
- 用户正式接受该入口不在本期范围。

---

### GAP-08｜M09 无本人打卡写端点/入口

**事实：**

- 设计 5.2 M09 包含“打卡”；附录 A 有 37 张卡。
- 当前前端 `/attendance` 的“我的打卡”Tab 主要读取月度投影、打卡明细、异常、补卡；`attendance/api.ts` 没有 `POST /v1/time/attendance/clock-in` 或等价本人打卡写端点。
- 后端路由/OpenAPI只有 `GET /v1/time/attendance`、`GET /v1/time/punches`、`POST /v1/time/punches/import`、`POST /v1/time/attendance/corrections` 等，没有本人 clock-in/out。

**影响：**

- 历史入口中“打卡”类卡片无法在 UI 验证；
- 不能把“补卡/导入”当“打卡”。

**本任务处理：**

- M09 标 `UNMAPPED — 导航确认；无入口则不虚构 API`；
- 只把补卡、关账、规则、班次、排班、重算、重开、导出作为可验收动作。

**解除条件：**

- 补本人打卡命令/页面；或
- 用户确认“打卡”仅指读取/补卡，不属于本期写入范围（需正式口径）。

---

### GAP-09｜M08 activate UI 缺失

**事实：**

- `page-actions.json` 有 `people.contract.detail.activate` → `POST /v1/employment-contracts/{id}/activate`。
- `ContractDetailPage.vue` 只导入 `amendContract`、`terminateContract`，没有 activate 调用或按钮。
- `ContractWorkspacePage.vue` 有续签，但续签是新建合同版本，不等于“激活”。

**影响：**

- 合同 DRAFT/PENDING_EFFECTIVE→ACTIVE 的 UI 验收无法执行；
- 自动测试或 API 不能替代。

**本任务处理：**

- M08 标 `UNMAPPED/BLOCKED`；
- 矩阵保留 manifest 事实，但明确“页面无按钮”。

**解除条件：**

- 补合同激活按钮/状态动作；或
- 用户正式修订合同激活路径（例如创建即激活，需说明安全影响）。

---

### GAP-10｜M19 入口计数冲突

**事实：**

- V2.3 155 矩阵 M19 为 6 条入口：`/system/users`、`/system/roles`、`/system/menus`、`/system/permissions`、`/system/data-scopes`、`/system/field-policies`。
- V2.4 附录 A M19 写“7 页/15 功能卡”。
- V2.3 矩阵合计严格为 155，M19 是 6；若按 7 则合计 156。

**影响：**

- 计数口径不一致；不能用“7 页”反推第 7 个入口。

**本任务处理：**

- 以 155 矩阵为准，M19=6；
- 附录 A 的 7 页作为历史汇总保留并标注冲突。

**解除条件：**

- 用户确认 M19 的真实入口清单；或
- 接受 155 矩阵为唯一入口口径、附录 A 的“7 页”为过时汇总。

---

### GAP-11｜M01/M19 附录 A 与 155 矩阵不一致

**事实：**

- 附录 A M01 = 7 页，但 155 矩阵 M01 = 8 条（1–6、141、150）。
- 附录 A M19 = 7 页，但 155 矩阵 M19 = 6 条。
- 两者总计都为 155，说明是模块归属/计数口径不同，不是简单加总错误。

**影响：**

- 模块级卡数/入口数在 T18 中不能混用。

**本任务处理：**

- 入口数与 155 矩阵一致；
- 卡数与附录 A 汇总一致；
- 在矩阵中分别注记来源，不强行相加。

---

### GAP-12｜Dashboard metric 契约漂移

**事实：**

- OpenAPI `DashboardMetric` 旧 schema：
  - required 包含 `trendDirection`、`drilldownLabel`；
  - `status` enum 只有 `AVAILABLE/UNAVAILABLE`；
  - `DashboardData` required 包含 `greeting`，且没有 `sections`/`freshnessState`。
- 当前后端 `DashboardService` 返回：
  - `unitLabel`、`availability`、`scope`、`scopeLabel`、`period`、`predicate`、`asOf`、`drilldown`、`preview`、`sections`、`freshnessState`；
  - `status` 可能是 `UNCONFIGURED/FORBIDDEN/UNAVAILABLE`；
  - 无 `greeting`、无 `trendDirection`、无 `drilldownLabel`。
- 前端 `reporting/api.ts` 已经按新字段归一化。

**影响：**

- M02/M03 的契约断言与页面实际字段可能不一致；
- 不能仅凭 OpenAPI 声称页面契约已验证；
- 这是 T02/T16/T17 的既有遗留，不属 T18 文档资产可自行修复范围。

**本任务处理：**

- 在 gap-log 记录；
- 矩阵 M02 标“契约漂移风险”；
- T18 执行时以**实际响应+OpenAPI 一致性**作为验收项，漂移未解决则 M02/M03 不能 PASS。

**解除条件：**

- 更新 OpenAPI schema 到实际 DTO，补契约测试；或
- 改实现回到旧 schema（不推荐，因前端已按新字段）；
- 由 T16/T17 owner 处理并给出证据。

---

### GAP-13｜registry 头注释计数过期

**事实：**

- `frontend/src/router/registry.ts` 注释：`134 business pages + 15 platform entries + 6 base pages = 155 entries`。
- 实际 `PAGE_REGISTRY` 37 条：4 base、23 business、10 platform。
- 测试 `routeRegistry.spec.ts` 断言 37。

**影响：**

- 读者可能误用 155 作为当前页面数；
- 但 155 是历史入口目标口径，不是当前页面数。

**本任务处理：**

- 矩阵明确区分“155 历史入口”与“37 当前页面”；
- 不修改 registry（本任务只写文档）。

**解除条件：**

- 后续代码任务更新注释；或在 T19 清理。

---

### GAP-14｜Vite 代理变量名不一致

**事实：**

- `frontend/.env.example` 写 `VITE_PROXY_TARGET=http://127.0.0.1:8000`。
- `frontend/vite.config.ts` 使用 `process.env.VITE_DEV_API_PROXY_TARGET`。
- 如果操作者只复制 `.env.example`，Vite 代理仍指向默认 `127.0.0.1:8000`；若后端不是 8000，会失败或指向其他 worktree。

**影响：**

- T18 环境准备可能误连其他栈，破坏隔离；
- 不能靠“页面能开”判断接的是本批次 API。

**本任务处理：**

- runbook 明确使用 `VITE_DEV_API_PROXY_TARGET`；
- 要求用 `/v1/meta` + 网络验证真实后端；
- gap 记录，不在本任务改代码。

**解除条件：**

- 统一环境变量名，更新 example；
- 或在 runbook/README 明确正确变量。

---

### GAP-15｜页面可见写动作未入 action manifest

**事实（静态观察，仍需 MCP 网络核实）：**

- M03：报表运行、导出、下载（0 action）。
- M04：`POST /v1/employees/onboard`、导入、导出。
- M05：成员添加、编制 PATCH。
- M07：详情 decisions/submit/withdraw。
- M08：模板写、激活 UI 缺失。
- M09：原始打卡导出。
- M13：导出/对账/输入读取。
- M14：法定参数版本写。
- M15：详情 review submit/calibration/participant。
- M16：面试/评价/沟通/openings。
- M17：计划/场次/考试发布/完成/签到/反馈。
- M18：公告回执、通知已读。
- M20：详情 decide/withdraw/retry。
- M21：备份/恢复/升级无 UI。

**影响：**

- 如果 T18 只按 manifest 验收，会漏掉大量页面实际可执行动作；
- 若只按页面按钮验收，又会与 generated action catalog 不一致；
- AC-05/AC-21 需要两者对齐证据。

**本任务处理：**

- 矩阵 §5 单列差异；
- 不新增/修改 manifest（业务代码范围外）；
- 每个差异必须 MCP 网络记录确认；差异未解决前对应模块不能仅凭 manifest 判 PASS。

**解除条件：**

- 由 T02/T17 owner 补齐 action manifest/registry；
- 或用户接受“页面可见但未入 manifest 的动作仍按 UI 验收”，但需在 AC-05 风险中保留。

---

### GAP-16｜T00–T04 evidence 引用缺失日志

**事实：**

- 多个 V2.4 evidence 引用 `gate-all.log`：
  - `docs/implementation/v24/00-baseline.md` → `docs/implementation/v24/00-baseline/gate-all.log`
  - `01-install/evidence.md` → `01-install/gate-all.log`
  - `02-action-catalog/evidence.md` → `02-action-catalog/gate-all.log`
  - `03-contracts/evidence.md` → `03-contracts/gate-all.log`
  - `04-compensation-chain/evidence.md` → `04-compensation-chain/gate-all.log`
- 这些 log 文件在当前 worktree 中不存在（`find docs/implementation/v24 -name '*.log'` 为空）。

**影响：**

- T00–T04 的“22 gates PASS”只有文档摘要，没有可复核原始日志；
- T18 不应依赖这些缺失日志作为 MCP 前置通过证明；
- T19 最终验收需要补齐或重跑。

**本任务处理：**

- 不修改 T00–T04 文档、不伪造 log；
- 在 gap-log 记录，evidence index 标“原始 log 缺失”；
- 相关前置只在“文档摘要”级别引用，不能作为 T18 PASS 证据。

**解除条件：**

- 补回原始 gate 日志；或由 T19 重跑 gate 并保存日志。

---

### GAP-17｜缺少部分角色账号

**事实：**

- `iam:seed-demo` 当前产生 5 个账号：`demo.admin`、`demo.hr`、`demo.manager`、`demo.employee`、`demo.payroll`。
- 需要的 `ATTENDANCE_SPECIALIST`、`PAYROLL_APPROVER`、`RECRUITER`、`AUDITOR` 没有对应 demo 账号。
- `TRAINING_ADMIN`、`BENEFITS_ADMIN`、`REPORT_VIEWER` 也未 seed。
- v21 五角色 UAT 只覆盖 SYSTEM_ADMIN/HR_ADMIN/LINE_MANAGER/EMPLOYEE/PAYROLL_SPECIALIST。

**影响：**

- AC-03 的七类角色（特别是考勤专员、复核人）无法直接登录验收；
- 部分模块（M14/M16/M17）没有专用管理员角色，可能需要用 HR/超管代替，但会改变角色差异结论。

**本任务处理：**

- 在 runbook §3 给出必须补建的账号清单；
- 不修改 seeder（业务代码范围外）；
- 未补建前相关模块的“代表角色”证据标 `BLOCKED`，不得用相似角色冒充。

**解除条件：**

- 由 T02/T17 或后续任务补 seed/角色账号；或用户批准受控 CLI 构造前置账号。

---

### GAP-18｜MFA 开关与 demo seed 行为不一致

**事实：**

- 默认 `IAM_MFA_ENABLED=false`，登录只需账号+密码；安装器不创建 TOTP。
- `iam:seed-demo` 会对 demo 用户打印 TOTP provisioning URI，但 `MfaPolicy` 在 `IAM_MFA_ENABLED=false` 时不要求 challenge。
- `deploy/compose/docker-compose.yml` 没有显式设置 `IAM_MFA_ENABLED`；它可能继承 `backend/.env`。
- 如果 `backend/.env` 被改过（例如开发时 true），T18 登录会变成 202 MFA_REQUIRED，与默认 runbook 不符。

**影响：**

- 操作者可能把 202 挑战误判为登录失败；
- 或使用 `IAM_MFA_ENABLED=false` 但误以为已验收 MFA 安全性。

**本任务处理：**

- runbook 要求记录 `IAM_MFA_ENABLED` 实测值；
- 默认验收用 false，并单独标注“MFA 可选路径未验证”；
- 若验证 MFA=true，必须单独造数据并单独记录。

**解除条件：**

- 明确 compose env 中 `IAM_MFA_ENABLED` 的固定值；
- 在 T18 执行中分别记录 false/true 两条路径（若用户要求 MFA）。

---

### GAP-19｜当前 37 页比 V2.3 目标 36 多一个详情

**事实：**

- V2.3 设计 §3.1/§3.2 目标 36 页面：26 workspace + 7 detail + 3 base（新增 `/system/pages`）。
- 当前 `registry.ts` 37 条，额外为 `/performance/cycles/:id`（`performance-cycle-detail`）。
- 该页面不在 155 历史入口里，也不是 V2.3 目标清单的详情之一；V2.4 设计 5.6 只说“约 37 个入口”。

**影响：**

- “唯一覆盖”的 36/37 口径需要说明；
- 不能为了凑 36 删除当前真实页面；也不能把它写成 155 历史入口。

**本任务处理：**

- 矩阵明确保留 37 当前页面；
- 在 M15 单列该详情；
- 记录与 36 目标清单的差异，不作为 155 入口。

**解除条件：**

- 用户确认 `/performance/cycles/:id` 是正式详情深链（纳入 37）；
- 或后续改为 36 目标页面（需代码变更，不属本任务）。

---

### GAP-20｜卡号→页面/动作映射缺失

**事实：**

- 即使承认 458 卡存在，仓库只有 21 行模块汇总，缺少每张卡的稳定编号、名称、来源、目标页面/动作。
- 无法把“M04 29 张卡”拆成 29 条可验收项，也无法把某条卡绑定到某个 action/API。
- 现有 `M*/integration.json` 记录的是页面/接口变更，不是卡片清单。

**影响：**

- AC-21 的“按功能卡逐卡验收”无法执行；
- 任何“458 卡已通过”的声明都会是虚假。

**本任务处理：**

- 标 `CARD-DETAIL BLOCKED`；
- 矩阵只以“当前页面+动作+API+AC”作为实际可执行验收单元；
- 不创建假卡号、不复制页面文案当卡片。

**解除条件：**

- 提供 458 卡权威清单（含卡号）；或
- 用户正式修订 AC-21，把验收单元定义为“当前页面/动作 + 155 历史入口”，458 卡只作为历史基线引用。

---

## 3. 计数冲突明细

### 3.1 入口数

| 模块 | 155 矩阵行数 | 附录 A 页数 | 本任务采用 |
|---|---:|---:|---|
| M01 | 8 | 7 | 8（矩阵） |
| M02 | 6 | 6 | 6 |
| M03 | 9 | 9 | 9 |
| M04 | 10 | 10 | 10 |
| M05 | 5 | 5 | 5 |
| M06 | 5 | 5 | 5 |
| M07 | 9 | 9 | 9 |
| M08 | 6 | 6 | 6 |
| M09 | 10 | 10 | 10 |
| M10 | 8 | 8 | 8 |
| M11 | 6 | 6 | 6 |
| M12 | 7 | 7 | 7 |
| M13 | 13 | 13 | 13 |
| M14 | 1 | 1 | 1 |
| M15 | 10 | 10 | 10 |
| M16 | 16 | 16 | 16 |
| M17 | 9 | 9 | 9 |
| M18 | 6 | 6 | 6 |
| M19 | 6 | 7 | 6（矩阵） |
| M20 | 2 | 2 | 2 |
| M21 | 3 | 3 | 3 |
| **合计** | **155** | **155** | **155** |

> M01/M19 的差异互相抵消；但模块级归属不同，不能互换使用。本任务入口数以 155 矩阵逐行为准。

### 3.2 卡数

| 来源 | 合计 | 可逐卡验证？ |
|---|---:|---|
| V2.4 附录 A 模块汇总 | 458 | 否（只有 21 行汇总） |
| V2.3 矩阵 | 无卡号 | 否 |
| M*/integration.json | 页面/API 变更 | 否 |

### 3.3 当前页面

| 来源 | 数量 | 说明 |
|---|---:|---|
| `registry.ts` 实际数组 | 37 | 4 base + 23 business + 10 platform |
| `scripts/v23-page-count.json` | 37 | 当前计数契约 |
| V2.3 设计目标 | 36 | 26 workspace + 7 detail + 3 base |
| V2.4 设计 5.6 | “约 37” | 非精确契约 |

---

## 4. 已确认可继续的资产工作

以下不依赖 458 卡/T20–T24/MCP 执行，已完成或可继续：

- 21 模块唯一矩阵；
- 155 历史入口编号唯一引用；
- 37 当前页面与 132 action manifest 的映射；
- 角色/前置数据/状态/边界/AC 的验收骨架；
- MCP runbook；
- evidence template；
- evidence index（初始 NOT RUN）；
- 本文档的缺口清单。

## 5. 明确不可由本任务自行决定的事项

1. 458 卡的文本/编号/归属；
2. T20–T24 的任务定义；
3. 是否接受“以页面动作替代 458 卡逐卡验收”；
4. 是否接受“T19 覆盖备份/恢复/升级，T18 不做 MCP”；
5. 是否补合同激活 UI、员工培训 Tab、本人打卡入口；
6. 是否补角色锚点 UI 或允许受控 CLI 造前置角色；
7. 是否统一 M01/M19 的入口归属；
8. 是否把 `/performance/cycles/:id` 纳入正式 37 页面口径。

以上必须由用户/lead 做正式口径决定，文档不得代签。

## 6. 关闭规则

关闭 GAP 必须：

- 引用权威源/代码变更/用户决定；
- 更新本文件状态为 `CLOSED` 并写关闭证据；
- 同步更新 `21-module-mcp-acceptance-matrix.md`、`evidence-index.md` 的相关行；
- 不得只改本文件而不同步矩阵/索引。
