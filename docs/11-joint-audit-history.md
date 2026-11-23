# 联合审计与验收记录（History）

> 按时间顺序记录本轮"继续编写代码 → 联合审计 → 测试 → 验收"的实际动作、
> 发现、决策与证据。命令、退出码与提交哈希可复现；未执行项明确标注。

## 基线

| 项 | 值 |
|---|---|
| 起点提交 | `f80259d`（生产授权目录、工作区投影与演示数据种子） |
| 起点状态 | 后端 687 tests、前端 28 unit、mock E2E 11、真实后端 E2E 7 全绿 |
| 已知遗留 | gitleaks 因 Windows 路径失败；`evidence/acceptance-report.md` 缺失；ADR 仅 3 份且编号与批准计划冲突；`rollback_drill.sh` 缺失；计划 §4.9 要求的两个 Reporting 测试缺失 |

## 时间线

### 1. 修复证据链

- **gitleaks**：MSYS 路径转换把 `-v "D:/...:/repo"` 改写成 `C:/Program Files/Git/repo`。
  以 `MSYS_NO_PATHCONV=1` 重跑：`106 commits scanned`、`no leaks found`、退出码 0
  → `evidence/logs/gitleaks.txt`。
- **PHPStan 证据过期**（文件仍记录 3 个已修复错误）：重跑后 `[OK] No errors`
  → `evidence/logs/phpstan.txt`。

### 2. 计划核对（不是只看代码是否绿）

逐条比对 `docs/HRMS_V1.0_实施计划_30周路线图.md`，发现代码通过之外的真实缺口：

| 计划条款 | 实际状态 | 处置 |
|---|---|---|
| §15 ADR-0001–0015 | 仅有 3 份，且 0002/0003 与计划主题冲突 | 重编号并补齐 15 份 |
| §4.9 E09 `DashboardConsistencyTest`、`ExportScopeTest` | 不存在 | 新增，共 9 tests / 185 assertions |
| §4.9 E09 仪表盘"待审批/合同到期" | 真实模式前端硬编码空数组 | 后端新增有界面板 |
| §4.10 E10 `rollback_drill.sh` + 三份 runbook | 全部缺失 | 新增并通过 |
| §2.4 安全扫描含 SAST | 只有依赖审计与 secret scan | 新增 CodeQL workflow（GATE） |
| §4.9 仪表盘数字与下钻同口径 | 无端到端证明 | 新增一致性测试 |

### 3. 审计期间发现的真实缺陷

#### 缺陷 1（Sev2）Case 类型与模板编码不匹配

- **现象**：Time 以 `LEAVE_REQUEST` / `ATTENDANCE_CORRECTION` 创建 Case，
  `CaseCommandService` 却用该字符串精确匹配 `workflow_templates.code`，而种子只发布
  `leave.request` / `generic.approval` 等点号编码。
- **为何测试没发现**：`TimeTestCase` 把 `WorkflowCasePort` 替换为
  `FakeWorkflowCasePort`，真实适配器与真实模板目录从未被 Time 测试执行。
- **复现**：新增 `TimeWorkflowIntegrationTest`，使用真实适配器 + 真实种子。
  修复前 3 项失败，报文 `No published workflow template exists for this case type.`
- **修复**：新增 `App\Modules\Workflow\Services\CaseTemplateCatalog`（闭合映射，
  未知类型仍失败关闭）；种子补充 `attendance.correction`。
- **结果**：`TimeWorkflowIntegrationTest` 5 passed / 18 assertions；`CommandsTest` 14 passed。

#### 缺陷 2（Sev2）经理审批人恒为空

- **现象**：`IamAssigneeDirectory::managerUserIdForSubject()` 直接 `return null`，
  所有 `MANAGER_OF_SUBJECT` 节点提交时报 `Approval node has no eligible assignee.`
- **修复**：新增 People 拥有的 `EmploymentManagerDirectory` 契约 + Eloquent 实现
  （as-of 读取 assignment → manager assignment → employment_user_link），
  IAM 侧委派该契约，保持"跨模块只经 Contract"。
- **结果**：断言 `workflow_tasks.assignee_user_id = 20` 通过。

#### 缺陷 3（Sev3）仪表盘三个面板在真实模式恒空

- **现象**：`frontend/src/api/http.ts` 把 `pendingApprovals/recentNotices/contractAlerts`
  硬编码为 `[]`，mock 数据掩盖了真实模式的空面板。
- **修复**：后端从**与指标相同的授权投影**生成三个有界（≤5）面板：
  待审批来自 `pending_approvals` 投影行、合同到期直接派生自 `contract_expiring`
  指标行（不可能与指标不一致）、公告来自 Engagement 的
  `NoticeWorkspaceDirectory` 并额外要求 `engagement.notice.read`。
- **契约**：`docs/openapi/openapi.yaml` 新增 `DashboardMetric`/`DashboardPendingApproval`/
  `DashboardNotice`/`DashboardContractAlert`/`DashboardData`，lint 与 Redocly 均通过。
- **结果**：`DashboardPanelsTest` 3 passed；真实 E2E 新增"公告面板来自真实已发布公告"断言。

#### 缺陷 4（Sev3）不可用指标的原因文案与事实不符

- **现象**：payroll / recruitment / turnover 的 unavailable 适配器声称
  "tables are not present on this branch"，但对应表与模块已存在。
- **修复**：改为"表存在，但尚未发布该 Reporting 投影，因此不可用而不是用未封存数据估算"。
- **意义**：审计要求"未执行/不可用"必须诚实，错误的原因文字会误导验收。

#### 缺陷 5（Sev3）HR 角色缺公告与报表能力

- **现象**：真实 API 探测 `/v1/engagement/notices`、`/v1/reporting/definitions` 对
  `demo.hr` 返回 403。
- **修复**：HR_ADMIN 补 `engagement.notice.read/manage`、`reporting.export`。
  **未**给 HR 任何 payroll run 能力——职责分离是设计而非疏漏。

### 4. 回归与门禁

- 后端：**705 passed / 35,666 assertions / 0 failure / 0 error / 0 skipped**
  （Unit 259、Feature 378、Contract 18、Integration 21、Architecture 29）。
- 前端：typecheck、lint、**30 unit**、build、**11 mock E2E**、**8 真实后端 E2E** 全通过。
- 契约：125 paths / 139 operations，lint OK，Redocly valid（3 条既有 warning）。
- `scripts/verify_release.sh`：**PASS=16 FAIL=0 SKIP=0**。

### 5. 运维证据

- 备份恢复：sha256 一致。
- 迁移彩排：6 域 `APPLIED` / `reconcile=PASS`（target=SHADOW）。
- 回滚演练：新增脚本，快照→恢复→完整性校验在 2.3s / 3600s 预算内 PASS；
  MySQL PITR、feature-flag 回退、路由切换以 `NOT_EXECUTED` 明示（本地无法证明）。

### 6. 诚实保留的缺口

| 缺口 | 原因 | 处置 |
|---|---|---|
| 工资平行运行 | 无旧系统导出文件，命令退出码 2 | 记为 NOT_EXECUTED，E-11 不给 PASS |
| 演示租户账本为空 | 无 leave type / ledger 记录 | E-10 记录为"空集对账"，正确性由测试覆盖 |
| 待审批口径非"分配给我" | Workflow 投影是租户级、无 assignee 字段 | 记录限制，需 Workflow 增投影 |
| 公告受众未展开 ROLE/ORG | 复用现有 workspace 投影 | 记录限制（偏保守，不会放宽） |
| 16 条架构 ownership 警告 | `RetentionService` 动态表名等 | 非阻断，登记为 ADR-0015 复审触发 |
| SAST | CodeQL 仅 GitHub 运行 | 记为 GATE |

## 结论

**`ACCEPTED_WITH_GATES`**：代码证据全绿、无 Sev1/Sev2 遗留；组织签认（S-01–S-08）、
真实 IdP、银行/税务、工资条 PDF、HA/PITR、渗透测试、真实工资平行运行仍为 GATE。

报告：`evidence/acceptance-report.md`；门禁日志：`evidence/logs/verify-release.txt`。
