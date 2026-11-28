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

## 3. 开始前已知阻塞 / 未映射

> 这些不是“执行后的 BLOCKED 结论”，而是 T18 开始前已确认的阻塞或未映射。执行时每个受影响证据项必须单独登记，不得只在总索引写一句话。

| ID | 类型 | 问题 | 影响 | 解除条件 | 当前状态 |
|---|---|---|---|---|---|
| `GAP-01` | `BLOCKED` | 458 功能卡明细不在仓库，只有模块汇总数 | AC-01/AC-21 的 458 卡逐项覆盖 | 提供权威 458 卡清单/整改包，或用户正式修订口径 | `OPEN` |
| `GAP-02` | `UNMAPPED` | master plan 只有 T00–T19，AC/计划要求 T01–T24 | AC-01/AC-21 任务覆盖 | 提供 T20–T24 权威任务卡或修订 | `OPEN` |
| `GAP-03` | `BLOCKED` | T05–T17 在 master plan 仍为 PENDING | T18 依赖；MCP 业务执行 | 相关任务完成并有证据 | `OPEN` |
| `GAP-04` | `CONFLICT` | T06 计划表 PENDING vs `06-time-facts/implementation-report.md` COMPLETE | M09–M12 前置状态 | 统一计划/证据状态 | `OPEN` |
| `GAP-05` | `BLOCKED` | 角色分配 UI 无 `binding_anchors` | R03/R05/R08 无法通过 UI 构造 | 补锚点 UI 或用户批准受控 CLI 仅造前置数据 | `OPEN` |
| `GAP-06` | `UNMAPPED` | M21 无备份/恢复/升级 UI | 6 卡中的安装/升级/备份恢复 | 转 T19 运维证据；T18 不伪造 | `OPEN` |
| `GAP-07` | `UNMAPPED` | M17 员工档案无培训记录 Tab | E2E-09/M17 卡片 | 补只读入口或用户接受未映射 | `OPEN` |
| `GAP-08` | `UNMAPPED` | M09 无本人打卡写端点 | “打卡”卡片可能不可执行 | 导航确认；无入口则标 UNMAPPED | `OPEN` |
| `GAP-09` | `UNMAPPED` | M08 manifest 声明 activate，但 ContractDetailPage 无按钮 | 激活动作 UI 验收 | 补按钮/页面或改口径 | `OPEN` |
| `GAP-10` | `COUNT` | M19 入口数摘要 6 vs 附录 A 7 页 | 模块计数口径 | 统一为 V2.3 矩阵 6 条 | `OPEN` |
| `GAP-11` | `COUNT` | V2.3 模块汇总 M01=7 页 vs 矩阵 8 条；M19=7 vs 6 | 附录 A 汇总与矩阵不完全一致 | 以 155 行矩阵为准，保留目标口径 | `OPEN` |
| `GAP-12` | `CONTRACT` | Dashboard metric OpenAPI 旧 schema 与实现 DTO 漂移 | M02/M03 契约断言 | 以实际响应+契约测试统一 | `OPEN` |
| `GAP-13` | `DOC` | `registry.ts` 头注释仍写 155，实际 37 | 计数阅读 | 以数组/测试为准 | `OPEN` |
| `GAP-14` | `ENV` | `frontend/.env.example` 写 `VITE_PROXY_TARGET`，`vite.config.ts` 用 `VITE_DEV_API_PROXY_TARGET` | 本地代理启动 | 统一变量或明确使用正确变量 | `OPEN` |

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
- 458 卡逐卡结论（仅在权威卡片源补齐后）。
- T20–T24 逐任务结论（仅在权威任务卡补齐后）。

### 6.2 当前阻塞/未映射

- 458 卡明细缺失（GAP-01）。
- T20–T24 缺任务卡（GAP-02）。
- T05–T17 未完成（GAP-03）。
- T06 状态冲突（GAP-04）。
- 角色锚点 UI 缺失（GAP-05）。
- M21 备份/恢复/升级无 UI（GAP-06）。
- M17 员工档案培训入口缺失（GAP-07）。
- M09 打卡写入口缺失/未确认（GAP-08）。
- M08 activate UI 缺失（GAP-09）。

### 6.3 完成条件

T18 只有同时满足以下条件才可判 `PASS`：

1. 21 模块证据齐全，且无未解释 `FAIL`；已明确 `BLOCKED/UNMAPPED` 的项由用户正式接受或补齐。
2. 12 条跨模块闭环全部真实执行并通过。
3. 155 历史入口逐条有证据或明确的 404/目标页结论。
4. 458 卡逐卡有结论；若源缺失，AC-01/AC-21 不能 PASS。
5. T01–T24 逐项有结论；T20–T24 缺失时不能 PASS。
6. 所有证据按模板保存，索引与实际文件一致。
7. 无未关闭 Sev1/Sev2 缺陷；金额泄漏=0；旧标签撤权写=拒绝；工资通知无金额。
8. 真实 `VITE_API_MODE=http`、MySQL/Redis/对象存储、干净安装、5+ 角色均可复核。

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
Known gaps: GAP-01..GAP-14
```

上述计数是本文件的真实初始值，不构成任何业务验收结论。
