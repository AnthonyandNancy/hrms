# FactoryHR V2.4｜21 模块 MCP 人工验收唯一覆盖矩阵

- **状态：** 验收资产已建立；MCP **尚未执行**，全部执行结论为 `NOT RUN`
- **日期：** 2026-11-28
- **代码基线：** `a5c90a199cb2b4d067495d4560dc517ec6684295`
- **源卡包：** `FactoryHR_V2.3整改包.zip`（SHA-256 `b801485ecd18f2f072105020e85581f91413b4d6ee536f7d34c63fcb4f9788b5`）；**源存在但权威性待确认**
- **分支/工作树：** `v24-t18-acceptance-matrix` / `t18-acceptance-matrix`
- **任务依据：** `docs/superpowers/plans/2026-11-28-v2-4-implementation-master-plan.md` T18
- **需求依据：** `docs/FactoryHR_V2.4_需求与技术设计说明书.md` AC-01–AC-22、5.2、5.3、5.6、附录 A/B/C
- **历史入口依据：** `docs/v2.3-remediation/2026-11-27-155-entry-disposition-matrix.md`
- **模块计划依据：** `docs/superpowers/plans/2026-11-27-v2-3-m01-*.md` 至 `m21-*.md`
- **当前页面依据：** `frontend/src/router/registry.ts`
- **当前动作依据：** `docs/generated/page-actions.json`、`frontend/src/generated/pageActions.ts`
- **当前契约依据：** `docs/openapi/openapi.yaml`
- **458 卡索引：** `458-card-index.md`（从源包 `06_全域逐页面逐功能AI施工卡_合订版.md` 提取 155 页/458 卡）

> 本文件是 T18 的**唯一模块级索引**，不复制历史矩阵、功能卡或需求正文。每一条历史入口、功能卡和 T 任务只能引用既有基线；未找到可确认的基线明细时，必须标记 `BLOCKED` 或 `UNMAPPED`，不得补写功能文本。矩阵中的“当前动作”只表示生成的 action manifest 或页面源码确实存在的动作，不表示已经通过 MCP。

---

## 1. 唯一覆盖口径

### 1.1 目标口径与实际可核验口径

| 维度 | 目标验收口径 | 当前实际可核验情况 | 本矩阵处理 |
|---|---:|---|---|
| 模块 | 21 | `registry.ts` 与 V2.3 模块计划均可归并为 M01–M21 | 21 行唯一模块 |
| 历史入口 | 155 | V2.3 矩阵逐项 1–155，无缺号、无重复 | 每个模块登记精确编号，合计 155 |
| 功能卡 | 458 | 源包 `FactoryHR_V2.3整改包.zip` 的 `06_全域逐页面逐功能AI施工卡_合订版.md` 已提取 458 个唯一 `Mxx-Pxxx-Fxx`；源包状态为**待审批/待确认** | 完整 ID/标题见 `458-card-index.md`；不复制卡片正文、不视为已批准需求 |
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

**说明：** 源包存在且已解析，**但权威性待确认**（包内明确“待审批/待确认”）。`registry.ts` 文件头仍写着“134 business + 15 platform + 6 base = 155”的旧计数契约，真实数组为 37 条；该冲突登记在 `gap-log.md`。本矩阵以实际数组与源包提取结果为准，同时保留目标 155/458/T01–T24 的验收口径。

### 1.3 证据状态语义

| 状态 | 含义 |
|---|---|
| `NOT RUN` | 尚未在真实 `VITE_API_MODE=http` 栈上以人工 MCP 操作执行 |
| `PASS` | 有真实角色、真实后端、截图/快照/网络/刷新/重登证据，且实际结果符合预期 |
| `FAIL` | 已执行且不符合预期；必须回补最小测试/实现并重跑 |
| `BLOCKED` | 前置环境、账号、数据、契约或依赖任务缺失，无法得出业务结论 |
| `UNMAPPED` | 基线找不到可确认的入口、卡片、动作或任务定义；不得猜写 |

本矩阵“执行结论”列一律 `NOT RUN`；`BLOCKED/UNMAPPED` 表示已知无法开始或无法唯一映射，不是执行结果。

> **功能卡源：** 458 卡已提取、**权威性待确认**；不把源包当作已批准需求，也不把卡索引当作执行结果。

---

## 2. 21 模块总览

> `证据 ID` 是模块级证据槽；实际每次操作使用 `<证据ID>-NNN` 子编号。`动作数` 只计数 generated action manifest；括号内为页面源码可见但未入 manifest 的写动作概数，详见 §4。
>
> **入口计数唯一口径：** §2 的“历史入口”与 §2.1、§4A 均按 **155 个入口号**归属；入口 141 归 M01（其源包页面 ID 仍为 `M19-P141`，源包页面归属 M19，仅作来源注记）。因此 M01=8、M19=6；源包页面数（M01=7、M19=7）不是本矩阵的入口计数。

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
| **合计** | **21 模块** | **155** | **458** | **37** | **132** | R01–R09（7 核心 + 2 补充；R02 部分可构造） | T01–T19 + T20–T24(UNMAPPED) | `MCP-M01..M21` | **全部 NOT RUN** |

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

> **唯一入口口径：** 本表、§2 总览和 §4A 台账都按入口号归属：M01 含 141，M19 仅 142–147。`M19-P141` 只是源包页面 ID/页面归属，不改变入口 141 的 M01 计数。源包页面数 M01=7、M19=7 见 GAP-11，不与入口数混用。

## 3. 全局角色与前置数据

### 3.1 角色（R01–R09：7 核心 + 2 补充）

> 口径：**R01–R07 为 7 个核心角色，R08–R09 为 2 个补充角色**；`t18.unlinked-admin` 是 R01 的变体，不是 R10。`HR_SPECIALIST`、`TRAINING_ADMIN`、`BENEFITS_ADMIN`、`REPORT_VIEWER` 只作为模块可选角色，不计入 9 个验收角色。

| 代号 | 实际角色代码 | 锚点 | 验收账号（计划） | 构造状态 |
|---|---|---|---|---|
| R01 | `SYSTEM_ADMIN` | COMPANY（通配） | `t18.admin` / `t18.unlinked-admin` | 可执行；`hrms:install` 创建；unlinked 变体需无 employment link |
| R02 | `HR_ADMIN` | COMPANY + CASE（`workflow.case.decide`） | `t18.hr` | **部分可构造**：COMPANY 授权可绑定；CASE 审批 `workflow.case.decide` 需 CASE anchor_id，现有 CLI/UI 不能写，M20 CASE 审批 `BLOCKED` |
| R03 | `ATTENDANCE_SPECIALIST` | ORG_UNIT + COMPANY | `t18.attendance` | **BLOCKED**：现有 `iam:bind-role` 无法写 ORG_UNIT anchor_id；`UserRoleDrawer` 不传 `binding_anchors` |
| R04 | `PAYROLL_SPECIALIST` | PAYROLL_RUN + COMPANY | `t18.payroll` | **BLOCKED**：现有命令无法写 PAYROLL_RUN anchor_id |
| R05 | `PAYROLL_APPROVER` | PAYROLL_RUN + COMPANY | `t18.approver` | **BLOCKED**：现有命令无法写 PAYROLL_RUN anchor_id |
| R06 | `LINE_MANAGER` | MANAGED | `t18.manager` | 可执行；MANAGED 不需要 anchor_id；需 A/B 直接下属 |
| R07 | `EMPLOYEE` | SELF | `t18.employee` | 可执行；SELF 不需要 anchor_id；需绑定 A 的 employment |
| R08 | `RECRUITER` | ORG_UNIT + CASE | `t18.recruiter` | **BLOCKED**：现有命令无法写 ORG_UNIT/CASE anchor_id |
| R09 | `AUDITOR` | COMPANY | `t18.auditor` | 可执行；只读审计 |

**R02 的锚点边界：** `HR_ADMIN` 的多数授权是 COMPANY，但确有一条 `workflow.case.decide` 使用 CASE 锚点；`iam:bind-role --role=HR_ADMIN` 只写 `anchor_id=null`，不能构造 CASE 审批。故 R02 只能算“部分可构造”，公司级 HR 功能可验收，但 M20 的 HR CASE 审批必须 `BLOCKED`，依赖 T02/T19 补 anchor binding UI/命令；不得用 super admin 冒充 HR 审批。

**R03/R04/R05/R08 的阻塞依赖：** 需要 T02/T19 增加 anchor binding UI 或命令，能够显式写入 `binding_anchors: {ORG_UNIT: <id>}`、`{PAYROLL_RUN: <id>}`、`{CASE: <id>}`。在这之前，**不得**用 `iam:bind-role`、`UserRoleDrawer`、手写 `authority_bindings`、SQL、demo seed 或任一 API 临时绕过；任何依赖这些角色的 MCP 项只能 `BLOCKED`。

**未绑定员工管理员：** `demo.admin` 在 `people:seed-demo` 后会被链接到合成员工，不能自动充当未绑定管理员。必须用 `hrms:install` 创建独立 R01 变体 `t18.unlinked-admin`；未创建前，M01 的“未绑定员工不得伪造本人业务”为 `BLOCKED`。

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
| `T18-RUN-01` | 工资批次 | DRAFT→INPUT_LOCKED→CALCULATED（实际 `PayrollRunStatus`）；后续复核/审批/发布/发放/关闭由独立账号完成 |
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
| 历史入口 | 编号 1–6、141、150；V2.3 模块 M01；卡片汇总 18；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/login`（`login`）、`/workspace`（`workspace`）、`/forbidden`（`forbidden`）、`/not-found`（`not-found`）、`/account`（`account`） |
| 当前动作（6） | `account.mfa.confirm`、`account.mfa.disable`、`account.mfa.enroll`、`account.password.change`、`account.sessions.revoke`、`account.sessions.revoke.all` |
| 页面可见但未入 manifest | 登录/退出、MFA challenge、工作台快捷入口、导航跳转；均不是业务写 action |
| 当前 API | `POST /v1/auth/login`、`GET /v1/auth/csrf`、`GET /v1/me`、`GET /v1/me/workspace`、`GET /v1/tasks/my`、`GET /v1/auth/sessions`、`GET /v1/auth/mfa/status`、`POST /v1/auth/password`、`POST /v1/auth/logout-all`、`DELETE /v1/auth/sessions/{id}`、`POST /v1/auth/mfa/totp/{enroll,confirm,disable}` |
| 角色/前置 | R01–R07 + 未绑定员工管理员；`demo.employee` 绑定合成 employment；MFA 默认关闭时密码直登；MFA=true 时必须准备 TOTP |
| 状态/边界 | loading/empty/error/forbidden/partial；未绑定员工时 `employment_linked=false`；撤权/停用后旧标签页写请求 403/401；会话 Cookie HttpOnly，CSRF 不在 localStorage |
| T/AC | T01、T02、T17、T18 / AC-01、AC-02、AC-03、AC-05、AC-18、AC-19、AC-21 |
| 当前缺口 | 未绑定管理员账号未内置；MFA=true 的 challenge 路径需单独证据；工作台快捷入口只验证导航，不证明下游模块业务 |
| 证据 ID / 结论 | `MCP-M01-Pxxx-NNN`；**NOT RUN**。前置 T17 未完成，且未绑定管理员数据未构造 |

### M02 首页仪表盘

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 7–12；卡片汇总 13；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/dashboard`（`dashboard`）；唯一看板，分区含指标、部门人数、人员构成、异动、提醒、招聘 |
| 当前动作（0） | 无 manifest write action；指标下钻为读操作 |
| 页面可见但未入 manifest | 业务日期筛选刷新、指标卡下钻（`GET /v1/reporting/dashboard/metrics/{metric}`） |
| 当前 API | `GET /v1/reporting/dashboard`、`GET /v1/reporting/dashboard/metrics/{metric}`（source-only；registry 只列 dashboard） |
| 角色/前置 | R01/R02/R06/R07；至少 1 个已发布公告、合同到期样本、异动/假勤/招聘数据；不得伪造空图 |
| 状态/边界 | 指标 `AVAILABLE/UNCONFIGURED/FORBIDDEN/UNAVAILABLE`；查询失败不得显示 0；无薪酬字段权限时金额不可见/不可反推；部门下钻受范围限制 |
| T/AC | T16、T17、T18 / AC-16、AC-18、AC-19、AC-21 |
| 当前缺口 | 指标 status 与 OpenAPI 旧 schema 存在漂移风险（见 gap-log）；当前页面无 action manifest，只有读模型证据 |
| 证据 ID / 结论 | `MCP-M02-Pxxx-NNN`；**NOT RUN**。前置 T16/T17 未完成 |

### M03 报表与导出中心

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 13–21；卡片汇总 26；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/reports`（`reports`）：报表目录、运行、结果、导出、下载 |
| 当前动作（0） | 无 manifest write action |
| 页面可见但未入 manifest | `POST /v1/reporting/run`、`POST /v1/reporting/exports`、`GET /v1/reporting/exports`、`GET /v1/reporting/exports/{jobId}/download`（source-only） |
| 当前 API | `GET /v1/reporting/definitions`、`GET /v1/org-units/tree`、`POST /v1/reporting/run`、`GET /v1/reporting/exports`、`POST /v1/reporting/exports`、`GET /v1/reporting/exports/{jobId}/download`；页面共享 dashboard 读 |
| 角色/前置 | R02/R04/R09 可读；R06/R07 受范围/字段限制；至少一个有数据和一个零数据定义；导出任务必须真实创建并可再次鉴权 |
| 状态/边界 | 报表运行失败不得显示空表；导出字段白名单；创建后被撤字段权限再下载 403；订阅者撤权后跳过/失败；分页/筛选/as-of 保持 |
| T/AC | T16、T17、T18 / AC-05、AC-16、AC-18、AC-19、AC-21 |
| 当前缺口 | 页面写动作未入 manifest；订阅/取消/重试是否有当前 UI 需 MCP 确认；无定义/无数据状态必须单独造数；未确认的订阅卡片入口标 UNMAPPED，不猜写 |
| 证据 ID / 结论 | `MCP-M03-Pxxx-NNN`；**NOT RUN**。前置 T16/T17 未完成，订阅卡片明细缺失 |

### M04 员工档案

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 22–31；卡片汇总 29；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/hr/employees`、`/hr/employees/:id`；8 个详情 Tab：基本信息/任职与异动/合同/证照与附件/教育/工作经历/联系方式/变更申请 |
| 当前动作（10） | `hr.employees.create.employment`、`hr.employees.contract.create`、`employee.change.submit`、`employee.detail.archive`、`employee.detail.assign`、`employee.detail.restore`、`employee.profile.{certificates,contacts,education,experiences}.retire` |
| 页面可见但未入 manifest | `POST /v1/employees/onboard`（新建员工抽屉）、`GET /v1/employee-imports`、`GET /v1/employees/export`（source-only） |
| 当前 API | `GET /v1/employees`、`GET /v1/employees/{employment_id}`、`GET /v1/employments/{employmentId}/timeline`、profile 子资源 GET/POST/PATCH、`DELETE /v1/employees/{employmentId}/{resource}/{id}`、change request submit、`DELETE /v1/employees/{employment_id}`、restore、assignments、contracts、imports/export |
| 角色/前置 | R01/R02/R06/R07；至少 3 名合成员工（含同名/近似名）；已有部门/岗位；A 有合同+执行薪酬；导入批次样本 |
| 状态/边界 | 员工建档原子（Person/Employment/Assignment/Terms）；同名不误绑；跨组织访问 404；字段裁剪；归档/恢复版本；子档案 retire append-only；刷新保留筛选/分页/选中 |
| T/AC | T03、T04、T05、T17、T18 / AC-04、AC-05、AC-07、AC-18、AC-20、AC-21 |
| 当前缺口 | “新增员工”实际 API 未入 action manifest；员工档案没有“培训记录”Tab（M17 计划要求只读培训入口），见 gap-log；导入/导出动作需 MCP 验证 |
| 证据 ID / 结论 | `MCP-M04-Pxxx-NNN`；**NOT RUN**。前置 T05/T17 未完成 |

### M05 部门与组织结构

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 32–36；卡片汇总 11；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/hr/departments`：组织树、部门列表/详情、成员、编制、移动影响 |
| 当前动作（3） | `org.departments.create`、`org.departments.move`、`org.departments.remove.member` |
| 页面可见但未入 manifest | 成员添加 `POST /v1/departments/{departmentId}/members`、编制修订 `PATCH /v1/departments/{departmentId}`（source-only） |
| 当前 API | `GET /v1/departments`、`GET /v1/org-units/tree`、`GET /v1/departments/{departmentId}/members`、`GET /v1/departments/staffing`、`GET /v1/org-units/{departmentId}/impact`、`POST /v1/departments`、`PATCH /v1/departments/{id}`、`POST /v1/departments/{departmentId}/members`、`POST /v1/org-units/{departmentId}/move` |
| 角色/前置 | R02/R06/R07；`T18-ORG-01` 及至少 2 个部门树；部门负责人/编制样本；有在岗人员的部门 |
| 状态/边界 | 禁止组织环；有在岗人员不可硬删；移动影响预览；as-of 历史归属不漂移；组织越权 403/404 |
| T/AC | T02、T17、T18 / AC-03、AC-04、AC-05、AC-16、AC-18、AC-20、AC-21 |
| 当前缺口 | 成员添加/编制写命令未完整入 manifest；`POST /v1/org-units` 可能已 legacy，本矩阵不将其作为当前动作 |
| 证据 ID / 结论 | `MCP-M05-Pxxx-NNN`；**NOT RUN**。前置 T17 未完成，写动作映射待网络证据核实 |

### M06 岗位、职级和任职资格

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 37–41；卡片汇总 14；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/hr/positions`：岗位目录、编制、职级；详情概览/在岗/任职资格/编制 |
| 当前动作（4） | `org.positions.create`、`org.positions.edit`、`org.positions.grade.upsert`、`org.positions.requirements.version` |
| 页面可见但未入 manifest | 岗位状态切换/编制编辑（实际 API 可能复用 PATCH；MCP 以网络记录为准） |
| 当前 API | `GET /v1/positions`、`GET /v1/positions/{positionId}`、`GET /v1/departments`、`GET /v1/positions/grades`、`POST /v1/positions/grades`、`POST /v1/positions`、`PATCH /v1/positions/{positionId}`、`GET /v1/positions/{positionId}/staff`、`GET/POST /v1/positions/{positionId}/requirements` |
| 角色/前置 | R02/R06；组织树/部门；岗位、职级、资格版本；在用岗位；岗位建议薪酬不得覆盖个人工资 |
| 状态/边界 | 重复 code 409；在用岗位停用 409；资格版本追加/不可原地改；无职级/无组织时 unconfigured；建议薪酬不自动进入工资 |
| T/AC | T03、T04、T17、T18 / AC-04、AC-06、AC-09、AC-14、AC-18、AC-21 |
| 当前缺口 | 岗位停用/编制写动作的 manifest 与页面按钮需逐项核对；本矩阵不伪造额外 action key |
| 证据 ID / 结论 | `MCP-M06-Pxxx-NNN`；**NOT RUN**。前置 T17 未完成 |

### M07 员工入转调离与异动

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 42–50；卡片汇总 30；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/hr/movements`、`/hr/movements/:id`；台账/类型/日历/详情/审批轨迹 |
| 当前动作（7，均在列表页） | `lifecycle.checklist.update`、`lifecycle.offboarding.create`、`lifecycle.onboarding.create`、`lifecycle.probation.create`、`lifecycle.salary-change.create`、`lifecycle.secondment.create`、`lifecycle.transfer.create` |
| 页面可见但未入 manifest | 详情页 `POST /v1/cases/{caseId}/decisions`、`POST /v1/cases/{caseId}/submit`、`POST /v1/cases/{caseId}/withdraw`（source-only；详情 registry actions=[]） |
| 当前 API | `GET /v1/lifecycle/cases`、`GET /v1/lifecycle/cases/{caseId}`、`GET /v1/cases`、`GET /v1/cases/{caseId}`、`GET /v1/cases/{caseId}/timeline`、`GET /v1/employees`、`GET /v1/departments`、`GET /v1/users`、`GET /v1/lifecycle/secondments`、`PATCH /v1/onboarding-tasks/{id}`、6 个 lifecycle 创建端点、`POST /v1/cases/{caseId}/submit|withdraw|decisions` |
| 角色/前置 | R01/R02/R05/R06/R07；员工 A/B/C、部门/岗位、合同/执行薪酬、审批模板、直接下属关系；未来生效日期样本 |
| 状态/边界 | 审批决定≠执行；Effect 失败可见可重试；未来生效不提前；重复提交/决定幂等或 409；审批前不生成 ACTIVE 版本；撤回原因；详情旧链接受权限 |
| T/AC | T03、T04、T05、T17、T18 / AC-04、AC-09、AC-13、AC-15、AC-20、AC-21 |
| 当前缺口 | 详情页实际动作未入 manifest/registry；入职新链路与 T05 尚未完成；页面入口无法稳定打开时标 `BLOCKED`，不猜动作 |
| 证据 ID / 结论 | `MCP-M07-Pxxx-NNN`；**NOT RUN**。前置 T05/T17 未完成 |

### M08 劳动合同

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 51–56；卡片汇总 19；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/hr/contracts`、`/hr/contracts/:id`；台账/到期/模板/详情/版本/附件 |
| 当前动作（4） | `people.contracts.create`、`people.contract.detail.activate`、`people.contract.detail.amend`、`people.contract.detail.terminate` |
| 页面可见但未入 manifest | 合同模板新建/发布（source-only；模板端点）；续签在台账抽屉中创建新版本（`POST /v1/employments/{employmentId}/contracts`） |
| 当前 API | `GET /v1/employments/contracts`、`GET /v1/employments/contracts/expiring`、`GET /v1/employments/{employmentId}/contracts`、`GET /v1/employments/{employmentId}/contracts/{id}`、`GET /v1/employment-contracts/{id}/amendments`、`GET /v1/contract-templates`、`POST /v1/employments/{employmentId}/contracts`、`POST /v1/employment-contracts/{id}/activate|amendments|terminate`、模板写、文件读取 |
| 角色/前置 | R02/R04/R07；员工 A 的合同 DRAFT/PENDING_EFFECTIVE/ACTIVE/EXPIRED/TERMINATED 各版本；到期窗口样本；附件/模板样本 |
| 状态/边界 | 同员工合同有效期不重叠；未来生效不提前；终止不可逆；合同约定薪酬与实际执行薪酬分离；跨员工详情拒绝；模板版本不可原地改 |
| T/AC | T03、T04、T17、T18 / AC-04、AC-09、AC-13、AC-20、AC-21 |
| 当前缺口 | **manifest 声明 `activate`，但当前 `ContractDetailPage.vue` 只导入 amend/terminate，页面无 activate 按钮**；模板动作未入 manifest。若 UI 无激活入口，标 `UNMAPPED/BLOCKED`，不得用 API 代替 UI 验收 |
| 证据 ID / 结论 | `MCP-M08-Pxxx-NNN`；**NOT RUN**。前置 T17 未完成，action/UI 一致性待 MCP 核实 |

### M09 考勤打卡与月结

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 57–66；卡片汇总 37；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/attendance`；10 个 Tab：我的打卡/明细/日历/异常/补卡/规则/班次/排班/月结/设备 |
| 当前动作（9） | `attendance.close`、`attendance.correction.request`、`attendance.recalculate`、`attendance.reopen`、`attendance.rule.publish`、`attendance.schedule.create`、`attendance.shift.create`、`attendance.shift.disable`、`attendance.shift.version` |
| 页面可见但未入 manifest | 原始打卡明细导出 `/v1/time/attendance/records/export`（source-only 导出动作） |
| 当前 API | `GET /v1/time/attendance`、`GET /v1/time/attendance/records`、`GET /v1/time/punches`、`GET /v1/time/attendance/records/export`、shifts/rules/schedules/closings/devices 读取、`POST /v1/time/shifts`、`PATCH /v1/time/shifts/{id}`、`POST /v1/time/shifts/{id}/disable`、`POST /v1/time/attendance/rules`、`POST /v1/time/shift-schedules`、`POST /v1/time/attendance/closings`、`POST /v1/time/attendance/closings/{id}/reopen`、`POST /v1/time/attendance/recalculate`、`POST /v1/time/attendance/corrections` |
| 角色/前置 | R03/R06/R07；R03 需要 ORG_UNIT 锚点；班次、排班、规则版本、月度打卡/异常、关账/重开样本；月结 hash 证据 |
| 状态/边界 | 时间事实唯一；关账生成冻结版本；已计薪事实只走更正；无设备/无规则 unconfigured；班次跨夜；补卡有 case；关账/重开不可非法跳转 |
| T/AC | T06、T07、T08、T17、T18 / AC-08、AC-10、AC-18、AC-19、AC-21 |
| 当前缺口 | 当前页面/API **没有独立“本人打卡写入”端点**（只有读、导入、更正、关账）；历史“打卡”卡片入口需 MCP 导航确认，无则为 `UNMAPPED`；原始打卡导出 action 未入 manifest |
| 证据 ID / 结论 | `MCP-M09-Pxxx-NNN`；**NOT RUN**。R03 锚点未构造，T06 状态冲突见 gap-log |

### M10 请假与假期余额

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 67–74；卡片汇总 21；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/leave`；7 个 Tab：我的请假/请假台账/假期类型/假期规则/余额/日历/汇总 |
| 当前动作（8） | `leave.policy.create`、`leave.policy.retire`、`leave.request.cancel`、`leave.request.on-behalf`、`leave.request.revoke`、`leave.request.self`、`leave.type.create`、`leave.type.update` |
| 页面可见但未入 manifest | 请假预览 `POST /v1/time/leave/preview`、代申请预览、账本读取（读/预览，不新增 action） |
| 当前 API | `GET /v1/time/leave/types|policies|balances|requests|reports/summary|ledger`、`POST/PATCH /v1/time/leave/types`、`POST /v1/time/leave/policies`、`POST /v1/time/leave/policies/{id}/retire`、`POST /v1/time/leave/requests`、`POST /v1/time/leave/requests/on-behalf`、`POST /v1/time/leave/requests/{caseId}/cancel`、`POST /v1/time/leave/requests/{caseId}/revoke`、预览端点 |
| 角色/前置 | R02/R03/R06/R07；R03 需要 ORG_UNIT；假期类型/规则版本、员工余额、已批准无薪假、跨月/半天/冲突/工资冻结样本 |
| 状态/边界 | 带薪属性来自规则版本；本人 subject 只能 SELF；代申请必须显式选择授权员工；跨月按天拆分；重复 code 409；撤销/销假与工资冻结保护；余额账本重放 |
| T/AC | T06、T07、T17、T18 / AC-05、AC-08、AC-10、AC-13、AC-21 |
| 当前缺口 | 两张预览接口未入 registry `requiredApis`；代申请 UI 需 employee selector；R03 未构造；卡明细见 `458-card-index.md`（源包待确认） |
| 证据 ID / 结论 | `MCP-M10-Pxxx-NNN`；**NOT RUN**。前置 T07/T17 未完成，R03 未构造 |

### M11 加班与调休

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 75–80；卡片汇总 18；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/overtime`；6 个 Tab：我的加班/加班台账/加班规则/加班确认/调休余额/结算记录 |
| 当前动作（5） | `overtime.policy.create`、`overtime.policy.retire`、`overtime.request.confirm`、`overtime.request.self`、`overtime.settlement.create` |
| 页面可见但未入 manifest | 规则/台账/调休余额/结算查询为读操作，无额外 manifest 写动作 |
| 当前 API | `GET /v1/time/overtime/records|policies|requests|timeoff-balances|settlements`、`POST /v1/time/overtime/policies`、`POST /v1/time/overtime/policies/{id}/retire`、`POST /v1/time/overtime/requests`、`POST /v1/time/overtime/requests/{id}/confirm`、`POST /v1/overtime/records/{recordId}/settlements` |
| 角色/前置 | R03/R06/R07/R04；已确认加班 8h、PAY/TIMEOFF 各一样本；调休余额；工资期间冻结/未冻结；R03 ORG_UNIT |
| 状态/边界 | 申请时长≠可付时长；PAY/TIMEOFF 互斥，不能重复结算；重复确认 409/幂等；冻结工资期间不可结算；调休余额只能由流水产生 |
| T/AC | T06、T08、T17、T18 / AC-05、AC-10、AC-13、AC-21 |
| 当前缺口 | R03 未构造；T08/T17 未完成；卡明细见 `458-card-index.md`（源包待确认）；结算与工资冻结的真实数据未构造 |
| 证据 ID / 结论 | `MCP-M11-Pxxx-NNN`；**NOT RUN** |

### M12 出差管理

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 81–87；卡片汇总 17；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/travel`；6 个 Tab：我的出差/出差台账/差旅政策/出差审批/出差统计/费用交接 |
| 当前动作（6） | `travel.expense.claim`、`travel.policy.publish`、`travel.request.approve`、`travel.request.cancel`、`travel.request.change`、`travel.request.create` |
| 页面可见但未入 manifest | 出差统计查询、审批/费用列表为读操作 |
| 当前 API | `GET /v1/travel/requests`、`POST /v1/travel/requests`、`POST /v1/travel/requests/{id}/change|approve|cancel`、`GET /v1/travel/policies`、`POST /v1/travel/policies`、`GET /v1/travel/expenses`、`POST /v1/travel/expenses`、`GET /v1/travel/reports/summary` |
| 角色/前置 | R02/R03/R06/R07；`T18-TRAVEL-01`；与请假/打卡/加班不重叠的日期；审批人 MANAGED；费用票据样本 |
| 状态/边界 | 出差/请假/打卡/加班不重叠；费用与工资补贴不重复；重复申请 409；审批权限；统计与明细同口径 |
| T/AC | T06、T08、T17、T18 / AC-10、AC-13、AC-16、AC-21 |
| 当前缺口 | T08/T17 未完成；审批锚点/数据依赖待网络验证；卡明细见 `458-card-index.md`（源包待确认） |
| 证据 ID / 结论 | `MCP-M12-Pxxx-NNN`；**NOT RUN** |

### M13 薪酬核算

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 88–100；卡片汇总 49；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/payroll`：4 Tab 批次/配置/工资条/报表；配置子区 items/plans/compensations；`/payroll/runs/:id`：计薪输入/复核/发放登记/工资条/导出 |
| 当前动作（18） | `payroll.compensation.create`、`payroll.item.create`、`payroll.item.retire`、`payroll.item.version`、`payroll.run.create`、`payroll.run.publish`、`payroll.run.review`、`payroll.scheme.create`；详情 `payroll.run.detail.{approve,calculate,cancel,close,correct,lock-inputs,mark-paid,publish,reopen,review}` |
| 页面可见但未入 manifest | 输入 `GET /runs/{id}/inputs`、对账 `GET /runs/{id}/reconciliation`、银行/税务导出、员工档案/调整读取（source-only 读/导出） |
| 当前 API（写） | `POST /v1/payroll/runs`、`POST /v1/payroll/runs/{id}/{lock-inputs,calculate,review,approve,publish,cancel,reopen,corrections,mark-paid,close}`、`POST /v1/payroll/items`、`POST /v1/payroll/items/{id}/versions|retire`、`POST /v1/payroll/schemes`、`POST /v1/payroll/employee-plans`；读：runs、detail、employees、inputs、reconciliation、exports、items、schemes、employee-plans、adjustments、payslips、reports |
| 角色/前置 | R04/R05/R09/R07；方案 M/H、合同/执行薪酬、闭合时间事实、法定参数、员工 A/B、`T18-RUN-01`、`T18-RUN-CORR-01`；R05 需要 PAYROLL_RUN 锚点 |
| 状态/边界 | DRAFT→INPUT_LOCKED→CALCULATED→REVIEWED→APPROVED→PUBLISHED→PAID→CLOSED（另有 CANCELLED）；状态以 `PayrollRunStatus` 实际枚举为准；发布/发放正交；缺 closed time fact/基础薪酬/社保参数阻断；已发布不可覆盖；更正累计差额；YTD signed；金额整数分、页面两位小数；对象 `allowed_actions` |
| T/AC | T09–T13、T17、T18 / AC-05–09、11–14、16、18–21 |
| 当前缺口 | master plan 表将 T09–T13 标 PENDING；V2.3 设计称“7 类工作区”，当前实现为 4 Tab + 配置子区；R05 锚点缺失；部分动作只在 manifest 声明，需 MCP 确认按钮与状态机一致；49 卡见 `458-card-index.md`（源包待确认） |
| 证据 ID / 结论 | `MCP-M13-Pxxx-NNN`；**NOT RUN**。**T09–T13/T17 未完成，不得开始最终 MCP 判定** |

### M14 社保公积金与福利

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 151；卡片汇总 2；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/benefits`；Tab：福利方案/参保登记/缴费台账/法定参数 |
| 当前动作（3） | `benefit.enrollment.create`、`benefit.plan.create`、`benefit.transaction.record` |
| 页面可见但未入 manifest | 法定参数新建版本 `POST /v1/payroll/statutory-parameters`（source-only） |
| 当前 API | `GET/POST /v1/benefits/plans`、`GET/POST /v1/benefits/enrollments`、`GET/POST /v1/benefits/transactions`、`GET/POST /v1/payroll/statutory-parameters` |
| 角色/前置 | R01/R02/R04/R07；BENEFITS_ADMIN 未内置（需构造）；法定参数版本、不同参保组合、A/B 两计划、冲销/冻结批次样本 |
| 状态/边界 | 参数唯一主源；个人/单位金额进入 Cost；一员工一期一计划；重复冲销 409；已发布快照不因费率更新漂移；无参数/无计划 unconfigured |
| T/AC | T09、T14、T18 / AC-06、AC-07、AC-14、AC-20、AC-21 |
| 当前缺口 | 法定参数写动作未入 manifest/registry action；BENEFITS_ADMIN 未 seed；T14 未完成；卡明细见 `458-card-index.md`（源包待确认）；旧 `/payroll/statutory` 必须验证 404 |
| 证据 ID / 结论 | `MCP-M14-Pxxx-NNN`；**NOT RUN**。前置 T14 未完成，BENEFITS_ADMIN/参数数据未构造 |

### M15 绩效考核

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 101–110；卡片汇总 30；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/performance`、`/performance/cycles/:id`；周期列表和模板/指标/目标/评价/360/校准/结果/申诉/报表 Tab |
| 当前动作（3，列表页） | `performance.appeal.create`、`performance.cycle.create`、`performance.cycle.publish` |
| 页面可见但未入 manifest | 详情页新建/保存/提交评价、校准、添加参与者、申诉（source-only；详情 registry actions=[]） |
| 当前 API | `GET/POST /v1/performance/cycles`、`POST /v1/performance/cycles/{id}/publish`、`GET/POST /v1/performance/reviews`、`POST /v1/performance/reviews/{id}/submit|calibration|appeals`、模板/指标/目标/反馈/校准/结果/报表 GET/POST |
| 角色/前置 | R02/R06/R07；绩效周期、模板/指标/目标、员工/主管关系、已发布结果、申诉样本；发布后冻结 |
| 状态/边界 | 未批准/未映射/草稿结果不发钱；发布后只读；申诉只针对已发布结果；权重/评分/重复 code 校验；结果快照不可变 |
| T/AC | T09、T15、T17、T18 / AC-13、AC-14、AC-15、AC-20、AC-21 |
| 当前缺口 | 详情写动作未入 manifest/registry；T15/T17 未完成；卡明细见 `458-card-index.md`（源包待确认）；绩效发布映射工资仍待 T14/T15 |
| 证据 ID / 结论 | `MCP-M15-Pxxx-NNN`；**NOT RUN** |

### M16 招聘需求与候选人

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 111–126；卡片汇总 43；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/recruitment/requests`、`/recruitment/candidates`、`/recruitment/candidates/:id` |
| 当前动作（10） | 需求：`recruitment.requisitions.create/update/close`；候选人：`recruitment.applications.create`、`recruitment.candidates.convert/import/move.stage/talent-pool`、`recruitment.offers.accept/create` |
| 页面可见但未入 manifest | 面试安排/评价/沟通写命令、需求 openings 写命令（source-only） |
| 当前 API | 需求：`GET/POST/PATCH /v1/recruitment/requisitions`、close、board/budget/reports、openings；候选人：`GET/PATCH /v1/recruitment/candidates`、stage、interviews、evaluations、communications、talent-pool、applications、offers、convert、imports |
| 角色/前置 | R08（需 ORG_UNIT+CASE）或 R02；需求、候选人、面试、Offer、Offer 接受、转入职、培训/绩效回路样本；同一 Offer 只转一员工 |
| 状态/边界 | 关闭需求拒绝新申请/面试/Offer；Offer 转一个员工；招聘→员工→合同→薪酬可追溯；未批准/未映射结果不得进入工资；候选人越权 404 |
| T/AC | T05、T15、T17、T18 / AC-04、AC-13、AC-14、AC-15、AC-21 |
| 当前缺口 | R08 无 CASE 锚点时 v2.1 UAT 明确 BLOCKED-FIXTURE；Offer/面试部分写动作未入 manifest；T15/T17 未完成；43 卡明细见 `458-card-index.md`（源包待确认） |
| 证据 ID / 结论 | `MCP-M16-Pxxx-NNN`；**NOT RUN** |

### M17 培训管理

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 127–135；卡片汇总 36；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/training`；9 Tab：计划/课程/场次/报名/签到/考试/反馈/档案/报表 |
| 当前动作（5） | `training.courses.update`、`training.enrollments.cancel`、`training.program.create`、`training.programs.update`、`training.sessions.update` |
| 页面可见但未入 manifest | 计划/场次创建、考试创建/发布、签到、报名、完成登记、反馈（source-only；后端 OpenAPI 存在但无 action manifest） |
| 当前 API | `GET/POST /v1/training/plans`、`GET/POST/PATCH /v1/training/courses`、`GET/POST/PATCH /v1/training/programs`、`GET/POST/PATCH /v1/training/sessions`、`GET/POST /v1/training/enrollments`、`POST /v1/training/enrollments/{id}/cancel|complete`、签到、`GET/POST /v1/training/exams`、`POST /v1/training/exams/{id}/publish`、反馈、records/export、reports、employees |
| 角色/前置 | R02/R06/R07；培训计划/课程/场次、员工报名/签到/考试/反馈/记录样本；未报名者样本；无培训津贴样本 |
| 状态/边界 | 未报名不能签到/考试；取消后名额释放；来源闭环；记录只来自有效报名/签到/考试；不默认改岗位或发钱 |
| T/AC | T15、T17、T18 / AC-15、AC-18、AC-21 |
| 当前缺口 | **员工档案无培训记录 Tab**（V2.3 M17 Task 5 要求只读入口；当前 EmployeeDetailPage/EmployeeTabs 仅 8 Tab），标 `UNMAPPED/BLOCKED`；大量写动作未入 manifest；36 卡已入 `458-card-index.md`；T17 未完成 |
| 证据 ID / 结论 | `MCP-M17-Pxxx-NNN`；**NOT RUN** |

### M18 公告与通知

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 136–140、154；卡片汇总 17；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/notices`、`/notifications` |
| 当前动作（10） | `engagement.notice-templates.create`、`engagement.notices.archive`、`audience-preview`、`create`、`decide`、`publish-approved`、`remind`、`restore`、`submit`、`notifications.delivery.retry` |
| 页面可见但未入 manifest | 公告回执 `POST /v1/engagement/notices/{id}/acknowledge`、通知已读 `POST /v1/engagement/notifications/{id}/read`、公告阅读导出（source-only；通知页无金额字段） |
| 当前 API | `GET/POST /v1/engagement/notices`、详情、`acknowledge|archive|restore|submit|decision|publish|remind`、reads、notice-templates、notifications、通知已读、delivery retry |
| 角色/前置 | R02/R06/R07/R09；已发布公告、撤回/过期/受众、工资条通知、通知投递失败样本；需回执公告 |
| 状态/边界 | 有效受众才可见；重复投递不重复；工资通知不暴露金额；撤回/撤权/过期旧链接失效；未发布不对受众展示；外部投递失败可重试 |
| T/AC | T16、T17、T18 / AC-05、AC-17、AC-18、AC-19、AC-21 |
| 当前缺口 | 公告回执/通知已读未入 manifest；通知外部 channel 显示 unavailable；T16/T17 未完成；无 17 卡明细 |
| 证据 ID / 结论 | `MCP-M18-Pxxx-NNN`；**NOT RUN** |

### M19 用户、角色、菜单与细粒度权限

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 142–147；卡片汇总 15；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/system/users`、`/system/roles`、`/system/pages` |
| 当前动作（11） | 用户：`system.users.create/update/assign.role`；角色：`system.roles.copy/create/delete/rename/save-authorization`；页面：`system.pages.menu.create/reset/update` |
| 页面可见但未入 manifest | 用户停用/启用/会话撤销实际走 `PATCH /v1/users/{id}`（manifest 为 `system.users.update`）；角色授权实际走 `PUT /v1/roles/{id}/authorization` |
| 当前 API | `GET /v1/users`、`POST /v1/users`、`PATCH /v1/users/{id}`、bindings、permissions-preview、users/{id}/roles、roles CRUD、`POST /v1/roles/{id}/copy`、`PUT /v1/roles/{id}/authorization`、role pages、pages catalog、menus CRUD、capabilities、org tree、employees |
| 角色/前置 | R01/R02/R09/R07；角色模板、页面目录、自定义角色、用户无任职/有任职、PAYROLL_RUN/ORG_UNIT/CASE 锚点样本；撤权目标账号 |
| 状态/边界 | 页面/动作/范围/字段同源；内置角色只读/可复制；授权原子保存；expected_version 409；不能自我越权；撤权后旧标签页下次写 403；删除最后管理员 409 |
| T/AC | T01、T02、T17、T18 / AC-03、AC-05、AC-18、AC-21 |
| 当前缺口 | **角色分配 API 要求 `binding_anchors`，`UserRoleDrawer` 未提供锚点输入**；PAYROLL_APPROVER/RECRUITER/ATTENDANCE_SPECIALIST 无法通过当前 UI 完整构造，标 `BLOCKED`（详见 gap-log）；V2.3 摘要 M19 写 6 入口、附录 A 写 7 页，冲突；15 卡明细见 `458-card-index.md`（源包待确认） |
| 证据 ID / 结论 | `MCP-M19-Pxxx-NNN`；**NOT RUN**。锚点 UI 缺口是角色验收前置阻塞 |

### M20 审批流程中心

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 148–149；卡片汇总 7；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/workflow`、`/workflow/cases/:id`；视图：我的待办/我发起的/我审批的/抄送我的/模板设置 |
| 当前动作（4） | `workflow.case.decide`、`workflow.case.withdraw`、`workflow.effect.retry`、`workflow.template.version.create` |
| 页面可见但未入 manifest | 详情页决策/撤回/效果重试为 source-only 控件，registry 详情 actions=[]；lifecycle 的 `POST /v1/cases/{id}/submit` 不属本页 manifest |
| 当前 API | `GET /v1/cases`、`GET /v1/cases/{id}`、timeline、tasks/my、templates、`POST /v1/cases/{caseId}/decisions`、`POST /v1/cases/{id}/withdraw`、`POST /v1/effects/{id}/retry` |
| 角色/前置 | R02/R05/R06/R07；待办/我发起/已处理/抄送样本；审批模板；失败 Effect；非审批人样本；工资审批案例 |
| 状态/边界 | 审批通过≠业务写入；Effect 失败可见可重试；重复决定/重试幂等；非 assignee 拒绝；决定后内容变化需重审；旧标签撤权 |
| T/AC | T02、T07、T11、T13、T18 / AC-13、AC-19、AC-21 |
| 当前缺口 | 详情页动作 registry 为空，但实际控件存在；T11/T13 未完成；7 卡明细见 `458-card-index.md`（源包待确认）；模板版本是否可新建/发布需 MCP 验证 |
| 证据 ID / 结论 | `MCP-M20-Pxxx-NNN`；**NOT RUN** |

### M21 公司基础设置与审计运维

| 维度 | 内容 |
|---|---|
| 历史入口 | 编号 152、153、155；卡片汇总 6；卡明细见 `458-card-index.md`（源包待确认） |
| 当前页面 | `/system/settings`：公司/日历/字典/参数/文件；`/system/audit`：审计查询/导出/健康 |
| 当前动作（6） | `system.settings.calendar.publish`、`system.settings.company-profile.save`、`system.settings.dictionary.update`、`system.settings.feature.update`、`system.settings.parameter.update`、`system.audit.export` |
| 页面可见但未入 manifest | 文件列表/链接、健康读取为读；备份/恢复页面不存在（`AuditHealthPanel` 显示 backend unavailable） |
| 当前 API | `GET/PATCH /v1/system/company-profile`、features GET/PUT、dictionaries GET/PATCH、parameters GET/PUT、`GET /v1/time/calendars`、`POST /v1/system/calendars/publish`、document-files、audit events、audit export、health/ready |
| 角色/前置 | R01/R09/R02；公司资料、工作日历版本、字典/参数、文件、审计事件、健康检查；生产演示数据/备份恢复样本由 T19 提供 |
| 状态/边界 | 新库唯一管理员/零业务 demo；生产拒绝 demo/reset；健康 reports migrations/installation/administrator；审计字段 allow-list；原始技术码仅管理员技术明细；备份/恢复不得用当前 UI 伪造 |
| T/AC | T01、T02、T16、T18、T19 / AC-02、AC-05、AC-16、AC-18、AC-19、AC-21、AC-22 |
| 当前缺口 | 6 卡汇总包含安装/升级/备份恢复，但当前 MCP 后台**没有备份/恢复/升级 UI**；这些能力只能由 T19 真实栈/运维证据覆盖，T18 标 `BLOCKED/UNMAPPED`。审计健康页明确 backup unavailable |
| 证据 ID / 结论 | `MCP-M21-Pxxx-NNN`；**NOT RUN**。备份/恢复/升级卡片不在本页 MCP 可执行范围 |

---

## 4A. 155 历史入口逐项台账（唯一、逐行）

> 本表严格按源包 `06_全域逐页面逐功能AI施工卡_合订版.md` 的 155 个 `Mxx-Pxxx` 页面与 458 个唯一 `Mxx-Pxxx-Fxx` 卡建立。**源存在但权威性待确认**；“当前页面”以 `registry.ts`/`PermissionResourceCatalog` 实际 37 页为准，并注明 KEEP/FOLD 到哪儿、旧 URL 当前行为。所有 155 行执行结论均为 `NOT RUN`；预期结果不是通过结果。功能卡完整标题见 `458-card-index.md`。

| 入口号 | 模块 | 页面ID | 功能卡ID/范围 | 当前页面（实际注册/旧入口） | 动作 | 角色 | 前置数据 | 上下游 | T/AC | 预期结果 | 证据槽 | 执行结论 | 备注 |
|---:|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | M01 | M01-P001 | M01-P001-F01–M01-P001-F05 | KEEP：/login（login） | 登录/退出（不在 action manifest；表单提交、回车、退出返回） | R01 SYSTEM_ADMIN / R02 HR_ADMIN / R07 EMPLOYEE / 未绑定管理员 | T18-EMP-A,T18-SESSION-01,T18-MFA-OFF,T18-UNLINKED-ADMIN | 上游 IAM 凭据/会话 + People employment link；下游全部受保护页面与菜单 | T01,T02,T17,T18 / AC-01,02,03,05,18,19,21 | 入口 1「登录」执行后出现/完成：账号密码登录表单；登录；回车提交；…共5卡。判据：账户错误/锁定统一中文提示，限速与 HttpOnly 会话校验。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M01/P001/ | NOT RUN | 登录限速默认 5/min；MFA 默认关闭 |
| 2 | M01 | M01-P002 | M01-P002-F01 | KEEP：/workspace（workspace） | 读取工作台（GET /v1/me/workspace、/v1/tasks/my） | R01 SYSTEM_ADMIN / R02 HR_ADMIN / R07 EMPLOYEE / 未绑定管理员 | T18-EMP-A,T18-SESSION-01,T18-MFA-OFF,T18-UNLINKED-ADMIN | 上游 IAM 凭据/会话 + People employment link；下游全部受保护页面与菜单 | T01,T02,T17,T18 / AC-01,02,03,05,18,19,21 | 入口 2「我的工作台」执行后出现/完成：“我的待办/我的申请/公告/快捷业务”四类卡片，均由本人权限和任职绑定决定。判据：无任务显示“暂无待办”，没有任职绑定只显示管理员任务。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M01/P002/ | NOT RUN | — |
| 3 | M01 | M01-P003 | M01-P003-F01–M01-P003-F03 | KEEP：/forbidden（forbidden） | 403 结果页导航（无写 action） | R01 SYSTEM_ADMIN / R02 HR_ADMIN / R07 EMPLOYEE / 未绑定管理员 | T18-EMP-A,T18-SESSION-01,T18-MFA-OFF,T18-UNLINKED-ADMIN | 上游 IAM 凭据/会话 + People employment link；下游全部受保护页面与菜单 | T01,T02,T17,T18 / AC-01,02,03,05,18,19,21 | 入口 3「无权访问」执行后出现/完成：无权访问说明；返回可访问首页；申请联系管理员提示。判据：403 不泄露是否存在敏感业务数据。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M01/P003/ | NOT RUN | — |
| 4 | M01 | M01-P004 | M01-P004-F01–M01-P004-F02 | KEEP：/not-found（not-found） | 404 结果页导航（无写 action） | R01 SYSTEM_ADMIN / R02 HR_ADMIN / R07 EMPLOYEE / 未绑定管理员 | T18-EMP-A,T18-SESSION-01,T18-MFA-OFF,T18-UNLINKED-ADMIN | 上游 IAM 凭据/会话 + People employment link；下游全部受保护页面与菜单 | T01,T02,T17,T18 / AC-01,02,03,05,18,19,21 | 入口 4「页面不存在」执行后出现/完成：页面不存在说明；返回可访问入口。判据：404 不自动跳转至无权业务。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M01/P004/ | NOT RUN | — |
| 5 | M01 | M01-P005 | M01-P005-F01 | FOLD：旧 /account/mfa → /account（account）；旧 URL 直达 catch-all /not-found | account.mfa.enroll / confirm / disable（MFA 可选；默认关闭时只验证不阻塞） | R01 SYSTEM_ADMIN / R02 HR_ADMIN / R07 EMPLOYEE / 未绑定管理员 | T18-EMP-A,T18-SESSION-01,T18-MFA-OFF,T18-UNLINKED-ADMIN | 上游 IAM 凭据/会话 + People employment link；下游全部受保护页面与菜单 | T01,T02,T17,T18 / AC-01,02,03,05,18,19,21 | 入口 5「账号安全/MFA」执行后出现/完成：可选账号安全项，若公司关闭 MFA 不在普通导航强调。判据：不能让关闭 MFA 的部署被旧中间件阻塞操作。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M01/P005/ | NOT RUN | MFA=false 时可选、不得阻塞；MFA=true 需 TOTP |
| 6 | M01 | M01-P006 | M01-P006-F01–M01-P006-F03 | FOLD：旧 /account/sessions → /account（account）；旧 URL 直达 catch-all /not-found | account.sessions.revoke / account.sessions.revoke.all | R01 SYSTEM_ADMIN / R02 HR_ADMIN / R07 EMPLOYEE / 未绑定管理员 | T18-EMP-A,T18-SESSION-01,T18-MFA-OFF,T18-UNLINKED-ADMIN | 上游 IAM 凭据/会话 + People employment link；下游全部受保护页面与菜单 | T01,T02,T17,T18 / AC-01,02,03,05,18,19,21 | 入口 6「会话管理」执行后出现/完成：显示活跃会话设备；最近操作；撤销指定会话和退出其他设备。判据：仅撤销本人会话，撤销立即失效。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M01/P006/ | NOT RUN | — |
| 7 | M02 | M02-P007 | M02-P007-F01 | KEEP：/dashboard（dashboard） | 读取 dashboard 与 metrics（筛选/下钻为 GET） | R01 / R02 / R06 LINE_MANAGER / R07 | T18-ORG-01,T18-EMP-A/B,T18-CON-A,T18-NOTICE-01,T18-RUN-01 | 上游 People/Org/Time/Talent/Workflow 事实；下游源业务详情与筛选 | T16,T17,T18 / AC-16,18,19,21 | 入口 7「首页看板」执行后出现/完成：公司/部门/本人可授权范围看板，支持日期/部门筛选和各业务下钻。判据：所有指标来自领域事实，零值/异常区分。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M02/P007/ | NOT RUN | — |
| 8 | M02 | M02-P008 | M02-P008-F01–M02-P008-F03 | FOLD：旧 /dashboard/departments → /dashboard（dashboard）；旧 URL 直达 catch-all /not-found | 读取 dashboard 的部门分区（GET；旧 URL 折叠） | R01 / R02 / R06 LINE_MANAGER / R07 | T18-ORG-01,T18-EMP-A/B,T18-CON-A,T18-NOTICE-01,T18-RUN-01 | 上游 People/Org/Time/Talent/Workflow 事实；下游源业务详情与筛选 | T16,T17,T18 / AC-16,18,19,21 | 入口 8「部门人数分析」执行后出现/完成：按部门人数条形图；员工人数；部门钻取与日期口径。判据：试用期人数、在职人数统一中文，来源有效任职。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M02/P008/ | NOT RUN | FOLD 到 /dashboard；旧 URL 兼容锚点需实测 |
| 9 | M02 | M02-P009 | M02-P009-F01 | FOLD：旧 /dashboard/workforce → /dashboard（dashboard）；旧 URL 直达 catch-all /not-found | 读取 dashboard 的构成分区（GET） | R01 / R02 / R06 LINE_MANAGER / R07 | T18-ORG-01,T18-EMP-A/B,T18-CON-A,T18-NOTICE-01,T18-RUN-01 | 上游 People/Org/Time/Talent/Workflow 事实；下游源业务详情与筛选 | T16,T17,T18 / AC-16,18,19,21 | 入口 9「员工构成」执行后出现/完成：性别/岗位/任职状态构成卡，组织日期筛选。判据：数据范围与员工档案同口径，未知枚举显示未知项。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M02/P009/ | NOT RUN | — |
| 10 | M02 | M02-P010 | M02-P010-F01 | FOLD：旧 /dashboard/changes → /dashboard（dashboard）；旧 URL 直达 catch-all /not-found | 读取 dashboard 的异动分区（GET） | R01 / R02 / R06 LINE_MANAGER / R07 | T18-ORG-01,T18-EMP-A/B,T18-CON-A,T18-NOTICE-01,T18-RUN-01 | 上游 People/Org/Time/Talent/Workflow 事实；下游源业务详情与筛选 | T16,T17,T18 / AC-16,18,19,21 | 入口 10「近期人事动态」执行后出现/完成：按生效日列入转调离动态，点击至异动/员工详情。判据：只展示已生效或明确标注待生效状态。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M02/P010/ | NOT RUN | — |
| 11 | M02 | M02-P011 | M02-P011-F01–M02-P011-F04 | FOLD：旧 /dashboard/tasks → /dashboard（dashboard）；旧 URL 直达 catch-all /not-found | 读取 dashboard 的任务分区（GET） | R01 / R02 / R06 LINE_MANAGER / R07 | T18-ORG-01,T18-EMP-A/B,T18-CON-A,T18-NOTICE-01,T18-RUN-01 | 上游 People/Org/Time/Talent/Workflow 事实；下游源业务详情与筛选 | T16,T17,T18 / AC-16,18,19,21 | 入口 11「工作提醒」执行后出现/完成：我的待审批；待处理异常；合同到期；…共4卡。判据：只展示当前用户有权办理的任务，点击定位源对象。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M02/P011/ | NOT RUN | — |
| 12 | M02 | M02-P012 | M02-P012-F01–M02-P012-F03 | FOLD：旧 /dashboard/recruitment → /dashboard（dashboard）；旧 URL 直达 catch-all /not-found | 读取 dashboard 的招聘分区（GET） | R01 / R02 / R06 LINE_MANAGER / R07 | T18-ORG-01,T18-EMP-A/B,T18-CON-A,T18-NOTICE-01,T18-RUN-01 | 上游 People/Org/Time/Talent/Workflow 事实；下游源业务详情与筛选 | T16,T17,T18 / AC-16,18,19,21 | 入口 12「招聘进展」执行后出现/完成：招聘需求；候选人转化；待入职数量与详情钻取。判据：指标取招聘实体而不是造模拟漏斗。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M02/P012/ | NOT RUN | — |
| 13 | M03 | M03-P013 | M03-P013-F01–M03-P013-F06 | KEEP：/reports（reports） | 报表目录、run/export/download（source-only；当前 manifest 0 action） | R02 / R04 PAYROLL_SPECIALIST / R09 REPORT_VIEWER/AUDITOR | T18-RPT-DEF-01/02,T18-RUN-01,T18-EMP-A | 上游各域只读事实 + IAM 字段/范围；下游导出文件、下载与审计 | T16,T17,T18 / AC-05,16,18,19,21 | 入口 13「报表目录」执行后出现/完成：选择报表类型；时间；部门；…共6卡。判据：查询失败不显示为空数据，工资报表字段动态授权。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M03/P013/ | NOT RUN | — |
| 14 | M03 | M03-P014 | M03-P014-F01–M03-P014-F02 | FOLD：旧 /reports/headcount → /reports（reports）；旧 URL 直达 catch-all /not-found | 人力资源报表查询/导出（source-only） | R02 / R04 PAYROLL_SPECIALIST / R09 REPORT_VIEWER/AUDITOR | T18-RPT-DEF-01/02,T18-RUN-01,T18-EMP-A | 上游各域只读事实 + IAM 字段/范围；下游导出文件、下载与审计 | T16,T17,T18 / AC-05,16,18,19,21 | 入口 14「人力资源报表」执行后出现/完成：在职/入职/离职；部门人数和岗位结构查询与导出。判据：人数口径与员工任职按日期相同。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M03/P014/ | NOT RUN | — |
| 15 | M03 | M03-P015 | M03-P015-F01–M03-P015-F03 | FOLD：旧 /reports/attendance → /reports（reports）；旧 URL 直达 catch-all /not-found | 考勤假期报表查询/导出（source-only） | R02 / R04 PAYROLL_SPECIALIST / R09 REPORT_VIEWER/AUDITOR | T18-RPT-DEF-01/02,T18-RUN-01,T18-EMP-A | 上游各域只读事实 + IAM 字段/范围；下游导出文件、下载与审计 | T16,T17,T18 / AC-05,16,18,19,21 | 入口 15「考勤假期报表」执行后出现/完成：出勤；请假；加班按月/部门/员工汇总及明细下钻。判据：引用已核对考勤/请假事实，拒绝重复算数。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M03/P015/ | NOT RUN | — |
| 16 | M03 | M03-P016 | M03-P016-F01 | FOLD：旧 /reports/payroll → /reports（reports）；旧 URL 直达 catch-all /not-found | 薪资成本报表查询/导出（source-only；字段鉴权） | R02 / R04 PAYROLL_SPECIALIST / R09 REPORT_VIEWER/AUDITOR | T18-RPT-DEF-01/02,T18-RUN-01,T18-EMP-A | 上游各域只读事实 + IAM 字段/范围；下游导出文件、下载与审计 | T16,T17,T18 / AC-05,16,18,19,21 | 入口 16「薪资成本报表」执行后出现/完成：工资批次和部门成本聚合，导出前审核敏感字段。判据：只能用已发布/已冻结合法批次，金额展示元。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M03/P016/ | NOT RUN | — |
| 17 | M03 | M03-P017 | M03-P017-F01–M03-P017-F03 | FOLD：旧 /reports/hiring → /reports（reports）；旧 URL 直达 catch-all /not-found | 招聘转化报表查询/导出（source-only） | R02 / R04 PAYROLL_SPECIALIST / R09 REPORT_VIEWER/AUDITOR | T18-RPT-DEF-01/02,T18-RUN-01,T18-EMP-A | 上游各域只读事实 + IAM 字段/范围；下游导出文件、下载与审计 | T16,T17,T18 / AC-05,16,18,19,21 | 入口 17「招聘转化报表」执行后出现/完成：需求；候选人阶段；Offer 接受与入职转化统计。判据：同一候选人多次应聘不能重复计人数。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M03/P017/ | NOT RUN | — |
| 18 | M03 | M03-P018 | M03-P018-F01 | FOLD：旧 /reports/talent → /reports（reports）；旧 URL 直达 catch-all /not-found | 绩效培训报表查询/导出（source-only） | R02 / R04 PAYROLL_SPECIALIST / R09 REPORT_VIEWER/AUDITOR | T18-RPT-DEF-01/02,T18-RUN-01,T18-EMP-A | 上游各域只读事实 + IAM 字段/范围；下游导出文件、下载与审计 | T16,T17,T18 / AC-05,16,18,19,21 | 入口 18「绩效培训报表」执行后出现/完成：绩效周期结果及培训场次完成情况聚合。判据：草稿绩效不入已发布统计，签到与报名相符。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M03/P018/ | NOT RUN | — |
| 19 | M03 | M03-P019 | M03-P019-F01–M03-P019-F03 | FOLD：旧 /reports/builder → /reports（reports）；旧 URL 直达 catch-all /not-found | 自定义报表定义保存/执行（source-only；无执行器则隐藏） | R02 / R04 PAYROLL_SPECIALIST / R09 REPORT_VIEWER/AUDITOR | T18-RPT-DEF-01/02,T18-RUN-01,T18-EMP-A | 上游各域只读事实 + IAM 字段/范围；下游导出文件、下载与审计 | T16,T17,T18 / AC-05,16,18,19,21 | 入口 19「自定义报表」执行后出现/完成：选择授权数据集/列/过滤；预览；保存报表定义。判据：没有可信执行器不得开放菜单和保存假报表。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M03/P019/ | NOT RUN | 无完整执行器时应隐藏，不得保存假报表 |
| 20 | M03 | M03-P020 | M03-P020-F01–M03-P020-F03 | FOLD：旧 /reports/subscriptions → /reports（reports）；旧 URL 直达 catch-all /not-found | 报表订阅新建/编辑/停用（source-only；未入 manifest） | R02 / R04 PAYROLL_SPECIALIST / R09 REPORT_VIEWER/AUDITOR | T18-RPT-DEF-01/02,T18-RUN-01,T18-EMP-A | 上游各域只读事实 + IAM 字段/范围；下游导出文件、下载与审计 | T16,T17,T18 / AC-05,16,18,19,21 | 入口 20「报表订阅」执行后出现/完成：订阅列表；新建/编辑/停用 Dialog；接收人与发送频率。判据：发送时重验数据范围和字段权限，失效订阅停止。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M03/P020/ | NOT RUN | 订阅动作未入 manifest（GAP-15） |
| 21 | M03 | M03-P021 | M03-P021-F01–M03-P021-F04 | FOLD：旧 /reports/exports → /reports（reports）；旧 URL 直达 catch-all /not-found | 导出任务下载/重试/过期（source-only；未入 manifest） | R02 / R04 PAYROLL_SPECIALIST / R09 REPORT_VIEWER/AUDITOR | T18-RPT-DEF-01/02,T18-RUN-01,T18-EMP-A | 上游各域只读事实 + IAM 字段/范围；下游导出文件、下载与审计 | T16,T17,T18 / AC-05,16,18,19,21 | 入口 21「导出任务」执行后出现/完成：导出任务列表；任务状态；下载；…共4卡。判据：下载链接在下载时再校验权限和文件归属。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M03/P021/ | NOT RUN | 导出下载再次鉴权；未入 manifest |
| 22 | M04 | M04-P022 | M04-P022-F01–M04-P022-F04 | KEEP：/hr/employees（hr-employees） | hr.employees.create.employment、hr.employees.contract.create；列表导出/导入 source-only | R02 / HR_SPECIALIST / R06 / R07 | T18-EMP-A/B/C,T18-CON-A,T18-COMP-A,T18-IMPORT-01 | 上游 IAM/Org；下游 Lifecycle/Contracts/Payroll/Time/Talent/Reports | T03,T04,T05,T17,T18 / AC-04,05,07,18,20,21 | 入口 22「员工列表」执行后出现/完成：员工列表筛选/排序/分页/导出；查看；Drawer 新增/编辑；…共4卡。判据：工号唯一；原子建档；多部门数据范围；禁直接硬删除。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M04/P022/ | NOT RUN | — |
| 23 | M04 | M04-P023 | M04-P023-F01 | KEEP：/hr/employees/:id（employee-detail） | 读取 employee-detail；employee.detail.assign/archive/restore | R02 / HR_SPECIALIST / R06 / R07 | T18-EMP-A/B/C,T18-CON-A,T18-COMP-A,T18-IMPORT-01 | 上游 IAM/Org；下游 Lifecycle/Contracts/Payroll/Time/Talent/Reports | T03,T04,T05,T17,T18 / AC-04,05,07,18,20,21 | 入口 23「员工详情」执行后出现/完成：员工详情：基本信息/任职/合同摘要/履历/附件/异动历史 Tab。判据：可深链接；只有同一员工对象内容；敏感字段按授权裁剪。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M04/P023/ | NOT RUN | — |
| 24 | M04 | M04-P024 | M04-P024-F01–M04-P024-F03 | FOLD：旧 /hr/employees/:id/education → /hr/employees/:id（employee-detail）；旧 URL 直达 catch-all /not-found | 教育经历新增/编辑（source-only）；归档 employee.profile.education.retire | R02 / HR_SPECIALIST / R06 / R07 | T18-EMP-A/B/C,T18-CON-A,T18-COMP-A,T18-IMPORT-01 | 上游 IAM/Org；下游 Lifecycle/Contracts/Payroll/Time/Talent/Reports | T03,T04,T05,T17,T18 / AC-04,05,07,18,20,21 | 入口 24「教育经历」执行后出现/完成：教育经历子表查看；新增/编辑 Dialog；历史版本。判据：学校/学历/时间合法，归属当前员工。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M04/P024/ | NOT RUN | 教育经历写动作 source-only |
| 25 | M04 | M04-P025 | M04-P025-F01 | FOLD：旧 /hr/employees/:id/work-history → /hr/employees/:id（employee-detail）；旧 URL 直达 catch-all /not-found | 工作经历新增/编辑（source-only） | R02 / HR_SPECIALIST / R06 / R07 | T18-EMP-A/B/C,T18-CON-A,T18-COMP-A,T18-IMPORT-01 | 上游 IAM/Org；下游 Lifecycle/Contracts/Payroll/Time/Talent/Reports | T03,T04,T05,T17,T18 / AC-04,05,07,18,20,21 | 入口 25「工作经历」执行后出现/完成：工作经历子表新增/修改/历史查询。判据：起止时间正确，涉及工作经历重叠提醒。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M04/P025/ | NOT RUN | 工作经历写动作 source-only |
| 26 | M04 | M04-P026 | M04-P026-F01–M04-P026-F03 | FOLD：旧 /hr/employees/:id/contacts → /hr/employees/:id（employee-detail）；旧 URL 直达 catch-all /not-found | 联系人新增/编辑（source-only）；归档 employee.profile.contacts.retire | R02 / HR_SPECIALIST / R06 / R07 | T18-EMP-A/B/C,T18-CON-A,T18-COMP-A,T18-IMPORT-01 | 上游 IAM/Org；下游 Lifecycle/Contracts/Payroll/Time/Talent/Reports | T03,T04,T05,T17,T18 / AC-04,05,07,18,20,21 | 入口 26「员工联系人」执行后出现/完成：紧急联系人与家庭联系人列表；编辑；隐藏敏感联系方式。判据：联系人归属当前员工，电话字段按权限脱敏。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M04/P026/ | NOT RUN | 联系人写动作 source-only；retire 已入 manifest |
| 27 | M04 | M04-P027 | M04-P027-F01–M04-P027-F04 | FOLD：旧 /hr/employees/:id/credentials → /hr/employees/:id（employee-detail）；旧 URL 直达 catch-all /not-found | 证照新增/编辑/附件（source-only）；归档 employee.profile.certificates.retire | R02 / HR_SPECIALIST / R06 / R07 | T18-EMP-A/B/C,T18-CON-A,T18-COMP-A,T18-IMPORT-01 | 上游 IAM/Org；下游 Lifecycle/Contracts/Payroll/Time/Talent/Reports | T03,T04,T05,T17,T18 / AC-04,05,07,18,20,21 | 入口 27「证照资质」执行后出现/完成：证照资质类型；编号；有效期；…共4卡。判据：证照附件权限与员工数据权限一致。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M04/P027/ | NOT RUN | 证照写动作 source-only；retire 已入 manifest |
| 28 | M04 | M04-P028 | M04-P028-F01–M04-P028-F03 | FOLD：旧 /hr/employees/:id/history → /hr/employees/:id（employee-detail）；旧 URL 直达 catch-all /not-found | 读取变更历史（GET /v1/employments/{id}/timeline） | R02 / HR_SPECIALIST / R06 / R07 | T18-EMP-A/B/C,T18-CON-A,T18-COMP-A,T18-IMPORT-01 | 上游 IAM/Org；下游 Lifecycle/Contracts/Payroll/Time/Talent/Reports | T03,T04,T05,T17,T18 / AC-04,05,07,18,20,21 | 入口 28「员工档案历史」执行后出现/完成：员工字段修改前后历史；操作人；业务生效时间查询。判据：历史不可直接修改或删除。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M04/P028/ | NOT RUN | — |
| 29 | M04 | M04-P029 | M04-P029-F01 | FOLD：旧 /hr/employees/:id/files → /hr/employees/:id（employee-detail）；旧 URL 直达 catch-all /not-found | 附件上传/预览/下载（source-only） | R02 / HR_SPECIALIST / R06 / R07 | T18-EMP-A/B/C,T18-CON-A,T18-COMP-A,T18-IMPORT-01 | 上游 IAM/Org；下游 Lifecycle/Contracts/Payroll/Time/Talent/Reports | T03,T04,T05,T17,T18 / AC-04,05,07,18,20,21 | 入口 29「员工附件」执行后出现/完成：附件列表/上传/预览/下载/归档。判据：文件大小与类型校验，下载凭权限签发。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M04/P029/ | NOT RUN | 附件上传 source-only |
| 30 | M04 | M04-P030 | M04-P030-F01–M04-P030-F04 | FOLD：旧 /hr/employees/:id/changes → /hr/employees/:id（employee-detail）；旧 URL 直达 catch-all /not-found | employee.change.submit；撤回/审核进度 source-only | R02 / HR_SPECIALIST / R06 / R07 | T18-EMP-A/B/C,T18-CON-A,T18-COMP-A,T18-IMPORT-01 | 上游 IAM/Org；下游 Lifecycle/Contracts/Payroll/Time/Talent/Reports | T03,T04,T05,T17,T18 / AC-04,05,07,18,20,21 | 入口 30「员工资料变更」执行后出现/完成：员工资料变更申请列表；提交；撤回；…共4卡。判据：仅变更申请审批通过后影响当前档案字段。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M04/P030/ | NOT RUN | 提交已入 manifest；撤回/进度 source-only |
| 31 | M04 | M04-P031 | M04-P031-F01–M04-P031-F05 | FOLD：旧 /hr/employees/imports → /hr/employees（hr-employees）；旧 URL 直达 catch-all /not-found | 批量导入/校验/确认（source-only；GET /v1/employee-imports） | R02 / HR_SPECIALIST / R06 / R07 | T18-EMP-A/B/C,T18-CON-A,T18-COMP-A,T18-IMPORT-01 | 上游 IAM/Org；下游 Lifecycle/Contracts/Payroll/Time/Talent/Reports | T03,T04,T05,T17,T18 / AC-04,05,07,18,20,21 | 入口 31「导入校验结果」执行后出现/完成：导入批次列表；文件上传；逐行校验结果；…共5卡。判据：不允许部分数据无提示成功，重复工号准确提示。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M04/P031/ | NOT RUN | 导入/导出未入 manifest（GAP-15） |
| 32 | M05 | M05-P032 | M05-P032-F01–M05-P032-F03 | KEEP：/hr/departments（hr-departments） | org.departments.create；读取组织树/部门详情 | R02 / R06 / R01 | T18-ORG-01,T18-EMP-A/B | 上游公司/组织树；下游 People 任职、IAM 范围、Reporting、Recruitment | T02,T17,T18 / AC-03,04,05,16,18,20,21 | 入口 32「组织架构树」执行后出现/完成：左侧组织树；右侧详情/成员/编制/负责人；添加下级。判据：禁止树成环，部门编码唯一。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M05/P032/ | NOT RUN | — |
| 33 | M05 | M05-P033 | M05-P033-F01 | FOLD：旧 /hr/departments/list → /hr/departments（hr-departments）；旧 URL 直达 catch-all /not-found | 读取部门列表视图（无写 action） | R02 / R06 / R01 | T18-ORG-01,T18-EMP-A/B | 上游公司/组织树；下游 People 任职、IAM 范围、Reporting、Recruitment | T02,T17,T18 / AC-03,04,05,16,18,20,21 | 入口 33「部门列表」执行后出现/完成：表格视图切换：部门编码/名称/上级/负责人/状态，行查看。判据：只读与树同一份部门事实，不独立新增第二列表。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M05/P033/ | NOT RUN | — |
| 34 | M05 | M05-P034 | M05-P034-F01 | FOLD：旧 /hr/departments/members → /hr/departments（hr-departments）；旧 URL 直达 catch-all /not-found | org.departments.remove.member；添加成员 source-only POST /v1/departments/{id}/members | R02 / R06 / R01 | T18-ORG-01,T18-EMP-A/B | 上游公司/组织树；下游 People 任职、IAM 范围、Reporting、Recruitment | T02,T17,T18 / AC-03,04,05,16,18,20,21 | 入口 34「部门成员」执行后出现/完成：在选中部门下显示成员，支持任职日期过滤与跳转员工详情。判据：成员数据来自 assignment，不在这里另建人岗。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M05/P034/ | NOT RUN | 成员添加未入 manifest；移除已入 manifest |
| 35 | M05 | M05-P035 | M05-P035-F01–M05-P035-F03 | FOLD：旧 /hr/departments/move → /hr/departments（hr-departments）；旧 URL 直达 catch-all /not-found | org.departments.move + 读取 impact | R02 / R06 / R01 | T18-ORG-01,T18-EMP-A/B | 上游公司/组织树；下游 People 任职、IAM 范围、Reporting、Recruitment | T02,T17,T18 / AC-03,04,05,16,18,20,21 | 入口 35「部门迁移」执行后出现/完成：部门移动向导：选目标上级；预览子树/任职/授权影响；确认。判据：无循环/未完成审批/组织权限；操作幂等留痕。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M05/P035/ | NOT RUN | — |
| 36 | M05 | M05-P036 | M05-P036-F01–M05-P036-F03 | FOLD：旧 /hr/departments/staffing → /hr/departments（hr-departments）；旧 URL 直达 catch-all /not-found | 读取 staffing；编制调整 source-only PATCH /v1/departments/{id} | R02 / R06 / R01 | T18-ORG-01,T18-EMP-A/B | 上游公司/组织树；下游 People 任职、IAM 范围、Reporting、Recruitment | T02,T17,T18 / AC-03,04,05,16,18,20,21 | 入口 36「部门编制」执行后出现/完成：编制数；在岗数；缺编数及历史版本，允许具权限人员维护编制。判据：编制人数不是员工数手写副本。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M05/P036/ | NOT RUN | 编制 PATCH 未入 manifest |
| 37 | M06 | M06-P037 | M06-P037-F01–M06-P037-F06 | KEEP：/hr/positions（hr-positions） | org.positions.create / org.positions.edit；读取详情/在岗 | R02 / HR_SPECIALIST / R06 | T18-ORG-01,T18-POS-01,T18-EMP-A | 上游 Org 部门/编制；下游 People 任职、Payroll、Recruitment、Performance | T03,T04,T17,T18 / AC-04,06,09,14,18,21 | 入口 37「岗位目录」执行后出现/完成：岗位搜索表格；详情；在岗人员；…共6卡。判据：岗位编码唯一，部门和岗位可关联。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M06/P037/ | NOT RUN | — |
| 38 | M06 | M06-P038 | M06-P038-F01–M06-P038-F03 | FOLD：旧 /hr/positions/headcount → /hr/positions（hr-positions）；旧 URL 直达 catch-all /not-found | 读取岗位编制；编制调整 source-only PATCH /v1/positions/{id} | R02 / HR_SPECIALIST / R06 | T18-ORG-01,T18-POS-01,T18-EMP-A | 上游 Org 部门/编制；下游 People 任职、Payroll、Recruitment、Performance | T03,T04,T17,T18 / AC-04,06,09,14,18,21 | 入口 38「岗位编制」执行后出现/完成：已核岗位编制；在岗与空缺对比；按部门过滤。判据：来源岗位编制事实+有效任职，不造人工人数。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M06/P038/ | NOT RUN | 编制调整未入 manifest |
| 39 | M06 | M06-P039 | M06-P039-F01 | FOLD：旧 /hr/positions/requirements → /hr/positions（hr-positions）；旧 URL 直达 catch-all /not-found | org.positions.requirements.version | R02 / HR_SPECIALIST / R06 | T18-ORG-01,T18-POS-01,T18-EMP-A | 上游 Org 部门/编制；下游 People 任职、Payroll、Recruitment、Performance | T03,T04,T17,T18 / AC-04,06,09,14,18,21 | 入口 39「岗位任职资格」执行后出现/完成：任职资格版本列表与新增 Drawer，学历/年限等校验。判据：资格历史保留，要求生效日不得覆盖历史。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M06/P039/ | NOT RUN | — |
| 40 | M06 | M06-P040 | M06-P040-F01–M06-P040-F03 | FOLD：旧 /hr/positions/grades → /hr/positions（hr-positions）；旧 URL 直达 catch-all /not-found | org.positions.grade.upsert | R02 / HR_SPECIALIST / R06 | T18-ORG-01,T18-POS-01,T18-EMP-A | 上游 Org 部门/编制；下游 People 任职、Payroll、Recruitment、Performance | T03,T04,T17,T18 / AC-04,06,09,14,18,21 | 入口 40「职级/职等」执行后出现/完成：职级/职等设置表，新增/编辑 Dialog；启停；排序。判据：在用职级不能物理删除，关联岗位要求生效。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M06/P040/ | NOT RUN | 职级 upsert 已入 manifest |
| 41 | M06 | M06-P041 | M06-P041-F01 | FOLD：旧 /hr/positions/staff → /hr/positions（hr-positions）；旧 URL 直达 catch-all /not-found | 读取岗位在岗人员（GET） | R02 / HR_SPECIALIST / R06 | T18-ORG-01,T18-POS-01,T18-EMP-A | 上游 Org 部门/编制；下游 People 任职、Payroll、Recruitment、Performance | T03,T04,T17,T18 / AC-04,06,09,14,18,21 | 入口 41「岗位任职人员」执行后出现/完成：在岗员工列表及员工详情跳转，支持任职日期筛选。判据：岗位占用来自 assignment；不可在此无异动单据调岗。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M06/P041/ | NOT RUN | — |
| 42 | M07 | M07-P042 | M07-P042-F01–M07-P042-F03 | KEEP：/hr/movements（hr-movements） | lifecycle.onboarding/probation/transfer/salary-change/secondment/offboarding/create；checklist.update | R02 / R06 / R04 / R05 PAYROLL_APPROVER / R07 | T18-EMP-A/B/C,T18-CON-A,T18-COMP-A,T18-WF-TPL-01 | 上游 People/Org/Contracts/Payroll/Workflow；下游任职、合同、薪酬、Time、Reports | T03,T04,T05,T17,T18 / AC-04,09,13,15,20,21 | 入口 42「异动列表」执行后出现/完成：异动台账按人/种类/状态/生效日筛选，查看；发起；撤回。判据：审批中与生效后的可用操作不同。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M07/P042/ | NOT RUN | — |
| 43 | M07 | M07-P043 | M07-P043-F01–M07-P043-F04 | FOLD：旧 /hr/movements/onboarding → /hr/movements（hr-movements）；旧 URL 直达 catch-all /not-found | lifecycle.onboarding.create + lifecycle.checklist.update | R02 / R06 / R04 / R05 PAYROLL_APPROVER / R07 | T18-EMP-A/B/C,T18-CON-A,T18-COMP-A,T18-WF-TPL-01 | 上游 People/Org/Contracts/Payroll/Workflow；下游任职、合同、薪酬、Time、Reports | T03,T04,T05,T17,T18 / AC-04,09,13,15,20,21 | 入口 43「入职办理」执行后出现/完成：员工入职任务清单；资料；任职绑定；…共4卡。判据：若从候选人转入职须幂等；信息不完整不能办结。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M07/P043/ | NOT RUN | 入职新链路依赖 T05 |
| 44 | M07 | M07-P044 | M07-P044-F01–M07-P044-F03 | FOLD：旧 /hr/movements/probation → /hr/movements（hr-movements）；旧 URL 直达 catch-all /not-found | lifecycle.probation.create | R02 / R06 / R04 / R05 PAYROLL_APPROVER / R07 | T18-EMP-A/B/C,T18-CON-A,T18-COMP-A,T18-WF-TPL-01 | 上游 People/Org/Contracts/Payroll/Workflow；下游任职、合同、薪酬、Time、Reports | T03,T04,T05,T17,T18 / AC-04,09,13,15,20,21 | 入口 44「转正管理」执行后出现/完成：转正申请；试用期结束日期；生效日及批准记录。判据：转正日期不得早于试用开始，保留原任职历史。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M07/P044/ | NOT RUN | — |
| 45 | M07 | M07-P045 | M07-P045-F01–M07-P045-F02 | FOLD：旧 /hr/movements/transfer → /hr/movements（hr-movements）；旧 URL 直达 catch-all /not-found | lifecycle.transfer.create | R02 / R06 / R04 / R05 PAYROLL_APPROVER / R07 | T18-EMP-A/B/C,T18-CON-A,T18-COMP-A,T18-WF-TPL-01 | 上游 People/Org/Contracts/Payroll/Workflow；下游任职、合同、薪酬、Time、Reports | T03,T04,T05,T17,T18 / AC-04,09,13,15,20,21 | 入口 45「调岗/调部门」执行后出现/完成：调岗/调部门申请，前后部门岗位与生效日；影响预览。判据：未经批准不更新 assignment；组织范围校验。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M07/P045/ | NOT RUN | — |
| 46 | M07 | M07-P046 | M07-P046-F01–M07-P046-F04 | FOLD：旧 /hr/movements/salary-change → /hr/movements（hr-movements）；旧 URL 直达 catch-all /not-found | lifecycle.salary-change.create | R02 / R06 / R04 / R05 PAYROLL_APPROVER / R07 | T18-EMP-A/B/C,T18-CON-A,T18-COMP-A,T18-WF-TPL-01 | 上游 People/Org/Contracts/Payroll/Workflow；下游任职、合同、薪酬、Time、Reports | T03,T04,T05,T17,T18 / AC-04,09,13,15,20,21 | 入口 46「调薪管理」执行后出现/完成：调薪申请；原方案/新方案；差额；…共4卡。判据：薪资专员权限，不能覆盖已冻结工资数据。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M07/P046/ | NOT RUN | — |
| 47 | M07 | M07-P047 | M07-P047-F01–M07-P047-F05 | FOLD：旧 /hr/movements/secondment → /hr/movements（hr-movements）；旧 URL 直达 catch-all /not-found | lifecycle.secondment.create | R02 / R06 / R04 / R05 PAYROLL_APPROVER / R07 | T18-EMP-A/B/C,T18-CON-A,T18-COMP-A,T18-WF-TPL-01 | 上游 People/Org/Contracts/Payroll/Workflow；下游任职、合同、薪酬、Time、Reports | T03,T04,T05,T17,T18 / AC-04,09,13,15,20,21 | 入口 47「借调/兼岗」执行后出现/完成：借调/兼岗申请；来源部门；目标部门；…共5卡。判据：避免同时互斥任职冲突，历史可追溯。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M07/P047/ | NOT RUN | — |
| 48 | M07 | M07-P048 | M07-P048-F01–M07-P048-F05 | FOLD：旧 /hr/movements/offboarding → /hr/movements（hr-movements）；旧 URL 直达 catch-all /not-found | lifecycle.offboarding.create | R02 / R06 / R04 / R05 PAYROLL_APPROVER / R07 | T18-EMP-A/B/C,T18-CON-A,T18-COMP-A,T18-WF-TPL-01 | 上游 People/Org/Contracts/Payroll/Workflow；下游任职、合同、薪酬、Time、Reports | T03,T04,T05,T17,T18 / AC-04,09,13,15,20,21 | 入口 48「离职办理」执行后出现/完成：离职清单；离职原因/日期；待办移交；…共5卡。判据：已发布工资/合同等保留历史，账号权限有策略调整。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M07/P048/ | NOT RUN | — |
| 49 | M07 | M07-P049 | M07-P049-F01–M07-P049-F03 | KEEP：/hr/movements/:id（hr-movements-by-id） | 读取异动详情；submit/decisions/withdraw source-only（详情 actions=[]） | R02 / R06 / R04 / R05 PAYROLL_APPROVER / R07 | T18-EMP-A/B/C,T18-CON-A,T18-COMP-A,T18-WF-TPL-01 | 上游 People/Org/Contracts/Payroll/Workflow；下游任职、合同、薪酬、Time、Reports | T03,T04,T05,T17,T18 / AC-04,09,13,15,20,21 | 入口 49「异动详情」执行后出现/完成：异动详情；审批轨迹；前后快照与生效结果。判据：操作重复不产生二次效果，状态不可逆改写。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M07/P049/ | NOT RUN | 详情 actions=[]（GAP-15） |
| 50 | M07 | M07-P050 | M07-P050-F01 | FOLD：旧 /hr/movements/calendar → /hr/movements（hr-movements）；旧 URL 直达 catch-all /not-found | 读取异动日历（GET） | R02 / R06 / R04 / R05 PAYROLL_APPROVER / R07 | T18-EMP-A/B/C,T18-CON-A,T18-COMP-A,T18-WF-TPL-01 | 上游 People/Org/Contracts/Payroll/Workflow；下游任职、合同、薪酬、Time、Reports | T03,T04,T05,T17,T18 / AC-04,09,13,15,20,21 | 入口 50「异动日历」执行后出现/完成：按月份日历查看生效的入转调离及待生效事件。判据：区分生效与计划，不把待审当已异动。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M07/P050/ | NOT RUN | — |
| 51 | M08 | M08-P051 | M08-P051-F01–M08-P051-F03 | KEEP：/hr/contracts（hr-contracts） | people.contracts.create；续签/到期筛选/台账读取 | R02 / HR_SPECIALIST / R04 / R07 | T18-EMP-A/B,T18-CON-A/B,T18-COMP-A,T18-CON-TPL-01 | 上游 People/Org/Workflow；下游 Payroll、Lifecycle、Reports | T03,T04,T17,T18 / AC-04,09,13,20,21 | 入口 51「合同台账」执行后出现/完成：合同台账查询；新建/编辑 Drawer；合同详情/续签/终止操作。判据：合同编号与任职绑定，不能误绑同名员工。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M08/P051/ | NOT RUN | — |
| 52 | M08 | M08-P052 | M08-P052-F01–M08-P052-F04 | KEEP：/hr/contracts/:id（hr-contracts-by-id） | people.contract.detail.amend / terminate；manifest activate 但页面无按钮（GAP-09） | R02 / HR_SPECIALIST / R04 / R07 | T18-EMP-A/B,T18-CON-A/B,T18-COMP-A,T18-CON-TPL-01 | 上游 People/Org/Workflow；下游 Payroll、Lifecycle、Reports | T03,T04,T17,T18 / AC-04,09,13,20,21 | 入口 52「合同详情」执行后出现/完成：合同详情：原文/附件；有效期；当前与历史版本；…共4卡。判据：深链接与附件必须单独受权。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M08/P052/ | NOT RUN | GAP-09 activate UI 缺失；不得以 API 代 UI |
| 53 | M08 | M08-P053 | M08-P053-F01–M08-P053-F04 | FOLD：旧 /hr/contracts/renewals → /hr/contracts（hr-contracts）；旧 URL 直达 catch-all /not-found | 续签抽屉创建新合同（POST /v1/employments/{employmentId}/contracts；source-only） | R02 / HR_SPECIALIST / R04 / R07 | T18-EMP-A/B,T18-CON-A/B,T18-COMP-A,T18-CON-TPL-01 | 上游 People/Org/Workflow；下游 Payroll、Lifecycle、Reports | T03,T04,T17,T18 / AC-04,09,13,20,21 | 入口 53「合同续签」执行后出现/完成：续签向导：原合同；续签日期；附件；…共4卡。判据：新旧有效期不异常重叠，原版本不覆盖。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M08/P053/ | NOT RUN | 续签为 source-only 台账抽屉 |
| 54 | M08 | M08-P054 | M08-P054-F01–M08-P054-F02 | FOLD：旧 /hr/contracts/changes → /hr/contracts（hr-contracts）；旧 URL 直达 catch-all /not-found | people.contract.detail.amend / terminate | R02 / HR_SPECIALIST / R04 / R07 | T18-EMP-A/B,T18-CON-A/B,T18-COMP-A,T18-CON-TPL-01 | 上游 People/Org/Workflow；下游 Payroll、Lifecycle、Reports | T03,T04,T17,T18 / AC-04,09,13,20,21 | 入口 54「合同变更/终止」执行后出现/完成：合同变更/终止动作，变更字段与原因；终止时间。判据：审批后更新新版本，历史可追溯。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M08/P054/ | NOT RUN | — |
| 55 | M08 | M08-P055 | M08-P055-F01–M08-P055-F02 | FOLD：旧 /hr/contracts/expiry → /hr/contracts（hr-contracts）；旧 URL 直达 catch-all /not-found | 读取到期预警（GET expiring） | R02 / HR_SPECIALIST / R04 / R07 | T18-EMP-A/B,T18-CON-A/B,T18-COMP-A,T18-CON-TPL-01 | 上游 People/Org/Workflow；下游 Payroll、Lifecycle、Reports | T03,T04,T17,T18 / AC-04,09,13,20,21 | 入口 55「到期预警」执行后出现/完成：60/30/7 天到期提醒，筛选；查看及续签跳转。判据：日期按公司时区，过期合同不可统计为未过期。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M08/P055/ | NOT RUN | — |
| 56 | M08 | M08-P056 | M08-P056-F01–M08-P056-F04 | FOLD：旧 /hr/contracts/templates → /hr/contracts（hr-contracts）；旧 URL 直达 catch-all /not-found | 合同模板创建/发布（source-only；未入 manifest） | R02 / HR_SPECIALIST / R04 / R07 | T18-EMP-A/B,T18-CON-A/B,T18-COMP-A,T18-CON-TPL-01 | 上游 People/Org/Workflow；下游 Payroll、Lifecycle、Reports | T03,T04,T17,T18 / AC-04,09,13,20,21 | 入口 56「合同模板」执行后出现/完成：合同模板列表；创建；预览；…共4卡。判据：草稿模板不可误用于正式合同，历史保留。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M08/P056/ | NOT RUN | 模板写动作未入 manifest |
| 57 | M09 | M09-P057 | M09-P057-F01–M09-P057-F03 | KEEP：/attendance（attendance） | 读取本人考勤；本人打卡写入 UNMAPPED（GAP-08） | R03 ATTENDANCE_SPECIALIST / R06 / R07 | T18-EMP-A/B,T18-TIME-2026-11,T18-SHIFT-01,T18-RULE-01,T18-SCHED-01,T18-CLOSE-01 | 上游 Org/People/规则/日历；下游 Payroll/Reporting/Leave/Overtime/Travel | T06,T07,T08,T17,T18 / AC-08,10,18,19,21 | 入口 57「我的打卡」执行后出现/完成：员工本人今日打卡；班次；月度个人日历与异常申诉入口。判据：服务端按本人 employment 固定 scope。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M09/P057/ | NOT RUN | GAP-08：无本人打卡写 API/入口 |
| 58 | M09 | M09-P058 | M09-P058-F01–M09-P058-F06 | FOLD：旧 /attendance/punches → /attendance（attendance）；旧 URL 直达 catch-all /not-found | 读取打卡明细；明细导出 source-only | R03 ATTENDANCE_SPECIALIST / R06 / R07 | T18-EMP-A/B,T18-TIME-2026-11,T18-SHIFT-01,T18-RULE-01,T18-SCHED-01,T18-CLOSE-01 | 上游 Org/People/规则/日历；下游 Payroll/Reporting/Leave/Overtime/Travel | T06,T07,T08,T17,T18 / AC-08,10,18,19,21 | 入口 58「打卡明细」执行后出现/完成：考勤明细：员工；日期；打卡时间；…共6卡。判据：同一打卡事件可追溯且不重复计算。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M09/P058/ | NOT RUN | 原始打卡导出未入 manifest |
| 59 | M09 | M09-P059 | M09-P059-F01–M09-P059-F04 | FOLD：旧 /attendance/calendar → /attendance（attendance）；旧 URL 直达 catch-all /not-found | 读取考勤日历（GET） | R03 ATTENDANCE_SPECIALIST / R06 / R07 | T18-EMP-A/B,T18-TIME-2026-11,T18-SHIFT-01,T18-RULE-01,T18-SCHED-01,T18-CLOSE-01 | 上游 Org/People/规则/日历；下游 Payroll/Reporting/Leave/Overtime/Travel | T06,T07,T08,T17,T18 / AC-08,10,18,19,21 | 入口 59「考勤日历」执行后出现/完成：日历按员工/部门/月显示班次；打卡；请假；…共4卡。判据：数据来自不同域只读投影，标识优先级清楚。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M09/P059/ | NOT RUN | — |
| 60 | M09 | M09-P060 | M09-P060-F01–M09-P060-F04 | FOLD：旧 /attendance/rules → /attendance（attendance）；旧 URL 直达 catch-all /not-found | attendance.rule.publish | R03 ATTENDANCE_SPECIALIST / R06 / R07 | T18-EMP-A/B,T18-TIME-2026-11,T18-SHIFT-01,T18-RULE-01,T18-SCHED-01,T18-CLOSE-01 | 上游 Org/People/规则/日历；下游 Payroll/Reporting/Leave/Overtime/Travel | T06,T07,T08,T17,T18 / AC-08,10,18,19,21 | 入口 60「考勤规则」执行后出现/完成：考勤规则列表；规则详情；版本发布；…共4卡。判据：规则生效日前不重算既往考勤，发布权限控制。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M09/P060/ | NOT RUN | — |
| 61 | M09 | M09-P061 | M09-P061-F01–M09-P061-F04 | FOLD：旧 /attendance/shifts → /attendance（attendance）；旧 URL 直达 catch-all /not-found | attendance.shift.create / shift.version / shift.disable | R03 ATTENDANCE_SPECIALIST / R06 / R07 | T18-EMP-A/B,T18-TIME-2026-11,T18-SHIFT-01,T18-RULE-01,T18-SCHED-01,T18-CLOSE-01 | 上游 Org/People/规则/日历；下游 Payroll/Reporting/Leave/Overtime/Travel | T06,T07,T08,T17,T18 / AC-08,10,18,19,21 | 入口 61「班次管理」执行后出现/完成：班次列表；增改 Dialog；上下班与容差；…共4卡。判据：班次生效与已排班冲突提示。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M09/P061/ | NOT RUN | — |
| 62 | M09 | M09-P062 | M09-P062-F01–M09-P062-F04 | FOLD：旧 /attendance/schedules → /attendance（attendance）；旧 URL 直达 catch-all /not-found | attendance.schedule.create | R03 ATTENDANCE_SPECIALIST / R06 / R07 | T18-EMP-A/B,T18-TIME-2026-11,T18-SHIFT-01,T18-RULE-01,T18-SCHED-01,T18-CLOSE-01 | 上游 Org/People/规则/日历；下游 Payroll/Reporting/Leave/Overtime/Travel | T06,T07,T08,T17,T18 / AC-08,10,18,19,21 | 入口 62「排班管理」执行后出现/完成：排班日历；员工/部门选取；批量排班；…共4卡。判据：员工有效任职，重复时间冲突拒绝。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M09/P062/ | NOT RUN | — |
| 63 | M09 | M09-P063 | M09-P063-F01–M09-P063-F03 | FOLD：旧 /attendance/corrections → /attendance（attendance）；旧 URL 直达 catch-all /not-found | attendance.correction.request | R03 ATTENDANCE_SPECIALIST / R06 / R07 | T18-EMP-A/B,T18-TIME-2026-11,T18-SHIFT-01,T18-RULE-01,T18-SCHED-01,T18-CLOSE-01 | 上游 Org/People/规则/日历；下游 Payroll/Reporting/Leave/Overtime/Travel | T06,T07,T08,T17,T18 / AC-08,10,18,19,21 | 入口 63「补卡申请」执行后出现/完成：补卡申请列表；本人与管理员视角；详情及审批结果。判据：重复补卡/已结月份需受控重开。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M09/P063/ | NOT RUN | 补卡需 case/审批；不能伪造本人打卡 |
| 64 | M09 | M09-P064 | M09-P064-F01–M09-P064-F03 | FOLD：旧 /attendance/exceptions → /attendance（attendance）；旧 URL 直达 catch-all /not-found | attendance.correction.request / attendance.recalculate | R03 ATTENDANCE_SPECIALIST / R06 / R07 | T18-EMP-A/B,T18-TIME-2026-11,T18-SHIFT-01,T18-RULE-01,T18-SCHED-01,T18-CLOSE-01 | 上游 Org/People/规则/日历；下游 Payroll/Reporting/Leave/Overtime/Travel | T06,T07,T08,T17,T18 / AC-08,10,18,19,21 | 入口 64「异常处理」执行后出现/完成：异常考勤处理列表，异常原因；处理；申请关联与复算。判据：处理前后日结果一致，有审核痕迹。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M09/P064/ | NOT RUN | — |
| 65 | M09 | M09-P065 | M09-P065-F01–M09-P065-F05 | FOLD：旧 /attendance/closing → /attendance（attendance）；旧 URL 直达 catch-all /not-found | attendance.close / attendance.reopen | R03 ATTENDANCE_SPECIALIST / R06 / R07 | T18-EMP-A/B,T18-TIME-2026-11,T18-SHIFT-01,T18-RULE-01,T18-SCHED-01,T18-CLOSE-01 | 上游 Org/People/规则/日历；下游 Payroll/Reporting/Leave/Overtime/Travel | T06,T07,T08,T17,T18 / AC-08,10,18,19,21 | 入口 65「考勤月结」执行后出现/完成：月结批次：预检；异常数；提交结算；…共5卡。判据：月结锁定与工资读取版本一致。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M09/P065/ | NOT RUN | — |
| 66 | M09 | M09-P066 | M09-P066-F01 | FOLD：旧 /attendance/devices → /attendance（attendance）；旧 URL 直达 catch-all /not-found | 读取设备对接（无真实设备时应隐藏） | R03 ATTENDANCE_SPECIALIST / R06 / R07 | T18-EMP-A/B,T18-TIME-2026-11,T18-SHIFT-01,T18-RULE-01,T18-SCHED-01,T18-CLOSE-01 | 上游 Org/People/规则/日历；下游 Payroll/Reporting/Leave/Overtime/Travel | T06,T07,T08,T17,T18 / AC-08,10,18,19,21 | 入口 66「设备对接」执行后出现/完成：设备对接配置/最近导入记录/告警和打卡去重。判据：没有真实设备集成时隐藏入口；不能以演示配置冒充。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M09/P066/ | NOT RUN | 无真实设备集成时隐藏，不得演示配置冒充 |
| 67 | M10 | M10-P067 | M10-P067-F01–M10-P067-F02 | KEEP：/leave（leave） | leave.request.self（subject 固定 SELF） | R03 / R02 / R06 / R07 | T18-EMP-A/B,T18-LEAVE-TYPE-01,T18-LEAVE-POL-01,T18-LEAVE-01,T18-BAL-01 | 上游 Org/People/Time/Workflow；下游 Attendance/Payroll/Reporting | T06,T07,T17,T18 / AC-05,08,10,13,21 | 入口 67「请假申请」执行后出现/完成：我的请假余额；历史及新申请 Drawer（固定本人）。判据：日期冲突、余额规则、审批状态可追溯。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M10/P067/ | NOT RUN | 本人 subject 固定 SELF |
| 68 | M10 | M10-P068 | M10-P068-F01–M10-P068-F04 | FOLD：旧 /leave/requests → /leave（leave）；旧 URL 直达 catch-all /not-found | leave.request.on-behalf / cancel / revoke（显式员工） | R03 / R02 / R06 / R07 | T18-EMP-A/B,T18-LEAVE-TYPE-01,T18-LEAVE-POL-01,T18-LEAVE-01,T18-BAL-01 | 上游 Org/People/Time/Workflow；下游 Attendance/Payroll/Reporting | T06,T07,T17,T18 / AC-05,08,10,13,21 | 入口 68「请假台账」执行后出现/完成：全员请假台账；按部门/员工过滤；HR代申请按钮；…共4卡。判据：代申请显式选择员工并验权，actor 与 subject 分开。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M10/P068/ | NOT RUN | 代申请 actor 与 subject 分开 |
| 69 | M10 | M10-P069 | M10-P069-F01–M10-P069-F03 | FOLD：旧 /leave/types → /leave（leave）；旧 URL 直达 catch-all /not-found | leave.type.create / leave.type.update | R03 / R02 / R06 / R07 | T18-EMP-A/B,T18-LEAVE-TYPE-01,T18-LEAVE-POL-01,T18-LEAVE-01,T18-BAL-01 | 上游 Org/People/Time/Workflow；下游 Attendance/Payroll/Reporting | T06,T07,T17,T18 / AC-05,08,10,13,21 | 入口 69「假期类型」执行后出现/完成：假别编码/中文名称/单位；启用停用；新增编辑 Dialog。判据：已使用假别不可硬删除，不展示 ANNUAL 等枚举。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M10/P069/ | NOT RUN | — |
| 70 | M10 | M10-P070 | M10-P070-F01–M10-P070-F05 | FOLD：旧 /leave/policies → /leave（leave）；旧 URL 直达 catch-all /not-found | leave.policy.create / leave.policy.retire | R03 / R02 / R06 / R07 | T18-EMP-A/B,T18-LEAVE-TYPE-01,T18-LEAVE-POL-01,T18-LEAVE-01,T18-BAL-01 | 上游 Org/People/Time/Workflow；下游 Attendance/Payroll/Reporting | T06,T07,T17,T18 / AC-05,08,10,13,21 | 入口 70「假期规则」执行后出现/完成：假期规则版本；适用对象；额度；…共5卡。判据：修改只影响新生效期间，审批中申请校验适用规则。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M10/P070/ | NOT RUN | — |
| 71 | M10 | M10-P071 | M10-P071-F01–M10-P071-F03 | FOLD：旧 /leave/balances → /leave（leave）；旧 URL 直达 catch-all /not-found | 读取余额/账本（ledger 为 GET/source-only） | R03 / R02 / R06 / R07 | T18-EMP-A/B,T18-LEAVE-TYPE-01,T18-LEAVE-POL-01,T18-LEAVE-01,T18-BAL-01 | 上游 Org/People/Time/Workflow；下游 Attendance/Payroll/Reporting | T06,T07,T17,T18 / AC-05,08,10,13,21 | 入口 71「假期余额」执行后出现/完成：员工个人/HR授权部门的假期额度；剩余；发放/扣减流水。判据：余额只能通过流水/调整命令变化。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M10/P071/ | NOT RUN | — |
| 72 | M10 | M10-P072 | M10-P072-F01–M10-P072-F02 | FOLD：旧 /leave/calendar → /leave（leave）；旧 URL 直达 catch-all /not-found | 读取假期日历（GET） | R03 / R02 / R06 / R07 | T18-EMP-A/B,T18-LEAVE-TYPE-01,T18-LEAVE-POL-01,T18-LEAVE-01,T18-BAL-01 | 上游 Org/People/Time/Workflow；下游 Attendance/Payroll/Reporting | T06,T07,T17,T18 / AC-05,08,10,13,21 | 入口 72「假期日历」执行后出现/完成：部门/个人假期日历；假别和审批状态标签。判据：未审批申请不能当作已批准缺勤。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M10/P072/ | NOT RUN | — |
| 73 | M10 | M10-P073 | M10-P073-F01 | FOLD：旧 /leave/cancellations → /leave（leave）；旧 URL 直达 catch-all /not-found | leave.request.cancel / leave.request.revoke | R03 / R02 / R06 / R07 | T18-EMP-A/B,T18-LEAVE-TYPE-01,T18-LEAVE-POL-01,T18-LEAVE-01,T18-BAL-01 | 上游 Org/People/Time/Workflow；下游 Attendance/Payroll/Reporting | T06,T07,T17,T18 / AC-05,08,10,13,21 | 入口 73「销假/撤销」执行后出现/完成：撤销/销假操作及详情，保留原申请和返还流水。判据：仅允许可撤销状态，已结算时发起修正流程。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M10/P073/ | NOT RUN | 已结算需修正流程 |
| 74 | M10 | M10-P074 | M10-P074-F01 | FOLD：旧 /leave/reports → /leave（leave）；旧 URL 直达 catch-all /not-found | 读取假期汇总（GET） | R03 / R02 / R06 / R07 | T18-EMP-A/B,T18-LEAVE-TYPE-01,T18-LEAVE-POL-01,T18-LEAVE-01,T18-BAL-01 | 上游 Org/People/Time/Workflow；下游 Attendance/Payroll/Reporting | T06,T07,T17,T18 / AC-05,08,10,13,21 | 入口 74「假期汇总」执行后出现/完成：假期类型/部门/月度占用汇总，链接到真实单据。判据：报表与余额流水核对，个人只看自己。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M10/P074/ | NOT RUN | — |
| 75 | M11 | M11-P075 | M11-P075-F01–M11-P075-F03 | KEEP：/overtime（overtime） | overtime.request.self | R03 / R06 / R07 / R04 | T18-EMP-A/B,T18-OT-POL-01,T18-OT-01,T18-TIMEOFF-01 | 上游 Org/People/Time/Workflow；下游 Payroll/TimeOff/Reports | T06,T08,T17,T18 / AC-05,10,13,21 | 入口 75「加班申请」执行后出现/完成：员工本人加班申请；历史；查看审批进度。判据：不能选择未授权员工，日期班次冲突检查。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M11/P075/ | NOT RUN | — |
| 76 | M11 | M11-P076 | M11-P076-F01–M11-P076-F04 | FOLD：旧 /overtime/requests → /overtime（overtime）；旧 URL 直达 catch-all /not-found | 读取加班台账；管理确认走 overtime.request.confirm | R03 / R06 / R07 / R04 | T18-EMP-A/B,T18-OT-POL-01,T18-OT-01,T18-TIMEOFF-01 | 上游 Org/People/Time/Workflow；下游 Payroll/TimeOff/Reports | T06,T08,T17,T18 / AC-05,10,13,21 | 入口 76「加班台账」执行后出现/完成：加班台账；筛选；申请详情；…共4卡。判据：管理者仅见授权组织/待办。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M11/P076/ | NOT RUN | — |
| 77 | M11 | M11-P077 | M11-P077-F01–M11-P077-F03 | FOLD：旧 /overtime/policies → /overtime（overtime）；旧 URL 直达 catch-all /not-found | overtime.policy.create / retire | R03 / R06 / R07 / R04 | T18-EMP-A/B,T18-OT-POL-01,T18-OT-01,T18-TIMEOFF-01 | 上游 Org/People/Time/Workflow；下游 Payroll/TimeOff/Reports | T06,T08,T17,T18 / AC-05,10,13,21 | 入口 77「加班规则」执行后出现/完成：加班规则版本；计时单位；调休/计薪分配规则。判据：规则版本生效不可直接影响冻结批次。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M11/P077/ | NOT RUN | — |
| 78 | M11 | M11-P078 | M11-P078-F01–M11-P078-F02 | FOLD：旧 /overtime/confirm → /overtime（overtime）；旧 URL 直达 catch-all /not-found | overtime.request.confirm | R03 / R06 / R07 / R04 | T18-EMP-A/B,T18-OT-POL-01,T18-OT-01,T18-TIMEOFF-01 | 上游 Org/People/Time/Workflow；下游 Payroll/TimeOff/Reports | T06,T08,T17,T18 / AC-05,10,13,21 | 入口 78「加班确认」执行后出现/完成：加班确认列表：实际时数与打卡事实对照；确认结果。判据：重复确认失败或幂等，记录确认人。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M11/P078/ | NOT RUN | 重复确认幂等/409 |
| 79 | M11 | M11-P079 | M11-P079-F01–M11-P079-F03 | FOLD：旧 /overtime/timeoff → /overtime（overtime）；旧 URL 直达 catch-all /not-found | 读取调休余额（GET） | R03 / R06 / R07 / R04 | T18-EMP-A/B,T18-OT-POL-01,T18-OT-01,T18-TIMEOFF-01 | 上游 Org/People/Time/Workflow；下游 Payroll/TimeOff/Reports | T06,T08,T17,T18 / AC-05,10,13,21 | 入口 79「调休余额」执行后出现/完成：员工调休额度；获得/使用/到期流水；按员工查询。判据：结算一笔只生成一次调休额度。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M11/P079/ | NOT RUN | — |
| 80 | M11 | M11-P080 | M11-P080-F01–M11-P080-F03 | FOLD：旧 /overtime/settlement → /overtime（overtime）；旧 URL 直达 catch-all /not-found | overtime.settlement.create（PAY/TIMEOFF 互斥） | R03 / R06 / R07 / R04 | T18-EMP-A/B,T18-OT-POL-01,T18-OT-01,T18-TIMEOFF-01 | 上游 Org/People/Time/Workflow；下游 Payroll/TimeOff/Reports | T06,T08,T17,T18 / AC-05,10,13,21 | 入口 80「加班结算」执行后出现/完成：加班结算登记；调休/计薪选择；状态/工资关联。判据：禁止重复结算，工资已冻结不能静默补发。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M11/P080/ | NOT RUN | PAY/TIMEOFF 互斥；冻结工资阻断 |
| 81 | M12 | M12-P081 | M12-P081-F01–M12-P081-F03 | KEEP：/travel（travel） | travel.request.create | R03 / R02 / R06 / R07 | T18-EMP-A/B,T18-TRAVEL-POL-01,T18-TRAVEL-01 | 上游 Org/People/Time/Workflow；下游 Attendance/Payroll/Reporting | T06,T08,T17,T18 / AC-10,13,16,21 | 入口 81「出差申请」执行后出现/完成：本人出差申请列表；发起 Drawer；行程与审批历史。判据：申请对象固定本人，日期冲突与审批人校验。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M12/P081/ | NOT RUN | — |
| 82 | M12 | M12-P082 | M12-P082-F01–M12-P082-F03 | FOLD：旧 /travel/requests → /travel（travel）；旧 URL 直达 catch-all /not-found | 读取/代申请（travel.request.create） | R03 / R02 / R06 / R07 | T18-EMP-A/B,T18-TRAVEL-POL-01,T18-TRAVEL-01 | 上游 Org/People/Time/Workflow；下游 Attendance/Payroll/Reporting | T06,T08,T17,T18 / AC-10,13,16,21 | 入口 82「出差台账」执行后出现/完成：出差台账；员工/部门/日期筛选；HR代办及审批跟踪。判据：管理员代办需显式选择员工与记录操作者。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M12/P082/ | NOT RUN | — |
| 83 | M12 | M12-P083 | M12-P083-F01–M12-P083-F03 | FOLD：旧 /travel/policies → /travel（travel）；旧 URL 直达 catch-all /not-found | travel.policy.publish | R03 / R02 / R06 / R07 | T18-EMP-A/B,T18-TRAVEL-POL-01,T18-TRAVEL-01 | 上游 Org/People/Time/Workflow；下游 Attendance/Payroll/Reporting | T06,T08,T17,T18 / AC-10,13,16,21 | 入口 83「差旅政策」执行后出现/完成：差旅政策列表/版本；适用部门；费用上限与启停。判据：规则生效版本，不改历史已批行程。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M12/P083/ | NOT RUN | — |
| 84 | M12 | M12-P084 | M12-P084-F01–M12-P084-F03 | FOLD：旧 /travel/approval → /travel（travel）；旧 URL 直达 catch-all /not-found | travel.request.approve | R03 / R02 / R06 / R07 | T18-EMP-A/B,T18-TRAVEL-POL-01,T18-TRAVEL-01 | 上游 Org/People/Time/Workflow；下游 Attendance/Payroll/Reporting | T06,T08,T17,T18 / AC-10,13,16,21 | 入口 84「出差审批」执行后出现/完成：待审批出差；查看行程；同意/驳回与备注。判据：审批只给真实待办人，不默认超管任意审批。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M12/P084/ | NOT RUN | — |
| 85 | M12 | M12-P085 | M12-P085-F01 | FOLD：旧 /travel/changes → /travel（travel）；旧 URL 直达 catch-all /not-found | travel.request.change | R03 / R02 / R06 / R07 | T18-EMP-A/B,T18-TRAVEL-POL-01,T18-TRAVEL-01 | 上游 Org/People/Time/Workflow；下游 Attendance/Payroll/Reporting | T06,T08,T17,T18 / AC-10,13,16,21 | 入口 85「出差变更/销单」执行后出现/完成：变更行程日期/地点或销单，比较变更前后及审批记录。判据：已结算费用不能随意撤销，状态机校验。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M12/P085/ | NOT RUN | 已结算费用不得随意撤销 |
| 86 | M12 | M12-P086 | M12-P086-F01–M12-P086-F02 | FOLD：旧 /travel/reports → /travel（travel）；旧 URL 直达 catch-all /not-found | 读取出差统计（GET） | R03 / R02 / R06 / R07 | T18-EMP-A/B,T18-TRAVEL-POL-01,T18-TRAVEL-01 | 上游 Org/People/Time/Workflow；下游 Attendance/Payroll/Reporting | T06,T08,T17,T18 / AC-10,13,16,21 | 入口 86「出差统计」执行后出现/完成：部门/月度出差次数/天数/人员汇总；明细下钻。判据：汇总来自真实申请状态，待审不算已完成。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M12/P086/ | NOT RUN | — |
| 87 | M12 | M12-P087 | M12-P087-F01–M12-P087-F02 | FOLD：旧 /travel/expenses → /travel（travel）；旧 URL 直达 catch-all /not-found | travel.expense.claim | R03 / R02 / R06 / R07 | T18-EMP-A/B,T18-TRAVEL-POL-01,T18-TRAVEL-01 | 上游 Org/People/Time/Workflow；下游 Attendance/Payroll/Reporting | T06,T08,T17,T18 / AC-10,13,16,21 | 入口 87「费用交接」执行后出现/完成：差旅费用交接：登记金额/单据/交接人；查看记录。判据：一笔费用关联出差单，内部登记不执行实际银行支付。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M12/P087/ | NOT RUN | 内部登记不等于银行支付 |
| 88 | M13 | M13-P088 | M13-P088-F01–M13-P088-F05 | KEEP：/payroll（payroll） | payroll.run.create / publish / review；读取批次摘要 | R04 / R05 / R09 / R07 | T18-EMP-A/B,T18-RUN-01,T18-RUN-CORR-01,T18-PAY-ITEM-01,T18-SCHEME-M/H,T18-SI-2026 | 上游 People/Org/Contracts/Time/Benefits/Performance；下游 Payslips/Reports/Notifications | T09,T10,T11,T12,T13,T17,T18 / AC-05,06,07,08,09,11,12,13,14,16,18,19,20,21 | 入口 88「薪资概览」执行后出现/完成：工资概览：本月批次；待核算；待复核；…共5卡。判据：金额按元展示、未授权工资金额不显示。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M13/P088/ | NOT RUN | — |
| 89 | M13 | M13-P089 | M13-P089-F01–M13-P089-F05 | KEEP：/payroll/runs/:id（payroll-run-detail） | payroll.run.detail.lock-inputs/calculate/review/approve/publish/cancel/reopen/correct/mark-paid/close | R04 / R05 / R09 / R07 | T18-EMP-A/B,T18-RUN-01,T18-RUN-CORR-01,T18-PAY-ITEM-01,T18-SCHEME-M/H,T18-SI-2026 | 上游 People/Org/Contracts/Time/Benefits/Performance；下游 Payslips/Reports/Notifications | T09,T10,T11,T12,T13,T17,T18 / AC-05,06,07,08,09,11,12,13,14,16,18,19,20,21 | 入口 89「工资批次详情」执行后出现/完成：批次详情：员工工资列表；输入快照；复核差异；…共5卡。判据：只有本状态允许的动作可见且服务端重复校验。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M13/P089/ | NOT RUN | 只允许当前状态动作；服务端重复校验 |
| 90 | M13 | M13-P090 | M13-P090-F01–M13-P090-F04 | FOLD：旧 /payroll/components → /payroll（payroll）；旧 URL 直达 catch-all /not-found | payroll.item.create / item.version / item.retire | R04 / R05 / R09 / R07 | T18-EMP-A/B,T18-RUN-01,T18-RUN-CORR-01,T18-PAY-ITEM-01,T18-SCHEME-M/H,T18-SI-2026 | 上游 People/Org/Contracts/Time/Benefits/Performance；下游 Payslips/Reports/Notifications | T09,T10,T11,T12,T13,T17,T18 / AC-05,06,07,08,09,11,12,13,14,16,18,19,20,21 | 入口 90「薪资项目」执行后出现/完成：薪资项目列表；添加/编辑 Dialog；类型；…共4卡。判据：项目编码唯一、已被使用时禁止删除、启停版本。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M13/P090/ | NOT RUN | — |
| 91 | M13 | M13-P091 | M13-P091-F01–M13-P091-F03 | FOLD：旧 /payroll/plans → /payroll（payroll）；旧 URL 直达 catch-all /not-found | payroll.scheme.create；读取方案 | R04 / R05 / R09 / R07 | T18-EMP-A/B,T18-RUN-01,T18-RUN-CORR-01,T18-PAY-ITEM-01,T18-SCHEME-M/H,T18-SI-2026 | 上游 People/Org/Contracts/Time/Benefits/Performance；下游 Payslips/Reports/Notifications | T09,T10,T11,T12,T13,T17,T18 / AC-05,06,07,08,09,11,12,13,14,16,18,19,20,21 | 入口 91「薪酬方案」执行后出现/完成：薪酬方案目录；新建/编辑 Drawer；项目权重/公式及生效范围。判据：计算公式校验、不允许循环依赖、不覆盖已生效版本。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M13/P091/ | NOT RUN | — |
| 92 | M13 | M13-P092 | M13-P092-F01–M13-P092-F06 | FOLD：旧 /payroll/employee-plans → /payroll（payroll）；旧 URL 直达 catch-all /not-found | payroll.compensation.create；读取员工档案 | R04 / R05 / R09 / R07 | T18-EMP-A/B,T18-RUN-01,T18-RUN-CORR-01,T18-PAY-ITEM-01,T18-SCHEME-M/H,T18-SI-2026 | 上游 People/Org/Contracts/Time/Benefits/Performance；下游 Payslips/Reports/Notifications | T09,T10,T11,T12,T13,T17,T18 / AC-05,06,07,08,09,11,12,13,14,16,18,19,20,21 | 入口 92「员工薪资档案」执行后出现/完成：员工薪资档案：员工；岗位；方案；…共6卡。判据：一个员工同一生效段不可冲突绑定，薪资专员专属字段。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M13/P092/ | NOT RUN | — |
| 93 | M13 | M13-P093 | M13-P093-F01–M13-P093-F04 | FOLD：旧 /payroll/statutory → /payroll（payroll）；旧 URL 直达 catch-all /not-found | 法定参数版本 POST /v1/payroll/statutory-parameters（source-only，未入 manifest；GAP-15） | R04 / R05 / R09 / R07 | T18-EMP-A/B,T18-RUN-01,T18-RUN-CORR-01,T18-PAY-ITEM-01,T18-SCHEME-M/H,T18-SI-2026 | 上游 People/Org/Contracts/Time/Benefits/Performance；下游 Payslips/Reports/Notifications | T09,T10,T11,T12,T13,T17,T18 / AC-05,06,07,08,09,11,12,13,14,16,18,19,20,21 | 入口 93「社保公积金参数」执行后出现/完成：社保公积金参数：版本；适用范围；费率/基数；…共4卡。判据：只允许一个主写入口，历史工资读取旧版本。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M13/P093/ | NOT RUN | GAP-15：参数写动作未入 manifest；T14 |
| 94 | M13 | M13-P094 | M13-P094-F01–M13-P094-F04 | FOLD：旧 /payroll/inputs → /payroll（payroll）；旧 URL 直达 catch-all /not-found | 读取 inputs；锁定走 payroll.run.detail.lock-inputs | R04 / R05 / R09 / R07 | T18-EMP-A/B,T18-RUN-01,T18-RUN-CORR-01,T18-PAY-ITEM-01,T18-SCHEME-M/H,T18-SI-2026 | 上游 People/Org/Contracts/Time/Benefits/Performance；下游 Payslips/Reports/Notifications | T09,T10,T11,T12,T13,T17,T18 / AC-05,06,07,08,09,11,12,13,14,16,18,19,20,21 | 入口 94「计薪数据汇总」执行后出现/完成：计薪输入核对：员工/批次；出勤/请假/加班；手工调整；…共4卡。判据：只接收已结算时间事实，输入快照锁定前可校验。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M13/P094/ | NOT RUN | — |
| 95 | M13 | M13-P095 | M13-P095-F01–M13-P095-F04 | FOLD：旧 /payroll/batches → /payroll（payroll）；旧 URL 直达 catch-all /not-found | payroll.run.create；读取批次历史 | R04 / R05 / R09 / R07 | T18-EMP-A/B,T18-RUN-01,T18-RUN-CORR-01,T18-PAY-ITEM-01,T18-SCHEME-M/H,T18-SI-2026 | 上游 People/Org/Contracts/Time/Benefits/Performance；下游 Payslips/Reports/Notifications | T09,T10,T11,T12,T13,T17,T18 / AC-05,06,07,08,09,11,12,13,14,16,18,19,20,21 | 入口 95「工资核算批次」执行后出现/完成：创建工资批次；月份；人员范围；…共4卡。判据：批次同周期冲突、执行幂等与重复计算审计。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M13/P095/ | NOT RUN | — |
| 96 | M13 | M13-P096 | M13-P096-F01–M13-P096-F03 | FOLD：旧 /payroll/reviews → /payroll（payroll）；旧 URL 直达 catch-all /not-found | payroll.run.review（复核） | R04 / R05 / R09 / R07 | T18-EMP-A/B,T18-RUN-01,T18-RUN-CORR-01,T18-PAY-ITEM-01,T18-SCHEME-M/H,T18-SI-2026 | 上游 People/Org/Contracts/Time/Benefits/Performance；下游 Payslips/Reports/Notifications | T09,T10,T11,T12,T13,T17,T18 / AC-05,06,07,08,09,11,12,13,14,16,18,19,20,21 | 入口 96「工资复核」执行后出现/完成：工资复核：异常清单；变动对比；复核通过/退回及意见。判据：复核人与制单人权限区分，避免未复核直接发布。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M13/P096/ | NOT RUN | — |
| 97 | M13 | M13-P097 | M13-P097-F01–M13-P097-F04 | FOLD：旧 /payroll/disbursement → /payroll（payroll）；旧 URL 直达 catch-all /not-found | payroll.run.detail.mark-paid（发放登记） | R04 / R05 / R09 / R07 | T18-EMP-A/B,T18-RUN-01,T18-RUN-CORR-01,T18-PAY-ITEM-01,T18-SCHEME-M/H,T18-SI-2026 | 上游 People/Org/Contracts/Time/Benefits/Performance；下游 Payslips/Reports/Notifications | T09,T10,T11,T12,T13,T17,T18 / AC-05,06,07,08,09,11,12,13,14,16,18,19,20,21 | 入口 97「工资发放登记」执行后出现/完成：发放登记：批准批次金额；日期；登记人；…共4卡。判据：登记不等于真实银行支付；重复发放确认保护。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M13/P097/ | NOT RUN | 登记发放不等于真实银行支付 |
| 98 | M13 | M13-P098 | M13-P098-F01–M13-P098-F03 | FOLD：旧 /payroll/payslips → /payroll（payroll）；旧 URL 直达 catch-all /not-found | 读取工资条；payroll.run.detail.publish 控制发布 | R04 / R05 / R09 / R07 | T18-EMP-A/B,T18-RUN-01,T18-RUN-CORR-01,T18-PAY-ITEM-01,T18-SCHEME-M/H,T18-SI-2026 | 上游 People/Org/Contracts/Time/Benefits/Performance；下游 Payslips/Reports/Notifications | T09,T10,T11,T12,T13,T17,T18 / AC-05,06,07,08,09,11,12,13,14,16,18,19,20,21 | 入口 98「工资条管理」执行后出现/完成：工资条列表/单条预览；发布状态；本人下载。判据：员工仅本人；未发布不可见；批次来源不可改。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M13/P098/ | NOT RUN | SELF 只能本人已发布工资条 |
| 99 | M13 | M13-P099 | M13-P099-F01 | FOLD：旧 /payroll/adjustments → /payroll（payroll）；旧 URL 直达 catch-all /not-found | 读取调薪记录（GET） | R04 / R05 / R09 / R07 | T18-EMP-A/B,T18-RUN-01,T18-RUN-CORR-01,T18-PAY-ITEM-01,T18-SCHEME-M/H,T18-SI-2026 | 上游 People/Org/Contracts/Time/Benefits/Performance；下游 Payslips/Reports/Notifications | T09,T10,T11,T12,T13,T17,T18 / AC-05,06,07,08,09,11,12,13,14,16,18,19,20,21 | 入口 99「调薪记录」执行后出现/完成：调薪历史关联异动单与薪资档案，显示生效日期/批准人。判据：不在此直接绕过审批改历史工资。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M13/P099/ | NOT RUN | — |
| 100 | M13 | M13-P100 | M13-P100-F01–M13-P100-F03 | FOLD：旧 /payroll/reports → /payroll（payroll）；旧 URL 直达 catch-all /not-found | 读取薪资报表/导出（GET/source-only） | R04 / R05 / R09 / R07 | T18-EMP-A/B,T18-RUN-01,T18-RUN-CORR-01,T18-PAY-ITEM-01,T18-SCHEME-M/H,T18-SI-2026 | 上游 People/Org/Contracts/Time/Benefits/Performance；下游 Payslips/Reports/Notifications | T09,T10,T11,T12,T13,T17,T18 / AC-05,06,07,08,09,11,12,13,14,16,18,19,20,21 | 入口 100「薪资报表」执行后出现/完成：薪资数据汇总；趋势；部门成本与授权导出。判据：只汇总已发布有效批次，禁止明细工资字段越权。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M13/P100/ | NOT RUN | — |
| 101 | M15 | M15-P101 | M15-P101-F01–M15-P101-F03 | KEEP：/performance（performance） | performance.cycle.create / performance.cycle.publish | R02 / R06 / R07 | T18-EMP-A/B/C,T18-PERF-CYCLE-01,T18-PERF-TPL-01,T18-PERF-RESULT-01 | 上游 People/Org/Workflow；下游 Payroll/Reporting/员工自助 | T09,T15,T17,T18 / AC-13,14,15,20,21 | 入口 101「绩效周期」执行后出现/完成：绩效周期列表；创建/启停/发布；周期详情与状态进度。判据：起止时间、模板绑定与参与员工范围准确。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M15/P101/ | NOT RUN | — |
| 102 | M15 | M15-P102 | M15-P102-F01–M15-P102-F04 | FOLD：旧 /performance/templates → /performance（performance）；旧 URL 直达 catch-all /not-found | 考核模板保存（source-only；模板 GET/POST） | R02 / R06 / R07 | T18-EMP-A/B/C,T18-PERF-CYCLE-01,T18-PERF-TPL-01,T18-PERF-RESULT-01 | 上游 People/Org/Workflow；下游 Payroll/Reporting/员工自助 | T09,T15,T17,T18 / AC-13,14,15,20,21 | 入口 102「考核模板」执行后出现/完成：考核模板列表；维度权重；新增/编辑 Drawer；…共4卡。判据：维度权重合计与评分规则校验，历史版本可追溯。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M15/P102/ | NOT RUN | 详情 actions=[]；模板写 source-only |
| 103 | M15 | M15-P103 | M15-P103-F01–M15-P103-F02 | FOLD：旧 /performance/indicators → /performance（performance）；旧 URL 直达 catch-all /not-found | 指标库保存（source-only；indicators GET/POST） | R02 / R06 / R07 | T18-EMP-A/B/C,T18-PERF-CYCLE-01,T18-PERF-TPL-01,T18-PERF-RESULT-01 | 上游 People/Org/Workflow；下游 Payroll/Reporting/员工自助 | T09,T15,T17,T18 / AC-13,14,15,20,21 | 入口 103「指标库」执行后出现/完成：指标库按部门/岗位筛选；维护指标定义/权重/单位。判据：指标业务中文名称唯一/指标版本稳定。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M15/P103/ | NOT RUN | 指标写 source-only |
| 104 | M15 | M15-P104 | M15-P104-F01–M15-P104-F03 | FOLD：旧 /performance/goals → /performance（performance）；旧 URL 直达 catch-all /not-found | 目标计划保存/提交（source-only；goals GET/POST） | R02 / R06 / R07 | T18-EMP-A/B/C,T18-PERF-CYCLE-01,T18-PERF-TPL-01,T18-PERF-RESULT-01 | 上游 People/Org/Workflow；下游 Payroll/Reporting/员工自助 | T09,T15,T17,T18 / AC-13,14,15,20,21 | 入口 104「目标计划」执行后出现/完成：员工目标计划新建/编辑/提交；主管确认；目标进度。判据：目标归属周期+员工，过期周期只读。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M15/P104/ | NOT RUN | 目标写 source-only |
| 105 | M15 | M15-P105 | M15-P105-F01–M15-P105-F03 | FOLD：旧 /performance/reviews → /performance（performance）；旧 URL 直达 catch-all /not-found | 自评/主管评分提交（source-only；reviews/{id}/submit） | R02 / R06 / R07 | T18-EMP-A/B/C,T18-PERF-CYCLE-01,T18-PERF-TPL-01,T18-PERF-RESULT-01 | 上游 People/Org/Workflow；下游 Payroll/Reporting/员工自助 | T09,T15,T17,T18 / AC-13,14,15,20,21 | 入口 105「自评/主管评分」执行后出现/完成：员工自评与主管评价独立按钮；评分草稿；提交与历史。判据：仅本人自评；主管仅授权员工；提交后不能无痕修改。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M15/P105/ | NOT RUN | 评分提交 source-only |
| 106 | M15 | M15-P106 | M15-P106-F01–M15-P106-F03 | FOLD：旧 /performance/feedback → /performance（performance）；旧 URL 直达 catch-all /not-found | 360 反馈保存（source-only；feedback GET/POST） | R02 / R06 / R07 | T18-EMP-A/B/C,T18-PERF-CYCLE-01,T18-PERF-TPL-01,T18-PERF-RESULT-01 | 上游 People/Org/Workflow；下游 Payroll/Reporting/员工自助 | T09,T15,T17,T18 / AC-13,14,15,20,21 | 入口 106「360反馈」执行后出现/完成：360反馈受评人/反馈人；匿名或实名规则；填写/汇总。判据：反馈人与受评人正确绑定；不能自选权限外对象。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M15/P106/ | NOT RUN | 反馈写 source-only |
| 107 | M15 | M15-P107 | M15-P107-F01–M15-P107-F03 | FOLD：旧 /performance/calibration → /performance（performance）；旧 URL 直达 catch-all /not-found | 绩效校准提交（source-only；reviews/{id}/calibration） | R02 / R06 / R07 | T18-EMP-A/B/C,T18-PERF-CYCLE-01,T18-PERF-TPL-01,T18-PERF-RESULT-01 | 上游 People/Org/Workflow；下游 Payroll/Reporting/员工自助 | T09,T15,T17,T18 / AC-13,14,15,20,21 | 入口 107「绩效校准」执行后出现/完成：按周期显示待校准名单；分布与调整原因；提交批准。判据：校准专员才有修改权限，已发布不能再改。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M15/P107/ | NOT RUN | 校准 source-only |
| 108 | M15 | M15-P108 | M15-P108-F01–M15-P108-F04 | FOLD：旧 /performance/results → /performance（performance）；旧 URL 直达 catch-all /not-found | 结果发布（source-only；publish 后冻结） | R02 / R06 / R07 | T18-EMP-A/B/C,T18-PERF-CYCLE-01,T18-PERF-TPL-01,T18-PERF-RESULT-01 | 上游 People/Org/Workflow；下游 Payroll/Reporting/员工自助 | T09,T15,T17,T18 / AC-13,14,15,20,21 | 入口 108「结果发布」执行后出现/完成：结果发布清单；确认范围；员工可见状态；…共4卡。判据：只能发布已完成评价；发布后冻结评分快照。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M15/P108/ | NOT RUN | 结果发布 source-only；发布后冻结 |
| 109 | M15 | M15-P109 | M15-P109-F01–M15-P109-F04 | FOLD：旧 /performance/appeals → /performance（performance）；旧 URL 直达 catch-all /not-found | performance.appeal.create | R02 / R06 / R07 | T18-EMP-A/B/C,T18-PERF-CYCLE-01,T18-PERF-TPL-01,T18-PERF-RESULT-01 | 上游 People/Org/Workflow；下游 Payroll/Reporting/员工自助 | T09,T15,T17,T18 / AC-13,14,15,20,21 | 入口 109「绩效申诉」执行后出现/完成：绩效申诉创建；材料上传；处理意见；…共4卡。判据：仅已发布评价可申诉，处理人非本人/越权者。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M15/P109/ | NOT RUN | — |
| 110 | M15 | M15-P110 | M15-P110-F01 | FOLD：旧 /performance/reports → /performance（performance）；旧 URL 直达 catch-all /not-found | 读取绩效报表（GET） | R02 / R06 / R07 | T18-EMP-A/B/C,T18-PERF-CYCLE-01,T18-PERF-TPL-01,T18-PERF-RESULT-01 | 上游 People/Org/Workflow；下游 Payroll/Reporting/员工自助 | T09,T15,T17,T18 / AC-13,14,15,20,21 | 入口 110「绩效报表」执行后出现/完成：绩效结果分布与部门趋势，查询/导出。判据：仅发布结果进入汇总，字段对主管和员工做授权。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M15/P110/ | NOT RUN | — |
| 111 | M16 | M16-P111 | M16-P111-F01–M16-P111-F04 | KEEP：/recruitment/requests（recruitment-requests） | recruitment.requisitions.create/update/close | R08 RECRUITER / R02 / R06 | T18-REQ-01,T18-CAND-01,T18-OFFER-01,T18-INTERVIEW-01 | 上游 Org/People/Workflow；下游 Lifecycle/People/Contracts/Payroll/Training | T05,T15,T17,T18 / AC-04,13,14,15,21 | 入口 111「招聘需求列表」执行后出现/完成：招聘需求列表；部门/岗位/状态筛选；申请/编辑 Drawer；…共4卡。判据：需求人数受编制与授权部门约束。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M16/P111/ | NOT RUN | — |
| 112 | M16 | M16-P112 | M16-P112-F01–M16-P112-F02 | FOLD：旧 /recruitment/requests/approval → /recruitment/requests（recruitment-requests）；旧 URL 直达 catch-all /not-found | 读取/审批招聘需求（source-only；无 manifest 审批 action） | R08 RECRUITER / R02 / R06 | T18-REQ-01,T18-CAND-01,T18-OFFER-01,T18-INTERVIEW-01 | 上游 Org/People/Workflow；下游 Lifecycle/People/Contracts/Payroll/Training | T05,T15,T17,T18 / AC-04,13,14,15,21 | 入口 112「招聘需求审批」执行后出现/完成：需求提交审批；待办与审批历史。判据：审批人是被授权的用人经理，申请人不能自动批准。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M16/P112/ | NOT RUN | 需求审批无 manifest action |
| 113 | M16 | M16-P113 | M16-P113-F01–M16-P113-F02 | FOLD：旧 /recruitment/requests/openings → /recruitment/requests（recruitment-requests）；旧 URL 直达 catch-all /not-found | openings GET/POST（source-only） | R08 RECRUITER / R02 / R06 | T18-REQ-01,T18-CAND-01,T18-OFFER-01,T18-INTERVIEW-01 | 上游 Org/People/Workflow；下游 Lifecycle/People/Contracts/Payroll/Training | T05,T15,T17,T18 / AC-04,13,14,15,21 | 入口 113「招聘岗位发布」执行后出现/完成：招聘岗位开放/渠道/开放人数；暂停与关闭状态。判据：岗位必须关联已批准招聘需求，不新增孤立岗位。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M16/P113/ | NOT RUN | — |
| 114 | M16 | M16-P114 | M16-P114-F01–M16-P114-F02 | FOLD：旧 /recruitment/requests/board → /recruitment/requests（recruitment-requests）；旧 URL 直达 catch-all /not-found | 读取招聘进度看板（GET board） | R08 RECRUITER / R02 / R06 | T18-REQ-01,T18-CAND-01,T18-OFFER-01,T18-INTERVIEW-01 | 上游 Org/People/Workflow；下游 Lifecycle/People/Contracts/Payroll/Training | T05,T15,T17,T18 / AC-04,13,14,15,21 | 入口 114「招聘进度看板」执行后出现/完成：招聘阶段看板；拖动候选人阶段/点击岗位或候选人。判据：阶段改变必须走服务端状态命令及审计。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M16/P114/ | NOT RUN | — |
| 115 | M16 | M16-P115 | M16-P115-F01–M16-P115-F03 | FOLD：旧 /recruitment/requests/budget → /recruitment/requests（recruitment-requests）；旧 URL 直达 catch-all /not-found | 读取预算与编制（GET budget） | R08 RECRUITER / R02 / R06 | T18-REQ-01,T18-CAND-01,T18-OFFER-01,T18-INTERVIEW-01 | 上游 Org/People/Workflow；下游 Lifecycle/People/Contracts/Payroll/Training | T05,T15,T17,T18 / AC-04,13,14,15,21 | 入口 115「招聘预算与编制」执行后出现/完成：招聘需求预算；核定编制；占用人数和剩余需求。判据：预算不得与申请部门、岗位脱离。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M16/P115/ | NOT RUN | — |
| 116 | M16 | M16-P116 | M16-P116-F01–M16-P116-F04 | FOLD：旧 /recruitment/requests/reports → /recruitment/requests（recruitment-requests）；旧 URL 直达 catch-all /not-found | 读取招聘统计（GET reports） | R08 RECRUITER / R02 / R06 | T18-REQ-01,T18-CAND-01,T18-OFFER-01,T18-INTERVIEW-01 | 上游 Org/People/Workflow；下游 Lifecycle/People/Contracts/Payroll/Training | T05,T15,T17,T18 / AC-04,13,14,15,21 | 入口 116「招聘统计」执行后出现/完成：需求周期；招聘时长；渠道；…共4卡。判据：已关闭需求与候选人总数一致，不虚构漏斗。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M16/P116/ | NOT RUN | — |
| 117 | M16 | M16-P117 | M16-P117-F01–M16-P117-F05 | KEEP：/recruitment/candidates（recruitment-candidates） | recruitment.applications.create / candidates.import / talent-pool | R08 RECRUITER / R02 / R06 | T18-REQ-01,T18-CAND-01,T18-OFFER-01,T18-INTERVIEW-01 | 上游 Org/People/Workflow；下游 Lifecycle/People/Contracts/Payroll/Training | T05,T15,T17,T18 / AC-04,13,14,15,21 | 入口 117「候选人列表」执行后出现/完成：候选人主列表；新增；来源；…共5卡。判据：联系方式合理脱敏，身份不按姓名去重。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M16/P117/ | NOT RUN | — |
| 118 | M16 | M16-P118 | M16-P118-F01 | KEEP：/recruitment/candidates/:id（recruitment-candidates-by-id） | 读取候选人详情（GET；详情 actions=[]） | R08 RECRUITER / R02 / R06 | T18-REQ-01,T18-CAND-01,T18-OFFER-01,T18-INTERVIEW-01 | 上游 Org/People/Workflow；下游 Lifecycle/People/Contracts/Payroll/Training | T05,T15,T17,T18 / AC-04,13,14,15,21 | 入口 118「候选人详情」执行后出现/完成：候选人详情：基本信息/应聘/面试/Offer/沟通/标签/转入职。判据：同一候选人跨岗位应聘不重复建档。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M16/P118/ | NOT RUN | 候选人详情 actions=[] |
| 119 | M16 | M16-P119 | M16-P119-F01 | FOLD：旧 /recruitment/candidates/imports → /recruitment/candidates/:id（recruitment-candidates-by-id）；旧 URL 直达 catch-all /not-found | recruitment.candidates.import | R08 RECRUITER / R02 / R06 | T18-REQ-01,T18-CAND-01,T18-OFFER-01,T18-INTERVIEW-01 | 上游 Org/People/Workflow；下游 Lifecycle/People/Contracts/Payroll/Training | T05,T15,T17,T18 / AC-04,13,14,15,21 | 入口 119「简历解析/导入」执行后出现/完成：简历上传/解析结果预览/字段修正/确认导入。判据：解析失败明确待修正；重复手机号/邮箱只能提示人工判断。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M16/P119/ | NOT RUN | 详情 actions=[]；导入 source-only |
| 120 | M16 | M16-P120 | M16-P120-F01–M16-P120-F03 | FOLD：旧 /recruitment/candidates/pipeline → /recruitment/candidates/:id（recruitment-candidates-by-id）；旧 URL 直达 catch-all /not-found | recruitment.candidates.move.stage | R08 RECRUITER / R02 / R06 | T18-REQ-01,T18-CAND-01,T18-OFFER-01,T18-INTERVIEW-01 | 上游 Org/People/Workflow；下游 Lifecycle/People/Contracts/Payroll/Training | T05,T15,T17,T18 / AC-04,13,14,15,21 | 入口 120「招聘流程看板」执行后出现/完成：按招聘岗位筛选的候选人看板；阶段移动；阶段历史。判据：阶段合法跃迁；需记录操作人时间。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M16/P120/ | NOT RUN | 详情 actions=[]；阶段移动 source-only |
| 121 | M16 | M16-P121 | M16-P121-F01–M16-P121-F03 | FOLD：旧 /recruitment/candidates/interviews → /recruitment/candidates/:id（recruitment-candidates-by-id）；旧 URL 直达 catch-all /not-found | 面试安排 POST（source-only） | R08 RECRUITER / R02 / R06 | T18-REQ-01,T18-CAND-01,T18-OFFER-01,T18-INTERVIEW-01 | 上游 Org/People/Workflow；下游 Lifecycle/People/Contracts/Payroll/Training | T05,T15,T17,T18 / AC-04,13,14,15,21 | 入口 121「面试安排」执行后出现/完成：面试排期日历；面试官/时段/地点/候选人；发起通知。判据：时间冲突、面试官任职可用、关联招聘申请。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M16/P121/ | NOT RUN | 面试写 source-only |
| 122 | M16 | M16-P122 | M16-P122-F01–M16-P122-F04 | FOLD：旧 /recruitment/candidates/evaluations → /recruitment/candidates/:id（recruitment-candidates-by-id）；旧 URL 直达 catch-all /not-found | 面试评价 POST（source-only） | R08 RECRUITER / R02 / R06 | T18-REQ-01,T18-CAND-01,T18-OFFER-01,T18-INTERVIEW-01 | 上游 Org/People/Workflow；下游 Lifecycle/People/Contracts/Payroll/Training | T05,T15,T17,T18 / AC-04,13,14,15,21 | 入口 122「面试评价」执行后出现/完成：面试反馈维度评分；意见；提交；…共4卡。判据：仅面试官与授权 HR 能评分，禁止为未参加面试的人造评价。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M16/P122/ | NOT RUN | 评价写 source-only |
| 123 | M16 | M16-P123 | M16-P123-F01–M16-P123-F03 | FOLD：旧 /recruitment/candidates/offers → /recruitment/candidates/:id（recruitment-candidates-by-id）；旧 URL 直达 catch-all /not-found | recruitment.offers.create / offers.accept | R08 RECRUITER / R02 / R06 | T18-REQ-01,T18-CAND-01,T18-OFFER-01,T18-INTERVIEW-01 | 上游 Org/People/Workflow；下游 Lifecycle/People/Contracts/Payroll/Training | T05,T15,T17,T18 / AC-04,13,14,15,21 | 入口 123「Offer管理」执行后出现/完成：Offer草稿；待遇要点；审核/发送/接受或拒绝。判据：Offer 关联候选人+需求，接受后转入职需幂等。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M16/P123/ | NOT RUN | Offer 接受/创建已入 manifest；敏感字段 P3 |
| 124 | M16 | M16-P124 | M16-P124-F01 | FOLD：旧 /recruitment/candidates/communications → /recruitment/candidates/:id（recruitment-candidates-by-id）；旧 URL 直达 catch-all /not-found | 候选人沟通 POST（source-only） | R08 RECRUITER / R02 / R06 | T18-REQ-01,T18-CAND-01,T18-OFFER-01,T18-INTERVIEW-01 | 上游 Org/People/Workflow；下游 Lifecycle/People/Contracts/Payroll/Training | T05,T15,T17,T18 / AC-04,13,14,15,21 | 入口 124「候选人沟通」执行后出现/完成：电话/面谈/通知沟通纪要和跟进提醒。判据：敏感联系方式授权，沟通记录与候选人绑定。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M16/P124/ | NOT RUN | 沟通写 source-only |
| 125 | M16 | M16-P125 | M16-P125-F01–M16-P125-F02 | FOLD：旧 /recruitment/candidates/talent-pool → /recruitment/candidates/:id（recruitment-candidates-by-id）；旧 URL 直达 catch-all /not-found | recruitment.candidates.talent-pool | R08 RECRUITER / R02 / R06 | T18-REQ-01,T18-CAND-01,T18-OFFER-01,T18-INTERVIEW-01 | 上游 Org/People/Workflow；下游 Lifecycle/People/Contracts/Payroll/Training | T05,T15,T17,T18 / AC-04,13,14,15,21 | 入口 125「人才库与标签」执行后出现/完成：标签管理与人才库筛选；归档/再激活。判据：候选人归属唯一主档，标签去重。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M16/P125/ | NOT RUN | — |
| 126 | M16 | M16-P126 | M16-P126-F01–M16-P126-F03 | FOLD：旧 /recruitment/candidates/convert → /recruitment/candidates/:id（recruitment-candidates-by-id）；旧 URL 直达 catch-all /not-found | recruitment.candidates.convert | R08 RECRUITER / R02 / R06 | T18-REQ-01,T18-CAND-01,T18-OFFER-01,T18-INTERVIEW-01 | 上游 Org/People/Workflow；下游 Lifecycle/People/Contracts/Payroll/Training | T05,T15,T17,T18 / AC-04,13,14,15,21 | 入口 126「录用转入职」执行后出现/完成：录用员工信息确认；原岗位和部门回填；转入职结果链接。判据：相同 Offer 不能重复生成员工，失败可恢复重试。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M16/P126/ | NOT RUN | Offer 只转一员工；幂等 |
| 127 | M17 | M17-P127 | M17-P127-F01–M17-P127-F04 | KEEP：/training（training） | training.program.create / programs.update | TRAINING_ADMIN / R02 / R06 / R07 | T18-TRAIN-PLAN-01,T18-COURSE-01,T18-SESSION-01,T18-ENROLL-01,T18-EXAM-01 | 上游 People/Org/Workflow；下游员工档案投影/Reports（不默认发钱） | T15,T17,T18 / AC-15,18,21 | 入口 127「培训计划」执行后出现/完成：培训计划列表；新建/编辑 Drawer；状态；…共4卡。判据：计划负责人与预算/适用人员范围明确。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M17/P127/ | NOT RUN | — |
| 128 | M17 | M17-P128 | M17-P128-F01–M17-P128-F06 | FOLD：旧 /training/courses → /training（training）；旧 URL 直达 catch-all /not-found | training.courses.update；课程新建 source-only | TRAINING_ADMIN / R02 / R06 / R07 | T18-TRAIN-PLAN-01,T18-COURSE-01,T18-SESSION-01,T18-ENROLL-01,T18-EXAM-01 | 上游 People/Org/Workflow；下游员工档案投影/Reports（不默认发钱） | T15,T17,T18 / AC-15,18,21 | 入口 128「课程库」执行后出现/完成：课程库列表；主题；时长；…共6卡。判据：课程版本与场次引用稳定，不直接删除在用课程。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M17/P128/ | NOT RUN | 课程写多数 source-only |
| 129 | M17 | M17-P129 | M17-P129-F01–M17-P129-F04 | FOLD：旧 /training/sessions → /training（training）；旧 URL 直达 catch-all /not-found | training.sessions.update；场次新建 source-only | TRAINING_ADMIN / R02 / R06 / R07 | T18-TRAIN-PLAN-01,T18-COURSE-01,T18-SESSION-01,T18-ENROLL-01,T18-EXAM-01 | 上游 People/Org/Workflow；下游员工档案投影/Reports（不默认发钱） | T15,T17,T18 / AC-15,18,21 | 入口 129「培训场次」执行后出现/完成：培训场次日历与列表；开课时间地点/容量；报名人数；…共4卡。判据：场次关联培训计划及课程，时间冲突校验。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M17/P129/ | NOT RUN | 场次写多数 source-only |
| 130 | M17 | M17-P130 | M17-P130-F01–M17-P130-F04 | FOLD：旧 /training/enrollments → /training（training）；旧 URL 直达 catch-all /not-found | training.enrollments.cancel；报名 POST source-only | TRAINING_ADMIN / R02 / R06 / R07 | T18-TRAIN-PLAN-01,T18-COURSE-01,T18-SESSION-01,T18-ENROLL-01,T18-EXAM-01 | 上游 People/Org/Workflow；下游员工档案投影/Reports（不默认发钱） | T15,T17,T18 / AC-15,18,21 | 入口 130「培训报名」执行后出现/完成：员工报名；取消；管理员代报名；…共4卡。判据：只有有权员工报名，重复报名幂等。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M17/P130/ | NOT RUN | 报名/取消；取消已入 manifest |
| 131 | M17 | M17-P131 | M17-P131-F01–M17-P131-F04 | FOLD：旧 /training/checkins → /training（training）；旧 URL 直达 catch-all /not-found | 签到 POST /v1/training/sessions/{id}/checkins（source-only） | TRAINING_ADMIN / R02 / R06 / R07 | T18-TRAIN-PLAN-01,T18-COURSE-01,T18-SESSION-01,T18-ENROLL-01,T18-EXAM-01 | 上游 People/Org/Workflow；下游员工档案投影/Reports（不默认发钱） | T15,T17,T18 / AC-15,18,21 | 入口 131「培训签到」执行后出现/完成：签到管理；员工到场时间；补签原因；…共4卡。判据：每签到对应有效报名和具体场次。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M17/P131/ | NOT RUN | 签到无 manifest action |
| 132 | M17 | M17-P132 | M17-P132-F01–M17-P132-F04 | FOLD：旧 /training/exams → /training（training）；旧 URL 直达 catch-all /not-found | 考试发布 POST /v1/training/exams/{id}/publish（source-only） | TRAINING_ADMIN / R02 / R06 / R07 | T18-TRAIN-PLAN-01,T18-COURSE-01,T18-SESSION-01,T18-ENROLL-01,T18-EXAM-01 | 上游 People/Org/Workflow；下游员工档案投影/Reports（不默认发钱） | T15,T17,T18 / AC-15,18,21 | 入口 132「培训考试」执行后出现/完成：培训考试；题目设置；提交评分；…共4卡。判据：仅对有效报名/在学员工开放考试，成绩历史保留。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M17/P132/ | NOT RUN | 考试发布无 manifest action |
| 133 | M17 | M17-P133 | M17-P133-F01–M17-P133-F02 | FOLD：旧 /training/feedback → /training（training）；旧 URL 直达 catch-all /not-found | 培训反馈 POST（source-only） | TRAINING_ADMIN / R02 / R06 / R07 | T18-TRAIN-PLAN-01,T18-COURSE-01,T18-SESSION-01,T18-ENROLL-01,T18-EXAM-01 | 上游 People/Org/Workflow；下游员工档案投影/Reports（不默认发钱） | T15,T17,T18 / AC-15,18,21 | 入口 133「培训反馈」执行后出现/完成：培训反馈问卷与评价；匿名规则/汇总。判据：记录关联场次，不泄漏受限评价人。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M17/P133/ | NOT RUN | 反馈无 manifest action |
| 134 | M17 | M17-P134 | M17-P134-F01–M17-P134-F04 | FOLD：旧 /training/records → /training（training）；旧 URL 直达 catch-all /not-found | 读取培训档案；员工详情培训 Tab 缺失（GAP-07） | TRAINING_ADMIN / R02 / R06 / R07 | T18-TRAIN-PLAN-01,T18-COURSE-01,T18-SESSION-01,T18-ENROLL-01,T18-EXAM-01 | 上游 People/Org/Workflow；下游员工档案投影/Reports（不默认发钱） | T15,T17,T18 / AC-15,18,21 | 入口 134「培训档案」执行后出现/完成：员工培训履历；课程；学时；…共4卡。判据：从报名签到与成绩自动生成，不再手工造重复档案。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M17/P134/ | NOT RUN | GAP-07：员工详情无培训记录 Tab |
| 135 | M17 | M17-P135 | M17-P135-F01–M17-P135-F04 | FOLD：旧 /training/reports → /training（training）；旧 URL 直达 catch-all /not-found | 读取培训报表/导出（GET） | TRAINING_ADMIN / R02 / R06 / R07 | T18-TRAIN-PLAN-01,T18-COURSE-01,T18-SESSION-01,T18-ENROLL-01,T18-EXAM-01 | 上游 People/Org/Workflow；下游员工档案投影/Reports（不默认发钱） | T15,T17,T18 / AC-15,18,21 | 入口 135「培训报表」执行后出现/完成：培训人数；完成率；课程使用量；…共4卡。判据：分母为参与员工/报名记录并注明统计口径。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M17/P135/ | NOT RUN | 报表只读 |
| 136 | M18 | M18-P136 | M18-P136-F01–M18-P136-F04 | KEEP：/notices（notices） | engagement.notices.create / submit / decide / publish-approved / archive / restore | R02 / R06 / R07 / R09 | T18-NOTICE-01,T18-NOTICE-TPL-01,T18-NOTIF-01 | 上游 IAM/Org/Workflow；下游通知、工作台、报表 | T16,T17,T18 / AC-05,17,18,19,21 | 入口 136「公告列表」执行后出现/完成：公告列表；正文预览；筛选状态；…共4卡。判据：仅发布公告对受众可见，保留历史。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M18/P136/ | NOT RUN | — |
| 137 | M18 | M18-P137 | M18-P137-F01 | FOLD：旧 /notices/approval → /notices（notices）；旧 URL 直达 catch-all /not-found | engagement.notices.decide / publish-approved | R02 / R06 / R07 / R09 | T18-NOTICE-01,T18-NOTICE-TPL-01,T18-NOTIF-01 | 上游 IAM/Org/Workflow；下游通知、工作台、报表 | T16,T17,T18 / AC-05,17,18,19,21 | 入口 137「公告审核」执行后出现/完成：公告提交/审核/驳回与审批历史。判据：审核者需实际待办，不许发布者绕过审核。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M18/P137/ | NOT RUN | — |
| 138 | M18 | M18-P138 | M18-P138-F01–M18-P138-F02 | FOLD：旧 /notices/audience → /notices（notices）；旧 URL 直达 catch-all /not-found | engagement.notices.audience-preview；受众保存 source-only | R02 / R06 / R07 / R09 | T18-NOTICE-01,T18-NOTICE-TPL-01,T18-NOTIF-01 | 上游 IAM/Org/Workflow；下游通知、工作台、报表 | T16,T17,T18 / AC-05,17,18,19,21 | 入口 138「公告发布范围」执行后出现/完成：选择公告接收部门/角色/指定员工；预览受众人数。判据：受众范围需按发布时有效组织关联计算。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M18/P138/ | NOT RUN | — |
| 139 | M18 | M18-P139 | M18-P139-F01–M18-P139-F03 | FOLD：旧 /notices/reads → /notices（notices）；旧 URL 直达 catch-all /not-found | 读取 reads；remind；回执/阅读 source-only | R02 / R06 / R07 / R09 | T18-NOTICE-01,T18-NOTICE-TPL-01,T18-NOTIF-01 | 上游 IAM/Org/Workflow；下游通知、工作台、报表 | T16,T17,T18 / AC-05,17,18,19,21 | 入口 139「公告阅读记录」执行后出现/完成：公告详情阅读/未阅读人数；回执列表；催阅。判据：阅读记录是原公告投递对象的回执。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M18/P139/ | NOT RUN | 回执列表 source-only；remind 已入 manifest |
| 140 | M18 | M18-P140 | M18-P140-F01–M18-P140-F03 | FOLD：旧 /notices/templates → /notices（notices）；旧 URL 直达 catch-all /not-found | engagement.notice-templates.create | R02 / R06 / R07 / R09 | T18-NOTICE-01,T18-NOTICE-TPL-01,T18-NOTIF-01 | 上游 IAM/Org/Workflow；下游通知、工作台、报表 | T16,T17,T18 / AC-05,17,18,19,21 | 入口 140「公告模板」执行后出现/完成：公告模板列表；创建/编辑 Dialog；停用与复制。判据：公告草稿引用模板时保留版本。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M18/P140/ | NOT RUN | — |
| 141 | M01 | M19-P141 | M19-P141-F01–M19-P141-F02 | KEEP：/account（account；源包页面归属 M19，入口归属按 §2.1 计 M01） | account 动作（见 M01-P005/P006；密码/会话） | R01 / R02 / R09 AUDITOR / R07 | T18-USERS,T18-ROLE-TPL,T18-ORG-01,T18-RUN-01 | 上游 IAM/Org；下游全部页面/API/导出授权 | T01,T02,T17,T18 / AC-03,05,18,21 | 入口 141「登录与账号安全」执行后出现/完成：账号密码修改；会话与账号设置导航。判据：单公司不增加 SSO/租户选择；MFA 仅可选。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M19/P141/ | NOT RUN | — |
| 142 | M19 | M19-P142 | M19-P142-F01–M19-P142-F04 | KEEP：/system/users（system-users） | system.users.create / update / assign.role（锚点 UI 缺失，GAP-05） | R01 / R02 / R09 AUDITOR / R07 | T18-USERS,T18-ROLE-TPL,T18-ORG-01,T18-RUN-01 | 上游 IAM/Org；下游全部页面/API/导出授权 | T01,T02,T17,T18 / AC-03,05,18,21 | 入口 142「用户管理」执行后出现/完成：用户列表；账号启停/重置密码；绑定员工；…共4卡。判据：用户与员工不必强制一对一；管理员不能自我越权授权。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M19/P142/ | NOT RUN | GAP-05：角色分配 UI 无 binding_anchors；R03/R05/R08 BLOCKED |
| 143 | M19 | M19-P143 | M19-P143-F01–M19-P143-F03 | KEEP：/system/roles（system-roles） | system.roles.create / copy / rename / delete / save-authorization | R01 / R02 / R09 AUDITOR / R07 | T18-USERS,T18-ROLE-TPL,T18-ORG-01,T18-RUN-01 | 上游 IAM/Org；下游全部页面/API/导出授权 | T01,T02,T17,T18 / AC-03,05,18,21 | 入口 143「角色管理」执行后出现/完成：角色列表；新建/复制/停用 Dialog；中文授权工作区四个维度。判据：授权保存原子操作；代码与 ADR 不显示业务界面。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M19/P143/ | NOT RUN | — |
| 144 | M19 | M19-P144 | M19-P144-F01 | FOLD：旧 /system/menus → /system/pages（system-pages）；旧 URL 直达 catch-all /not-found | system.pages.menu.create / update / reset | R01 / R02 / R09 AUDITOR / R07 | T18-USERS,T18-ROLE-TPL,T18-ORG-01,T18-RUN-01 | 上游 IAM/Org；下游全部页面/API/导出授权 | T01,T02,T17,T18 / AC-03,05,18,21 | 入口 144「菜单/页面管理」执行后出现/完成：菜单目录树，名称/顺序/图标/隐藏/路由配置。判据：删除或隐藏菜单不自动删除动作能力。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M19/P144/ | NOT RUN | — |
| 145 | M19 | M19-P145 | M19-P145-F01 | FOLD：旧 /system/permissions → /system/pages（system-pages）；旧 URL 直达 catch-all /not-found | 角色授权页面/动作（PUT /v1/roles/{id}/authorization；source-only UI） | R01 / R02 / R09 AUDITOR / R07 | T18-USERS,T18-ROLE-TPL,T18-ORG-01,T18-RUN-01 | 上游 IAM/Org；下游全部页面/API/导出授权 | T01,T02,T17,T18 / AC-03,05,18,21 | 入口 145「操作权限配置」执行后出现/完成：按模块展示页面→按钮动作矩阵，授权与影响预览。判据：只看页面不拥有按钮；后端 API 不受 UI 绕过。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M19/P145/ | NOT RUN | 页面/动作授权走角色授权保存 |
| 146 | M19 | M19-P146 | M19-P146-F01 | FOLD：旧 /system/data-scopes → /system/roles（system-roles）；旧 URL 直达 catch-all /not-found | 角色数据范围保存（PUT /v1/roles/{id}/authorization） | R01 / R02 / R09 AUDITOR / R07 | T18-USERS,T18-ROLE-TPL,T18-ORG-01,T18-RUN-01 | 上游 IAM/Org；下游全部页面/API/导出授权 | T01,T02,T17,T18 / AC-03,05,18,21 | 入口 146「数据范围权限」执行后出现/完成：角色组织范围：本人/本部门含子级/全公司/自选部门。判据：查询、详情、导出、下拉选择同一数据边界。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M19/P146/ | NOT RUN | 数据范围授权 |
| 147 | M19 | M19-P147 | M19-P147-F01–M19-P147-F03 | FOLD：旧 /system/field-policies → /system/roles（system-roles）；旧 URL 直达 catch-all /not-found | 角色字段级权限保存（PUT /v1/roles/{id}/authorization） | R01 / R02 / R09 AUDITOR / R07 | T18-USERS,T18-ROLE-TPL,T18-ORG-01,T18-RUN-01 | 上游 IAM/Org；下游全部页面/API/导出授权 | T01,T02,T17,T18 / AC-03,05,18,21 | 入口 147「字段级权限」执行后出现/完成：工资/证件/联系方式等字段授权；脱敏级别；审计。判据：字段裁剪在后端发生，不止前端打星。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M19/P147/ | NOT RUN | 字段级授权；后端裁剪 |
| 148 | M20 | M20-P148 | M20-P148-F01–M20-P148-F02 | KEEP：/workflow（workflow） | workflow.case.decide / withdraw / effect.retry / template.version.create | R06 / R02 / R05 / R07 | T18-WF-TPL-01,T18-CASE-01,T18-EFFECT-FAIL-01 | 上游业务单据/模板；下游领域 Effect、通知、审计 | T02,T07,T11,T13,T18 / AC-13,19,21 | 入口 148「审批流程中心」执行后出现/完成：我的待办/我发起/已处理/模板设置列表；审批操作。判据：申请人/申请对象/审批人分别记录，审批人必须受分派。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M20/P148/ | NOT RUN | — |
| 149 | M20 | M20-P149 | M20-P149-F01–M20-P149-F05 | KEEP：/workflow/cases/:id（workflow-case-detail） | 读取审批详情；decide/withdraw/retry source-only（详情 actions=[]） | R06 / R02 / R05 / R07 | T18-WF-TPL-01,T18-CASE-01,T18-EFFECT-FAIL-01 | 上游业务单据/模板；下游领域 Effect、通知、审计 | T02,T07,T11,T13,T18 / AC-13,19,21 | 入口 149「审批详情」执行后出现/完成：审批详情；申请摘要；证据；…共5卡。判据：重复决定幂等，审批结果与业务单据一致。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M20/P149/ | NOT RUN | 详情 actions=[]（GAP-15） |
| 150 | M01 | M01-P150 | M01-P150-F01–M01-P150-F03 | FOLD：旧 /self-service → /workspace（workspace）；旧 URL 直达 catch-all /not-found | 读取本人聚合（无专用 manifest；source-only 聚合入口） | R01 SYSTEM_ADMIN / R02 HR_ADMIN / R07 EMPLOYEE / 未绑定管理员 | T18-EMP-A,T18-SESSION-01,T18-MFA-OFF,T18-UNLINKED-ADMIN | 上游 IAM 凭据/会话 + People employment link；下游全部受保护页面与菜单 | T01,T02,T17,T18 / AC-01,02,03,05,18,19,21 | 入口 150「员工自助门户」执行后出现/完成：我的员工档案；我的考勤请假；工资条与培训等自助聚合。判据：本人查询由 session 绑定 employee，角色权限动态显示。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M01/P150/ | NOT RUN | FOLD 到 /workspace；旧 /self-service 直达为 catch-all /not-found |
| 151 | M14 | M14-P151 | M14-P151-F01–M14-P151-F02 | KEEP：/benefits（benefits） | benefit.plan.create / enrollment.create / transaction.record；法定参数 source-only | BENEFITS_ADMIN / R04 / R02 / R07 | T18-EMP-A/B,T18-BENEFIT-PLAN-01,T18-BEN-ENR-01,T18-SI-2026 | 上游 People/Payroll/Statutory；下游 Payroll/Reporting | T09,T14,T18 / AC-06,07,14,20,21 | 入口 151「社保公积金与福利」执行后出现/完成：社保参数/福利方案/参保登记/福利流水版本化列表；维护 Drawer。判据：所有员工引用同一参数主源，历史薪资保持版本。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M14/P151/ | NOT RUN | BENEFITS_ADMIN 未 seed；参数写动作未入 manifest（GAP-05/GAP-15） |
| 152 | M21 | M21-P152 | M21-P152-F01–M21-P152-F03 | FOLD：旧 /system/calendars → /system/settings（system-settings）；旧 URL 直达 catch-all /not-found | system.settings.calendar.publish | R01 / R09 / R02 | T18-COMPANY-01,T18-CAL-01,T18-DICT-01,T18-PARAM-01,T18-AUD-01,T18-HEALTH-01 | 上游 Platform/IAM；下游全部模块、日历、Payroll、Reporting、Audit | T01,T02,T16,T18,T19 / AC-02,05,16,18,19,21,22 | 入口 152「节假日与工作日历」执行后出现/完成：工作日历：节假日；工作日例外；版本发布与覆盖范围。判据：请假、考勤、薪资按同一公司日历计算。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M21/P152/ | NOT RUN | — |
| 153 | M21 | M21-P153 | M21-P153-F01 | KEEP：/system/settings（system-settings） | company-profile.save / dictionary.update / feature.update / parameter.update | R01 / R09 / R02 | T18-COMPANY-01,T18-CAL-01,T18-DICT-01,T18-PARAM-01,T18-AUD-01,T18-HEALTH-01 | 上游 Platform/IAM；下游全部模块、日历、Payroll、Reporting、Audit | T01,T02,T16,T18,T19 / AC-02,05,16,18,19,21,22 | 入口 153「字典、参数与文件」执行后出现/完成：业务字典/参数/文件容量与访问设置列表及编辑 Drawer。判据：不把原始 code 作为前端标签，不在业务页显示 API 提示。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M21/P153/ | NOT RUN | — |
| 154 | M18 | M18-P154 | M18-P154-F01–M18-P154-F04 | KEEP：/notifications（notifications） | notification read；notifications.delivery.retry；通知已读 source-only | R02 / R06 / R07 / R09 | T18-NOTICE-01,T18-NOTICE-TPL-01,T18-NOTIF-01 | 上游 IAM/Org/Workflow；下游通知、工作台、报表 | T16,T17,T18 / AC-05,17,18,19,21 | 入口 154「消息与通知中心」执行后出现/完成：消息列表；已读未读；按业务来源跳转；…共4卡。判据：消息仅辅助提醒，权限撤销后不可经通知链接越权。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M18/P154/ | NOT RUN | 通知已读 source-only；delivery retry 已入 manifest |
| 155 | M21 | M21-P155 | M21-P155-F01–M21-P155-F02 | KEEP：/system/audit（system-audit） | system.audit.export；备份/恢复状态只读（GAP-06） | R01 / R09 / R02 | T18-COMPANY-01,T18-CAL-01,T18-DICT-01,T18-PARAM-01,T18-AUD-01,T18-HEALTH-01 | 上游 Platform/IAM；下游全部模块、日历、Payroll、Reporting、Audit | T01,T02,T16,T18,T19 / AC-02,05,16,18,19,21,22 | 入口 155「日志审计与运维」执行后出现/完成：审计筛选：操作人/对象/时间/结果，查看脱敏差异；导出与备份状态。判据：业务使用者看不到系统堆栈，审计日志不可改写。刷新后仍为同一授权范围/状态；无权限或跨范围直接请求被拒绝且不产生数据；失败显示中文与 request_id，不显示空/0。 | evidence/MCP-M21/P155/ | NOT RUN | GAP-06：备份/恢复/升级无 UI，T19 接管；审计导出已入 manifest |


### 4A.1 台账核验

```text
台账行数：155
入口号唯一：155
页面 ID 唯一：155
功能卡 ID：458（分布与 458-card-index.md 一致）
FOLD：120；KEEP：35（与 V2.3 处置矩阵一致）
执行结论：全部 NOT RUN
```

## 4B. 21 模块可判定预期结果

> 本表把每个模块从“通用操作清单”收紧为可判定的业务结果。每条都由该模块 155 行台账中的具体入口/卡片推导；不要求自动测试通过，也不预先判定 PASS。执行时必须按 `mcp-runbook.md` 记录实际值，并按 evidence 模板逐项比对。

| 模块 | 最低可判定预期（全部必须实际观察） | 失败/阻断判定 |
|---|---|---|
| M01 | 正确密码登录 200/202；错误密码与锁定统一中文且不泄露用户存在性；回车提交等价点击；退出后旧标签页下一次请求 401；刷新/重登后角色=实际绑定；无任职管理员只有管理工作台、不能伪造“我的申请”；会话撤销后目标会话立即失效；MFA=false 不阻塞，MFA=true 才出现挑战 | 任何“登录成功但无真实会话”、管理员伪造本人员工、撤销后仍可写、关闭 MFA 被阻塞即 FAIL |
| M02 | dashboard 指标值与源事实一致；零值、未配置、计算失败三态可区分；日期/部门筛选改变真实口径；点击下钻落到正确源对象；无薪资权限不显示金额且不能由其他字段反推；部门经理只看 MANAGED 范围 | 查询失败显示 0/空图、指标与源事实不符、越权可见金额即 FAIL |
| M03 | 至少一个报表定义可执行并返回真实行；零数据不是查询失败；列预览字段与导出字段一致；导出下载再次鉴权；撤权后旧导出链接 403；工资列只对授权角色可见；导出任务状态与文件真实对应 | 失败显示空表、下载不重新鉴权、导出泄露未授权列即 FAIL |
| M04 | 新建员工产生唯一 employee_no 且 Person/Employment/Assignment/Terms 关联；同名员工不误绑；跨组织详情 404；子档案归属当前员工；归档/恢复保留历史；导入逐行校验且无静默部分成功；刷新保留筛选/分页 | 同名误绑、半建档、跨组织可见、导入静默成功即 FAIL |
| M05 | 部门树无环、编码唯一；新建部门可见；成员来自真实任职；移动前显示子树/任职/权限影响，确认后历史 as-of 不漂移；有在岗人员不可硬删；编制数等于编制事实而非员工数副本 | 成环、移动后历史漂移、成员被另建一套、在岗硬删即 FAIL |
| M06 | 岗位编码唯一；新建/编辑后详情与列表一致；在用岗位停用被 409 拒绝；资格版本追加且历史可查；在岗人员来自 assignment；建议薪酬不写个人工资 | 覆盖历史资格、在岗人员手写副本、建议薪酬自动进工资即 FAIL |
| M07 | 异动草稿、提交、审批、生效投影分阶段；未来生效不提前；撤回有原因且状态可追溯；Effect 失败可见并可重试；重复提交/决定不产生第二份任职或合同；异动详情深链可刷新 | 审批前更新 assignment、Effect 失败被吞、重复执行产生两份业务即 FAIL |
| M08 | 合同关联正确员工；同员工有效期不重叠；未来生效不提前；续签生成新版本而非覆盖；变更/终止保留历史；附件单独鉴权；activate 仅在真实 UI 入口存在时可验收（当前无按钮则 UNMAPPED） | 同名误绑、覆盖历史、终止可逆、用 API 冒充 UI 即 FAIL/BLOCKED |
| M09 | 班次/规则/排班真实持久化；补卡产生 case 且审批后才改日结果；已计薪事实只走更正；关账冻结、重开受控；重算与明细一致；原始打卡导出再次鉴权；无设备集成时设备入口隐藏 | 未审批改日结果、关账后静默改数、重复计算、无入口却伪造打卡即 FAIL/UNMAPPED |
| M10 | 本人 subject 固定 SELF；代申请显式选择员工且 actor≠subject；预览与提交结果一致；余额变化有流水；跨月按天拆分；已结算撤销走修正流程；无权限角色看不到代申请 | 可选他人 subject、余额直接改总数、已结算静默撤销即 FAIL |
| M11 | 本人/代申请按角色；确认记录确认人；PAY/TIMEOFF 互斥且只结算一次；调休余额由流水产生；冻结工资期间结算被拒；重复确认幂等/409 | 双重结算、余额手改、冻结期静默补发即 FAIL |
| M12 | 出差日期与请假/打卡/加班不重叠；审批只给真实待办人；变更/销单保留历史；费用登记关联出差单且不等于银行支付；统计与明细同口径 | 重复日期、超管任意审批、费用重复/冒充支付即 FAIL |
| M13 | 批次状态按实际 `PayrollRunStatus`：DRAFT→INPUT_LOCKED→CALCULATED→REVIEWED→APPROVED→PUBLISHED→PAID→CLOSED（CANCELLED 为终态分支）推进；缺来源显示阻断原因而非 0；月薪 A 金额 8000/380/600/500/9480/400/1400/8080/9980 可逐项核对；月中 10 天 8000+10 天 9000=8500；更正累计 +220 再 +50；发布/发放正交；SELF 只看本人已发布；金额整数分/界面两位小数 | 任一金额不符、覆盖已发布、缺来源显示 0、SELF 越权、重复发放即 FAIL |
| M14 | 法定参数只一个主源；福利方案/参保/流水按员工与期间唯一；个人/单位金额进入工资 Cost；重复冲销 409；已发布快照不因费率更新漂移；无参数时阻断并说明 | 第二套参数写入口、历史批次漂移、重复冲销即 FAIL |
| M15 | 周期/模板/指标/目标/评分/校准/结果/申诉按周期+员工归属；员工仅自评；主管仅 MANAGED；提交后无痕修改被拒；发布后冻结；仅已发布结果可申诉；草稿不进报表/工资 | 草稿发钱、发布后可改、越权评分/申诉即 FAIL |
| M16 | 需求受编制/部门约束；审批人是真实待办；Offer 关联候选人+需求；同一 Offer 两次转入职只建一个员工；面试/评价只对真实参与者；关闭需求拒绝新流程；候选人详情可深链 | 重复建档、需求关闭后仍可 Offer、非面试官评价即 FAIL |
| M17 | 未报名不能签到/考试；取消释放名额；签到必须关联有效场次+报名；考试发布有版本与成绩历史；培训档案只由有效来源生成；培训不默认改岗位或发钱；员工详情培训 Tab 缺失则 UNMAPPED | 无报名生成签到/成绩、取消后仍占名额、培训津贴误发即 FAIL |
| M18 | 仅已发布公告对有效受众可见；受众预览人数与发布后可见一致；审核人必须是真实待办；回执对象为原投递对象；撤回/过期/撤权后旧链接不泄露；通知不含工资金额；外部失败可重试且不重复 | 未发布可见、受众外可见、通知泄露金额、撤回后旧链可读即 FAIL |
| M19 | 用户/角色/页面/动作/数据范围/字段五层授权可保存并刷新生效；内置角色只读可复制；授权原子保存；expected_version 冲突 409；不能自我越权；删除最后管理员 409；撤权后旧标签页下一次写 403；角色中文可读 | 只看页面即拥有按钮、隐藏按钮可绕过、撤权后仍写、锚点 UI 无法构造即 FAIL/BLOCKED |
| M20 | 申请人/对象/审批人分别记录；待办只给真实 assignee；决定幂等且业务 Effect 结果一致；非审批人拒绝；效果失败可见可重试；重复决定/重试不产生二次业务；详情深链可刷新 | 审批通过即冒充业务完成、非 assignee 可批、重复 Effect 即 FAIL |
| M21 | 公司资料/日历/字典/参数保存后刷新仍生效；日历发布版本被请假/考勤/薪资读取；字典不显示原始 code；健康 ready 六项全 true；审计只读、不可改写、字段导出无 token/密码；备份/恢复/升级无 UI 则 BLOCKED 并转 T19 | 日历版本不一致、审计可改、导出泄露敏感字段、用 API/脚本冒充 MCP 即 FAIL/BLOCKED |

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

> 以上是每模块最低要求；AC-21 还要求覆盖 155 个历史入口 dispositions、458 卡（已提取、权威性待确认）和 12 个跨模块闭环。

---

## 9. 本矩阵自身的执行结论

- **21 模块唯一映射：** 已建立。
- **155 历史入口唯一引用：** 已建立；编号 1–155 无缺号、无重复。
- **37 当前页面：** 已建立；与 `registry.ts` 实测一致。
- **132 action manifest：** 已建立；模块级全部引用，另有页面可见未入 manifest 写动作，须在 MCP 中核实。
- **458 功能卡：** 已从源包完整提取 155 页/458 个唯一卡 ID 与标题（`458-card-index.md`）；源包权威性待确认，逐卡执行结论仍为 `NOT RUN`。
- **T01–T24：** T01–T19 有 master plan 任务卡；T20–T24 `UNMAPPED`。
- **MCP 执行：** **零项执行**；本文件不包含任何 PASS 结论。
- **155 行台账：** 已建立；每行含页面 ID、卡范围、当前页面、动作、角色、前置、上下游、T/AC、具体预期、证据槽、执行结论；执行结论全部 `NOT RUN`。
