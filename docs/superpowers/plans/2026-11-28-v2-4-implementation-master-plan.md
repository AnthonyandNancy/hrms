# FactoryHR V2.4 Implementation Master Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** IN PROGRESS — execution may be resumed by a different agent from the status table and execution log below.

**Goal:** 在不重新定义已确认需求与架构的前提下，把 `docs/FactoryHR_V2.4_需求与技术设计说明书.md` 的 Requirement + Design 落成可运行、可验证、可恢复执行的代码整改，最终以 AC-01–AC-22 的真实行为验收，而不是以“计划完成”判定完成。

**Architecture:** 保留 Laravel 13 / PHP 8.4 模块化单体、Vue 3 + TypeScript + Element Plus 和单公司权限模型。按“基线与治理 → 合同/薪酬事实链 → Payroll 规则与批次 → Time 事实与审批 Effect → 跨模块输入 → 前端动作与自助 → 真实栈/MCP 验收”的真实依赖顺序实现，所有跨模块协作只经 `Contracts` 端口。

**Tech Stack:** Laravel 13, PHP 8.4, MySQL 8 / SQLite test database, Vue 3.5, TypeScript, Pinia, Element Plus, PHPUnit 12, Vitest, Playwright, OpenAPI 3.1, bcmath/Decimal, Redis/object storage for the final real-stack gate.

---

## 0. 执行者必读与恢复协议

### 0.1 Source of truth

优先级从高到低：

1. 用户本轮明确指令；
2. `AGENTS.md`；
3. `docs/FactoryHR_V2.4_需求与技术设计说明书.md` 的 Requirement 和已确认 Design；
4. 本计划；
5. V2.3 历史规格与现有实现。

本计划不是第二轮需求分析，任何会改变业务口径、事实所有权、状态语义或验收标准的发现，必须停下并输出 `# Plan 偏差`，不能私自改设计。

### 0.2 每个任务的统一执行循环

每个任务都必须按下列顺序执行，不能先写生产代码再补测试：

1. 读取任务列出的 current files，确认行号和当前行为；若文件已变化，只更新计划中的位置，不改变任务语义。
2. 写 failing tests，先在任务 `Test` 文件增加用例。
3. 运行精确测试并确认失败原因与预期一致（RED）。
4. 写最小生产实现，复用现有 `CommandRunner`、`Money`、`Decimal`、`Clock`、`Workflow`、`Auditor`、`Outbox` 能力。
5. 运行精确测试到 GREEN，再运行相邻回归。
6. 更新 OpenAPI、capability/action manifest、前端类型、页面动作和审计规则（只有任务涉及 API/UI 时才更新）。
7. 运行该任务的 `Verification` 命令，保存命令、exit code、关键输出和未执行项到 `docs/implementation/v24/`。
8. 将本文件对应 checkbox 勾选，并在 §0.5 执行日志追加一行；若中断，保留 `IN PROGRESS` 和最后通过的步骤号。
9. 只有独立可签收的 Task 才提交；提交前确认代码、测试、契约、证据四者一致。

### 0.3 状态枚举

- `PENDING`：尚未开始。
- `IN PROGRESS`：至少一个步骤已执行但未全部通过；恢复时从第一个未勾选步骤继续。
- `BLOCKED`：存在任务内无法自行决定的冲突；必须按 §0.4 输出偏差并记录阻塞原因。
- `COMPLETE`：全部 checkbox、精确测试、关联回归、证据文件完成；不要求整个系统 GO。
- `DEFERRED`：只允许用户明确批准；AI 不得自行标记。

### 0.4 计划偏差模板

```text
# Plan 偏差

发现：
...

原 Plan：
...

实际代码：
...

影响：
...

建议：
...
```

小偏差（文件移动、已有方法可复用、无需修改）可直接改计划并记录原因；大偏差（表模型假设错误、跨模块所有权冲突、核心流程无法实现、需要改 Requirement）必须停止该任务。

### 0.5 执行状态表

| Task | 工作包 | 状态 | 最后通过步骤 | 证据 |
|---|---|---|---|---|
| T00 | 基线与 ADR | COMPLETE | Step 4 | `docs/implementation/v24/00-baseline/gate-all.log` |
| T01 | 安装状态与干净初始化 | COMPLETE | Step 5 | `docs/implementation/v24/01-install/evidence.md`; gate log |
| T02 | 权限单一目录与对象动作 | COMPLETE | Step 5 | `docs/implementation/v24/02-action-catalog/evidence.md`; gate log |
| T03 | 合同约定版本 | COMPLETE | Step 5 | `docs/implementation/v24/03-contracts/evidence.md` |
| T04 | 员工执行薪酬版本 | COMPLETE | Step 5 | `docs/implementation/v24/04-compensation-chain/evidence.md` |
| T05 | 单一入职与合同/薪酬待办 | COMPLETE | T05a + T05b | `da57e57`; `docs/implementation/v24/05-onboarding/implementation-report.md`; `gate-all-t05b.log` |
| T06 | Time 月度事实版本 | COMPLETE | Step 5 + hardening review | `docs/implementation/v24/06-time-facts/implementation-report.md`；MySQL 并发验证留 T19 |
| T07 | 请假审批 Effect 与净流水 | COMPLETE | T07a/T07b/T07c | `2ae0378`; `docs/implementation/v24/07-leave-effects/implementation-report.md`; `gate-all-t07c.log` |
| T08 | 加班/调休/出差事实 | IN PROGRESS | T08a ledger/travel facts merged; overtime Workflow/effects and travel revision API pending | `236a6e3`; `docs/implementation/v24/08-overtime-travel/implementation-report.md`; `gate-all-t08a.log` |
| T09 | Snapshot v2 与员工级方案 | COMPLETE | T09a-T09d | `3cb50af`; `docs/implementation/v24/09-payroll-snapshot/implementation-report.md`; `gate-all-t09d2.log`; T14/T15 must replace default adapters and rerun |
| T10 | 受限规则引擎 | COMPLETE | T10a-T10f | `89943ef`; `docs/implementation/v24/10-payroll-rules/implementation-report.md`; `gate-all-t10f.log`; MCP in T18 |
| T11 | 批次成员、阻断与正交状态 | COMPLETE | T11a/T11b1/T11b2 | `0dfd885`; `docs/implementation/v24/11-payroll-runs/implementation-report.md`; `gate-all-t11b2.log`; member form polish in T17 |
| T12 | 月中分段与周期级税费 | COMPLETE | T12a/T12b | `8b97b38`; `docs/implementation/v24/12-midmonth/implementation-report.md`; `gate-all-t12b.log` | MCP still required for end-to-end acceptance. |
| T13 | 累计更正与 signed YTD | COMPLETE | T13a-T13d | `207cb39`; `docs/implementation/v24/13-corrections/implementation-report.md`; `gate-all-t13d.log`; MCP in T18 |
| T14 | 福利计薪输入与冲销 | IN PROGRESS | T14a-T14c merged; UI reversal done; field-profile masking pending | `894f056`; `docs/implementation/v24/14-benefits/implementation-report.md`; `gate-all-t14-ui.log` |
| T15 | 绩效奖金与 Offer/培训链路 | COMPLETE | T15a-T15e | `b848a46`; `docs/implementation/v24/15-performance-bonus/implementation-report.md`; `gate-all-t15e.log`; MCP in T18 |
| T16 | 通知状态与报表字段裁剪 | BLOCKED | T16a/T16b/T16c 拆分；分支未合并 | `79de276` on `v24-t16-engagement-reporting`; review findings |
| T17 | 前端精确金额、动作与自助 | PENDING | — | — |
| T18 | Playwright MCP 全后台验收 | IN PROGRESS | 验收资产已合并；MCP 全部 NOT RUN | `docs/implementation/v24/18-mcp-acceptance/` |
| T19 | 真实栈、迁移恢复与发布判定 | PENDING | — | — |

### 0.6 执行日志

| 时间 | Task/Step | 命令或操作 | 结果 | 下一位执行者注意事项 |
|---|---|---|---|---|
| 2026-11-28 | T00 planning baseline | `pnpm gate:all` | FAIL at `backend:phpunit`; 1490 tests, 8 failures | 先修 §T00 的 8 个真实契约漂移，不得绕过 |
| 2026-11-28 | T00 Step 4 | focused PHPUnit + `pnpm gate:all` | Focused 29 tests PASS; full 22-gate matrix PASS in 238.9s; evidence at `docs/implementation/v24/00-baseline/gate-all.log` | T00 complete. Continue with T01. |
| 2026-11-28 | T01 Step 5 | `phpunit --filter 'Install|Health|SyncCapabilities|DemoSeed'`; `pnpm gate:all` | 28 adjacent tests PASS; 22 gates PASS in 222.5s; evidence at `docs/implementation/v24/01-install/evidence.md` | T01 complete. Continue with T02; do not claim AC-02 until real-stack/MCP evidence. |
| 2026-11-28 | T02 Step 5 | 38 backend tests + 652 frontend tests + `pnpm gate:all` | 22 gates PASS in 245.3s; generated PHP/JSON/TS action artifacts; evidence at `docs/implementation/v24/02-action-catalog/evidence.md` | T02 complete with contract/Time detail wiring explicitly carried into T03/T07/T08. Continue T03. |
| 2026-11-28 | T03/T04 Step 5 | combined focused 34 tests + full `pnpm gate:all` | 22 gates PASS in 247.7s; evidence in `docs/implementation/v24/03-contracts/` and `04-compensation-chain/` | Contract ladder and execution compensation link complete. Payroll still reads terms until T09. |
| 2026-11-28 | T06 hardening review | 58fbe80 + 33 fact tests + independent re-review | Spec ✅ / Approved-with-risk; MySQL true concurrency explicitly deferred T19; final full gate pending T16/T07 merges | Do not claim concurrency verified until T19. |
| 2026-11-28 | T18 acceptance assets | merged 1517c56 | 155/458 matrix, 21-module runbook and evidence index merged; all MCP conclusions NOT RUN; R02–R05/R08 anchor setup BLOCKED | T18 remains IN PROGRESS until real MCP execution. |
| 2026-11-28 | T05 re-scope | Mini Plan created | Legacy direct onboarding will become a Lifecycle facade in three small steps; contract/compensation drafts deferred to T05b | Execute after current parallel branches merge; do not claim T05 complete. |
| 2026-11-28 | T06 final gate | clean `pnpm gate:all` on merged main | 22 gates PASS in 344.3s; log `docs/implementation/v24/06-time-facts/gate-all-final.log` | T06 approved-with-risk; MySQL concurrency/T19 outstanding. |
| 2026-11-28 | T05a onboarding merge | `a304379` + full gate | 22 gates PASS in 272.6s; encrypted PII draft, 202 pending Case, capability/org scope, pending dedup; log `docs/implementation/v24/05-onboarding/gate-all.log` | T05b followed. |
| 2026-11-28 | T05b contract/comp drafts | `da57e57` + full gate | 22 gates PASS in 225.1s; contract DRAFT+CREATION version and compensation DRAFT linked to case/contract; rollback test; log `docs/implementation/v24/05-onboarding/gate-all-t05b.log` | T05 complete; payroll activation remains T09/T11. |
| 2026-11-28 | T07b merge | `0209113` + full gate | 22 gates PASS in 247.6s; real leave apply/reverse + outcome release, narrow admin auth, template v2; log `docs/implementation/v24/07-leave-effects/gate-all-t07b.log` | T07c followed. |
| 2026-11-28 | T07c merge | `2ae0378` + full gate | 22 gates PASS in 244.3s; reversal audit/outbox/link/unique, UNRESOLVED boundary, company business dates; log `docs/implementation/v24/07-leave-effects/gate-all-t07c.log` | T07 complete; Snapshot closed-fact consumption remains T09. |
| 2026-11-28 | T09a/T09b merge | `87b3e91` + full gate | 22 gates PASS in 288.1s; execution compensation and closed Time fact evidence/blocker in Snapshot v2; log `docs/implementation/v24/09-payroll-snapshot/gate-all-t09ab.log` | T09c followed. |
| 2026-11-28 | T09c merge | `8ad0070` + full gate | 22 gates PASS in 287.1s; BLOCKED employees and CALCULATED_WITH_BLOCKERS; payroll feature+integration 104 tests; log `docs/implementation/v24/09-payroll-snapshot/gate-all-t09c.log` | Benefits/performance frozen inputs and full employee-scheme paths remain T09d. |
| 2026-11-28 | T09d1 status exposure | `2101916` + full gate | 22 gates PASS in 240.1s; OpenAPI/TS/payroll UI expose CALCULATED_WITH_BLOCKERS and BLOCKED; log `docs/implementation/v24/09-payroll-snapshot/gate-all-t09d1.log` | T09d2 followed. |
| 2026-11-28 | T09d2 merge | `3cb50af` + full gate | 22 gates PASS in 237.7s; benefit/performance frozen ports, fake adapter calculation proof; log `docs/implementation/v24/09-payroll-snapshot/gate-all-t09d2.log` | T09 complete; T14/T15 must replace adapters and rerun. |
| 2026-11-28 | T11a run members | `6a4df39` + full gate | 22 gates PASS in 236.3s; payroll_run_members, PREPARER source, preassigned REVIEWER access, detail membership; log `docs/implementation/v24/11-payroll-runs/gate-all-t11a.log` | T11b followed. |
| 2026-11-28 | T11b1 orthogonal states | `5ead6a6` + full gate | 22 gates PASS in 241.3s; publish/disbursement flags independent, close prerequisites, YTD-after-disburse trigger; log `docs/implementation/v24/11-payroll-runs/gate-all-t11b1.log` | T11b2 followed. |
| 2026-11-28 | T11b2 member actions | `0dfd885` + full gate | 22 gates PASS in 241.3s; member HTTP/actions, blocked/orthogonal action projection; log `docs/implementation/v24/11-payroll-runs/gate-all-t11b2.log` | T11 complete; member form visual polish in T17. |
| 2026-11-28 | T12a mid-month segments | `9cb0709` + full gate | 22 gates PASS in 238.6s; 8500 golden weighted base from two compensation segments; log `docs/implementation/v24/12-midmonth/gate-all-t12a.log` | T12b followed. |
| 2026-11-28 | T12b segment evidence | `8b97b38` + full gate | 22 gates PASS in 234.5s; once-only SI/HF/tax control, segment provenance/UI, overlap/gap blockers; log `docs/implementation/v24/12-midmonth/gate-all-t12b.log` | T12 complete; MCP AC evidence still pending. |
| 2026-11-28 | T13a/T13b signed YTD | `6dc0a81` + full gate | 22 gates PASS in 284.2s; signed append-only ledger, negative carry-forward, idempotent publish, correction chain identity and UI; log `docs/implementation/v24/13-corrections/gate-all-t13ab.log` | T13c followed. |
| 2026-11-28 | T13c cumulative target/delta | `55566e2` + full gate | 22 gates PASS in 237.7s; AC-12 exact 8080→8300→8350, +220/+50, target/baseline/delta/cumulative and UI; log `docs/implementation/v24/13-corrections/gate-all-t13c.log` | T14 followed. |
| 2026-11-28 | T14 benefit linkage | `4bc2edb` + full gate | 22 gates PASS in 243.2s; multi-plan/period transactions, append-only reversal, real benefit input port, employer cost lines, frozen-snapshot regression; log `docs/implementation/v24/14-benefits/gate-all-t14.log` | T14 UI followed. |
| 2026-11-28 | T14 reversal UI | `894f056` + full gate | 22 gates PASS in 237.5s; ledger reversal button/prompt/API/idempotency and Chinese failure copy; log `docs/implementation/v24/14-benefits/gate-all-t14-ui.log` | T15 followed. |
| 2026-11-28 | T15a performance bonus | `6527940` + full gate | 22 gates PASS in 236.6s; published snapshot grade/revision, approved bonus mapping, real Payroll port, DRAFT/future exclusion; log `docs/implementation/v24/15-performance-bonus/gate-all-t15a.log` | T15b followed. |
| 2026-11-28 | T15b Offer compensation | `f4d11f8` + full gate | 22 gates PASS in 239.4s; offer salary/scheme→onboarding→one EmployeeCompensation draft; log `docs/implementation/v24/15-performance-bonus/gate-all-t15b.log` | T15c followed. |
| 2026-11-28 | T15c mapping/exam attempts | `04bf8ab` + full gate | 22 gates PASS in 247.7s; bonus mapping API/list/approve, training attempt source/checkin enforcement, completion derives from latest attempt; log `docs/implementation/v24/15-performance-bonus/gate-all-t15c.log` | T15d followed. |
| 2026-11-28 | T15d attempt HTTP | `4eb491b` + full gate | 22 gates PASS in 240.8s; POST exam attempts, capability/action/OpenAPI/ledger, source-hash HTTP regression; log `docs/implementation/v24/15-performance-bonus/gate-all-t15d.log` | T15e followed. |
| 2026-11-28 | T15e performance/training UI | `b848a46` + full gate | 22 gates PASS in 242.7s; bonus mapping dialog + approve, attempt form/source result; log `docs/implementation/v24/15-performance-bonus/gate-all-t15e.log` | T15 complete; MCP remains T18. |
| 2026-11-28 | T10a rule engine core | `bc3335e` + full gate | 22 gates PASS in 234.9s; strict AST whitelist/depth/node/variable validation, Decimal evaluator, IF/TIER/MIN/MAX/ROUND trace; log `docs/implementation/v24/10-payroll-rules/gate-all-t10a.log` | T10b followed. |
| 2026-11-28 | T10b SchemeItem execution | `80e06f2` + full gate | 22 gates PASS in 236.0s; FIXED/PERCENT/ATTENDANCE_PRORATE/FORMULA_LIMITED, formula snapshot column, blocked invalid formula; log `docs/implementation/v24/10-payroll-rules/gate-all-t10b.log` | T10c followed. |
| 2026-11-28 | T10c rule versions | `88a8c26` + full gate | 22 gates PASS in 238.4s; rule version DRAFT/PUBLISHED/RETIRED, hash verification, published AST frozen into snapshot; log `docs/implementation/v24/10-payroll-rules/gate-all-t10c.log` | T10d followed. |
| 2026-11-28 | T10d rule API | `20c8249` + full gate | 22 gates PASS in 256.2s; rule list/create/publish/retire HTTP, CommandRunner/idempotency, OpenAPI/actions/ledger, HTTP lifecycle; log `docs/implementation/v24/10-payroll-rules/gate-all-t10d.log` | T10e followed. |
| 2026-11-28 | T10e dependencies/editor | `b018d9d` + full gate | 22 gates PASS in 239.3s; dependency publish/retire/cycle rules, restricted AST editor, frontend/backend regressions; log `docs/implementation/v24/10-payroll-rules/gate-all-t10e.log` | T10f followed. |
| 2026-11-28 | T10f AC-07 hardening | `89943ef` + full gate | 22 gates PASS in 240.3s; EMPLOYER_COST, SUM, CENT unit, exact 9480/1400/8080/9980 calculation; log `docs/implementation/v24/10-payroll-rules/gate-all-t10f.log` | T10 complete; MCP remains T18. |
| 2026-11-28 | T08a timeoff/travel facts | `236a6e3` + full gate | 22 gates PASS in 237.8s; append-only ledger/TIMEOFF EARN, travel conflicts, payroll travel formula; log `docs/implementation/v24/08-overtime-travel/gate-all-t08a.log` | Overtime Workflow and travel revision API remain T08b. |
| 2026-11-28 | T13d correction void/retry | `207cb39` + full gate | 22 gates PASS in 245.3s; independent OPEN/VOID state, HTTP void, retry same period, UI evidence; log `docs/implementation/v24/13-corrections/gate-all-t13d.log` | T13 complete; MCP remains T18. |
| 2026-11-28 | Final acceptance | `docs/implementation/v24/21-final-requirement-acceptance.md` | **NO-GO**: T16 blocked; T08b/T14 masking/T17/T19 not fully implemented; MCP 0 executed | Continue T08b/T17 and T16 unblock. |

---

## 1. 全局约束

- 不重做多租户/多公司；所有新表遵从现有单公司迁移惯例，不新增 `tenant_id`/`legal_entity_id` 业务概念。
- 金额只存整数最小单位；比率使用十进制定标字符串/整数；PHP 生产代码禁止 float 金额路径。
- 所有时间戳 UTC，业务日期经 `Clock`、`CompanyBusinessDate`、公司时区/日历解析。
- 每个 `/v1` 路由必须 capability-guarded，除非在 public allowlist；所有有副作用命令必须 `Idempotency-Key`、事务、审计。
- 每个表只有一个写拥有者；跨模块只能通过 `Contracts` 端口；禁止跨模块 Model 写入或 raw `DB::table`。
- 迁移 forward-only，匿名类；不编辑已合并迁移。
- 历史工资条、快照、合同版本、审计、已完成流程不静默改写；无法唯一迁移的数据标 `unresolved`。
- 缺失事实不得零化。`required` 缺失阻断；`optional` 默认值必须由已发布规则显式声明。
- AI 不添加与需求无关的抽象；优先复用现有 Money/Decimal、idempotency、effect retry、projection 和 page action 工具。
- 自动测试不是最终 MCP 人工验收证据；但自动化测试、lint、typecheck、contract 和 build 是完成前置门禁。
- 所有 completion 声明必须引用实际运行输出；未执行必须写 `NOT RUN`，不能写“应该通过”。

---

## 2. 真实依赖图

```text
T00  基线 + ADR
  ↓
T01  安装/健康  ───────────────┐
T02  权限目录/对象动作 ←────────┘
  ↓
T03  合同约定版本 → T04 执行薪酬版本 → T05 入职单一入口
  ↓                                  │
T06  Time 月度事实版本 → T07 请假 Effect → T08 加班/出差
  ↓                                  │
T09  Snapshot v2 + 员工级方案 ←───────┘
  ↓
T10  规则引擎 → T11 批次成员/阻断/状态 → T12 月中分段
  ↓
T13  更正/YTD
  ↓
T14  福利 → T15 绩效/招聘/培训 → T16 通知/报表
  ↓
T17  前端动作/金额/自助
  ↓
T18  MCP 真实人工验收
  ↓
T19  真实栈迁移/恢复/发布判定
```

并行限制：T01/T02 可在 T00 后并行调查，但只能由同一写入者在同一工作树按顺序落盘，避免 `pageActions`、OpenAPI、route ledger 相互覆盖。T14–T16 的领域服务可独立写测试，但必须先满足 T09 的 Snapshot 契约。

---

## 3. Task 卡

### T00：基线与不可逆设计记录

**状态：** COMPLETE

**修改位置：**
- `backend/tests/Unit/Authorization/CapabilityCatalogTest.php:142`
- `backend/app/Modules/IAM/Authorization/RoleTemplateCatalog.php:320`（HR_SPECIALIST/ATTENDANCE_SPECIALIST 的 leave 能力 grant）
- `backend/tests/Authorization/iam_endpoint_matrix.php`
- `backend/tests/Contract/fixtures/legacy-routes.frozen.txt`
- `backend/tests/Contract/PeopleDirectoryFrozenSignatureTest.php`
- 新增 `docs/adr/0039-payroll-execution-compensation-and-time-fact-versions.md`
- 新增 `docs/implementation/v24/00-baseline.md`

**当前行为：**
- baseline `pnpm gate:all` 在 `backend:phpunit` 处失败，1490 tests / 8 failures。
- Capability catalog 实现有 171 项，测试仍断言 170。
- 标准角色在 `ORG_UNIT` 授予只允许 `COMPANY` 的 leave policy/type 能力，导致 role copy 422。
- 新加 `GET /v1/auth/csrf` 未进入 IAM denial matrix 与 frozen route ledger。
- `PeopleDirectory` 已有 `employmentIdByEmployeeNo`，契约快照测试仍按旧接口冻结。

**修改内容：**
- 新增/修改测试：把 capability 总数断言改为 171，并写明增量来源；在 IAM matrix 与 frozen ledger 增加 `GET /v1/auth/csrf` 的 barrier/denial 行；PeopleDirectory 契约预期加入 `employmentIdByEmployeeNo(string): ?int`。
- 修改 `RoleTemplateCatalog`：`HR_SPECIALIST`/`ATTENDANCE_SPECIALIST` 的 `time.leave.type.manage`、`time.leave.policy.read`、`time.leave.policy.manage` 改为 `$le = AnchorType::COMPANY`；不放开 capability 的 anchor。
- 写 ADR-0039：记录执行薪酬唯一源、合同约定与执行金额分离、时间事实闭账版本、累计更正/signed YTD、发布/发放独立、对象动作服务端投影；引用已确认 V2.4 文档，不回退设计。
- 记录 baseline 命令、失败列表、HEAD、PHP/Node 版本、SQLite/MySQL 状态、未执行项。

**对应需求：** AC-01、AC-02、AC-05、AC-13、AC-21 的追踪/安装/权限/状态/验收基线；Design 5.1.8、5.4、5.8、7。

**依赖：** 无。T00 未完成不得进入 T01+。

**验证方式：**
```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'CapabilityCatalogTest|RoleAdminTest|RoleAuthorizationApiTest|IamRouteMatrixTest|LegacyRouteInventoryTest|PeopleDirectoryFrozenSignatureTest'
cd ..
pnpm gate:all
```

预期：6 个聚焦测试文件全绿；`gate:all` 22 gates 全部 PASS。

**TDD 步骤：**
- [x] Step 1：仅修改断言/矩阵/契约测试，运行聚焦测试确认仍因同一原因 FAIL。
- [x] Step 2：修正 role anchors、catalog count、csrf ledger、PeopleDirectory freeze，运行聚焦测试 → PASS。
- [x] Step 3：写 ADR-0039 与 baseline 文档；记录 `git diff --check`。
- [x] Step 4：运行 `pnpm gate:all` → 全部 PASS；把输出保存到 `docs/implementation/v24/00-baseline/gate-all.log`。
- [ ] Step 5：提交 `fix(v2.4): close baseline contract drift`。

---

### T01：安装状态、干净 Seeder 与健康检查

**状态：** COMPLETE

**修改位置：**
- 新增 `backend/database/migrations/2026_11_28_000100_create_platform_installation_states.php`
- 新增 `backend/app/Modules/Platform/Models/PlatformInstallationState.php`
- `backend/app/Modules/IAM/Console/Commands/InstallCommand.php`
- `backend/app/Modules/IAM/Console/Commands/SeedDemoCommand.php`
- `backend/database/seeders/DatabaseSeeder.php`
- `backend/app/Modules/IAM/Console/Commands/SyncCapabilitiesCommand.php`
- `backend/app/Modules/Platform/Services/HealthService.php`
- `backend/app/Modules/Platform/Http/Controllers/ReadinessController.php`
- `backend/routes/api.php`, `docs/openapi/openapi.yaml`
- Tests: `backend/tests/Feature/Install/InstallAndDemoGuardTest.php`, `backend/tests/Feature/IAM/SyncCapabilitiesPreservesCustomGrantsTest.php`, `backend/tests/Feature/HealthTest.php`

**当前行为：**
- `DatabaseSeeder::run()` 无条件调用 `iam:seed-demo`（无密码时警告但仍运行）。
- `SeedDemoCommand` 没有 production guard；`demo:seed/reset` 已有 guard，说明命令级防线的模式可复用。
- `InstallCommand` 要求非必要的 `--annual-days`/`--legal-basis`；重复用不同用户名可创建第二个管理员；无单一安装完成态。
- `SyncCapabilitiesCommand` 全删 `RolePage` 和模板 `RoleCapability`，可能删除自定义授权。
- `HealthService` 只检查 db/cache/storage，不检查 pending migration、安装状态、唯一管理员。

**修改内容：**
- 新增 `platform_installation_states`（单行锁，status、admin_user_id、catalog_hash、completed_at、installed_at、version）并让 `hrms:install` 在事务中创建/更新唯一状态；重复参数幂等，换管理员参数若已有状态返回 409/失败提示，不新增管理员。
- 年度假期/法律依据改为可选后置配置；未提供时 leave types 状态为未配置而不是伪造值。安装只写必要元数据、权限/工作流模板、唯一管理员；零员工/零工资/零业务数据。
- `DatabaseSeeder` 改为只调用 `hrms:install` 的安全默认路径或空运行；严禁默认 demo。`SeedDemoCommand` 在生产环境（`APP_ENV=production` 或显式 production flag）拒绝执行，除非 `--force-demo` 且非 production；reset 同样。
- capability sync 只 upsert capability、模板 role 的缺省 grants/pages；不得清空自定义 role/custom grants；记录保留与冲突。`RolePage` 用 `updateOrCreate`，模板 grant 用 `updateOrCreate` 并按模板唯一键删除本次模板内已移除的旧行，不跨自定义角色。
- Health: `GET /v1/health/ready` 检查 migration 无 pending、install state completed、恰好一个 active superadmin，失败返回 503；不泄露凭据。
- 新增/更新 API 文档与 action manifest（健康读写均不开放给普通用户）。

**对应需求：** AC-02、AC-03、AC-17、AC-22；Design 5.1.8、5.2 F、5.5 设置/安装、5.8 部署。

**依赖：** T00。

**验证方式：**
```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'InstallAndDemoGuardTest|SyncCapabilitiesPreservesCustomGrantsTest|HealthTest|InstallCommandTest|DemoSeedTest'
cd ..
pnpm openapi:validate
pnpm actions:check
```

**TDD 步骤：**
- [x] Step 1：写 failing tests：`test_database_seeder_does_not_seed_demo_in_production`、`test_install_is_single_state_and_idempotent`、`test_sync_preserves_custom_pages_and_grants`、`test_readiness_fails_on_pending_migration_install_or_admin`。
- [x] Step 2：运行聚焦测试确认 FAIL。
- [x] Step 3：新增迁移/模型/安装状态与 health 检查；把 requirement 的非必要参数改为可选。
- [x] Step 4：替换 Seeder 默认行为、增加 demo guard、修正 sync upsert；跑聚焦测试 GREEN。
- [x] Step 5：跑 OpenAPI/action checks；提交 `feat(v2.4): add clean install state and production guards`。

---

### T02：单一 Page/Action Catalog 与对象级 allowed_actions

**状态：** COMPLETE

**修改位置：**
- 新增 `backend/app/Modules/IAM/Authorization/PageActionCatalog.php`
- 新增 `backend/app/Shared/Authorization/AllowedActionResolver.php`
- `backend/app/Modules/IAM/Authorization/PermissionProjection.php`
- `backend/app/Modules/IAM/Http/Controllers/MeController.php`
- `backend/app/Modules/IAM/Http/Controllers/RoleAuthorizationController.php`
- `backend/app/Modules/IAM/Services/RoleAdminService.php`
- `backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php`
- `scripts/generate_action_manifest.mjs`, `docs/generated/action-input.json`, `frontend/src/generated/pageActions.ts`
- `frontend/src/stores/session.ts`, `frontend/src/modules/platform/pages/SystemRolesPage.vue`
- Tests: `backend/tests/Contract/PageActionCatalogParityTest.php`, `backend/tests/Feature/IAM/RoleAuthorizationConcurrencyTest.php`, `backend/tests/Feature/IAM/AllowedActionsTest.php`, `frontend/tests/unit/payrollAllowedActions.spec.ts`

**当前行为：**
- 页面来自 `PermissionResourceCatalog`，动作来自 `action-input.json`/`PAGE_REGISTRY`/OpenAPI 的多源拼接。
- `/me/permissions` 只返回 action code 字符串和 pages，没有对象 level `allowed_actions`、`version`、`source_version`。
- 角色授权运行时读取 docs JSON，capability→page 是单值；多页 capability 会校验错页，且没有 expected_version/409。

**修改内容：**
- 新增 code-owned `PageActionCatalog`，至少暴露 `all(): array`、`forPage(string): list<array>`、`forObject(string): list<array>`；动作字段为 key、label、capability、api、anchor_types、field_keys。其为唯一后端来源。
- 修改 generator：从 PageActionCatalog 导出/生成 `action-input.json`/`pageActions.ts`；保留现有 OpenAPI 校验，删除运行时读 docs JSON 的角色授权路径。
- 角色授权改为一个原子命令：`pages[]`、`capabilities[]`、`expected_version`、`reason`、`Idempotency-Key`；服务端校验每个 page required capability、anchor、field profile，支持 >64 动作，版本冲突 409；提升受影响用户 auth_version。
- 新增 `AllowedActionResolver::for(Principal $p, string $objectType, int|string $id, array $state, string $businessDate): array`；用 capability + object anchor + 状态机 + page action 判断，输出 `allowed_actions`、`state`、`version`、`source_version`。本任务接入工资批次；合同详情在 T03、请假/加班/出差在 T07/T08 接入，T17 完成其余前端消费。
- 前端 session 不缓存对象动作为权限真值；详情使用响应 allowed_actions，按钮不再展示内部 capability、不自行推导对象状态。

**对应需求：** AC-03、AC-05、AC-18；Design 5.1.8、5.2 F、5.5 `/me/permissions`/角色授权、5.6 数据来源。

**依赖：** T00、T01。

**验证方式：**
```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'PageActionCatalogParityTest|RoleAuthorizationConcurrencyTest|AllowedActionsTest|MePermissionsTest|RoleAuthorizationApiTest'
cd ..
pnpm actions:generate && pnpm actions:check && pnpm openapi:validate && pnpm typecheck
cd frontend && pnpm test:unit -- payrollAllowedActions.spec.ts
```

**TDD 步骤：**
- [x] Step 1：写 failing tests：同 capability 多页面正确解析、expected_version 409、>64 动作、自定义角色保留、对象动作随状态变化、前端使用响应 allowed_actions。
- [x] Step 2：运行确认 FAIL。
- [x] Step 3：实现 PageActionCatalog + AllowedActionResolver + atomic role service；生成器切换到单一源。
- [x] Step 4：更新 `/me/permissions`、角色 API、OpenAPI、前端 session/角色页；聚焦测试 GREEN。
- [x] Step 5：跑 action/openapi/typecheck/frontend tests；提交 `feat(v2.4): unify action catalog and object authorization`。

---

### T03：合同约定版本、激活与不可变

**状态：** COMPLETE

**修改位置：**
- `backend/database/migrations/2026_11_27_000801_add_contract_detail_fields.php`（仅新建后续迁移，不修改该历史迁移）
- 新增 `backend/database/migrations/2026_11_28_000200_add_agreed_terms_to_contract_versions.php`
- `backend/app/Modules/Lifecycle/Models/EmploymentContractVersion.php`
- `backend/app/Modules/Lifecycle/Services/EmploymentContractVersionLedgerService.php`
- `backend/app/Modules/People/Services/ContractLifecycleService.php`
- `backend/app/Modules/People/Http/Controllers/EmploymentContractController.php`
- `backend/app/Modules/People/Http/Controllers/ContractLifecycleController.php`
- `backend/routes/api.php`, `docs/openapi/openapi.yaml`
- Tests: `backend/tests/Feature/People/ContractVersionImmutabilityTest.php`, `ContractActivationTest.php`, `ContractVersionAppendTest.php`

**当前行为：**
- `employment_contract_versions` 有 snapshot_json，但无首等约定薪酬/币种/terms hash/activated_at；模型未禁改删。
- 合同创建可直接 `ACTIVE`；无显式 `DRAFT → PENDING_EFFECTIVE → ACTIVE` 激活命令；续签/变更/终止可写。
- 合同约定与实际 `employee_compensations` 未绑定。

**修改内容：**
- 新迁移扩版本表：`agreed_salary_minor`、`agreed_currency`、`terms_hash`、`activated_at`、`activated_by`、`status`；增加 append-only DB triggers（与快照模式一致），模型 booted 拒绝 update/delete。
- 合同创建默认 DRAFT；新增 `activate(int $contractId, int $actorUserId, string $effectiveFrom): array`，校验同 employee、日期、唯一有效主合同，写版本记录并转 PENDING_EFFECTIVE/ACTIVE（按日期）。
- ContractLifecycle amend/terminate/renew 通过版本 ledger；版本新增 `contract_id + version_seq` 唯一；未来生效合同不提前参与当前工资。
- 合同详情返回 `agreed_salary`（按 P3 字段策略）、当前执行薪酬摘要与 `source_version`，不把合同金额当实际工资。
- 增加 `GET /v1/employments/{id}/contract-versions?as_of=`，只返回同 employment 的版本，供薪酬 Drawer 使用。

**对应需求：** AC-04、AC-13、AC-20；Design 5.1.2、流程 B、5.3.2、5.5 合同。

**依赖：** T02。

**验证方式：**
```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'ContractVersionImmutabilityTest|ContractActivationTest|ContractVersionAppendTest|EmploymentContractApiTest|ContractLifecycleTest'
cd ..
pnpm openapi:validate && pnpm actions:check
```

**TDD 步骤：**
- [x] Step 1：写 failing tests：版本不可 update/delete、DRAFT 不可直接 ACTIVE、activation 同员工/有效期/唯一主合同、未来生效不参与 as-of、合同金额被字段策略裁剪。
- [x] Step 2：运行确认 FAIL。
- [x] Step 3：前向迁移 + 模型防护 + activate 服务；不修改旧迁移。
- [x] Step 4：控制器/API/详情投影；聚焦测试 GREEN。
- [x] Step 5：补 OpenAPI/action/负测；提交 `feat(v2.4): add immutable contract agreed versions`。

---

### T04：员工执行薪酬版本与合同/方案关联

**状态：** COMPLETE

**修改位置：**
- 新增 `backend/database/migrations/2026_11_28_000300_extend_employee_compensations_for_execution.php`
- `backend/app/Modules/Payroll/Models/EmployeeCompensation.php`
- `backend/app/Modules/Payroll/Services/CompensationService.php`
- `backend/app/Modules/Payroll/Http/Controllers/CompensationController.php`
- `backend/app/Modules/Payroll/Contracts/*`（新增 `CompensationVersionDirectory`）
- `backend/app/Modules/People/Contracts/EmploymentContractVersions`（如需要）
- Tests: `backend/tests/Feature/Payroll/CompensationTest.php`（扩展）, `backend/tests/Integration/Payroll/ContractCompensationChainTest.php`

**当前行为：**
- `employee_compensations` 只有 employment/effective_from/base/currency/scheme_code；无 contract_version_id、scheme_id/version、参数覆盖、effective_to、status、case、content hash。
- 只检查同 effective_from 冲突，不检查区间重叠；合同、方案、员工/日期未强校验。
- 历史 terms 仍可写入工资，执行薪酬不是唯一来源。

**修改内容：**
- 迁移增加：`contract_version_id`、`payroll_scheme_id`、`payroll_scheme_version`、`parameter_overrides_json`、`effective_to`、`status(DRAFT/PENDING_APPROVAL/SCHEDULED/ACTIVE/SUPERSEDED/ENDED)`、`approved_case_id`、`source_type/source_id`、`content_hash`、`supersedes_id`；保留现有唯一键，增加区间索引。
- `CompensationService::createCompensation` 接收合同版本、方案版本、参数覆盖、expected_version；事务锁同 employment，检查日期交点、合同属于同 employment 且有效、方案已发布/active、币种一致、参数 schema；写 hash 和审计。
- 同员工同日只能一个有效版本；新版本不更新旧行；`salary_adjustments` 绑定新 compensation id，审批前不生成 ACTIVE 版本。
- 增加 resolver：给定 employment+period+cutoff，返回分段有效版本（未来版本不提前），后续 T09 消费。
- 扩展详情 API，P3 金额字段由 Authorizer/FieldSet 裁剪；合同摘要只回 agreed amount 权限内。
- 迁移历史 `employment_terms` 工资：只有满足同合同/方案/唯一有效期的行回填；否则标 unresolved，禁止自动取第一条。旧 terms 金额只读。

**对应需求：** AC-04、AC-06、AC-09、AC-14、AC-20；Design 5.1.2、5.1.3、流程 B、5.8 历史迁移。

**依赖：** T03。

**验证方式：**
```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'CompensationTest|ContractCompensationChainTest|SalaryAdjustmentCaseTest'
```

**TDD 步骤：**
- [x] Step 1：写 failing tests：contract/employment mismatch、日期重叠、未来版本、参数超限、历史 unresolved、P3 字段隐藏、同日期唯一。
- [x] Step 2：运行确认 FAIL。
- [x] Step 3：迁移 + model + service + resolver；保留旧行。
- [x] Step 4：API/字段策略/审计；聚焦测试 GREEN。
- [x] Step 5：提交 `feat(v2.4): bind execution compensation to contract and scheme versions`。

---

### T05：单一入职入口与合同/薪酬待办

**状态：** PENDING

**修改位置：**
- `backend/routes/api.php` 的 `/v1/employees/onboard` 与 `/v1/lifecycle/onboarding`
- `backend/app/Modules/Lifecycle/Http/Controllers/LifecycleController.php`
- `backend/app/Modules/Lifecycle/EffectHandlers/OnboardingEffectHandler.php`
- `backend/app/Modules/People/Services/CreateEmployeeService.php`
- `backend/app/Modules/People/Services/DepartmentMembershipService.php`
- `backend/app/Modules/Payroll/Services/CompensationService.php`
- `frontend/src/modules/people/pages/EmployeeDetailPage.vue`, `EmployeeCreateDrawer.vue`
- Tests: `backend/tests/Feature/Lifecycle/OnboardingSingleEntryTest.php`, `backend/tests/Feature/People/OnboardingAtomicityTest.php`

**当前行为：**
- 直接 `/employees/onboard` 与 Lifecycle 双入口；CreateEmployeeService 原子写 Person/Employment/Assignment/Terms，但绕过 Case；完成 response 只有 case/draft。
- Onboarding Effect 不创建合同/薪酬草稿，Offer 工资未透传。

**修改内容：**
- 保留 Lifecycle 为唯一 UI/API 写入口；`/employees/onboard` 改为代理 Lifecycle 命令或返回 410（若现有测试/兼容需求要求，采用门面代理同一服务，不复制逻辑），不得另写业务表。
- Onboarding intent payload 增加合同/薪酬草稿参数（合同版本、方案、base/params），审批 Effect 在 People 事务创建 Person/Employment/Assignment/Terms 后，通过 Payroll command port 创建补偿草稿（不直接跨模块写表）。
- 响应返回 case、employment、contract_draft、compensation_draft 与 allowed_actions；幂等键覆盖整个入职。
- 员工详情提供合同/薪酬待办入口，不显示内部 ID。

**对应需求：** AC-04、AC-14、AC-15；Design 流程 A/B、5.4、5.5 员工建档。

**依赖：** T04。

**验证方式：**
```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'OnboardingSingleEntryTest|OnboardingAtomicityTest|OnboardingEffectTest|OfferOnboardingSinglePathTest'
cd frontend && pnpm test:unit -- peoplePages.spec.ts
```

**TDD 步骤：**
- [ ] Step 1：写 failing tests：双入口不产生两员工、审批前无 domain rows、Effect 失败无半条、合同/薪酬草稿同 employment、重复 case 返回原结果。
- [ ] Step 2：运行确认 FAIL。
- [ ] Step 3：改 Lifecycle command/effect + 端口调用；逐字段校验。
- [ ] Step 4：前端入口收口、详情待办；聚焦测试 GREEN。
- [ ] Step 5：提交 `feat(v2.4): close onboarding single entry and drafts`。

---

### T06：Time 月度事实版本与闭账 hash

**状态：** COMPLETE（MySQL 真并发验证留 T19）

**修改位置：**
- 新增 `backend/database/migrations/2026_11_28_000400_create_time_payroll_fact_versions.php`
- 新增 `backend/app/Modules/Time/Models/TimePayrollFactVersion.php`
- 新增 `backend/app/Modules/Time/Models/TimePayrollFactRow.php`
- 新增 `backend/app/Modules/Time/Services/TimeFactVersionService.php`
- `backend/app/Modules/Time/Services/AttendanceClosingService.php`
- `backend/app/Modules/Time/Http/Controllers/AttendanceClosingController.php`
- `backend/app/Modules/Time/Contracts/PayrollInputDirectory.php`
- `backend/tests/Feature/Time/TimeFactVersionTest.php`
- `backend/tests/Integration/Payroll/TimeClosedFactPayrollTest.php`（先只验证接口存在）

**当前行为：**
- `attendance_period_closures` 只有 CLOSED/REOPENED 状态，没有不可变事实版本/hash/异常预检。
- `reopen()` 复用同一行；Payroll 直接读实时 attendance，不读闭账事实。
- `AttendanceClosingController` 重算接口返回 202 占位。

**修改内容：**
- 新表 `time_payroll_fact_versions`（period/version/status OPEN/CLOSING/CLOSED/source_hash/exception_count/precheck_json/generated_at/closed_at/by/reopened_from_version_id）和 `time_payroll_fact_rows`（employment/date/时间分钟/带薪/无薪/加班分类/出差/来源/version/审批状态/source hash）。
- 关闭：预检未闭环审批、重复来源、缺排班、重叠事实；任何 required 缺失 → CLOSING 失败，不能产生 CLOSED；成功时写入不可变 rows、hash，closure 记录 current_fact_version_id。
- 重开：closure 状态 REOPENED，保留旧 version 不 update；下一次关闭生成 v2，source_hash 不同。
- Payroll 端口增加 `closedFactForPeriod(int $employmentId, string $period): ?PayrollTimeFactExport`；Payroll 后续只消费 closed version。保留现有 attendanceForPeriod 作为只读预览，但 Snapshot 不再调用。
- 所有 time mutation entrypoints 在 closed period 通过 `PayrollFreezeGuard` 阻断；不可用改数据的方式绕过。

**对应需求：** AC-08、AC-10、AC-13、AC-20；Design 5.1.5、流程 C、5.3.3、5.5 考勤月结。

**依赖：** T04。

**验证方式：**
```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'TimeFactVersionTest|AttendanceClosingTest|LeaveRevokeFreezeTest'
```

**TDD 步骤：**
- [x] Step 1：写 failing tests：关闭产生 hash+rows、重复 close 幂等、重开保留 v1、新 close v2、所有入口拒绝 closed、缺失排班阻断。
- [x] Step 2：运行确认 FAIL。
- [x] Step 3：迁移/模型/服务；更新 closure controller/port。
- [x] Step 4：闭账回归；提交 `feat(v2.4): freeze monthly time facts and hashes`。
- [x] Step 5：独立审查三轮修复：signed net/真实 revoke、空月与逐员工缺失阻断、close/reopen/mutation 同月锁、idempotency、v3 指针、全列不可变证据；提交 f63fe3e/9842629/58fbe80。

---

### T07：请假审批 Effect、跨月净流水与撤权同步

**状态：** PENDING

**修改位置：**
- `backend/config/workflow.php`
- `backend/app/Modules/Workflow/Console/SeedWorkflowTemplatesCommand.php`
- `backend/app/Modules/Workflow/Services/DecisionService.php`（只补 outcome effect 接线，不改 Case 语义）
- 新增 `backend/app/Modules/Time/EffectHandlers/LeaveApprovalEffectHandler.php`
- `backend/app/Modules/Time/Services/LeaveApprovalService.php`, `LeaveRequestService.php`, `LeaveLedgerService.php`, `PayrollInputDirectoryService.php`
- 新增 `backend/database/migrations/2026_11_28_000500_add_leave_payroll_treatment.php`
- `backend/app/Modules/Time/Models/LeavePolicyVersion.php`, `LeaveLedgerEntry.php`
- Tests: `backend/tests/Feature/Time/LeaveWorkflowEffectTest.php`, `backend/tests/Feature/Workflow/TimeOutcomeSyncTest.php`

**当前行为：**
- 请假模板 `workflow.noop`，生产 Time provider 未注册 leave effect；审批只改 case，不消费/释放账本。
- 按整张申请 from_date CONSUME，不按日拆分；REVERSE 记当前业务日；Payroll 只读 CONSUME 且按 code 判带薪。
- 已批准撤销只改投影，不转 CANCELLED/reverse effect。

**修改内容：**
- `leave_policy_versions` 增加 `payroll_treatment(PAID/PARTIAL/UNPAID/UNRESOLVED)`、`paid_ratio_scaled`；历史无属性标 UNRESOLVED，新申请配置前阻断。
- 注册 `time.leave.apply`/`time.leave.reverse`（具体 effect type 以现有模板命名一致为准）；审批通过在同一事务执行 CONSUME/RELEASE/净日拆分，重复重放只返回原结果。
- 无薪假事实按日记录业务日期、分钟、政策版本；跨月申请拆成各月事实；销假/撤销写 REVERSE/RELEASE 并生成对应负净额，不改原消费流水。
- Payroll input 改为净额消费（CONSUME + REVERSE/RELEASE），以 `payroll_treatment`/`paid_ratio` 计算无薪/部分薪，不按 code。
- Workflow 撤回/退回/拒绝产生 outcome effect（若已生效则 reverse effect），业务投影同时显示 application/effect/business 三状态；旧页签下一次写请求按 auth_version/状态拒绝。

**对应需求：** AC-08、AC-13、AC-20；Design 5.1.5、流程 C、5.3.3、5.7 异常。

**依赖：** T06。

**验证方式：**
```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'LeaveWorkflowEffectTest|TimeOutcomeSyncTest|LeaveLedgerBalanceTest|ReversalTest|LeaveRevokeFreezeTest|TimeToPayrollIntegrationTest'
```

**TDD 步骤：**
- [ ] Step 1：写 failing tests：批准只消费一次、跨月拆分、撤销净额、未出勤不得补餐补、UNRESOLVED 阻断、重复决定幂等。
- [ ] Step 2：运行确认 FAIL。
- [ ] Step 3：迁移政策属性/账本关系；实现 effect handler 并注册。
- [ ] Step 4：接 Workflow、净额 Payroll、撤销同步；聚焦测试 GREEN。
- [ ] Step 5：提交 `feat(v2.4): close leave workflow effects and net time facts`。

---

### T08：加班 PAY/TIMEOFF 互斥与出差计勤

**状态：** IN PROGRESS (T08a append-only ledger/travel conflicts/payroll travel done)

**修改位置：**
- 新增 `backend/database/migrations/2026_11_28_000600_create_timeoff_ledger_and_overtime_reversals.php`
- 新增 `backend/app/Modules/Time/Models/TimeoffLedgerEntry.php`
- `backend/app/Modules/Time/Services/OvertimeRequestService.php`, `OvertimeSettlementService.php`
- `backend/app/Modules/Time/Models/OvertimeSettlement.php`, `BusinessTripRecord.php`, `TravelAttendanceLink.php`
- `backend/app/Modules/Time/Http/Controllers/OvertimeRequestController.php`, `TravelController.php`
- 新增/扩展 `backend/app/Modules/Time/EffectHandlers/OvertimeApprovalEffectHandler.php`, `TravelAttendanceEffectHandler.php`
- `backend/tests/Feature/Time/OvertimeSettlementReversalTest.php`, `TravelTimeFactTest.php`

**当前行为：**
- 加班申请创建即 APPROVED 且 case_id null；PAY 不写工资，TIMEOFF 不回款账本；结算唯一约束只防重复 settlement；Payroll 读 attendance_days.overtime_minutes。
- 出差只创建 calendar-only link，不建工资事实，且可与请假/打卡/加班重叠。

**修改内容：**
- 加班申请统一 Workflow；effect 只允许 policy 允许的 PAY/TIMEOFF，选择唯一，结算写 `overtime_settlements` + `timeoff_ledger_entries`（若 TIMEOFF）或形成 closed Time fact 的 `overtime_pay_minutes`（若 PAY）。
- `timeoff_ledger_entries` append-only，支持 EARN/USE/REVERSE/EXPIRE/ADJUST；余额查询只汇总有效流水；结算反向有且仅有一条有效结算。
- 出差同 Workflow 审批，生成统一 Time fact rows（不伪造 punch）；与请假/打卡/加班时间冲突预检，重叠阻断；取消/变更生成新版本/冲销。
- Payroll 只读取 closed fact 的 `overtime_pay_minutes` 和 `travel_minutes`，TIMEOFF minutes 不得产生工资项。
- 新增 API/详情 allowed_actions；旧加班/出差动作补 capability/anchor/幂等测试。

**对应需求：** AC-10、AC-13、AC-19；Design 5.1.5、流程 C、5.5 加班/出差。

**依赖：** T06、T07（Workflow effect 注册与闭账）。

**验证方式：**
```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'OvertimeSettlementReversalTest|TravelTimeFactTest|OvertimeWorkspaceTest|TravelWorkspaceTest|TimeFactVersionTest'
```

**TDD 步骤：**
- [x] Step 1：PAY/TIMEOFF 二选一、重复结算、ledger append-only、出差重叠、Payroll travel 测试。
- [x] Step 2：T08a focused RED/GREEN。
- [x] Step 3：timeoff ledger + TIMEOFF EARN + travel conflict facts。
- [x] Step 4：closed fact travel → payroll/formula；API balance 读 ledger。
- [ ] Step 5：加班申请统一 Workflow effect、travel change/reversal allowed_actions 后完成 T08。

---

### T09：Snapshot v2、员工级多方案与缺项阻断

**状态：** PENDING

**修改位置：**
- `backend/app/Modules/Payroll/Services/SnapshotService.php`
- `backend/app/Modules/Payroll/Services/CalculationService.php`
- `backend/app/Modules/Payroll/Services/PayrollRunService.php`
- `backend/app/Modules/Payroll/Models/PayrollInputSnapshot.php`, `PayrollRunEmployee.php`
- 新增 `backend/app/Modules/Payroll/Contracts/CompensationVersionDirectory.php` / `BenefitPayrollInputDirectory.php` / `PerformanceBonusDirectory.php`
- `backend/database/migrations/2026_11_28_000700_create_payroll_run_members.php`
- `backend/tests/Feature/Payroll/PayrollSnapshotTest.php`（扩展）, `MissingAttendanceBlockingTest.php`, `MultiSchemeRunTest.php`
- `backend/tests/Integration/Payroll/ContractCompensationChainTest.php`

**当前行为：**
- 批次只用一个 `scheme_id`；Snapshot 从 `employment_terms` 取工资，缺考勤返回全 0；员工失败仍可能进入 CALCULATED；计算没有福利/绩效 oneTimeBonuses。
- 没有 run members 表，PAYROLL_RUN anchor 依赖已写 actor 列。

**修改内容：**
- 新增 `payroll_run_members(run_id,user_id,member_role,assigned_by,reason,valid_from)`，唯一 run+user+role；创建批次分配 preparer，reviewer/approver/publisher/disburser 可通过管理动作预分配；membership directory 改读表 + 兼容旧 actors。
- Snapshot v2 schema：employment period、contract_version_id/agreed terms、execution compensation versions/segments、scheme/rule versions、closed time fact id/hash、benefits、performance、manual adjustments、statutory versions、completeness/blockers/source hashes。每个员工可解析不同 scheme/version。
- 缺失 closed time fact、基础薪酬、社保参数等 required 项写 blocker；不生成全 0。计算时逐员工：失败/阻断记 `PayrollEmployeeStatus::BLOCKED/FAILED`，批次若存在 blocker 不得转 `CALCULATED`，而是 `CALCULATED_WITH_BLOCKERS` 并禁止 review/approve。
- Calculation 使用 snapshot 中的 comp/scheme/benefits/performance，不再读实时 terms/scheme；只对成功员工生成 payslip/run employee。批次合计为成功员工之和，另返回 blockers 总数。
- T05 的入职草稿/合同/薪酬链路在 integration test 中必须能完成到批次准备。

**对应需求：** AC-06、AC-07、AC-11、AC-12、AC-14、AC-19；Design 5.1.2–5.1.4、流程 B/C/D、5.3.1、5.7 事务。

**依赖：** T04、T05、T06。Benefit/Performance 端口在本任务先定义 nullable/default 适配器并用测试 fake 覆盖；T14/T15 完成后必须重跑本任务的集成用例，证明真实福利/绩效输入已接入，不能以 fake 作为最终完成证据。

**验证方式：**
```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'PayrollSnapshotTest|MissingAttendanceBlockingTest|MultiSchemeRunTest|ContractCompensationChainTest|TimeClosedFactPayrollTest'
```

T14/T15 完成后追加：
```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'BenefitMultiPlanReversalTest|PerformanceBonusPayrollTest|PayrollSnapshotTest'
```

**TDD 步骤：**
- [ ] Step 1：写 failing tests：A/B 同批次不同方案、缺 closed fact 阻断、员工级失败不得整体 CALCULATED、snapshot hash 稳定、预分配 reviewer/approver 后可访问 run。
- [ ] Step 2：运行确认 FAIL。
- [ ] Step 3：migration + members + resolver ports + snapshot v2。
- [ ] Step 4：calculation blocker/多方案接线；聚焦测试 GREEN。
- [ ] Step 5：提交 `feat(v2.4): resolve employee compensation and block missing inputs`。

---

### T10：受限 AST 规则引擎

**状态：** COMPLETE (T10a-T10f; AC-07 exact sample and AC-11 regressions pass)

**修改位置：**
- 新增 `backend/database/migrations/2026_11_28_000800_create_payroll_rule_versions.php`
- 新增 `backend/app/Modules/Payroll/Domain/RuleAst.php`, `RuleCompiler.php`, `RuleEngine.php`, `RuleEvaluationResult.php`
- `backend/app/Modules/Payroll/Models/PayrollScheme.php`, `PayrollSchemeItem.php`
- `backend/app/Modules/Payroll/Services/PayrollRunService.php`, `CalculationService.php`, `PayrollItemService.php`
- `backend/routes/api.php`, `docs/openapi/openapi.yaml`, `docs/generated/action-input.json`
- Tests: `backend/tests/Unit/Modules/Payroll/RuleCompilerTest.php`, `RuleEngineTest.php`, `backend/tests/Feature/Payroll/PayrollRulePublishTest.php`, `frontend/tests/unit/payrollRuleEditor.spec.ts`

**当前行为：**
- `payroll_scheme_items` 的 calculation/rate/basis/formula 大多只存储；计算只按 amount_cents 和少量 overtime，formula 未真实消费。
- 无规则版本/依赖图/发布/退役/审计 hash。

**修改内容：**
- 新表 `payroll_rule_versions`：scheme_item_id/project_item_version、AST JSON、stage、dependencies、conditions、rounding、unit、engine_version、hash、status DRAFT/VALIDATED/PUBLISHED/RETIRED、published_by/at、retire_reason。
- 白名单 AST：常量、变量、+ - * /、比较/布尔、IF/MIN/MAX/CLAMP/ROUND/ABS、SUM 显式集合、TIER；禁 eval/网络/文件/DB/时间/随机；服务端验证节点数、深度、未知变量、循环、类型、除零、溢出、币种。
- `RuleCompiler` 编译/验证；`RuleEngine` 用 Decimal/Money 执行并输出逐项 trace；`PayrollCalculator` 作为受控汇总/税费引擎继续复用，不双算。
- 方案 publish/retire API：仅发布版本可进入 snapshot；旧版本不可 update；错误返回 rule code/变量/位置。
- 前端规则编辑器只做受限表单/JSON 可视化，不在 JS 计算净额；错误中文化、参数 schema 来自后端。
- 历史 scheme items 迁移为 FIXED/PERCENT 等 AST；无法转换标 unresolved，禁止新批次。

**对应需求：** AC-06、AC-11、AC-09；Design 5.1.3、流程 B/D、5.3.2、5.5 项目/方案。

**依赖：** T04、T09（Snapshot 接口先定义）。

**验证方式：**
```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'RuleCompilerTest|RuleEngineTest|PayrollRulePublishTest|PayrollItemVersionTest'
cd ..
pnpm openapi:validate && pnpm actions:check
```

**TDD 步骤：**
- [x] Step 1：compiler/engine、循环、未知变量、除零/tier/公式执行测试。
- [x] Step 2：TDD RED/GREEN 记录于实现报告。
- [x] Step 3：迁移 + AST + compiler/engine；接 published rules/snapshot hash。
- [x] Step 4：API/前端编辑器/OpenAPI/actions；GREEN。
- [x] Step 5：提交 `feat(v2.4): add restricted payroll rule engine`。
- [x] Hardening：SUM/CENT unit、legacy fail-closed、AC-07 exact scheme sample；VALIDATED 由 publish 前重编译/hash 校验等价实现。
- Residual risk：编辑器为受限 JSON（非可视化 builder）；历史无 AST 的 FORMULA 项按员工阻断。

---

### T11：批次成员、阻断状态与发布/发放正交

**状态：** PENDING

**修改位置：**
- `backend/app/Modules/Payroll/Enums/PayrollRunStatus.php`
- `backend/app/Modules/Payroll/Services/PayrollStateMachine.php`, `CalculationService.php`, `ApprovalService.php`, `PayrollRunService.php`, `ReopenService.php`
- `backend/app/Modules/Payroll/Http/Controllers/PayrollRunController.php`
- `backend/app/Modules/Payroll/Authorization/EloquentPayrollRunMembershipDirectory.php`
- `backend/app/Modules/Payroll/Models/PayrollRun.php`
- `backend/app/Modules/Payroll/Models/Payslip.php`
- `backend/database/migrations/2026_11_28_000900_add_orthogonal_payroll_statuses.php`
- `backend/tests/Feature/Payroll/PayrollRunLifecycleTest.php`, `PayrollBlockerTransitionTest.php`, `PayrollPublishDisburseTest.php`
- `frontend/src/modules/payroll/components/PayrollActionBar.vue`, `PayrollRunDetailPage.vue`

**当前行为：**
- 状态机缺 `CALCULATED_WITH_BLOCKERS`；批准/发布/发放/关闭仍是单链；部分失败仍可 CALCULATED；review 无 blocker 检查。
- 工作人员仅通过 run actor 列成为成员；没有预先 reviewer/approver；`allowed_actions` 未接。

**修改内容：**
- 状态枚举/迁移增加 `CALCULATED_WITH_BLOCKERS`、`payslip_status(UNPUBLISHED/PUBLISHED)`、`disbursement_status(NOT_REGISTERED/REGISTERED)`、`correction_status`（或等价正交列）；历史 PUBLISHED/PAID/CLOSED 映射到标志，不改历史金额。
- 状态转移：DRAFT→INPUT_LOCKED→CALCULATING→CALCULATED/CALCULATED_WITH_BLOCKERS；CALCULATED→REVIEWED→APPROVED；APPROVED→publish/disburse 独立动作→CLOSED；有 blockers 禁止 review/approve/publish/disburse；已 APPROVED 不可改金额。
- `payroll_run_members` 作为 membership source；创建/分配通过命令，能力与成员分离；Service 提供 reviewer/approver/publisher 预分配 API（最小角色、越权 403）。
- publish 只设 payslip_status 和 published_at；disbursement registration 只设 disbursement_status/reference；close 要求两者均完成。YTD 只由财务生效(publish)一次，重复/publish 重放幂等。
- 所有详情/动作返回 `allowed_actions/state/version/source_version`；前端 ActionBar 只渲染服务端动作，不再显示 capability。
- 更正状态与正常批次分离，后续 T13 接入。

**对应需求：** AC-06、AC-07、AC-12、AC-18；Design 5.3.1、流程 D/E、5.5 批次、5.7 幂等。

**依赖：** T09、T10。

**验证方式：**
```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'PayrollRunLifecycleTest|PayrollBlockerTransitionTest|PayrollPublishDisburseTest|PayrollHttpLifecycleTest'
cd frontend && pnpm test:unit -- payrollDetail.spec.ts payrollAllowedActions.spec.ts
```

**TDD 步骤：**
- [ ] Step 1：写 failing tests：blocker 不得 review、publish/disburse 独立、重复 publish 幂等、close 前置、预分配 reviewer 可访问、金额不可改。
- [ ] Step 2：运行确认 FAIL。
- [ ] Step 3：迁移/状态机/成员目录/API。
- [ ] Step 4：前端动作/状态展示；聚焦 GREEN。
- [ ] Step 5：提交 `feat(v2.4): split payroll blockers publication and disbursement`。

---

### T12：月中分段与周期级税费一次

**状态：** PENDING

**修改位置：**
- `backend/app/Modules/Payroll/Services/CalculationService.php`
- `backend/app/Modules/Payroll/Domain/PayrollCalculator.php`
- `backend/app/Modules/Payroll/Domain/PayrollCalculationInput.php`, `AttendanceSummary.php`
- `backend/tests/Unit/Modules/Payroll/MidMonthSegmentTest.php`
- `backend/tests/Feature/Payroll/MidMonthCompensationTest.php`
- `frontend/src/modules/payroll/components/PayrollRunDetailPage.vue`（来源/段明细）
- `backend/app/Modules/Payroll/Models/PayrollRunEmployee.php` / `payslip_lines` 来源扩展

**当前行为：**
- 只取期间末端 terms/一个 basic salary；无 segment input；月中调薪会按期末值整月计算；税费/社保按月一次但分段可能重复。
- 工资条来源不显示 segment 边界。

**修改内容：**
- Snapshot v2 生成每员工 segments：每个有效合同/薪酬版本边界（日期区间、base、scheme/rule/参数来源）；无重叠/无空档。
- Calculation 逐段计算基础收入和时间事实；按月合并各项；周期级社保/税/一次性 deduction 仅执行一次；未知/不完整 segment 阻断而非默认。
- 增加 `segment_index/source_version` 到 line source/payload；UI 可展开段明细。
- Golden test：前10天8000/后10天9000，20计划工作日 → base 8500；未来日期不提前；审批前不变。
- 不重复税费/社保：assert employee_si/hf/tax 在分段合并后仅一次。

**对应需求：** AC-09、AC-12、AC-20；Design 5.1.2、流程 B、5.3.2、规则 3.2。

**依赖：** T04、T10、T11。

**验证方式：**
```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'MidMonthSegmentTest|MidMonthCompensationTest|PayrollCalculationPropertyTest'
```

**TDD 步骤：**
- [ ] Step 1：写 failing test 精确 8500 和一次性税费断言。
- [ ] Step 2：运行确认 FAIL（当前 9000/端点值）。
- [ ] Step 3：扩展 segment DTO/snapshot/calculator；保持现有金额 API。
- [ ] Step 4：前端段来源与 golden；GREEN。
- [ ] Step 5：提交 `feat(v2.4): prorate mid-month compensation by effective segments`。

---

### T13：累计更正与 signed YTD ledger

**状态：** PENDING

**修改位置：**
- 新增 `backend/database/migrations/2026_11_28_001000_create_payroll_ytd_ledger_and_correction_chain.php`
- 新增 `backend/app/Modules/Payroll/Models/PayrollYtdLedgerEntry.php`
- `backend/app/Modules/Payroll/Services/CorrectionService.php`, `CalculationService.php`, `PayrollYtdService.php`, `ApprovalService.php`
- `backend/app/Modules/Payroll/Models/PayrollRun.php`, `PayrollRunEmployee.php`, `PayrollYtd.php`
- `backend/tests/Feature/Payroll/CorrectionRunTest.php`（扩展）, `PayrollYtdLedgerTest.php`
- `frontend/src/modules/payroll/components/PayrollActionBar.vue`, `PayrollRunDetailPage.vue`

**当前行为：**
- 更正只取原 run，第二次更正 baseline 不含首次更正；YTD 可变汇总 + `nonNegativeAdd` 截断；没有 append-only signed ledger；更正文案/入口缺 UI。

**修改内容：**
- `payroll_ytd_ledger` append-only：employment_id/tax_year/source_run_id/source_type/entry_type/signed amounts/source_hash/created_at/by；唯一 source run/employee/entry_type。
- YTD 汇总（`payroll_ytd`）只作为可重算投影；每次 publish 写 ledger，重复 publish 幂等；负纠正不 max(0)，carry_forward 明确保存/展示。
- 更正链：`correction_baseline_run_id`、prior correction ids、original period、settlement period、target amount、cumulative approved amount、current delta、correction_seq、unique(original_run, employee, period, seq) 或等价约束。
- Correction calculation target = fresh target for original period; delta = target - (original + all effective prior corrections)，不是只取原；第二次 8350 得 +50，不是 +270。
- 已支付更正不能被普通编辑覆盖；重放返回原 run；未支付作废/重试有独立状态。
- UI 更正/重开/取消按钮、原批次/更正链、目标/累计/差额、YTD ledger 展示；自足只读。

**对应需求：** AC-12、AC-20；Design 5.1.6、流程 E、5.3.1、5.5 更正。

**依赖：** T11、T12。

**验证方式：**
```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'CorrectionRunTest|PayrollYtdLedgerTest|PayrollYtdTest|PayrollCorrectionDeltaTest'
```

**TDD 步骤：**
- [ ] Step 1：写 failing tests：8080→8300 +220、8300→8350 +50、cumulative 8350、重复不重复支付、负 YTD、publish 一次。
- [ ] Step 2：运行确认 FAIL。
- [ ] Step 3：ledger/迁移/correction chain/calculation。
- [ ] Step 4：YTD projection/API/UI；GREEN。
- [ ] Step 5：提交 `feat(v2.4): make corrections cumulative and YTD append-only`。

---

### T14：福利计薪输入、多计划与冲销

**状态：** PENDING

**修改位置：**
- 新增 `backend/database/migrations/2026_11_28_001100_add_benefit_multi_plan_and_reversals.php`
- 新增 `backend/app/Modules/Payroll/Contracts/BenefitPayrollInputDirectory.php`
- 新增 `backend/app/Modules/Payroll/Services/BenefitPayrollInputService.php`
- `backend/app/Modules/Payroll/Models/BenefitTransaction.php`
- `backend/app/Modules/Payroll/Services/BenefitService.php`, `SnapshotService.php`
- `backend/app/Modules/Payroll/Http/Controllers/BenefitController.php`, `backend/routes/api.php`
- Tests: `backend/tests/Feature/Payroll/BenefitMultiPlanReversalTest.php`, `BenefitTest.php`（扩展）

**当前行为：**
- `benefit_transactions` 有 `payroll_run_id` 唯一和 enrollment+period 唯一；一员工一期不能多计划。
- 福利不进入 Payroll Snapshot；无冲销/reversal；Payroll 只读取公司统一 SI/HF。

**修改内容：**
- 迁移删除不合需求的唯一限制（必须新增 forward migration，用新表/索引；MySQL 删除索引/唯一键兼容），增加 `entry_type(RECORD/REVERSAL)`、`reversal_of_id`、`reversed_by_id`、`reason`、`source_hash`，唯一 reversal_of 防重复。
- Benefit command `reverseTransaction` 追加反向交易，不改原行；状态/审计/幂等。
- `BenefitPayrollInputDirectory::contributionsForPeriod` 返回员工当期所有参保/计划个人/单位金额；Payroll Snapshot v2 写 benefits 数组/hash；Calculation 将 employer/employee contributions 计入对应行，不通过 company-wide params 代替个人组合。
- 费用/费率变更不改变历史快照/已发布工资条；冻结保护与 run status 检查。
- API/前端福利页支持多计划、冲销、来源与期次；字段权限不泄露金额给无 P3 用户。

**对应需求：** AC-14、AC-20；Design 5.1.7、流程 D、5.5 福利。

**依赖：** T09。

**验证方式：**
```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'BenefitMultiPlanReversalTest|BenefitTest|BenefitFrozenPayrollTest'
```

**TDD 步骤：**
- [ ] Step 1：写 failing tests：同一员工一期两计划、重复冲销、已发布快照无变化、个人/单位金额分别进入 Cost。
- [ ] Step 2：运行确认 FAIL。
- [ ] Step 3：迁移/service/端口/Payroll 接线。
- [ ] Step 4：API/前端；GREEN。
- [ ] Step 5：提交 `feat(v2.4): connect benefit contributions and reversals`。

---

### T15：绩效奖金映射与 Offer/培训来源闭环

**状态：** PENDING

**修改位置：**
- `backend/database/migrations/2026_11_28_001200_add_performance_bonus_and_offer_compensation.php`
- `backend/database/migrations/2026_11_28_001300_create_training_exam_attempts.php`
- `backend/app/Modules/Talent/Models/PerformanceResultSnapshot.php`
- 新增 `backend/app/Modules/Talent/Models/PerformanceBonusMapping.php`
- 新增 `backend/app/Modules/Talent/Contracts/PerformanceBonusDirectory.php`
- 新增 `backend/app/Modules/Payroll/Contracts/PayrollCompensationDraftPort.php`
- `backend/app/Modules/Talent/Services/PerformanceCycleService.php`, `TalentOnboardingDraftService.php`, `TrainingLifecycleService.php`, `TrainingExamService.php`
- `backend/app/Modules/Payroll/Services/CompensationService.php`, `SnapshotService.php`, `CalculationService.php`
- `backend/app/Modules/Lifecycle/EffectHandlers/OnboardingEffectHandler.php`
- `backend/tests/Feature/Payroll/PerformanceBonusPayrollTest.php`, `OfferCompensationDraftTest.php`, `TrainingExamAttemptSourceTest.php`
- Frontend performance/recruitment/training panels + `frontend/tests/unit/performanceBonus.spec.ts`

**当前行为：**
- 绩效快照仅 rating 字符串；无 score/grade/revision/bonus mapping；oneTimeBonuses 未接。
- Offer 工资未透传，Onboarding 只 appendTerms，不建合同/薪酬；培训完成可手工传 score/result，不要求在册/签到/考试尝试。

**修改内容：**
- 绩效快照加 score_scaled/grade/source_revision；新增 performance_bonus_mappings（snapshot_id, payroll_item_code, period, amount_cents, mapping_version, status, approved_by/at），唯一并只允许 published+approved+period matching。
- Payroll `PerformanceBonusDirectory` 在 Snapshot 读取 approved mappings 到 oneTimeBonuses/规则变量；草稿/未映射/跨期不进入。
- Offer 增加 salary_scheme_code/currency；`TalentOnboardingDraftService` 透传 offer salary 到 Lifecycle payload；OnboardingEffect 通过 `PayrollCompensationDraftPort` 创建同一 employment 的 draft compensation，不直接写 Payroll 表。
- 培训新增 attempts 表与 `TrainingExamAttemptService::submit`；完成/成绩只能由有效 enrollment+checkin+attempt 回写；无培训津贴映射不产生工资项。
- 前端显示绩效映射、Offer 薪酬待办、培训 attempt/source；不显示内部 ID。

**对应需求：** AC-14、AC-15、AC-19；Design 5.1.7、流程 A/B、5.5 绩效/招聘/培训。

**依赖：** T05、T09、T14。

**验证方式：**
```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'PerformanceBonusPayrollTest|OfferCompensationDraftTest|TrainingExamAttemptSourceTest|PerformanceResultSnapshotTest|OfferOnboardingSinglePathTest|TrainingLifecycleTest'
```

**TDD 步骤：**
- [ ] Step 1：写 failing tests：未批准/未映射/跨期不发奖金、Offer 一次生成 draft、重复入职不重复、未报名不能提交考试、工资不产生培训津贴。
- [ ] Step 2：运行确认 FAIL。
- [ ] Step 3：迁移/模型/服务/端口；Payroll 接线。
- [ ] Step 4：前端面板/状态/来源；GREEN。
- [ ] Step 5：提交 `feat(v2.4): close performance offer and training payroll sources`。

---

### T16：通知真实投递位置与报表字段裁剪

**状态：** PENDING

**修改位置：**
- 新增 `backend/database/migrations/2026_11_28_001400_create_notification_deliveries.php`
- `backend/app/Modules/Engagement/Services/NotificationService.php`, `LogExternalNotificationChannel.php`, `NoticeAccessPolicy.php`
- `backend/app/Modules/Engagement/Http/Controllers/NotificationController.php`, `NotificationAdminController.php`
- `backend/app/Modules/Reporting/Services/ReportService.php`, `MetricQueryBuilder.php`, `ProjectionBuilder.php`
- `backend/app/Shared/Authorization/FieldSet.php`
- `backend/app/Modules/Reporting/Services/ExportService.php`（现有再鉴权保留）
- Tests: `backend/tests/Feature/Engagement/NotificationDeliveryAndRevocationTest.php`, `backend/tests/Feature/Reporting/ReportFieldProjectionTest.php`

**当前行为：**
- 通知先 in-app 后日志 stub，admin 无条件写 SENT；打开/已读不重查受众/撤回；工资条发布不发通知。
- ReportService columns 不过滤字段，MetricQueryBuilder 展开全部 attributes，ProjectionBuilder 写 gross/net/employer_cost；P2 数字可泄露。

**修改内容：**
- `notification_deliveries`：notification_id/channel/status PENDING/SENT/FAILED/attempts/provider_message_id/last_error/sent_at；ExternalNotificationChannel 返回 receipt，只有成功才 SENT；失败可重试、重复投递幂等。
- 通知 list/open/read 按当前 principal 重鉴权，检查公告撤回/链接过期/受众；工资条通知只带 payslip_id，不带金额。
- payslip publish 后发送仅含 ID 的通知（事务后 outbox/queue），通知读取再查 payslip 发布状态/权限。
- ReportService columns 与 attributes 由 FieldSet 过滤；金额字段无 P3 时删除而非 null；导出重新鉴权并字段裁剪；聚合不暴露单人明细。
- 前端报表列/导出错误中文，失败不显示空表；通知中心支持已读/失效状态。

**对应需求：** AC-16、AC-17、AC-18；Design 5.1.7、5.4、5.5 通知/报表、5.6 状态。

**依赖：** T11、T13、T14。

**验证方式：**
```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'NotificationDeliveryAndRevocationTest|ReportFieldProjectionTest|NoticeAudienceTest|ExportReauthorizationTest|ExportScopeTest'
cd frontend && pnpm test:unit -- reportCatalog.spec.ts reportErrorState.spec.ts
```

**TDD 步骤：**
- [ ] Step 1：写 failing tests：无 receipt 不 SENT、重复投递一次、撤回后旧链接 404/403、无 P2 字段金额缺失、导出再次鉴权、工资通知无金额。
- [ ] Step 2：运行确认 FAIL。
- [ ] Step 3：迁移/投递抽象/通知鉴权/报表过滤。
- [ ] Step 4：前端状态/列/导出；GREEN。
- [ ] Step 5：提交 `feat(v2.4): make notification delivery and report fields real`。

---

### T17：前端精确金额、对象动作、自助工资条与详情补全

**状态：** PENDING

**修改位置：**
- `frontend/src/utils/format.ts`
- `frontend/src/components/MoneyText.vue`
- 所有调用 `formatMoney` / `parseYuanToCents` 的页面（以 `rg` 结果为准，重点 payroll/time/reporting）
- `frontend/src/stores/session.ts`
- `frontend/src/modules/payroll/components/PayrollActionBar.vue`, `PayrollRunDetailPage.vue`, `PayrollPayslipsPanel.vue`, `PayslipDrawer.vue`
- `frontend/src/modules/platform/pages/WorkspaceView.vue` / `frontend/src/modules/platform/self-service`
- `frontend/src/router/registry.ts`（自助 detail 深链）
- `frontend/src/modules/payroll/api.ts`, `frontend/src/generated/api.ts`
- Tests: `frontend/tests/unit/moneyExact.spec.ts`, `payrollAllowedActions.spec.ts`, `selfPayslip.spec.ts`, `contractFormDrawer.spec.ts`, `pageErrorIntegration.spec.ts`

**当前行为：**
- `formatMoney` 把整数除以 100 后交给 Intl，金额有 Number 精度/固定 ¥ 问题；`MoneyText` 只接受 number。
- 详情页面没有统一 allowed_actions；ActionBar 客户端按 capability/status 推导，且显示 capability；部分详情显示内部 ID。
- 员工自助入口仍跳 `/payroll`（要求 payroll.run.read），本人工资条未列表化/详情。
- 部分表单英文枚举（如 `ACTIVE`）和英文错误。

**修改内容：**
- `formatMoney(cents: string|number|bigint|null, currency?)`：BigInt/字符串精确计算分转元，支持负数、两位小数、公司币种；不经过 float。`parseYuanToCents` 用字符串/BigInt 校验，拒绝超整型/非法小数。
- 所有页面传入 API 金额字段（安全整数可 number）；`MoneyText` props 改 `string|number|bigint`；移除浮点除法路径。
- 详情响应消费 `allowed_actions`；ActionGate/按钮不显示 capability/内部 ID；服务端动作 key 与 generated manifest 一致；统一 loading/empty/error/forbidden/partial/success/conflict 状态。
- 自助工资条路由 `/workspace/payslips`（或既有 self-service 路径）只使用 `/v1/payroll/payslips`，支持期间/明细/发布状态，不调用管理 `/payroll` API。
- 所有页面中文枚举映射、错误中文/request_id、刷新保留筛选/分页/选中，提交防重复/失败保留输入。
- 补详情动作（合同、请假、加班、出差、工资批次、公告、报表），确认无需修改的列表入口只在计划注明。

**对应需求：** AC-03、AC-05、AC-16、AC-17、AC-18、AC-19；Design 5.6 全部、5.5 工资条/自助。

**依赖：** T02、T03、T07、T08、T11、T13、T14、T15、T16。

**验证方式：**
```bash
cd frontend
pnpm test:unit -- moneyExact.spec.ts payrollAllowedActions.spec.ts selfPayslip.spec.ts contractFormDrawer.spec.ts pageErrorIntegration.spec.ts
pnpm typecheck
pnpm lint
pnpm build
cd ..
pnpm actions:check && pnpm page-components:check && pnpm page-model:check && pnpm ui-primitives:check && pnpm copy:check
```

**TDD 步骤：**
- [ ] Step 1：写 failing unit tests：超过 Number 安全位金额、负数、两位小数、无 P3 金额隐藏、自助入口不要求 payroll.run.read、内部 capability 不显示。
- [ ] Step 2：运行确认 FAIL。
- [ ] Step 3：改 format/MoneyText/API client/session/Button；接服务端 allowed_actions。
- [ ] Step 4：补详情/自助/中文状态/错误；运行 typecheck/lint/build 与审计。
- [ ] Step 5：提交 `feat(v2.4): make money actions and self service exact`。

---

### T18：Playwright MCP 全后台验收资产与执行

**状态：** PENDING

**修改位置：**
- 新增 `docs/implementation/v24/21-module-155-entry-acceptance-matrix.md`（从 V2.3 矩阵和 V2.4 T01–T24 映射，unique coverage）
- 新增 `evidence/v24/mcp/README.md`、`evidence/v24/mcp/<module>-<function>.md`
- `frontend/playwright.real.config.ts`（真实模式仅确认已有配置，不把自动测试当验收）
- `docs/implementation/v24/18-mcp-runbook.md`
- `docs/implementation/v24/19-evidence-index.md`

**当前行为：**
- V2.3 有 21 模块和 155 入口历史矩阵，但 V2.4 T01–T24、当前动作、跨模块 12 条未统一到可执行 MCP 清单。
- 自动 test 和 `--list` 存在，不能作为人工验收证据。

**修改内容：**
- 建唯一覆盖矩阵：模块/历史入口/功能卡/当前页面动作/当前 API/角色/前置数据/上下游/T01–T24/验收项/状态/证据链接。对 21×155×458 的引用使用基线索引不复制内容；每条至少有一个判定。
- 编写 MCP runbook：真实 `VITE_API_MODE=http`、隔离 MySQL/Redis/对象存储、角色账号、前置数据脚本、串行执行、每动作截图/快照/网络结果/刷新/重登/撤权、PASS/FAIL/BLOCKED。
- 按 21 模块执行人工操作，优先核心 AC-02–AC-20 的路径；每项保存证据；失败必须回补代码/测试。
- 对未执行项写 `NOT RUN`，不得以自动测试代替。至少覆盖 loading/empty/error/forbidden/partial/conflict，以及角色差异和旧链接。
- 最终生成 evidence index 和未通过/阻断清单。

**对应需求：** AC-01、AC-21、AC-22，以及每个 AC 的浏览器证据要求；Design 5.6 Playwright MCP。

**依赖：** T01–T17 的功能行为；T18 只允许在功能基本完成后开始，不允许以 MCP 验收替代缺失实现。

**验证方式：**
```bash
# 环境启动和账号构造按 runbook 执行
cd frontend
VITE_API_MODE=http pnpm build
pnpm exec playwright test --config=playwright.real.config.ts --list
# 人工 MCP 验收每项记录截图/快照/结论
```

**TDD/验收步骤：**
- [ ] Step 1：核对 21 modules/155 entries/458 cards/T01–T24 唯一映射，缺项补清单。
- [ ] Step 2：启动隔离真实栈和干净安装，构造角色/业务数据。
- [ ] Step 3：逐模块执行主流程、状态流程、边界、权限差异和回归；记录证据。
- [ ] Step 4：对 FAIL 回补最小测试和实现，重跑对应 MCP 路径。
- [ ] Step 5：更新矩阵状态和 evidence index；输出阻断项。

---

### T19：迁移恢复、全门禁与最终 Requirement 判定

**状态：** PENDING

**修改位置：**
- 新增 `docs/implementation/v24/20-migration-reconciliation.md`
- 新增 `docs/implementation/v24/21-final-requirement-acceptance.md`
- 新增 `scripts/audit_v24_acceptance.mjs`（如已有审计可扩展，不重复造）
- `docs/runbooks/*`（仅更新实际变化）
- 测试/证据目录 `docs/implementation/v24/`

**当前行为：**
- 本地 SQLite 迁移不完整；真实 MySQL/Redis/对象存储和备份恢复尚未证明；`pnpm gate:all` 基线曾失败；历史工资/YTD 迁移冲突需清单化。

**修改内容：**
- 对所有新表/字段做 forward migration + backfill 策略：空库干净安装、旧库升级、unresolved 清单、历史工资条/快照/合同/YTD 核对；不使用假默认、不静默丢弃。
- 运行完整 `pnpm gate:all`、真实栈迁移/升级/重复安装、备份恢复、权限/工资对账；记录 HEAD、命令、exit code、日志、截图/RPO/RTO。
- 按 AC-01–AC-22 逐条形成 Requirement acceptance 勾选；没有证据的条目不勾选。
- 只有关键 blocker、金额泄漏、演示数据、工资对账差异为零（或有正式批准解释）且全部当前后台功能有 MCP 证据才 GO；否则 NO-GO 并列原因。
- 最终输出不得以“Plan 完成”替代业务行为完成。

**对应需求：** AC-02、AC-12、AC-20、AC-21、AC-22；Design 5.8、6 风险 7/8/10/11。

**依赖：** T01–T18 全部 COMPLETE；任何一项未完成则不得进入最终 GO。

**验证方式：**
```bash
pnpm gate:all
cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter '...' 
# 按 runbook 启真实栈、migrate:fresh --seed、安装/升级/备份恢复
# 汇总 MCP 证据索引并逐 AC 判定
```

**TDD 步骤：**
- [ ] Step 1：写迁移/升级/恢复测试与核对 fixture；历史关键数据 before/after 快照。
- [ ] Step 2：运行空库/旧库/重复安装；确认零 demo、零重复管理员、pending 迁移被 health 检出。
- [ ] Step 3：跑全门禁和真实栈；修复回归后重跑。
- [ ] Step 4：逐 AC 填写 PASS/FAIL/BLOCKED 与证据路径；未证据不通过。
- [ ] Step 5：输出最终 GO/NO-GO、剩余风险与回滚条件。

---

## 4. 关联影响逐项检查表

每个任务完成前按 Impact Map 检查；无修改也要在证据文件中写原因。

| 影响面 | 必查位置 | 本计划落点 |
|---|---|---|
| 角色/页面/菜单 | `/me/permissions`、registry、RolePage、PageActionCatalog、ActionGate | T01/T02/T17 |
| API/OpenAPI/action manifest | `backend/routes/api.php`、`docs/openapi/openapi.yaml`、`docs/generated/action-input.json`、`scripts/generate_action_manifest.mjs` | 所有新增 API 任务 |
| 员工/合同/组织 | Employee detail、contract ledger、compensation drawer、assignment as-of | T03/T04/T05/T17 |
| Time 上游/下游 | Attendance/Leave/Overtime/Travel、closure、PayrollInputDirectory、Report | T06–T08 |
| Payroll 状态/金额 | Run states、approval、publish/disbursement、correction、payslip、YTD | T09–T13 |
| Benefits/Performance/Talent | BenefitController, PerformanceCycle, Offer, Training, Payroll ports | T14/T15 |
| 通知/报表/导出 | Notification, Notice, ReportService, ExportService, FieldSet | T16 |
| 缓存/会话/撤权 | `auth_version`, session store, old tab write | T02/T07/T11 |
| 历史数据/迁移 | migrations, old snapshots, YTD, employment_terms | T00/T03/T04/T13/T19 |
| 列表/详情/搜索/筛选/分页 | all workspaces and detail routes | T11/T15/T16/T17 |
| 空/错/部分/冲突状态 | PageShell, composables, API errors | T17/T18 |
| 审计/Outbox/定时任务 | AuditRecorder, Outbox, jobs/commands | T02/T07/T11/T13/T16 |

---

## 5. 验证矩阵（执行时记录实际结果）

### 5.1 需求验收

- [ ] AC-01 21 模块/155 入口/458 功能卡/12 跨模块/V2.4 T01–T24 唯一覆盖。
- [ ] AC-02 空库只唯一超管/零业务数据；旧库升级数据一致；重复安装幂等；生产拒绝 demo/reset。
- [ ] AC-03 七类角色登录/菜单/动作/数据；密码登录；未绑定员工不能自助；撤权即时生效。
- [ ] AC-04 员工→任职→合同→薪酬同员工/同有效期；失败无半条；详情同源。
- [ ] AC-05 页面/动作/对象/字段/范围/导出权限真实；无越权金额/证件/存在性泄漏。
- [ ] AC-06 同批 A月薪/B计时各用自身合同方案参数；合计=明细。
- [ ] AC-07 样例 8000/380/600/500/9480/1400/8080/9980，逐项来源公式。
- [ ] AC-08 请假取消+实际出勤 -> 餐补400/无欠扣/实发8500；未出勤按缺勤。
- [ ] AC-09 10天8000+10天9000 -> 8500；审批前/未来日期不生效；税费一次。
- [ ] AC-10 加班/调休/出差/打卡/请假时间互斥；不二次计薪/额度。
- [ ] AC-11 固定/比例/条件/阶梯/组合规则真实计算；非法阻断；发布版本不可改。
- [ ] AC-12 8080→8300=+220；再→8350=+50；累计8350；原条不变/不重复。
- [ ] AC-13 调薪/请假/离职/合同终止/工资审批+执行一致；内容变化重审；重放幂等。
- [ ] AC-14 不同参保组合、个人/单位金额、绩效映射进入正确工资项；历史不受费率更新影响。
- [ ] AC-15 Offer→员工/合同/初始薪酬一次；培训记录来源真实；无培训津贴不加工资。
- [ ] AC-16 看板/报表/导出汇总明细一致；历史组织不漂移；重鉴权/无金额列。
- [ ] AC-17 公告/通知有效受众；重复投递一次；工资通知无金额；撤权/撤回/过期链接失效。
- [ ] AC-18 全页面中文、金额元两位、公司时区、对象选择器、防重复、刷新保留上下文。
- [ ] AC-19 缺数据/不存在/冲突/重复/权限变化/失败均有中文可恢复结果；无半成功/假零/冻结覆盖。
- [ ] AC-20 历史员工/合同/假勤/工资条/角色/审批未被未授权修改；历史快照可重放。
- [ ] AC-21 21模块/155入口/458功能卡/当前动作/V2.4 T01–T24 均有 MCP 证据和 PASS/FAIL/BLOCKED。
- [ ] AC-22 P0/P1=0；未授权金额泄漏=0；新库 demo=0；工资对账差异=0 或正式解释；备份恢复通过。

### 5.2 主流程

- [ ] 干净安装 → 超管登录 → 建组织/员工 → 合同 → 初始薪酬 → Time 事实 → 工资批次 → 计算/复核/审批 → 发布/发放 → 工资条自助。
- [ ] 调薪审批 → 月中分段 → 工资草稿刷新 → 审批前不变/审批后生效。
- [ ] 请假审批 → 撤销 → 实际出勤 → 快照/薪资变化。
- [ ] 已发工资 → 更正 +220 → 再更正 +50 → YTD/报表/工资条链一致。

### 5.3 状态流程

- [ ] Payroll：DRAFT → INPUT_LOCKED → CALCULATING → CALCULATED / CALCULATED_WITH_BLOCKERS；CALCULATED → REVIEWED → APPROVED；publish/disburse 独立 → CLOSED；非法路径 409。
- [ ] Contract：DRAFT → PENDING_EFFECTIVE → ACTIVE → TERMINATED/EXPIRED/ARCHIVED；未来生效不提前。
- [ ] Compensation：DRAFT → PENDING_APPROVAL → SCHEDULED → ACTIVE → SUPERSEDED/ENDED；append-only。
- [ ] Leave/Overtime/Travel：DRAFT → SUBMITTED → IN_REVIEW → APPROVED/REJECTED/RETURNED/WITHDRAWN → EFFECTIVE/CONFIRMED → COMPLETED/CANCELLED/REVERSED。
- [ ] Workflow：Case/Decision/Effect 状态与业务投影分离；失败可见/可重试/幂等。
- [ ] Time closure：OPEN/CLOSING/CLOSED → REOPENED → 新版本 CLOSED。

### 5.4 边界/关联回归

- [ ] 缺合同、缺薪酬、缺 closed time fact、缺法定参数、未知变量、循环、除零、溢出、币种冲突必须阻断。
- [ ] 同员工同期间重复普通批次/重复更正/重复发布/重复投递/重复 Effect 不重复写入。
- [ ] 月中入职/离职/调岗/调薪、跨月请假、夜间班次、出差/请假/加班重叠。
- [ ] 无页面/无动作/跨 anchor/字段 P2/P3/旧标签撤权/导出重鉴权。
- [ ] 旧入口能力保留、详情深链、历史工资条和合同版本可读；不恢复 155 个新菜单。
- [ ] 空/错/部分/冲突页面状态，中文文案、金额/日期/关联选择器。

### 5.5 技术门禁

- [ ] `php artisan test` 或权威等价 PHPUnit 通过。
- [ ] Pint、PHPStan、capability audit、arch test 通过。
- [ ] OpenAPI lint/validate/check、action manifest 通过。
- [ ] typecheck、lint、unit、frontend build、所有 product audits 通过。
- [ ] Playwright MCP 真实栈验收完成（自动 Playwright test 不是替代）。
- [ ] 迁移/升级/重复安装/备份恢复/工资对账证据完成。

---

## 6. 完成与最终输出格式

完成标准不是“所有 checkbox 已勾”，而是：Requirement 行为全部满足、Design 已落地、关联影响已核对、必要测试通过、没有已知阻塞。最终回复必须使用：

```text
# Implementation Plan

1. ...

# 已完成修改

* ...

# 关联影响处理

* ...

# 验证结果

* ...

# 未修改的重要关联位置

* ...
* 原因：...

# 剩余风险

无 / ...

# Requirement 验收

* [x] ...
```

若任何 AC 未满足或证据缺失，结论必须是 `NO-GO`，并列出 `FAIL/BLOCKED/NOT RUN`，不得使用“计划全部执行完成”作为完成标准。
