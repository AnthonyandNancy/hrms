# FactoryHR V2.4 T18｜MCP 验收证据索引

- **状态：** 初始索引；所有未执行项 `NOT RUN`
- **日期：** 2026-11-28
- **HEAD：** `a5c90a199cb2b4d067495d4560dc517ec6684295`
- **分支：** `v24-t18-acceptance-matrix`
- **矩阵：** `21-module-mcp-acceptance-matrix.md`
- **Runbook：** `mcp-runbook.md`
- **模板：** `evidence-template.md`
- **结论规则：** 只有真实 `VITE_API_MODE=http` 人工 MCP 执行并有证据才可改 `PASS`；未执行一律 `NOT RUN`；本文档初始状态禁止出现任何 `PASS`。

---

## 1. 状态汇总

| 状态 | 数量 | 说明 |
|---|---:|---|
| `PASS` | **0** | 尚未执行任何 MCP 项 |
| `FAIL` | 0 | 尚未执行 |
| `BLOCKED` | 0（执行前汇总） | 依赖/前置问题单列 §3，不计入已执行失败 |
| `NOT RUN` | 21 模块 + 12 闭环 + 环境项 | 全部待执行 |

> 本索引**不预先把已知缺口填成 BLOCKED 执行结论**；它们列在 §3 作为“开始前已知阻塞/未映射”，执行时按证据项正式登记。

## 2. 环境与基础证据

| 证据 ID | 内容 | 前置 | 状态 | 证据路径 |
|---|---|---|---|---|
| `MCP-ENV-001` | HEAD/分支/工作树/build SHA/`/v1/meta` 追溯 | 无 | `NOT RUN` | `evidence/env/MCP-ENV-001.md` |
| `MCP-ENV-002` | `/v1/health/ready` 六项检查 | 隔离 MySQL/Redis/MinIO | `NOT RUN` | `evidence/env/MCP-ENV-002.md` |
| `MCP-ENV-003` | 空库干净安装：唯一管理员/零业务数据/重复安装幂等 | 隔离数据库 | `NOT RUN` | `evidence/env/MCP-ENV-003.md` |
| `MCP-ENV-004` | 生产拒绝 demo/reset | 独立 test env | `NOT RUN` | `evidence/env/MCP-ENV-004.md` |
| `MCP-ENV-005` | 合成数据清单与 ID | 账号/锚点 | `NOT RUN` | `evidence/env/MCP-ENV-005.md` |

## 3. 开始前已知阻塞 / 未映射（GAP-01–GAP-20 全量）

> 这些不是“执行后的 BLOCKED 结论”，而是 T18 开始前已确认的阻塞或未映射。执行时每个受影响证据项必须单独登记，不得只在总索引写一句话。完整事实、影响与解除条件见 `gap-log.md`。

| ID | 类型 | 问题 | 影响 | 解除条件 | 当前状态 |
|---|---|---|---|---|---|
| `GAP-01` | `BLOCKED` | 458 卡源包存在但**权威性待确认**（包内“待审批/待确认”） | AC-01/AC-21 不能把草稿当批准需求 | 源包获批/修订；卡明细已提取到 `458-card-index.md` | `OPEN` |
| `GAP-02` | `UNMAPPED` | master plan 只有 T00–T19；AC/计划要求 T01–T24 | AC-01/AC-21 任务覆盖 | 提供 T20–T24 权威任务卡或修订 | `OPEN` |
| `GAP-03` | `BLOCKED` | T05–T17 在 master plan 仍为 PENDING | T18 依赖；MCP 业务执行 | 相关任务完成并有证据 | `OPEN` |
| `GAP-04` | `CONFLICT` | T06 计划表 PENDING vs `06-time-facts/implementation-report.md` COMPLETE | M09–M12 前置状态 | 统一计划/证据状态 | `OPEN` |
| `GAP-05` | `BLOCKED` | R02 的 CASE 审批及 R03/R04/R05/R08 需要 CASE/ORG_UNIT/PAYROLL_RUN anchor_id，但 `iam:bind-role` 只写 null、`UserRoleDrawer` 不传 `binding_anchors` | R02 部分可构造（M20 CASE 审批 BLOCKED）；R03/R04/R05/R08 无法构造 | T02/T19 增加 anchor binding UI/命令 | `OPEN` |
| `GAP-06` | `UNMAPPED` | M21 无备份/恢复/升级 UI | 6 卡中的安装/升级/备份恢复 | 转 T19 运维证据；T18 不伪造 | `OPEN` |
| `GAP-07` | `UNMAPPED` | M17 员工档案无培训记录 Tab | E2E-09/M17 卡片 | 补只读入口或用户接受未映射 | `OPEN` |
| `GAP-08` | `UNMAPPED` | M09 无本人打卡写端点 | “打卡”卡片可能不可执行 | 导航确认；无入口则标 UNMAPPED | `OPEN` |
| `GAP-09` | `UNMAPPED` | M08 manifest 声明 activate，但 ContractDetailPage 无按钮 | 激活动作 UI 验收 | 补按钮/页面或改口径 | `OPEN` |
| `GAP-10` | `COUNT` | M19 入口数摘要 6 vs 附录 A 7 页 | 模块计数口径 | 统一为 V2.3 矩阵 6 条 | `OPEN` |
| `GAP-11` | `COUNT` | M01=7 页（源包）vs 155 入口 8；M19=7 页（源包）vs 155 入口 6；入口 141 归 M01 | 页面数≠入口数口径 | **唯一入口口径：§2/§2.1/§4A 均按 155 入口号；141 归 M01；源包页面数仅作来源** | `CLOSED（口径已统一）` |
| `GAP-12` | `CONTRACT` | Dashboard metric OpenAPI 旧 schema 与实现 DTO 漂移 | M02/M03 契约断言 | 以实际响应+契约测试统一 | `OPEN` |
| `GAP-13` | `DOC` | `registry.ts` 头注释仍写 155，实际 37 | 计数阅读 | 以数组/测试为准 | `OPEN` |
| `GAP-14` | `ENV` | `frontend/.env.example` 写 `VITE_PROXY_TARGET`，`vite.config.ts` 用 `VITE_DEV_API_PROXY_TARGET` | 本地代理启动 | 统一变量或明确使用正确变量 | `OPEN` |
| `GAP-15` | `ACTION` | 页面可见写动作未入 generated action manifest（M03/M04/M05/M09/M13/M14/M15/M16/M17/M18/M20 等） | 动作覆盖/AC-05/AC-21 | T02/T17 补齐 manifest/registry；或按 UI 验收并保留风险 | `OPEN` |
| `GAP-16` | `EVIDENCE` | T00–T04 evidence 引用 `gate-all.log`，文件不存在 | 证据可复核性 | 补原始日志或 T19 重跑 | `OPEN` |
| `GAP-17` | `ROLE` | `ATTENDANCE_SPECIALIST`/`PAYROLL_APPROVER`/`RECRUITER`/`AUDITOR` 无 demo 账号 | R01–R09（7 核心 + 2 补充）覆盖 | 补 seed/账号；锚点部分仍受 GAP-05 | `OPEN` |
| `GAP-18` | `ENV` | MFA 默认关闭，但 demo seed 创建并打印 TOTP；`backend/.env` 可被改 true | 登录 200 vs 202 分支 | 固定 compose `IAM_MFA_ENABLED=false` 并记录；MFA=true 单独证据 | `OPEN` |
| `GAP-19` | `COUNT` | 当前 37 页面含 `/performance/cycles/:id`，不在 V2.3 目标 36 清单 | 页面计数 | 用户确认 37 为正式口径或后续改为 36 | `OPEN` |
| `GAP-20` | `CARD-MAP` | 458 卡已提取 ID/标题，但源卡没有“卡→`page_code`/action/API”稳定映射 | 卡级执行槽 | 用户/评审确认卡到当前页面/动作映射规则；不得猜 | `OPEN` |

## 3A. 458 功能卡索引

完整 155 页/458 卡 ID 与标题见 `458-card-index.md`。源包状态为**待审批/待确认**；本索引只建立可追溯槽位，不把卡片当已批准需求，也不预写 PASS。

| 模块 | 页面数 | 卡数 | 卡索引范围 | 执行结论 |
|---|---:|---:|---|---|
| M01 | 7 | 18 | `M01-P001`–`M01-P006`,`M01-P150` | `NOT RUN` |
| M02 | 6 | 13 | `M02-P007`–`M02-P012` | `NOT RUN` |
| M03 | 9 | 26 | `M03-P013`–`M03-P021` | `NOT RUN` |
| M04 | 10 | 29 | `M04-P022`–`M04-P031` | `NOT RUN` |
| M05 | 5 | 11 | `M05-P032`–`M05-P036` | `NOT RUN` |
| M06 | 5 | 14 | `M06-P037`–`M06-P041` | `NOT RUN` |
| M07 | 9 | 30 | `M07-P042`–`M07-P050` | `NOT RUN` |
| M08 | 6 | 19 | `M08-P051`–`M08-P056` | `NOT RUN` |
| M09 | 10 | 37 | `M09-P057`–`M09-P066` | `NOT RUN` |
| M10 | 8 | 21 | `M10-P067`–`M10-P074` | `NOT RUN` |
| M11 | 6 | 18 | `M11-P075`–`M11-P080` | `NOT RUN` |
| M12 | 7 | 17 | `M12-P081`–`M12-P087` | `NOT RUN` |
| M13 | 13 | 49 | `M13-P088`–`M13-P100` | `NOT RUN` |
| M14 | 1 | 2 | `M14-P151` | `NOT RUN` |
| M15 | 10 | 30 | `M15-P101`–`M15-P110` | `NOT RUN` |
| M16 | 16 | 43 | `M16-P111`–`M16-P126` | `NOT RUN` |
| M17 | 9 | 36 | `M17-P127`–`M17-P135` | `NOT RUN` |
| M18 | 6 | 17 | `M18-P136`–`M18-P140`,`M18-P154` | `NOT RUN` |
| M19 | 7 | 15 | `M19-P141`–`M19-P147` | `NOT RUN` |
| M20 | 2 | 7 | `M20-P148`–`M20-P149` | `NOT RUN` |
| M21 | 3 | 6 | `M21-P152`,`M21-P153`,`M21-P155` | `NOT RUN` |
| **合计** | **155** | **458** | 完整 ID/标题见 `458-card-index.md` | **全部 NOT RUN** |

> **唯一入口口径：** 155 入口按入口号归属为 M01=8（含 141）、M19=6；`M19-P141` 只是源包页面 ID。源包页面数 M01=7、M19=7 仅作来源，不参与入口计数。

## 4. 21 模块证据索引

> 每模块的详细项拆分为 `<模块>-NNN`，至少覆盖矩阵 §8 的 12 类。此处保持模块级初始槽位；执行时新增子行，不覆盖历史。

| 模块 | 证据 ID | 当前唯一结论 | 当前缺陷数 | 当前阻断/未映射 | 最后执行（UTC） | 证据目录 |
|---|---|---|---|---:|---|---|
| M01 | `MCP-M01` | `NOT RUN` | 0 | 未绑定管理员数据未构造；T17 PENDING | — | `evidence/MCP-M01/` |
| M02 | `MCP-M02` | `NOT RUN` | 0 | T16/T17 PENDING | — | `evidence/MCP-M02/` |
| M03 | `MCP-M03` | `NOT RUN` | 0 | T16/T17 PENDING；订阅入口待确认 | — | `evidence/MCP-M03/` |
| M04 | `MCP-M04` | `NOT RUN` | 0 | T05/T17 PENDING；onboard/import/export action 未入 manifest | — | `evidence/MCP-M04/` |
| M05 | `MCP-M05` | `NOT RUN` | 0 | T17 PENDING；成员/编制 action 未入 manifest | — | `evidence/MCP-M05/` |
| M06 | `MCP-M06` | `NOT RUN` | 0 | T17 PENDING | — | `evidence/MCP-M06/` |
| M07 | `MCP-M07` | `NOT RUN` | 0 | T05/T17 PENDING；详情动作未入 manifest | — | `evidence/MCP-M07/` |
| M08 | `MCP-M08` | `NOT RUN` | 0 | T17 PENDING；activate UI 缺失 | — | `evidence/MCP-M08/` |
| M09 | `MCP-M09` | `NOT RUN` | 0 | T06 状态冲突；R03 未构造；打卡入口未确认 | — | `evidence/MCP-M09/` |
| M10 | `MCP-M10` | `NOT RUN` | 0 | T07/T17 PENDING；R03 未构造 | — | `evidence/MCP-M10/` |
| M11 | `MCP-M11` | `NOT RUN` | 0 | T08/T17 PENDING；R03 未构造 | — | `evidence/MCP-M11/` |
| M12 | `MCP-M12` | `NOT RUN` | 0 | T08/T17 PENDING | — | `evidence/MCP-M12/` |
| M13 | `MCP-M13` | `NOT RUN` | 0 | T09–T13/T17 PENDING；R05 锚点未构造 | — | `evidence/MCP-M13/` |
| M14 | `MCP-M14` | `NOT RUN` | 0 | T14 PENDING；BENEFITS_ADMIN 未 seed | — | `evidence/MCP-M14/` |
| M15 | `MCP-M15` | `NOT RUN` | 0 | T15/T17 PENDING；详情动作未入 manifest | — | `evidence/MCP-M15/` |
| M16 | `MCP-M16` | `NOT RUN` | 0 | T15/T17 PENDING；R08 锚点未构造 | — | `evidence/MCP-M16/` |
| M17 | `MCP-M17` | `NOT RUN` | 0 | T15/T17 PENDING；员工档案培训 Tab 未映射 | — | `evidence/MCP-M17/` |
| M18 | `MCP-M18` | `NOT RUN` | 0 | T16/T17 PENDING | — | `evidence/MCP-M18/` |
| M19 | `MCP-M19` | `NOT RUN` | 0 | T17 PENDING；角色锚点 UI 缺失 | — | `evidence/MCP-M19/` |
| M20 | `MCP-M20` | `NOT RUN` | 0 | T07/T11/T13 PENDING；详情动作未入 manifest | — | `evidence/MCP-M20/` |
| M21 | `MCP-M21` | `NOT RUN` | 0 | 备份/恢复/升级无 UI；T19 接管 | — | `evidence/MCP-M21/` |

> **入口计数唯一口径：** 155 入口按入口号归属；入口 141 归 M01（页面 ID 为 `M19-P141`，源包页面归属 M19）；M01=8、M19=6。禁止把源包页面数 7/7 当作入口数。
>
> **禁止**把上表的“Txx PENDING”直接写成执行结论 `BLOCKED`；执行时必须逐证据项记录阻塞原因和影响范围。

## 5. 12 条跨模块闭环索引

| ID | 闭环 | 依赖模块 | 当前结论 | 证据路径 |
|---|---|---|---|---|
| `MCP-X01` | 招聘转入职 | M16→M07/M04/M08/M13 | `NOT RUN` | `evidence/MCP-X01/` |
| `MCP-X02` | 请假到薪资 | M10→M06/M13 | `NOT RUN` | `evidence/MCP-X02/` |
| `MCP-X03` | HR 代请假 | M10/M19/M20 | `NOT RUN` | `evidence/MCP-X03/` |
| `MCP-X04` | 组织迁移影响权限与报表 | M05/M19/M03 | `NOT RUN` | `evidence/MCP-X04/` |
| `MCP-X05` | 调薪到工资条 | M07/M06/M13 | `NOT RUN` | `evidence/MCP-X05/` |
| `MCP-X06` | 加班到调休或计薪 | M11/M09/M13 | `NOT RUN` | `evidence/MCP-X06/` |
| `MCP-X07` | 出差到考勤与费用 | M12/M09/M03 | `NOT RUN` | `evidence/MCP-X07/` |
| `MCP-X08` | 绩效发布与申诉 | M15/M20/M13 | `NOT RUN` | `evidence/MCP-X08/` |
| `MCP-X09` | 培训到员工档案 | M17/M04/M20 | `NOT RUN` | `evidence/MCP-X09/` |
| `MCP-X10` | 角色授权到 API/导出 | M19/M03/M20 | `NOT RUN` | `evidence/MCP-X10/` |
| `MCP-X11` | 合同到期与续签 | M08/M07/M20 | `NOT RUN` | `evidence/MCP-X11/` |
| `MCP-X12` | 公告受众与回执 | M18/M19/M20 | `NOT RUN` | `evidence/MCP-X12/` |

## 6. 当前已知待执行/阻塞和完成条件

### 6.1 待执行

- 21 个模块的 MCP 主流程、状态、权限、边界、旧链接、刷新、重登/撤权。
- 12 条跨模块闭环。
- 环境/安装/健康/账号/数据前置证据。
- 155 个历史入口逐条的“当前可达/404/目标页/证据”结论。
- 458 卡逐卡结论（卡索引已齐；源包权威确认与 card→page/action 映射仍 OPEN）。
- T20–T24 逐任务结论（仅在权威任务卡补齐后）。

### 6.2 当前阻塞/未映射

- 458 卡源包存在但权威性待确认（GAP-01）；ID/标题已提取到 `458-card-index.md`。
- T20–T24 缺任务卡（GAP-02）。
- T05–T17 未完成（GAP-03）。
- T06 状态冲突（GAP-04）。
- R02 CASE 审批与 R03/R04/R05/R08 锚点 UI/命令缺失（GAP-05）；R02 公司级部分可验收。
- M21 备份/恢复/升级无 UI（GAP-06）。
- M17 员工档案培训入口缺失（GAP-07）。
- M09 打卡写入口缺失/未确认（GAP-08）。
- M08 activate UI 缺失（GAP-09）。
- M19 入口数摘要 6 vs 附录 A 7 页（GAP-10）；入口 141 已统一归 M01（GAP-11 口径已关闭）。
- Dashboard metric 契约漂移（GAP-12）。
- registry 头注释过期（GAP-13）。
- Vite 代理变量名不一致（GAP-14）。
- 页面写动作未入 action manifest（GAP-15）。
- T00–T04 gate 日志缺失（GAP-16）。
- 缺角色 demo 账号（GAP-17）。
- MFA 开关/seed 行为需固定（GAP-18）。
- `/performance/cycles/:id` 不在 36 目标清单（GAP-19）。
- 458 卡无稳定 card→page/action 映射（GAP-20）。

### 6.3 完成条件

T18 只有同时满足以下条件才可判 `PASS`：

1. 21 模块证据齐全，且无未解释 `FAIL`；已明确 `BLOCKED/UNMAPPED` 的项由用户正式接受或补齐。
2. 12 条跨模块闭环全部真实执行并通过。
3. 155 历史入口逐条有证据或明确的 404/目标页结论。
4. 458 卡逐卡有结论，且源包已获权威确认；未确认时 AC-01/AC-21 不能 PASS。
5. T01–T24 逐项有结论；T20–T24 缺失时不能 PASS。
6. 所有证据按模板保存，索引与实际文件一致。
7. 无未关闭 Sev1/Sev2 缺陷；金额泄漏=0；旧标签撤权写=拒绝；工资通知无金额。
8. 真实 `VITE_API_MODE=http`、MySQL/Redis/对象存储、干净安装、R01–R09（7 核心 + 2 补充）均可复核。

## 7. 更新规则

- 每次执行后：新增子证据行，更新模块结论、缺陷数、最后执行时间、证据路径。
- 失败：保留原证据；子编号递增；不得覆盖。
- 重试：记录新证据并引用旧证据。
- PASS：必须有 `evidence/MCP-...` 文件和模板全字段；没有证据不得改 PASS。
- 未执行：保持 `NOT RUN`；不得用空白或“待补”替代。
- 本索引的初始状态为 `NOT RUN`；任何 `PASS` 都必须在人工执行后由证据支持。

## 8. 初始声明

本索引创建时：

```text
MCP executed: 0
PASS: 0
FAIL: 0
BLOCKED (executed): 0
NOT RUN: 21 modules + 12 cross-module flows + env items
Known gaps: GAP-01..GAP-20
```

上述计数是本文件的真实初始值，不构成任何业务验收结论。

## 9. Execution update — 2026-11-28

```text
MCP executed: 1 evidence pack (T19-MCP-001)
PASS: 2 scoped observations (login/workspace, dashboard clean-install)
FAIL: 0 observed in executed scope
BLOCKED (executed): 0 new (existing R03/R04/R05/R08 + 458 authority remain)
NOT RUN: remaining 20 modules, 155 entries, 12 cross-module flows, 458 cards
```

- Evidence: `evidence/T19-MCP-001/README.md`, `workspace.png`, `workspace.yml`,
  `dashboard.png`, `dashboard.yml`.
- T18 remains **IN PROGRESS**; this entry does not imply module-wide PASS.

## 10. Execution update — T19-MCP-002

```text
MCP executed: 2 evidence packs total
PASS: +1 scoped observation (real role copy + PAYROLL_RUN anchor-id controls)
FAIL: 0 in executed scope
NOT RUN: saving/binding a real run anchor, role-scoped allow/deny, remaining matrix
```

- Evidence: `evidence/T19-MCP-002/`.

## 11. Execution update — T19-MCP-003

```text
MCP executed: 3 evidence packs total
PASS: +1 scoped observation (PAYROLL_RUN #1 allow / #2 deny)
FAIL: 0 in executed scope
NOT RUN: role-assignment UI, remaining modules/entries/cards
```

- Evidence: `evidence/T19-MCP-003/`.

## 12. Execution update — T19-MCP-004

```text
MCP executed: 4 evidence packs total
PASS: +1 scoped observation (user role assignment UI; overlapping-role API repaired)
FAIL: 1 defect found then fixed (500 authority_bindings unique conflict)
NOT RUN: remaining modules/entries/cards and deeper role-removal flows
```

- Evidence: `evidence/T19-MCP-004/`.

## 13. Execution update — T19-CRAWL-001

```text
MCP executed: 5 evidence packs total
PASS: 28 route-level HTTP 200 renders (24 without console errors)
PARTIAL: attendance/leave/overtime/travel no-employment 422/409 paths
NOT RUN: six dynamic detail routes, role matrix, 155 entries, 458 cards
```

- Evidence: `evidence/T19-CRAWL-001/`.
- Gap: GAP-21 no-employment admin self-service request gating.


## 14. Execution update — T19-CRAWL-002

```text
MCP executed: 6 evidence packs total
PASS: attendance/leave/overtime/travel no-employment states, zero non-2xx, zero console errors
GAP-21: FIXED in v24-t19e
NOT RUN: dynamic details, role matrix, 155 entries, 458 cards
```

- Evidence: `evidence/T19-CRAWL-002/`.

## 15. Execution update — T19-CRAWL-003

```text
MCP executed: 7 evidence packs total
PASS: 6 dynamic detail pages + employee 8-tab navigation; zero non-2xx/console errors
NOT RUN: role matrix, 155 historical entries, 458 cards, every destructive interaction
```

- Evidence: `evidence/T19-CRAWL-003/`.

## 16. Execution update — T19-MCP-006

```text
MCP executed: 8 evidence packs total
PASS: real UI role assignment with ORG_UNIT and CASE anchor IDs (201)
PARTIAL/BLOCKED: R08 page access after login (GAP-22 hardcoded COMPANY checks)
```

- Evidence: `evidence/T19-MCP-006/`.

## 17. Execution update — GAP-22 fixed

```text
MCP executed: 9 evidence packs total
PASS: R08 recruiter login + requisition/candidate/org-tree reads
FIXED: GAP-22 hardcoded COMPANY recruitment scope
NOT RUN: full R01–R09 matrix, 155 entries, 458 cards
```

- Evidence: `evidence/T19-MCP-006/`.

## 18. Execution update — T19-MCP-007

```text
MCP executed: 10 evidence packs total
PASS: R06 line-manager scoped employee directory (3 rows, zero non-2xx)
NOT RUN: remaining role matrix modules, 155 entries, 458 cards
```

- Evidence: `evidence/T19-MCP-007/`.


## 19. Execution update — T19-ROLES-001

```text
MCP executed: 11 evidence packs total
PASS: synthetic role accounts created/linked; R02/R06/R07/R08 login and selected pages
ENVIRONMENT: ready=503 due synthetic second administrator (expected for T18 seed)
NOT RUN: full R01–R09 module/action matrix, 155 entries, 458 cards
```

- Evidence: `evidence/T19-ROLES-001/`.
- GAP-24: R09 audit readiness is blocked by the synthetic second privileged
  administrator; final T19 clean baseline must be re-run without demo admin.

## 20. Execution update — T19-MCP-008

```text
MCP executed: 12 evidence packs total
PASS: R09 auditor audit page, readiness 200 with two admins
FIXED: GAP-24 readiness over-strict administrator count
NOT RUN: full module/action matrix, 155 entries, 458 cards
```

- Evidence: `evidence/T19-MCP-008/`.
