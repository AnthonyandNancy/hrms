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
| GAP-01 | 源权威性 | 阻断 | 458 卡源包存在，但包内明确“待审批/待确认” | AC-01/AC-21 | OPEN |
| GAP-02 | 缺失源 | 阻断 | master plan 只有 T00–T19；AC/计划要求 T01–T24 | AC-01/AC-21 | OPEN |
| GAP-03 | 依赖 | 阻断 | T05–T17 在 master plan 仍为 PENDING | AC-01/AC-21 | OPEN |
| GAP-04 | 状态冲突 | 高 | T06 计划表 PENDING vs `06-time-facts` COMPLETE | AC-08/AC-10/AC-21 | OPEN |
| GAP-05 | 功能缺口 | 阻断 | R02 的 CASE 审批及 R03/R04/R05/R08 需要 CASE/ORG_UNIT/PAYROLL_RUN anchor_id；`iam:bind-role` 只写 null、`UserRoleDrawer` 不传 `binding_anchors` | AC-03/AC-05/AC-21 | OPEN |
| GAP-06 | 入口缺失 | 阻断 | M21 无备份/恢复/升级 UI | AC-02/AC-21/AC-22 | OPEN |
| GAP-07 | 入口缺失 | 高 | M17 员工档案无培训记录 Tab | AC-15/AC-21 | OPEN |
| GAP-08 | 入口缺失 | 高 | M09 无本人打卡写端点/入口 | AC-10/AC-21 | OPEN |
| GAP-09 | UI/契约 | 高 | M08 manifest 声明 activate，页面无按钮 | AC-04/AC-13/AC-21 | OPEN |
| GAP-10 | 计数冲突 | 中 | M19 入口数摘要 6 vs 附录 A 7 页 | AC-01 | OPEN |
| GAP-11 | 计数冲突 | 中 | 源包 M01=7 页/M19=7 页，155 入口 M01=8/M19=6；入口 141 归 M01 | AC-01 | CLOSED（唯一入口口径已统一） |
| GAP-12 | 契约漂移 | 高 | Dashboard metric OpenAPI schema 与实现 DTO 不一致 | AC-16/AC-19 | OPEN |
| GAP-13 | 文档过期 | 低 | `registry.ts` 头注释仍写 155，实际 37 | AC-01 | OPEN |
| GAP-14 | 环境命名 | 中 | `.env.example` 的代理变量名与 `vite.config.ts` 不一致 | AC-21 环境准备 | OPEN |
| GAP-15 | action 缺失 | 高 | 大量页面可见写动作未入 generated action manifest | AC-05/AC-21 | OPEN |
| GAP-16 | 证据缺失 | 高 | T00–T04 evidence 引用 `gate-all.log`，但文件不存在 | 证据可复核性 | OPEN |
| GAP-17 | 角色缺失 | 高 | `ATTENDANCE_SPECIALIST` / `PAYROLL_APPROVER` / `RECRUITER` / `AUDITOR` 无 demo 账号 | AC-03/AC-21 | OPEN |
| GAP-18 | 环境/账号 | 中 | MFA 默认关闭，但 seed 创建并打印 TOTP；env 可被改为 true | AC-03/AC-21 | OPEN |
| GAP-19 | 入口计数 | 中 | 当前 37 页面含 `/performance/cycles/:id`，不在 V2.3 目标 36 清单 | AC-01 | OPEN |
| GAP-20 | 卡片映射 | 阻断 | 458 卡 ID/标题已提取，但源卡无稳定 card→page_code/action/API 映射 | AC-21 | OPEN |

---

## 2. 逐项详情

### GAP-01｜458 卡源存在但权威性待确认

**事实：**

- 根目录 `FactoryHR_V2.3整改包.zip` 确实存在（SHA-256 `b801485ecd18f2f072105020e85581f91413b4d6ee536f7d34c63fcb4f9788b5`），并被 `.gitignore` 第 38 行 `/FactoryHR_*.zip` 忽略。
- 包内 `FactoryHR_V2.3_模块闭环实施方案_待确认/06_全域逐页面逐功能AI施工卡_合订版.md` 已解析出：
  - 155 个唯一 `Mxx-Pxxx` 页面；
  - 458 个唯一 `Mxx-Pxxx-Fxx` 功能卡；
  - 模块分布 18+13+26+29+11+14+30+19+37+21+18+17+49+2+30+43+36+17+15+7+6 = 458。
- 包内 `00_阅读说明` 明写“版本状态：待审批，未修改任何业务源码”“审批状态：待确认”；因此它是**可追溯源**，不是已批准需求。
- 卡级 ID/标题已完整保存在 `458-card-index.md`；不再声称“找不到”。

**影响：**

- AC-01/AC-21 的 458 卡逐项验收可以建立槽位，但不能把待审批草稿当批准口径。
- 源卡没有稳定的“卡→当前 `page_code`/action/API”映射字段；矩阵只能按页面/模块给出当前页面/动作，不能伪造一键对应。

**本任务处理：**

- 新增 `458-card-index.md`，完整列出 458 个唯一卡 ID 与标题；
- 矩阵新增 155 行台账，每行引用完整卡范围，执行结论均为 `NOT RUN`；
- 不复制卡片正文成需求，不声称卡片已实现/已通过。

**解除条件：**

1. 用户/评审确认源包为批准基线；或
2. 提供正式修订的卡清单/映射；在此之前 AC-01/AC-21 不能 PASS。

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

### GAP-05｜R02 CASE 审批与 R03/R04/R05/R08 无法构造锚点角色

**事实：**

- `POST /v1/users/{id}/roles` OpenAPI 请求体有 `binding_anchors`；`UserAdminController::resolveRoleAnchors()` 对 `ORG_UNIT`/`PAYROLL_RUN`/`CASE` 等需要 id 的锚点返回 `ROLE_ANCHOR_REQUIRED`。
- `frontend/src/modules/platform/components/UserRoleDrawer.vue` 的保存只发送 `{ role_code, reason }`，没有 `binding_anchors` 输入。
- `iam:bind-role` 的签名只有 `{--user=} {--role=} {--days=}`；实现中 `$anchorId = null`，无法写 `ORG_UNIT`/`PAYROLL_RUN`/`CASE` 的 anchor_id。
- 因此：
  - R02 `HR_ADMIN` 的 `workflow.case.decide` 使用 CASE 锚点，故 R02 仅“部分可构造”：COMPANY 可绑定，M20 CASE 审批无法构造；
  - R03 `ATTENDANCE_SPECIALIST`（ORG_UNIT + COMPANY）无法通过当前 UI/CLI 构造；
  - R04 `PAYROLL_SPECIALIST`（PAYROLL_RUN + COMPANY）无法构造；
  - R05 `PAYROLL_APPROVER`（PAYROLL_RUN + COMPANY）无法构造；
  - R08 `RECRUITER`（ORG_UNIT + CASE）无法构造。

**影响：**

- R02 只能部分验收（公司级可执行，M20 HR CASE 审批 BLOCKED）；
- AC-03 的七类角色/补充角色无法完整覆盖；
- M09–M16 相关角色差异与撤权证据无法开始；
- 不能用 `demo.payroll` 的既有 run 锚点冒充任意批次。

**本任务处理：**

- runbook §3.1 明确 R02 为“部分可构造（CASE 审批 BLOCKED）”，R03/R04/R05/R08 为 `BLOCKED`；
- 明确依赖 **T02/T19 增加 anchor binding UI/命令**；
- 不提供 `iam:bind-role`/`UserRoleDrawer`/SQL/API 的替代步骤；任何此类步骤都不能作为 MCP 证据。

**解除条件：**

- 角色分配 UI 增加 ORG_UNIT/PAYROLL_RUN/CASE 锚点选择与校验；或
- T19 提供可审计的 anchor binding 命令，并有单测/契约；
- 在此之前，依赖 R03/R04/R05/R08 的项只能 `BLOCKED`。

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

### GAP-11｜源包页面数与 155 入口矩阵口径（已统一）

**事实：**

- 源包 `06_...施工卡` 按页面归属：M01=7、M19=7。
- V2.3 处置矩阵按入口号归属：M01=8（1–6、141、150）、M19=6（142–147）。
- 两者总数都为 155；差异是“页面”与“入口”的归属口径，不是缺号。
- 附录 A 又写 M01=7 页、M19=7 页，与 155 入口矩阵的 M19=6 冲突。

**影响：**

- “155 页面/155 入口”不能混为一谈；
- 模块卡数来自源包（M01=18、M19=15），入口数来自 155 矩阵。

**本任务处理：**

- 矩阵 §2/§2.1/§4A 统一按 **155 入口号**归属：M01=8（含 141）、M19=6；
- 155 行台账入口 141 归 M01，页面 ID 仍 `M19-P141` 并注明源包页面归属；
- 源包页面数 M01=7/M19=7 仅作来源，不参与入口计数。

**结论：**

- 本任务已采用“入口号”唯一覆盖口径；入口 141 归 M01，M19 仅 142–147；源包页面数仅作来源。
- 该 GAP 的口径部分已关闭；若用户要改为“页面数”口径，需正式修订清单。

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

### GAP-17｜缺少部分角色账号与锚点

**事实：**

- `iam:seed-demo` 当前产生 5 个账号：`demo.admin`、`demo.hr`、`demo.manager`、`demo.employee`、`demo.payroll`。
- 需要的 `ATTENDANCE_SPECIALIST`、`PAYROLL_APPROVER`、`RECRUITER`、`AUDITOR` 没有对应 demo 账号。
- `TRAINING_ADMIN`、`BENEFITS_ADMIN`、`REPORT_VIEWER` 也未 seed。
- 即使补账号，R02 的 CASE 审批与 R03/R04/R05/R08 仍受 GAP-05 的 anchor binding 阻塞；R02 公司级能力可单独验收。

**影响：**

- AC-03 的七类角色（特别是考勤专员、复核人）无法直接登录验收；
- 部分模块（M14/M16/M17）没有专用管理员角色，不能把 HR/超管结果冒充为角色差异结论。

**本任务处理：**

- runbook §3.1 区分完整可构造的 R01/R06/R07/R09、部分可构造的 R02（CASE 审批 BLOCKED）、以及 BLOCKED 的 R03/R04/R05/R08；
- 可选角色不计入 R01–R09 的 7 核心 + 2 补充口径；
- 不修改 seeder（业务代码范围外）。

**解除条件：**

- T02/T19 补 seed/角色账号，并解决 GAP-05 的锚点构造；在此之前相关项 `BLOCKED`。

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

- 458 个唯一卡 ID/标题已从源包 `06_...施工卡` 提取并保存在 `458-card-index.md`。
- 源卡只有页面归属（`Mxx-Pxxx`）、标题、功能交付、前后端要求、权限、验收，没有稳定的 `page_code`/action/API 字段。
- 一个源卡可能落在目标页的多个 Tab、Drawer 或 action 上；当前 manifest 也不是按源卡号生成。
- 因此可以逐卡建立“验收槽”，但不能把某卡强行映射成某个 action key 或 API。

**影响：**

- AC-21 的“逐卡验收”需要先定义“卡→当前页面/动作”的映射规则；
- 若直接用页面动作代替卡片，会漏掉卡片级字段/权限/验收差异；
- 若强行一一映射，则会伪造关系。

**本任务处理：**

- `458-card-index.md` 保留完整卡 ID/标题（已提取、源包权威性待确认）；
- 155 行台账按“页面 ID + 卡范围”登记，执行结论 `NOT RUN`；
- 卡片→action/API 的精确映射标 `UNMAPPED`，不编造。

**解除条件：**

- 用户/评审确认卡片验收以“源卡 ID + 源卡验收文本”直接在目标页面/Tab/动作上执行；或
- 提供权威的卡→页面/action 映射表。

## 3. 计数冲突明细

### 3.1 入口数 vs 源包页面数

| 模块 | V2.3 入口矩阵行数 | 源包 `06` 页面数 | 本任务台账采用 |
|---|---:|---:|---|
| M01 | 8 | 7 | 8 个入口，页面 7 |
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
| M19 | 6 | 7 | 6 个入口，页面 7 |
| M20 | 2 | 2 | 2 |
| M21 | 3 | 3 | 3 |
| **合计** | **155** | **155** | **155 入口** |

> 源包页面数与入口矩阵在 M01/M19 的模块归属不同，但总数都为 155。台账按入口号逐行；卡数按源包页面/卡片统计，二者不混用。

### 3.2 卡数

| 来源 | 合计 | 可逐卡验证？ |
|---|---:|---|
| 源包 `06_...施工卡` | 155 页 / 458 唯一卡 | 是（`458-card-index.md` 完整 ID/标题；无稳定 card→page/action 映射，GAP-20） |
| V2.4 附录 A 模块汇总 | 458 | 仅汇总 |
| V2.3 155 矩阵 | 无卡号 | 入口维度 |
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

以下不依赖 MCP 执行，已完成或可继续：

- 21 模块唯一矩阵；
- 155 历史入口编号唯一引用；
- 458 个唯一卡 ID/标题索引（源包待确认）；
- 37 当前页面与 132 action manifest 的映射；
- 角色/前置数据/状态/边界/AC 的验收骨架；
- MCP runbook；
- evidence template；
- evidence index（初始 NOT RUN）；
- 本文档的缺口清单。

## 5. 明确不可由本任务自行决定的事项

1. 458 卡源包的权威性确认与 card→page/action 映射；
2. T20–T24 的任务定义；
3. 是否接受“以页面动作替代 458 卡逐卡验收”；
4. 是否接受“T19 覆盖备份/恢复/升级，T18 不做 MCP”；
5. 是否补合同激活 UI、员工培训 Tab、本人打卡入口；
6. 是否由 T02/T19 补角色锚点 UI/命令（R03/R04/R05/R08 当前 BLOCKED）；
7. 是否统一 M01/M19 的入口归属；
8. 是否把 `/performance/cycles/:id` 纳入正式 37 页面口径。

以上必须由用户/lead 做正式口径决定，文档不得代签。

## 6. 关闭规则

关闭 GAP 必须：

- 引用权威源/代码变更/用户决定；
- 更新本文件状态为 `CLOSED` 并写关闭证据；
- 同步更新 `21-module-mcp-acceptance-matrix.md`、`evidence-index.md` 的相关行；
- 不得只改本文件而不同步矩阵/索引。

## GAP-21 — no-employment admin pages emit self-service 422/409

- Observed in `T19-CRAWL-001` with synthetic installer (SYSTEM_ADMIN, no
  employment binding):
  - attendance: one 422;
  - leave: one 409;
  - overtime: five 409s;
  - travel: one 409.
- Pages render Chinese unavailable/empty states, but console/network failures
  remain and prevent these pages from being full T18 PASS.
- Required fix: gate self-scoped attendance/leave/overtime/travel requests when
  the current principal has no employment, or return an explicit empty
  no-employment projection without error.

### GAP-21 status — FIXED in v24-t19e

- Device query now accepts `active=true` and `active=false`.
- Attendance/leave/overtime/travel self requests are gated on the principal's
  employment id; a `NoActiveEmploymentState` renders without self API calls.
- Real-stack recheck `T19-CRAWL-002`: all four pages have zero non-2xx `/v1`
  responses and zero console errors.
- Regression: backend devices test plus four frontend no-employment tests.

## GAP-22 — recruitment/shared routes hardcode COMPANY while RECRUITER uses ORG_UNIT

- `T19-MCP-006` assigned RECRUITER with ORG_UNIT=4 and CASE=2 successfully.
- The synthetic recruiter could log in, but its real pages returned 403:
  - `/v1/recruitment/requisitions`;
  - `/v1/engagement/notices`;
  - `/v1/org-units/tree`.
- Root cause: recruitment controller and shared read routes call
  `AnchorRef::company()` despite role-template ORG_UNIT grants.
- Required fix: use `permits` plus `constrain` for collection reads and
  resolve resource org-unit anchors for detail/write paths; add org-unit
  scoped recruiter regressions. This affects R08 and M14-M17.

### GAP-22 status — FIXED in v24-t19g

- Recruitment requisition/candidate reads now constrain by visible org-unit
  requisitions; writes resolve explicit org-unit anchors.
- RECRUITER now receives `org.unit.read`; AppShell gates unread notices by
  `engagement.notice.read`.
- Real recruiter verification: requisitions/candidates/org-tree 200; no shell
  notices request; only the pre-login `/v1/me` 401 remains.

### GAP-24 status — FIXED in v24-t19j

- Readiness now requires at least one active privileged administrator, not
  exactly one.
- Regression proves 0 admins is unhealthy and 2 admins is healthy.
- Auditor audit page returns 200 with readiness 200 and audit events 200.
