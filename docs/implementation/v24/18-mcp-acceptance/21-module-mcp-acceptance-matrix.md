# FactoryHR V2.4｜21 模块 MCP 人工验收唯一覆盖矩阵

- **状态：** 验收资产已建立；MCP **尚未执行**，全部执行结论为 `NOT RUN`
- **日期：** 2026-11-28
- **代码基线：** `a5c90a199cb2b4d067495d4560dc517ec6684295`
- **分支/工作树：** `v24-t18-acceptance-matrix` / `t18-acceptance-matrix`
- **任务依据：** `docs/superpowers/plans/2026-11-28-v2-4-implementation-master-plan.md` T18
- **需求依据：** `docs/FactoryHR_V2.4_需求与技术设计说明书.md` AC-01–AC-22、5.2、5.3、5.6、附录 A/B/C
- **历史入口依据：** `docs/v2.3-remediation/2026-11-27-155-entry-disposition-matrix.md`
- **模块计划依据：** `docs/superpowers/plans/2026-11-27-v2-3-m01-*.md` 至 `m21-*.md`
- **当前页面依据：** `frontend/src/router/registry.ts`
- **当前动作依据：** `docs/generated/page-actions.json`、`frontend/src/generated/pageActions.ts`
- **当前契约依据：** `docs/openapi/openapi.yaml`

> 本文件是 T18 的**唯一模块级索引**，不复制历史矩阵、功能卡或需求正文。每一条历史入口、功能卡和 T 任务只能引用既有基线；未找到可确认的基线明细时，必须标记 `BLOCKED` 或 `UNMAPPED`，不得补写功能文本。矩阵中的“当前动作”只表示生成的 action manifest 或页面源码确实存在的动作，不表示已经通过 MCP。

---

## 1. 唯一覆盖口径

### 1.1 目标口径与实际可核验口径

| 维度 | 目标验收口径 | 当前实际可核验情况 | 本矩阵处理 |
|---|---:|---|---|
| 模块 | 21 | `registry.ts` 与 V2.3 模块计划均可归并为 M01–M21 | 21 行唯一模块 |
| 历史入口 | 155 | V2.3 矩阵逐项 1–155，无缺号、无重复 | 每个模块登记精确编号，合计 155 |
| 功能卡 | 458 | 仓库只有 V2.4 附录 A 的**模块汇总数**和“458 张功能卡”声明；整改包/卡明细不在仓库 | 保留模块卡数并标 `CARD-DETAIL BLOCKED`，禁止伪造卡片文本 |
| 当前页面 | 37 | `registry.ts` 实测 37 条：4 base、23 business、10 platform；17 workspace、7 detail、9 governance、4 base | 37 条全部映射到 21 模块 |
| 当前动作 | 132 | `page-actions.json` 实测 27 个页面、132 个 action | 模块级全部引用；另列页面可见但未入 manifest 的写动作 |
| 跨模块闭环 | 12 | V2.3 设计规格 §7.2 定义 E2E-01–E2E-12 | 见 §6 |
| V2.4 任务 | T01–T24 | master plan 实际只有 T00–T19；T20–T24 无任务卡 | T01–T19 可映射，T20–T24 标 `UNMAPPED` |
| AC | AC-01–AC-22 | 设计说明书 §16 完整存在 | 每个模块引用相关 AC |

### 1.2 计数与唯一性核验

```text
V2.3 历史入口编号：1..155
总行数：155；重复编号：0；缺号：0
各模块历史入口合计：155

registry.ts 当前路径：37
  layer: base=4, business=23, platform=10
  kind: workspace=17, detail=7, governance=9, base=4
生成 action manifest：27 个 page_code / 132 actions
OpenAPI：293 paths / 376 operations（/v1）
```

**说明：** `registry.ts` 文件头仍写着“134 business + 15 platform + 6 base = 155”的旧计数契约，真实数组为 37 条。该冲突登记在 `gap-log.md`；本矩阵以实际数组为准，同时保留目标 155/458 的验收口径。

### 1.3 证据状态语义

| 状态 | 含义 |
|---|---|
| `NOT RUN` | 尚未在真实 `VITE_API_MODE=http` 栈上以人工 MCP 操作执行 |
| `PASS` | 有真实角色、真实后端、截图/快照/网络/刷新/重登证据，且实际结果符合预期 |
| `FAIL` | 已执行且不符合预期；必须回补最小测试/实现并重跑 |
| `BLOCKED` | 前置环境、账号、数据、契约或依赖任务缺失，无法得出业务结论 |
| `UNMAPPED` | 基线找不到可确认的入口、卡片、动作或任务定义；不得猜写 |

本矩阵“执行结论”列一律 `NOT RUN`；`BLOCKED/UNMAPPED` 表示已知无法开始或无法唯一映射，不是执行结果。

---

## 2. 21 模块总览

> `证据 ID` 是模块级证据槽；实际每次操作使用 `<证据ID>-NNN` 子编号。`动作数` 只计数 generated action manifest；括号内为页面源码可见但未入 manifest 的写动作概数，详见 §4。

| 模块 | 名称 | 历史入口 | 卡片 | 当前页面 | 动作数 | 代表角色 | T/AC 映射 | 证据 ID | 执行结论 |
|---|---|---:|---:|---:|---:|---|---|---|---|
| M01 | 登录、个人中心与员工自助 | 8 | 18 | 5 | 6 | 全部七类 + 未绑定员工管理员 | T01,T02,T17,T18 / AC-01,02,03,05,18,19,21 | `MCP-M01` | `NOT RUN` |
| M02 | 首页仪表盘 | 6 | 13 | 1 | 0 | SYSTEM_ADMIN,HR_ADMIN,LINE_MANAGER,EMPLOYEE | T16,T17,T18 / AC-16,18,19,21 | `MCP-M02` | `NOT RUN` |
| M03 | 报表与导出中心 | 9 | 26 | 1 | 0 (+2) | HR_ADMIN,PAYROLL_SPECIALIST,REPORT_VIEWER,AUDITOR | T16,T17,T18 / AC-05,16,18,19,21 | `MCP-M03` | `NOT RUN` |
| M04 | 员工档案 | 10 | 29 | 2 | 10 (+3) | HR_ADMIN,HR_SPECIALIST,LINE_MANAGER,EMPLOYEE | T03,T04,T05,T17,T18 / AC-04,05,07,18,20,21 | `MCP-M04` | `NOT RUN` |
| M05 | 部门与组织结构 | 5 | 11 | 1 | 3 (+2) | HR_ADMIN,LINE_MANAGER,EMPLOYEE | T02,T17,T18 / AC-03,04,05,16,18,20,21 | `MCP-M05` | `NOT RUN` |
| M06 | 岗位、职级和任职资格 | 5 | 14 | 1 | 4 | HR_ADMIN,HR_SPECIALIST,LINE_MANAGER | T03,T04,T17,T18 / AC-04,06,09,14,18,21 | `MCP-M06` | `NOT RUN` |
| M07 | 员工入转调离与异动 | 9 | 30 | 2 | 7 (+4) | HR_ADMIN,LINE_MANAGER,EMPLOYEE,PAYROLL_APPROVER | T03,T04,T05,T17,T18 / AC-04,09,13,15,20,21 | `MCP-M07` | `NOT RUN` |
| M08 | 劳动合同 | 6 | 19 | 2 | 4 (+1) | HR_ADMIN,HR_SPECIALIST,PAYROLL_SPECIALIST,EMPLOYEE | T03,T04,T17,T18 / AC-04,09,13,20,21 | `MCP-M08` | `NOT RUN` |
| M09 | 考勤打卡与月结 | 10 | 37 | 1 | 9 (+1) | ATTENDANCE_SPECIALIST,LINE_MANAGER,EMPLOYEE | T06,T07,T08,T17,T18 / AC-08,10,18,19,21 | `MCP-M09` | `NOT RUN` |
| M10 | 请假与假期余额 | 8 | 21 | 1 | 8 | ATTENDANCE_SPECIALIST,HR_ADMIN,LINE_MANAGER,EMPLOYEE | T06,T07,T17,T18 / AC-05,08,10,13,21 | `MCP-M10` | `NOT RUN` |
| M11 | 加班与调休 | 6 | 18 | 1 | 5 | ATTENDANCE_SPECIALIST,LINE_MANAGER,EMPLOYEE,PAYROLL_SPECIALIST | T06,T08,T17,T18 / AC-05,10,13,21 | `MCP-M11` | `NOT RUN` |
| M12 | 出差管理 | 7 | 17 | 1 | 6 | ATTENDANCE_SPECIALIST,HR_ADMIN,LINE_MANAGER,EMPLOYEE | T06,T08,T17,T18 / AC-10,13,16,21 | `MCP-M12` | `NOT RUN` |
| M13 | 薪酬核算 | 13 | 49 | 2 | 18 | PAYROLL_SPECIALIST,PAYROLL_APPROVER,AUDITOR,EMPLOYEE | T09–T13,T17,T18 / AC-05–09,11–14,16,18–21 | `MCP-M13` | `NOT RUN` |
| M14 | 社保公积金与福利 | 1 | 2 | 1 | 3 (+1) | BENEFITS_ADMIN,HR_ADMIN,PAYROLL_SPECIALIST,EMPLOYEE | T09,T14,T18 / AC-06,07,14,20,21 | `MCP-M14` | `NOT RUN` |
| M15 | 绩效考核 | 10 | 30 | 2 | 3 (+5) | HR_ADMIN,LINE_MANAGER,EMPLOYEE | T09,T15,T17,T18 / AC-13,14,15,20,21 | `MCP-M15` | `NOT RUN` |
| M16 | 招聘需求与候选人 | 16 | 43 | 3 | 10 | RECRUITER,HR_ADMIN,LINE_MANAGER | T05,T15,T17,T18 / AC-04,13,14,15,21 | `MCP-M16` | `NOT RUN` |
| M17 | 培训管理 | 9 | 36 | 1 | 5 (+7) | TRAINING_ADMIN,HR_ADMIN,LINE_MANAGER,EMPLOYEE | T15,T17,T18 / AC-15,18,21 | `MCP-M17` | `NOT RUN` |
| M18 | 公告与通知 | 6 | 17 | 2 | 10 (+3) | HR_ADMIN,LINE_MANAGER,EMPLOYEE,AUDITOR | T16,T17,T18 / AC-05,17,18,19,21 | `MCP-M18` | `NOT RUN` |
| M19 | 用户、角色、菜单与细粒度权限 | 6 | 15 | 3 | 11 | SYSTEM_ADMIN,HR_ADMIN,AUDITOR,EMPLOYEE | T01,T02,T17,T18 / AC-03,05,18,21 | `MCP-M19` | `NOT RUN` |
| M20 | 审批流程中心 | 2 | 7 | 2 | 4 (+2) | LINE_MANAGER,HR_ADMIN,PAYROLL_APPROVER,EMPLOYEE | T02,T07,T11,T13,T18 / AC-13,19,21 | `MCP-M20` | `NOT RUN` |
| M21 | 公司基础设置与审计运维 | 3 | 6 | 2 | 6 | SYSTEM_ADMIN,AUDITOR,HR_ADMIN | T01,T02,T16,T18,T19 / AC-02,05,16,18,19,21,22 | `MCP-M21` | `NOT RUN` |
| **合计** | **21 模块** | **155** | **458** | **37** | **132** | 7 类核心 + 2 补充角色 | T01–T19 + T20–T24(UNMAPPED) | `MCP-M01..M21` | **全部 NOT RUN** |

### 2.1 模块入口编号（唯一、不重叠）

| 模块 | V2.3 历史入口编号 | 数量 | V2.3 模块计划 |
|---|---|---:|---|
| M01 | 1,2,3,4,5,6,141,150 | 8 | `docs/superpowers/plans/2026-11-27-v2-3-m01-identity-self-service.md` |
| M02 | 7,8,9,10,11,12 | 6 | `docs/superpowers/plans/2026-11-27-v2-3-m02-dashboard.md` |
| M03 | 13,14,15,16,17,18,19,20,21 | 9 | `docs/superpowers/plans/2026-11-27-v2-3-m03-reporting.md` |
| M04 | 22,23,24,25,26,27,28,29,30,31 | 10 | `docs/superpowers/plans/2026-11-27-v2-3-m04-employee-records.md` |
| M05 | 32,33,34,35,36 | 5 | `docs/superpowers/plans/2026-11-27-v2-3-m05-organization.md` |
| M06 | 37,38,39,40,41 | 5 | `docs/superpowers/plans/2026-11-27-v2-3-m06-positions.md` |
| M07 | 42,43,44,45,46,47,48,49,50 | 9 | `docs/superpowers/plans/2026-11-27-v2-3-m07-lifecycle.md` |
| M08 | 51,52,53,54,55,56 | 6 | `docs/superpowers/plans/2026-11-27-v2-3-m08-contracts.md` |
| M09 | 57,58,59,60,61,62,63,64,65,66 | 10 | `docs/superpowers/plans/2026-11-27-v2-3-m09-attendance.md` |
| M10 | 67,68,69,70,71,72,73,74 | 8 | `docs/superpowers/plans/2026-11-27-v2-3-m10-leave.md` |
| M11 | 75,76,77,78,79,80 | 6 | `docs/superpowers/plans/2026-11-27-v2-3-m11-overtime.md` |
| M12 | 81,82,83,84,85,86,87 | 7 | `docs/superpowers/plans/2026-11-27-v2-3-m12-travel.md` |
| M13 | 88,89,90,91,92,93,94,95,96,97,98,99,100 | 13 | `docs/superpowers/plans/2026-11-27-v2-3-m13-payroll.md` |
| M14 | 151 | 1 | `docs/superpowers/plans/2026-11-27-v2-3-m14-benefits.md` |
| M15 | 101,102,103,104,105,106,107,108,109,110 | 10 | `docs/superpowers/plans/2026-11-27-v2-3-m15-performance.md` |
| M16 | 111,112,113,114,115,116,117,118,119,120,121,122,123,124,125,126 | 16 | `docs/superpowers/plans/2026-11-27-v2-3-m16-recruitment.md` |
| M17 | 127,128,129,130,131,132,133,134,135 | 9 | `docs/superpowers/plans/2026-11-27-v2-3-m17-training.md` |
| M18 | 136,137,138,139,140,154 | 6 | `docs/superpowers/plans/2026-11-27-v2-3-m18-engagement.md` |
| M19 | 142,143,144,145,146,147 | 6 | `docs/superpowers/plans/2026-11-27-v2-3-m19-iam.md` |
| M20 | 148,149 | 2 | `docs/superpowers/plans/2026-11-27-v2-3-m20-workflow.md` |
| M21 | 152,153,155 | 3 | `docs/superpowers/plans/2026-11-27-v2-3-m21-platform-settings.md` |

## 3. 全局角色与前置数据

### 3.1 角色

| 代号 | 实际角色代码 | 锚点 | 验收账号（计划） | 当前状态 |
|---|---|---|---|---|
| R01 | `SYSTEM_ADMIN` | COMPANY（通配） | `demo.admin` | 由 `iam:seed-demo` 产生 |
| R02 | `HR_ADMIN` | COMPANY | `demo.hr` | 由 `iam:seed-demo` 产生 |
| R03 | `ATTENDANCE_SPECIALIST` | ORG_UNIT + COMPANY | `v24.attendance` | **必须补建；锚点需准备** |
| R04 | `PAYROLL_SPECIALIST` | PAYROLL_RUN + COMPANY | `demo.payroll` | 由 seed 产生；PAYROLL_RUN 绑定依赖先存在工资批次 |
| R05 | `PAYROLL_APPROVER` | PAYROLL_RUN + COMPANY | `v24.approver` | **必须补建；锚点需准备** |
| R06 | `LINE_MANAGER` | MANAGED | `demo.manager` | 由 seed 产生 |
| R07 | `EMPLOYEE` | SELF | `demo.employee` | 由 seed 产生 |
| R08 | `RECRUITER` | ORG_UNIT + CASE | `v24.recruiter` | 可选；无 CASE 锚点时 v2.1 UAT 明示 BLOCKED-FIXTURE |
| R09 | `AUDITOR` | COMPANY | `v24.auditor` | 可选；用于审计只读负测 |

**未绑定员工管理员：** 需要一个 `SYSTEM_ADMIN` 且没有 employment link 的账号。`demo.admin` 在 `people:seed-demo` 后会被链接到合成员工，不能自动充当未绑定管理员。该账号必须作为单独合成前置数据创建；未创建前，M01 的“未绑定员工不得伪造本人业务”为 `BLOCKED`。

### 3.2 前置数据总表

| 数据 ID | 用途 | 合成要求 |
|---|---|---|
| `T18-ORG-01` | 根/部门/子树 | 名称与编码含 `T18-`，不得使用真实公司名 |
| `T18-POS-01` | 岗位、职级、资格版本 | 编码 `T18-POS-*`，不写真实薪酬建议 |
| `T18-EMP-A` | 月薪员工 A | 基本工资 8000.00 元（800000 分），20 个计划工作日 |
| `T18-EMP-B` | 计时员工 B | 与 A 使用不同员工级方案/参数 |
| `T18-EMP-C` | 部门经理/审批人 | 与 A/B 有直接下属关系，用于 MANAGED 范围 |
| `T18-CON-A/B` | 合同版本 | 同一员工、一致有效期，不得同名误绑 |
| `T18-COMP-A/B` | 执行薪酬版本 | 与合同、方案、员工一致；金额整数分 |
| `T18-SCHEME-M/H` | 月薪/计时方案 | 用于 AC-06 同批多方案 |
| `T18-TIME-2026-11` | 考勤月度事实 | 含无薪假、已确认加班、出差/调休冲突样本 |
| `T18-RUN-01` | 工资批次 | DRAFT→INPUT_LOCKED→CALCULATED；后续审批由独立账号完成 |
| `T18-RUN-CORR-01` | 更正批次 | 8080→8300→8350 的累计差额样本 |
| `T18-LEAVE-01` | 已批准无薪假 | 用于 AC-08；取消后须实际补足出勤 |
| `T18-OT-01` | 已确认加班 | 8 小时；PAY/TIMEOFF 只能选一个 |
| `T18-TRAVEL-01` | 出差与费用 | 与请假/打卡/加班不重叠 |
| `T18-PERF-01` | 已发布绩效结果 | 奖金映射到正确工资项；草稿不得发钱 |
| `T18-OFFER-01` | 已接受 Offer | 只转一个员工；合同/薪酬首月可追溯 |
| `T18-TRAIN-01` | 培训计划/场次/报名/签到/考试 | 来源必须有效；无津贴不得产生工资 |
| `T18-NOTICE-01` | 公告与通知 | 含指定受众、需回执、撤回/过期场景 |
| `T18-AUD-01` | 审计样本 | 只用于只读查询和字段导出负测 |

所有名称、证件号、手机号、邮箱、金额均使用明显虚构值；禁止真实个人数据。

---

## 4. 21 模块逐项映射

> 每节结构：历史引用 → 当前页面/动作 → API → 角色/前置 → 状态/边界 → T/AC 与缺口 → 证据/结论。API 列表来自 `registry.ts` 的 `requiredApis`、generated action manifest 以及页面/模块 API 源码；`(source-only)` 表示页面源码实际调用但 registry/manifest 未完整声明。

### M01 登录、个人中心与员工自助

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 1–6、141、150；V2.3 模块 M01；卡片汇总 18；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/login`（`login`）、`/workspace`（`workspace`）、`/forbidden`（`forbidden`）、`/not-found`（`not-found`）、`/account`（`account`） |
| 当前动作（6） | `account.mfa.confirm`、`account.mfa.disable`、`account.mfa.enroll`、`account.password.change`、`account.sessions.revoke`、`account.sessions.revoke.all` |
| 页面可见但未入 manifest | 登录/退出、MFA challenge、工作台快捷入口、导航跳转；均不是业务写 action |
| 当前 API | `POST /v1/auth/login`、`GET /v1/auth/csrf`、`GET /v1/me`、`GET /v1/me/workspace`、`GET /v1/tasks/my`、`GET /v1/auth/sessions`、`GET /v1/auth/mfa/status`、`POST /v1/auth/password`、`POST /v1/auth/logout-all`、`DELETE /v1/auth/sessions/{id}`、`POST /v1/auth/mfa/totp/{enroll,confirm,disable}` |
| 角色/前置 | R01–R07 + 未绑定员工管理员；`demo.employee` 绑定合成 employment；MFA 默认关闭时密码直登；MFA=true 时必须准备 TOTP |
| 状态/边界 | loading/empty/error/forbidden/partial；未绑定员工时 `employment_linked=false`；撤权/停用后旧标签页写请求 403/401；会话 Cookie HttpOnly，CSRF 不在 localStorage |
| T/AC | T01、T02、T17、T18 / AC-01、AC-02、AC-03、AC-05、AC-18、AC-19、AC-21 |
| 当前缺口 | 未绑定管理员账号未内置；MFA=true 的 challenge 路径需单独证据；工作台快捷入口只验证导航，不证明下游模块业务 |
| 证据 ID / 结论 | `MCP-M01-*`；**NOT RUN**。前置 T17 未完成，且未绑定管理员数据未构造 |

### M02 首页仪表盘

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 7–12；卡片汇总 13；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/dashboard`（`dashboard`）；唯一看板，分区含指标、部门人数、人员构成、异动、提醒、招聘 |
| 当前动作（0） | 无 manifest write action；指标下钻为读操作 |
| 页面可见但未入 manifest | 业务日期筛选刷新、指标卡下钻（`GET /v1/reporting/dashboard/metrics/{metric}`） |
| 当前 API | `GET /v1/reporting/dashboard`、`GET /v1/reporting/dashboard/metrics/{metric}`（source-only；registry 只列 dashboard） |
| 角色/前置 | R01/R02/R06/R07；至少 1 个已发布公告、合同到期样本、异动/假勤/招聘数据；不得伪造空图 |
| 状态/边界 | 指标 `AVAILABLE/UNCONFIGURED/FORBIDDEN/UNAVAILABLE`；查询失败不得显示 0；无薪酬字段权限时金额不可见/不可反推；部门下钻受范围限制 |
| T/AC | T16、T17、T18 / AC-16、AC-18、AC-19、AC-21 |
| 当前缺口 | 指标 status 与 OpenAPI 旧 schema 存在漂移风险（见 gap-log）；当前页面无 action manifest，只有读模型证据 |
| 证据 ID / 结论 | `MCP-M02-*`；**NOT RUN**。前置 T16/T17 未完成 |

### M03 报表与导出中心

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 13–21；卡片汇总 26；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/reports`（`reports`）：报表目录、运行、结果、导出、下载 |
| 当前动作（0） | 无 manifest write action |
| 页面可见但未入 manifest | `POST /v1/reporting/run`、`POST /v1/reporting/exports`、`GET /v1/reporting/exports`、`GET /v1/reporting/exports/{jobId}/download`（source-only） |
| 当前 API | `GET /v1/reporting/definitions`、`GET /v1/org-units/tree`、`POST /v1/reporting/run`、`GET /v1/reporting/exports`、`POST /v1/reporting/exports`、`GET /v1/reporting/exports/{jobId}/download`；页面共享 dashboard 读 |
| 角色/前置 | R02/R04/R09 可读；R06/R07 受范围/字段限制；至少一个有数据和一个零数据定义；导出任务必须真实创建并可再次鉴权 |
| 状态/边界 | 报表运行失败不得显示空表；导出字段白名单；创建后被撤字段权限再下载 403；订阅者撤权后跳过/失败；分页/筛选/as-of 保持 |
| T/AC | T16、T17、T18 / AC-05、AC-16、AC-18、AC-19、AC-21 |
| 当前缺口 | 页面写动作未入 manifest；订阅/取消/重试是否有当前 UI 需 MCP 确认；无定义/无数据状态必须单独造数；未确认的订阅卡片入口标 UNMAPPED，不猜写 |
| 证据 ID / 结论 | `MCP-M03-*`；**NOT RUN**。前置 T16/T17 未完成，订阅卡片明细缺失 |

### M04 员工档案

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 22–31；卡片汇总 29；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/hr/employees`、`/hr/employees/:id`；8 个详情 Tab：基本信息/任职与异动/合同/证照与附件/教育/工作经历/联系方式/变更申请 |
| 当前动作（10） | `hr.employees.create.employment`、`hr.employees.contract.create`、`employee.change.submit`、`employee.detail.archive`、`employee.detail.assign`、`employee.detail.restore`、`employee.profile.{certificates,contacts,education,experiences}.retire` |
| 页面可见但未入 manifest | `POST /v1/employees/onboard`（新建员工抽屉）、`GET /v1/employee-imports`、`GET /v1/employees/export`（source-only） |
| 当前 API | `GET /v1/employees`、`GET /v1/employees/{employment_id}`、`GET /v1/employments/{employmentId}/timeline`、profile 子资源 GET/POST/PATCH、`DELETE /v1/employees/{employmentId}/{resource}/{id}`、change request submit、`DELETE /v1/employees/{employment_id}`、restore、assignments、contracts、imports/export |
| 角色/前置 | R01/R02/R06/R07；至少 3 名合成员工（含同名/近似名）；已有部门/岗位；A 有合同+执行薪酬；导入批次样本 |
| 状态/边界 | 员工建档原子（Person/Employment/Assignment/Terms）；同名不误绑；跨组织访问 404；字段裁剪；归档/恢复版本；子档案 retire append-only；刷新保留筛选/分页/选中 |
| T/AC | T03、T04、T05、T17、T18 / AC-04、AC-05、AC-07、AC-18、AC-20、AC-21 |
| 当前缺口 | “新增员工”实际 API 未入 action manifest；员工档案没有“培训记录”Tab（M17 计划要求只读培训入口），见 gap-log；导入/导出动作需 MCP 验证 |
| 证据 ID / 结论 | `MCP-M04-*`；**NOT RUN**。前置 T05/T17 未完成 |

### M05 部门与组织结构

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 32–36；卡片汇总 11；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/hr/departments`：组织树、部门列表/详情、成员、编制、移动影响 |
| 当前动作（3） | `org.departments.create`、`org.departments.move`、`org.departments.remove.member` |
| 页面可见但未入 manifest | 成员添加 `POST /v1/departments/{departmentId}/members`、编制修订 `PATCH /v1/departments/{departmentId}`（source-only） |
| 当前 API | `GET /v1/departments`、`GET /v1/org-units/tree`、`GET /v1/departments/{departmentId}/members`、`GET /v1/departments/staffing`、`GET /v1/org-units/{departmentId}/impact`、`POST /v1/departments`、`PATCH /v1/departments/{id}`、`POST /v1/departments/{departmentId}/members`、`POST /v1/org-units/{departmentId}/move` |
| 角色/前置 | R02/R06/R07；`T18-ORG-01` 及至少 2 个部门树；部门负责人/编制样本；有在岗人员的部门 |
| 状态/边界 | 禁止组织环；有在岗人员不可硬删；移动影响预览；as-of 历史归属不漂移；组织越权 403/404 |
| T/AC | T02、T17、T18 / AC-03、AC-04、AC-05、AC-16、AC-18、AC-20、AC-21 |
| 当前缺口 | 成员添加/编制写命令未完整入 manifest；`POST /v1/org-units` 可能已 legacy，本矩阵不将其作为当前动作 |
| 证据 ID / 结论 | `MCP-M05-*`；**NOT RUN**。前置 T17 未完成，写动作映射待网络证据核实 |

### M06 岗位、职级和任职资格

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 37–41；卡片汇总 14；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/hr/positions`：岗位目录、编制、职级；详情概览/在岗/任职资格/编制 |
| 当前动作（4） | `org.positions.create`、`org.positions.edit`、`org.positions.grade.upsert`、`org.positions.requirements.version` |
| 页面可见但未入 manifest | 岗位状态切换/编制编辑（实际 API 可能复用 PATCH；MCP 以网络记录为准） |
| 当前 API | `GET /v1/positions`、`GET /v1/positions/{positionId}`、`GET /v1/departments`、`GET /v1/positions/grades`、`POST /v1/positions/grades`、`POST /v1/positions`、`PATCH /v1/positions/{positionId}`、`GET /v1/positions/{positionId}/staff`、`GET/POST /v1/positions/{positionId}/requirements` |
| 角色/前置 | R02/R06；组织树/部门；岗位、职级、资格版本；在用岗位；岗位建议薪酬不得覆盖个人工资 |
| 状态/边界 | 重复 code 409；在用岗位停用 409；资格版本追加/不可原地改；无职级/无组织时 unconfigured；建议薪酬不自动进入工资 |
| T/AC | T03、T04、T17、T18 / AC-04、AC-06、AC-09、AC-14、AC-18、AC-21 |
| 当前缺口 | 岗位停用/编制写动作的 manifest 与页面按钮需逐项核对；本矩阵不伪造额外 action key |
| 证据 ID / 结论 | `MCP-M06-*`；**NOT RUN**。前置 T17 未完成 |

### M07 员工入转调离与异动

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 42–50；卡片汇总 30；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/hr/movements`、`/hr/movements/:id`；台账/类型/日历/详情/审批轨迹 |
| 当前动作（7，均在列表页） | `lifecycle.checklist.update`、`lifecycle.offboarding.create`、`lifecycle.onboarding.create`、`lifecycle.probation.create`、`lifecycle.salary-change.create`、`lifecycle.secondment.create`、`lifecycle.transfer.create` |
| 页面可见但未入 manifest | 详情页 `POST /v1/cases/{caseId}/decisions`、`POST /v1/cases/{caseId}/submit`、`POST /v1/cases/{caseId}/withdraw`（source-only；详情 registry actions=[]） |
| 当前 API | `GET /v1/lifecycle/cases`、`GET /v1/lifecycle/cases/{caseId}`、`GET /v1/cases`、`GET /v1/cases/{caseId}`、`GET /v1/cases/{caseId}/timeline`、`GET /v1/employees`、`GET /v1/departments`、`GET /v1/users`、`GET /v1/lifecycle/secondments`、`PATCH /v1/onboarding-tasks/{id}`、6 个 lifecycle 创建端点、`POST /v1/cases/{caseId}/submit|withdraw|decisions` |
| 角色/前置 | R01/R02/R05/R06/R07；员工 A/B/C、部门/岗位、合同/执行薪酬、审批模板、直接下属关系；未来生效日期样本 |
| 状态/边界 | 审批决定≠执行；Effect 失败可见可重试；未来生效不提前；重复提交/决定幂等或 409；审批前不生成 ACTIVE 版本；撤回原因；详情旧链接受权限 |
| T/AC | T03、T04、T05、T17、T18 / AC-04、AC-09、AC-13、AC-15、AC-20、AC-21 |
| 当前缺口 | 详情页实际动作未入 manifest/registry；入职新链路与 T05 尚未完成；页面入口无法稳定打开时标 `BLOCKED`，不猜动作 |
| 证据 ID / 结论 | `MCP-M07-*`；**NOT RUN**。前置 T05/T17 未完成 |

### M08 劳动合同

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 51–56；卡片汇总 19；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/hr/contracts`、`/hr/contracts/:id`；台账/到期/模板/详情/版本/附件 |
| 当前动作（4） | `people.contracts.create`、`people.contract.detail.activate`、`people.contract.detail.amend`、`people.contract.detail.terminate` |
| 页面可见但未入 manifest | 合同模板新建/发布（source-only；模板端点）；续签在台账抽屉中创建新版本（`POST /v1/employments/{employmentId}/contracts`） |
| 当前 API | `GET /v1/employments/contracts`、`GET /v1/employments/contracts/expiring`、`GET /v1/employments/{employmentId}/contracts`、`GET /v1/employments/{employmentId}/contracts/{id}`、`GET /v1/employment-contracts/{id}/amendments`、`GET /v1/contract-templates`、`POST /v1/employments/{employmentId}/contracts`、`POST /v1/employment-contracts/{id}/activate|amendments|terminate`、模板写、文件读取 |
| 角色/前置 | R02/R04/R07；员工 A 的合同 DRAFT/PENDING_EFFECTIVE/ACTIVE/EXPIRED/TERMINATED 各版本；到期窗口样本；附件/模板样本 |
| 状态/边界 | 同员工合同有效期不重叠；未来生效不提前；终止不可逆；合同约定薪酬与实际执行薪酬分离；跨员工详情拒绝；模板版本不可原地改 |
| T/AC | T03、T04、T17、T18 / AC-04、AC-09、AC-13、AC-20、AC-21 |
| 当前缺口 | **manifest 声明 `activate`，但当前 `ContractDetailPage.vue` 只导入 amend/terminate，页面无 activate 按钮**；模板动作未入 manifest。若 UI 无激活入口，标 `UNMAPPED/BLOCKED`，不得用 API 代替 UI 验收 |
| 证据 ID / 结论 | `MCP-M08-*`；**NOT RUN**。前置 T17 未完成，action/UI 一致性待 MCP 核实 |

### M09 考勤打卡与月结

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 57–66；卡片汇总 37；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/attendance`；10 个 Tab：我的打卡/明细/日历/异常/补卡/规则/班次/排班/月结/设备 |
| 当前动作（9） | `attendance.close`、`attendance.correction.request`、`attendance.recalculate`、`attendance.reopen`、`attendance.rule.publish`、`attendance.schedule.create`、`attendance.shift.create`、`attendance.shift.disable`、`attendance.shift.version` |
| 页面可见但未入 manifest | 原始打卡明细导出 `/v1/time/attendance/records/export`（source-only 导出动作） |
| 当前 API | `GET /v1/time/attendance`、`GET /v1/time/attendance/records`、`GET /v1/time/punches`、`GET /v1/time/attendance/records/export`、shifts/rules/schedules/closings/devices 读取、`POST /v1/time/shifts`、`PATCH /v1/time/shifts/{id}`、`POST /v1/time/shifts/{id}/disable`、`POST /v1/time/attendance/rules`、`POST /v1/time/shift-schedules`、`POST /v1/time/attendance/closings`、`POST /v1/time/attendance/closings/{id}/reopen`、`POST /v1/time/attendance/recalculate`、`POST /v1/time/attendance/corrections` |
| 角色/前置 | R03/R06/R07；R03 需要 ORG_UNIT 锚点；班次、排班、规则版本、月度打卡/异常、关账/重开样本；月结 hash 证据 |
| 状态/边界 | 时间事实唯一；关账生成冻结版本；已计薪事实只走更正；无设备/无规则 unconfigured；班次跨夜；补卡有 case；关账/重开不可非法跳转 |
| T/AC | T06、T07、T08、T17、T18 / AC-08、AC-10、AC-18、AC-19、AC-21 |
| 当前缺口 | 当前页面/API **没有独立“本人打卡写入”端点**（只有读、导入、更正、关账）；历史“打卡”卡片入口需 MCP 导航确认，无则为 `UNMAPPED`；原始打卡导出 action 未入 manifest |
| 证据 ID / 结论 | `MCP-M09-*`；**NOT RUN**。R03 锚点未构造，T06 状态冲突见 gap-log |

### M10 请假与假期余额

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 67–74；卡片汇总 21；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/leave`；7 个 Tab：我的请假/请假台账/假期类型/假期规则/余额/日历/汇总 |
| 当前动作（8） | `leave.policy.create`、`leave.policy.retire`、`leave.request.cancel`、`leave.request.on-behalf`、`leave.request.revoke`、`leave.request.self`、`leave.type.create`、`leave.type.update` |
| 页面可见但未入 manifest | 请假预览 `POST /v1/time/leave/preview`、代申请预览、账本读取（读/预览，不新增 action） |
| 当前 API | `GET /v1/time/leave/types|policies|balances|requests|reports/summary|ledger`、`POST/PATCH /v1/time/leave/types`、`POST /v1/time/leave/policies`、`POST /v1/time/leave/policies/{id}/retire`、`POST /v1/time/leave/requests`、`POST /v1/time/leave/requests/on-behalf`、`POST /v1/time/leave/requests/{caseId}/cancel`、`POST /v1/time/leave/requests/{caseId}/revoke`、预览端点 |
| 角色/前置 | R02/R03/R06/R07；R03 需要 ORG_UNIT；假期类型/规则版本、员工余额、已批准无薪假、跨月/半天/冲突/工资冻结样本 |
| 状态/边界 | 带薪属性来自规则版本；本人 subject 只能 SELF；代申请必须显式选择授权员工；跨月按天拆分；重复 code 409；撤销/销假与工资冻结保护；余额账本重放 |
| T/AC | T06、T07、T17、T18 / AC-05、AC-08、AC-10、AC-13、AC-21 |
| 当前缺口 | 两张预览接口未入 registry `requiredApis`；代申请 UI 需 employee selector；R03 未构造；无卡明细 |
| 证据 ID / 结论 | `MCP-M10-*`；**NOT RUN**。前置 T07/T17 未完成，R03 未构造 |

### M11 加班与调休

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 75–80；卡片汇总 18；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/overtime`；6 个 Tab：我的加班/加班台账/加班规则/加班确认/调休余额/结算记录 |
| 当前动作（5） | `overtime.policy.create`、`overtime.policy.retire`、`overtime.request.confirm`、`overtime.request.self`、`overtime.settlement.create` |
| 页面可见但未入 manifest | 规则/台账/调休余额/结算查询为读操作，无额外 manifest 写动作 |
| 当前 API | `GET /v1/time/overtime/records|policies|requests|timeoff-balances|settlements`、`POST /v1/time/overtime/policies`、`POST /v1/time/overtime/policies/{id}/retire`、`POST /v1/time/overtime/requests`、`POST /v1/time/overtime/requests/{id}/confirm`、`POST /v1/overtime/records/{recordId}/settlements` |
| 角色/前置 | R03/R06/R07/R04；已确认加班 8h、PAY/TIMEOFF 各一样本；调休余额；工资期间冻结/未冻结；R03 ORG_UNIT |
| 状态/边界 | 申请时长≠可付时长；PAY/TIMEOFF 互斥，不能重复结算；重复确认 409/幂等；冻结工资期间不可结算；调休余额只能由流水产生 |
| T/AC | T06、T08、T17、T18 / AC-05、AC-10、AC-13、AC-21 |
| 当前缺口 | R03 未构造；T08/T17 未完成；无卡明细；结算与工资冻结的真实数据未构造 |
| 证据 ID / 结论 | `MCP-M11-*`；**NOT RUN** |

### M12 出差管理

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 81–87；卡片汇总 17；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/travel`；6 个 Tab：我的出差/出差台账/差旅政策/出差审批/出差统计/费用交接 |
| 当前动作（6） | `travel.expense.claim`、`travel.policy.publish`、`travel.request.approve`、`travel.request.cancel`、`travel.request.change`、`travel.request.create` |
| 页面可见但未入 manifest | 出差统计查询、审批/费用列表为读操作 |
| 当前 API | `GET /v1/travel/requests`、`POST /v1/travel/requests`、`POST /v1/travel/requests/{id}/change|approve|cancel`、`GET /v1/travel/policies`、`POST /v1/travel/policies`、`GET /v1/travel/expenses`、`POST /v1/travel/expenses`、`GET /v1/travel/reports/summary` |
| 角色/前置 | R02/R03/R06/R07；`T18-TRAVEL-01`；与请假/打卡/加班不重叠的日期；审批人 MANAGED；费用票据样本 |
| 状态/边界 | 出差/请假/打卡/加班不重叠；费用与工资补贴不重复；重复申请 409；审批权限；统计与明细同口径 |
| T/AC | T06、T08、T17、T18 / AC-10、AC-13、AC-16、AC-21 |
| 当前缺口 | T08/T17 未完成；审批锚点/数据依赖待网络验证；卡明细缺失 |
| 证据 ID / 结论 | `MCP-M12-*`；**NOT RUN** |

### M13 薪酬核算

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 88–100；卡片汇总 49；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/payroll`：4 Tab 批次/配置/工资条/报表；配置子区 items/plans/compensations；`/payroll/runs/:id`：计薪输入/复核/发放登记/工资条/导出 |
| 当前动作（18） | `payroll.compensation.create`、`payroll.item.create`、`payroll.item.retire`、`payroll.item.version`、`payroll.run.create`、`payroll.run.publish`、`payroll.run.review`、`payroll.scheme.create`；详情 `payroll.run.detail.{approve,calculate,cancel,close,correct,lock-inputs,mark-paid,publish,reopen,review}` |
| 页面可见但未入 manifest | 输入 `GET /runs/{id}/inputs`、对账 `GET /runs/{id}/reconciliation`、银行/税务导出、员工档案/调整读取（source-only 读/导出） |
| 当前 API（写） | `POST /v1/payroll/runs`、`POST /v1/payroll/runs/{id}/{lock-inputs,calculate,review,approve,publish,cancel,reopen,corrections,mark-paid,close}`、`POST /v1/payroll/items`、`POST /v1/payroll/items/{id}/versions|retire`、`POST /v1/payroll/schemes`、`POST /v1/payroll/employee-plans`；读：runs、detail、employees、inputs、reconciliation、exports、items、schemes、employee-plans、adjustments、payslips、reports |
| 角色/前置 | R04/R05/R09/R07；方案 M/H、合同/执行薪酬、闭合时间事实、法定参数、员工 A/B、`T18-RUN-01`、`T18-RUN-CORR-01`；R05 需要 PAYROLL_RUN 锚点 |
| 状态/边界 | DRAFT→INPUT_LOCKED→CALCULATING→CALCULATED/CALCULATED_WITH_BLOCKERS→REVIEWED→APPROVED；发布/发放正交；缺 closed time fact/基础薪酬/社保参数阻断；已发布不可覆盖；更正累计差额；YTD signed；金额整数分、页面两位小数；对象 `allowed_actions` |
| T/AC | T09–T13、T17、T18 / AC-05–09、11–14、16、18–21 |
| 当前缺口 | master plan 表将 T09–T13 标 PENDING；V2.3 设计称“7 类工作区”，当前实现为 4 Tab + 配置子区；R05 锚点缺失；部分动作只在 manifest 声明，需 MCP 确认按钮与状态机一致；无可确认的 49 卡明细 |
| 证据 ID / 结论 | `MCP-M13-*`；**NOT RUN**。**T09–T13/T17 未完成，不得开始最终 MCP 判定** |

### M14 社保公积金与福利

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 151；卡片汇总 2；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/benefits`；Tab：福利方案/参保登记/缴费台账/法定参数 |
| 当前动作（3） | `benefit.enrollment.create`、`benefit.plan.create`、`benefit.transaction.record` |
| 页面可见但未入 manifest | 法定参数新建版本 `POST /v1/payroll/statutory-parameters`（source-only） |
| 当前 API | `GET/POST /v1/benefits/plans`、`GET/POST /v1/benefits/enrollments`、`GET/POST /v1/benefits/transactions`、`GET/POST /v1/payroll/statutory-parameters` |
| 角色/前置 | R01/R02/R04/R07；BENEFITS_ADMIN 未内置（需构造）；法定参数版本、不同参保组合、A/B 两计划、冲销/冻结批次样本 |
| 状态/边界 | 参数唯一主源；个人/单位金额进入 Cost；一员工一期一计划；重复冲销 409；已发布快照不因费率更新漂移；无参数/无计划 unconfigured |
| T/AC | T09、T14、T18 / AC-06、AC-07、AC-14、AC-20、AC-21 |
| 当前缺口 | 法定参数写动作未入 manifest/registry action；BENEFITS_ADMIN 未 seed；T14 未完成；卡明细缺失；旧 `/payroll/statutory` 必须验证 404 |
| 证据 ID / 结论 | `MCP-M14-*`；**NOT RUN**。前置 T14 未完成，BENEFITS_ADMIN/参数数据未构造 |

### M15 绩效考核

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 101–110；卡片汇总 30；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/performance`、`/performance/cycles/:id`；周期列表和模板/指标/目标/评价/360/校准/结果/申诉/报表 Tab |
| 当前动作（3，列表页） | `performance.appeal.create`、`performance.cycle.create`、`performance.cycle.publish` |
| 页面可见但未入 manifest | 详情页新建/保存/提交评价、校准、添加参与者、申诉（source-only；详情 registry actions=[]） |
| 当前 API | `GET/POST /v1/performance/cycles`、`POST /v1/performance/cycles/{id}/publish`、`GET/POST /v1/performance/reviews`、`POST /v1/performance/reviews/{id}/submit|calibration|appeals`、模板/指标/目标/反馈/校准/结果/报表 GET/POST |
| 角色/前置 | R02/R06/R07；绩效周期、模板/指标/目标、员工/主管关系、已发布结果、申诉样本；发布后冻结 |
| 状态/边界 | 未批准/未映射/草稿结果不发钱；发布后只读；申诉只针对已发布结果；权重/评分/重复 code 校验；结果快照不可变 |
| T/AC | T09、T15、T17、T18 / AC-13、AC-14、AC-15、AC-20、AC-21 |
| 当前缺口 | 详情写动作未入 manifest/registry；T15/T17 未完成；无卡明细；绩效发布映射工资仍待 T14/T15 |
| 证据 ID / 结论 | `MCP-M15-*`；**NOT RUN** |

### M16 招聘需求与候选人

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 111–126；卡片汇总 43；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/recruitment/requests`、`/recruitment/candidates`、`/recruitment/candidates/:id` |
| 当前动作（10） | 需求：`recruitment.requisitions.create/update/close`；候选人：`recruitment.applications.create`、`recruitment.candidates.convert/import/move.stage/talent-pool`、`recruitment.offers.accept/create` |
| 页面可见但未入 manifest | 面试安排/评价/沟通写命令、需求 openings 写命令（source-only） |
| 当前 API | 需求：`GET/POST/PATCH /v1/recruitment/requisitions`、close、board/budget/reports、openings；候选人：`GET/PATCH /v1/recruitment/candidates`、stage、interviews、evaluations、communications、talent-pool、applications、offers、convert、imports |
| 角色/前置 | R08（需 ORG_UNIT+CASE）或 R02；需求、候选人、面试、Offer、Offer 接受、转入职、培训/绩效回路样本；同一 Offer 只转一员工 |
| 状态/边界 | 关闭需求拒绝新申请/面试/Offer；Offer 转一个员工；招聘→员工→合同→薪酬可追溯；未批准/未映射结果不得进入工资；候选人越权 404 |
| T/AC | T05、T15、T17、T18 / AC-04、AC-13、AC-14、AC-15、AC-21 |
| 当前缺口 | R08 无 CASE 锚点时 v2.1 UAT 明确 BLOCKED-FIXTURE；Offer/面试部分写动作未入 manifest；T15/T17 未完成；43 卡明细缺失 |
| 证据 ID / 结论 | `MCP-M16-*`；**NOT RUN** |

### M17 培训管理

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 127–135；卡片汇总 36；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/training`；9 Tab：计划/课程/场次/报名/签到/考试/反馈/档案/报表 |
| 当前动作（5） | `training.courses.update`、`training.enrollments.cancel`、`training.program.create`、`training.programs.update`、`training.sessions.update` |
| 页面可见但未入 manifest | 计划/场次创建、考试创建/发布、签到、报名、完成登记、反馈（source-only；后端 OpenAPI 存在但无 action manifest） |
| 当前 API | `GET/POST /v1/training/plans`、`GET/POST/PATCH /v1/training/courses`、`GET/POST/PATCH /v1/training/programs`、`GET/POST/PATCH /v1/training/sessions`、`GET/POST /v1/training/enrollments`、`POST /v1/training/enrollments/{id}/cancel|complete`、签到、`GET/POST /v1/training/exams`、`POST /v1/training/exams/{id}/publish`、反馈、records/export、reports、employees |
| 角色/前置 | R02/R06/R07；培训计划/课程/场次、员工报名/签到/考试/反馈/记录样本；未报名者样本；无培训津贴样本 |
| 状态/边界 | 未报名不能签到/考试；取消后名额释放；来源闭环；记录只来自有效报名/签到/考试；不默认改岗位或发钱 |
| T/AC | T15、T17、T18 / AC-15、AC-18、AC-21 |
| 当前缺口 | **员工档案无培训记录 Tab**（V2.3 M17 Task 5 要求只读入口；当前 EmployeeDetailPage/EmployeeTabs 仅 8 Tab），标 `UNMAPPED/BLOCKED`；大量写动作未入 manifest；36 卡明细缺失；T17 未完成 |
| 证据 ID / 结论 | `MCP-M17-*`；**NOT RUN** |

### M18 公告与通知

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 136–140、154；卡片汇总 17；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/notices`、`/notifications` |
| 当前动作（10） | `engagement.notice-templates.create`、`engagement.notices.archive`、`audience-preview`、`create`、`decide`、`publish-approved`、`remind`、`restore`、`submit`、`notifications.delivery.retry` |
| 页面可见但未入 manifest | 公告回执 `POST /v1/engagement/notices/{id}/acknowledge`、通知已读 `POST /v1/engagement/notifications/{id}/read`、公告阅读导出（source-only；通知页无金额字段） |
| 当前 API | `GET/POST /v1/engagement/notices`、详情、`acknowledge|archive|restore|submit|decision|publish|remind`、reads、notice-templates、notifications、通知已读、delivery retry |
| 角色/前置 | R02/R06/R07/R09；已发布公告、撤回/过期/受众、工资条通知、通知投递失败样本；需回执公告 |
| 状态/边界 | 有效受众才可见；重复投递不重复；工资通知不暴露金额；撤回/撤权/过期旧链接失效；未发布不对受众展示；外部投递失败可重试 |
| T/AC | T16、T17、T18 / AC-05、AC-17、AC-18、AC-19、AC-21 |
| 当前缺口 | 公告回执/通知已读未入 manifest；通知外部 channel 显示 unavailable；T16/T17 未完成；无 17 卡明细 |
| 证据 ID / 结论 | `MCP-M18-*`；**NOT RUN** |

### M19 用户、角色、菜单与细粒度权限

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 142–147；卡片汇总 15；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/system/users`、`/system/roles`、`/system/pages` |
| 当前动作（11） | 用户：`system.users.create/update/assign.role`；角色：`system.roles.copy/create/delete/rename/save-authorization`；页面：`system.pages.menu.create/reset/update` |
| 页面可见但未入 manifest | 用户停用/启用/会话撤销实际走 `PATCH /v1/users/{id}`（manifest 为 `system.users.update`）；角色授权实际走 `PUT /v1/roles/{id}/authorization` |
| 当前 API | `GET /v1/users`、`POST /v1/users`、`PATCH /v1/users/{id}`、bindings、permissions-preview、users/{id}/roles、roles CRUD、`POST /v1/roles/{id}/copy`、`PUT /v1/roles/{id}/authorization`、role pages、pages catalog、menus CRUD、capabilities、org tree、employees |
| 角色/前置 | R01/R02/R09/R07；角色模板、页面目录、自定义角色、用户无任职/有任职、PAYROLL_RUN/ORG_UNIT/CASE 锚点样本；撤权目标账号 |
| 状态/边界 | 页面/动作/范围/字段同源；内置角色只读/可复制；授权原子保存；expected_version 409；不能自我越权；撤权后旧标签页下次写 403；删除最后管理员 409 |
| T/AC | T01、T02、T17、T18 / AC-03、AC-05、AC-18、AC-21 |
| 当前缺口 | **角色分配 API 要求 `binding_anchors`，`UserRoleDrawer` 未提供锚点输入**；PAYROLL_APPROVER/RECRUITER/ATTENDANCE_SPECIALIST 无法通过当前 UI 完整构造，标 `BLOCKED`（详见 gap-log）；V2.3 摘要 M19 写 6 入口、附录 A 写 7 页，冲突；15 卡明细缺失 |
| 证据 ID / 结论 | `MCP-M19-*`；**NOT RUN**。锚点 UI 缺口是角色验收前置阻塞 |

### M20 审批流程中心

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 148–149；卡片汇总 7；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/workflow`、`/workflow/cases/:id`；视图：我的待办/我发起的/我审批的/抄送我的/模板设置 |
| 当前动作（4） | `workflow.case.decide`、`workflow.case.withdraw`、`workflow.effect.retry`、`workflow.template.version.create` |
| 页面可见但未入 manifest | 详情页决策/撤回/效果重试为 source-only 控件，registry 详情 actions=[]；lifecycle 的 `POST /v1/cases/{id}/submit` 不属本页 manifest |
| 当前 API | `GET /v1/cases`、`GET /v1/cases/{id}`、timeline、tasks/my、templates、`POST /v1/cases/{caseId}/decisions`、`POST /v1/cases/{id}/withdraw`、`POST /v1/effects/{id}/retry` |
| 角色/前置 | R02/R05/R06/R07；待办/我发起/已处理/抄送样本；审批模板；失败 Effect；非审批人样本；工资审批案例 |
| 状态/边界 | 审批通过≠业务写入；Effect 失败可见可重试；重复决定/重试幂等；非 assignee 拒绝；决定后内容变化需重审；旧标签撤权 |
| T/AC | T02、T07、T11、T13、T18 / AC-13、AC-19、AC-21 |
| 当前缺口 | 详情页动作 registry 为空，但实际控件存在；T11/T13 未完成；7 卡明细缺失；模板版本是否可新建/发布需 MCP 验证 |
| 证据 ID / 结论 | `MCP-M20-*`；**NOT RUN** |

### M21 公司基础设置与审计运维

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 152、153、155；卡片汇总 6；卡明细 `CARD-DETAIL BLOCKED` |
| 当前页面 | `/system/settings`：公司/日历/字典/参数/文件；`/system/audit`：审计查询/导出/健康 |
| 当前动作（6） | `system.settings.calendar.publish`、`system.settings.company-profile.save`、`system.settings.dictionary.update`、`system.settings.feature.update`、`system.settings.parameter.update`、`system.audit.export` |
| 页面可见但未入 manifest | 文件列表/链接、健康读取为读；备份/恢复页面不存在（`AuditHealthPanel` 显示 backend unavailable） |
| 当前 API | `GET/PATCH /v1/system/company-profile`、features GET/PUT、dictionaries GET/PATCH、parameters GET/PUT、`GET /v1/time/calendars`、`POST /v1/system/calendars/publish`、document-files、audit events、audit export、health/ready |
| 角色/前置 | R01/R09/R02；公司资料、工作日历版本、字典/参数、文件、审计事件、健康检查；生产演示数据/备份恢复样本由 T19 提供 |
| 状态/边界 | 新库唯一管理员/零业务 demo；生产拒绝 demo/reset；健康 reports migrations/installation/administrator；审计字段 allow-list；原始技术码仅管理员技术明细；备份/恢复不得用当前 UI 伪造 |
| T/AC | T01、T02、T16、T18、T19 / AC-02、AC-05、AC-16、AC-18、AC-19、AC-21、AC-22 |
| 当前缺口 | 6 卡汇总包含安装/升级/备份恢复，但当前 MCP 后台**没有备份/恢复/升级 UI**；这些能力只能由 T19 真实栈/运维证据覆盖，T18 标 `BLOCKED/UNMAPPED`。审计健康页明确 backup unavailable |
| 证据 ID / 结论 | `MCP-M21-*`；**NOT RUN**。备份/恢复/升级卡片不在本页 MCP 可执行范围 |

---

## 5. 当前 action manifest 与页面可见写动作差异

> 本节带出 T18 必须核实的验收风险。这些是静态发现，不是自动测试判定；仍须由 MCP 网络记录确认实际请求。

| 模块 | manifest/页面可见差异 | 影响 |
|---|---|---|
| M01 | 6 个 account 动作；登录/退出/MFA challenge 不在 manifest，属会话流程 | 需单独验证登录、刷新、重登和 MFA |
| M02 | 0 action；页面有指标下钻读操作 | 不把读下钻算写动作 |
| M03 | 0 action；页面有报表运行/导出/下载写动作 | 报表/导出必须人工验证，不能因 manifest 为空跳过 |
| M04 | manifest 10；新建员工调用 `/v1/employees/onboard` 未入 manifest；导入/导出未入 | 新建/导入/导出需 MCP 证据；否则 AC-04/AC-21 不完整 |
| M05 | manifest 3；成员添加/编制 PATCH 未入 | 成员/编制验收需网络证据 |
| M06 | manifest 4；状态/编制写命令未完整入 | 需确认按钮与 API 存在 |
| M07 | manifest 7 在列表；详情决定/提交/撤回未入，详情 registry actions=[] | 审批/提交需详情页证据 |
| M08 | manifest 4；**activate 声明但页面无按钮**；模板写动作未入 | 激活/模板需确认；不得用 API 代替 UI |
| M09 | manifest 9；原始打卡导出未入；**没有本人打卡写端点** | “打卡”卡片可能 UNMAPPED；需导航确认 |
| M10 | manifest 8；预览/账本为读 | 正常，代申请预览需证据 |
| M11 | manifest 5；与页面写动作一致 | T08 未完成前不能判 PASS |
| M12 | manifest 6；与页面写动作一致 | T08 未完成前不能判 PASS |
| M13 | manifest 18；读取/导出/对账/输入明细未入 | 导出/对账/工资条需 MCP 证据 |
| M14 | manifest 3；法定参数版本写动作未入 | 法定参数 Tab 需网络证据，否则 M14 不完整 |
| M15 | manifest 3 在列表；详情 review submit/calibration/participant 未入，详情 actions=[] | 评分/校准/发布需详情页证据 |
| M16 | manifest 10；面试/评价/沟通/openings 写动作未入 | 这些 Tab 仍需 MCP；43 卡不能由 10 action 替代 |
| M17 | manifest 5；计划/场次创建、考试发布、完成、签到等未入 | 9 Tab 大部分写动作需页面/网络证据 |
| M18 | manifest 10；公告回执、通知已读为 source-only | 受众/回执/通知撤权需 MCP |
| M19 | manifest 11；锚点 UI 缺失（详见 gap-log） | R03/R05/R08 不能仅靠 UI 构造和验收 |
| M20 | manifest 4 在列表；详情动作未入，详情 actions=[] | 详情审批/撤回/重试需 MCP |
| M21 | manifest 6；备份/恢复/升级无 UI/API | T18 不可覆盖，转 T19；不得伪造 |

---

## 6. 12 条跨模块闭环映射

> 来源：V2.3 模块闭环设计 §7.2。每条均须有真实角色、真实栈和 MCP 证据；本表仅建立索引，不写执行结论。

| ID | 场景 | 主模块/页面 | 关联 AC | 证据 ID | 当前状态 |
|---|---|---|---|---|---|
| E2E-01 | 招聘转入职 | M16→M07/M04/M08/M13 | AC-04,15,21 | `MCP-X01` | `NOT RUN` |
| E2E-02 | 请假到薪资 | M10→M06/M13 | AC-08,10,14,21 | `MCP-X02` | `NOT RUN` |
| E2E-03 | HR 代请假 | M10/M19/M20 | AC-05,08,13,21 | `MCP-X03` | `NOT RUN` |
| E2E-04 | 组织迁移影响权限与报表 | M05/M19/M03 | AC-04,05,16,21 | `MCP-X04` | `NOT RUN` |
| E2E-05 | 调薪到工资条 | M07/M06/M13 | AC-06,09,12,13,21 | `MCP-X05` | `NOT RUN` |
| E2E-06 | 加班到调休或计薪 | M11/M09/M13 | AC-10,14,21 | `MCP-X06` | `NOT RUN` |
| E2E-07 | 出差到考勤与费用 | M12/M09/M03 | AC-10,16,21 | `MCP-X07` | `NOT RUN` |
| E2E-08 | 绩效发布与申诉 | M15/M20/M13 | AC-13,14,21 | `MCP-X08` | `NOT RUN` |
| E2E-09 | 培训到员工档案 | M17/M04/M20 | AC-15,18,21 | `MCP-X09` | `NOT RUN` |
| E2E-10 | 角色授权到 API/导出 | M19/M03/M20 | AC-03,05,16,17,21 | `MCP-X10` | `NOT RUN` |
| E2E-11 | 合同到期与续签 | M08/M07/M20 | AC-04,09,13,20,21 | `MCP-X11` | `NOT RUN` |
| E2E-12 | 公告受众与回执 | M18/M19/M20 | AC-05,17,21 | `MCP-X12` | `NOT RUN` |

---

## 7. V2.4 T01–T24 与模块覆盖

### 7.1 实际任务卡映射

| 任务 | 可映射模块 | 状态依据 | T18 验收注意 |
|---|---|---|---|
| T01 | M01/M19/M21 | T01 evidence COMPLETE | 只证明安装语义，不等于 MCP 安装界面 |
| T02 | M01/M05/M19/M20 | T02 evidence COMPLETE | action catalog 静态证据不能代替 MCP |
| T03 | M04/M06/M07/M08 | T03 evidence COMPLETE | 合同/薪酬版本链仍需 MCP |
| T04 | M04/M06/M07/M08/M13 | T04 evidence COMPLETE | 执行薪酬链仍需 MCP |
| T05 | M04/M07/M13/M16 | master plan PENDING | 入职单一入口未完成前，相关 MCP BLOCKED |
| T06 | M09/M10/M11/M12 | 计划表 PENDING；`06-time-facts/implementation-report.md` 写 COMPLETE | 状态冲突，未完成 MCP 前置确认；见 gap-log |
| T07 | M09/M10/M11/M20 | PENDING | 请假 Effect/净流水未完成 |
| T08 | M09/M11/M12 | PENDING | 加班互斥/出差计勤未完成 |
| T09 | M13/M14/M15 | PENDING | Snapshot v2 未完成 |
| T10 | M13 | PENDING | 规则引擎未完成 |
| T11 | M13/M20 | PENDING | 批次阻断/状态未完成 |
| T12 | M13 | PENDING | 月中分段未完成 |
| T13 | M13/M20 | PENDING | 更正/YTD 未完成 |
| T14 | M13/M14/M18 | PENDING | 福利计薪输入未完成 |
| T15 | M15/M16/M17 | PENDING | 绩效奖金/Offer/培训链路未完成 |
| T16 | M02/M03/M16/M18/M21 | PENDING | 通知/报表裁剪未完成 |
| T17 | 全部前台模块 | PENDING | 精确金额/对象动作/自助未完成 |
| T18 | 全部模块/12 个闭环 | PENDING | 本文件只建立验收资产，尚未执行 |
| T19 | M21 + 全部模块最终判定 | PENDING | 真实栈、迁移恢复、最终 GO/NO-GO |
| T20 | 无任务卡 | `UNMAPPED` | AC/计划提到 T01–T24，但 master plan 无 T20 |
| T21 | 无任务卡 | `UNMAPPED` | 同上 |
| T22 | 无任务卡 | `UNMAPPED` | 同上 |
| T23 | 无任务卡 | `UNMAPPED` | 同上 |
| T24 | 无任务卡 | `UNMAPPED` | 同上 |

### 7.2 判定边界

- 本矩阵只能声明“覆盖映射存在”，不能声明 T 任务完成。
- T05–T17 未完成时，T18 的 MCP 结果必须为 `BLOCKED`，不能以自动测试、OpenAPI lint、`--list` 或静态审计代替。
- T20–T24 未找到任务卡，AC-01/AC-21 的 T01–T24 唯一覆盖条件未满足；除非用户补充权威任务卡或正式修订口径，否则最终 AC-21 不能 PASS。

---

## 8. 每个模块的最低 MCP 执行集合

每个模块至少执行以下 12 类操作，具体步骤由 `mcp-runbook.md` 和 evidence template 展开：

1. 以允许角色进入唯一主页面，记录 URL、菜单、页面标题；以禁止角色直接输入 URL，确认 forbidden，不泄露对象存在性。
2. 记录 loading → empty/success 至少一种真实状态；无数据场景使用新库或明确隔离的空数据集。
3. 列表筛选、分页、排序或业务日期 as-of 至少一项，刷新后状态保留。
4. 详情深链：从列表打开，记录 ID/版本，刷新后重新加载。
5. 主写动作：表单校验失败 + 成功各一次；提交防重复；失败保留输入。
6. 取消/返回：确认无半写入；列表上下文保留。
7. 审批/执行：若模块有，记录审批前后业务投影和 Effect 结果。
8. 权限差异：无动作角色看不到按钮，直接调用也被拒绝；旧标签页撤权后下一次写 403/401。
9. partial/error：至少一个来源失败或部分投影不可用；显示中文/request_id，不显示空数据/0。
10. 冲突：重复提交、版本冲突或冻结数据，保留输入并提示刷新。
11. 重新登录：会话结束后回到 login，不泄露敏感数据。
12. 证据归档：截图、snapshot、network、实际步骤、结论、缺陷编号全部写入模板。

> 以上是每模块最低要求；AC-21 还要求覆盖 155 个历史入口 dispositions、458 卡（若提供源）和 12 个跨模块闭环。

---

## 9. 本矩阵自身的执行结论

- **21 模块唯一映射：** 已建立。
- **155 历史入口唯一引用：** 已建立；编号 1–155 无缺号、无重复。
- **37 当前页面：** 已建立；与 `registry.ts` 实测一致。
- **132 action manifest：** 已建立；模块级全部引用，另有页面可见未入 manifest 写动作，须在 MCP 中核实。
- **458 功能卡：** **未完成**；只有模块汇总数，没有卡片明细权威源。状态为 `CARD-DETAIL BLOCKED`。
- **T01–T24：** T01–T19 有 master plan 任务卡；T20–T24 `UNMAPPED`。
- **MCP 执行：** **零项执行**；本文件不包含任何 PASS 结论。
