# HRMS V1.0 分域切换（Cutover）Runbook

> 文档性质：本仓库是 HRMS V1.0 的参考实现，本文件定义**切换执行与证据基线**，不代表任何生产切换已经完成。
> 所有组织签认、真实环境操作和未执行的外部集成，一律标记 **GATE** 或 **NOT_EXECUTED**，不得写成 PASS。
> 回滚操作见 `docs/runbooks/rollback.md`；上线后 90 天见 `docs/runbooks/hypercare.md`。

## 0. 适用范围与硬约束

- 适用：单公司、1–3k 员工试点（D-21/S-04）；数据库为生产 MySQL 8.4（本地 SQLite 仅参考实现）。
- 切换顺序：**核心人事 → 考勤/假勤 → 工资 → 人才/自助/报表**，每一波独立 go/no-go，不允许“一次全切”。
- 旧系统在切换后保持只读 **60–90 天**；具体截止日由 S-04 签认（**GATE**）。
- 工资影子和平行运行**至少 2 个完整工资周期**；未满足不得进入 W3。
- 回滚目标：**≤ 60 分钟**；第三次彩排冻结窗口 **≤ 4 小时**，增量迁移 **≤ 30 分钟**。
- 已发工资、已报税、已签合同、已发通知、外部回执**不可回滚**，只能更正、冲正或人工对账。
- 生产 IdP、银行/税务回执、真实负载均衡/DNS、生产数据 owner 签字均不在本仓库，统一为 **GATE**。

## 1. 角色与决策权（姓名/联系方式为 GATE）

| 角色 | 切换职责 | 备注 |
|---|---|---|
| 项目发起人 | 最终 Go/No-Go、预算/范围/风险接受 | 计划 10.1、16.2；不填写姓名即视为 **GATE** |
| 交付经理 | 切换总控、时间线、变更单、证据归档 | 计划 RACI |
| 技术负责人 | 技术 go/no-go、数据库/发布/回滚决策 | 与交付经理、业务 owner 共同决定回滚 |
| HR 业务 owner | W1/W2 业务验收、数据 owner 确认 | S-04/S-05/S-06 关联 |
| 财务/薪酬负责人 | W3 工资平行、税务/银行边界、更正批次 | S-05；工资相关回滚必须参加 |
| QA 负责人 | 门禁证据、UAT/P0 结论、缺陷等级 | S-03；Sev1/2 未关闭不得 Go |
| DevOps/DBA | 备份、迁移、发布、路由切换执行 | 双人复核 |
| 安全/合规 | 身份、MFA、审计、隐私、法务保全 | S-03/S-08；Sev1 数据泄露一票否决 |
| 试点部门代表 | 用户 smoke、沟通反馈 | S-04；具体人员 **GATE** |

**Go/No-Go 记录要求**：每一波都必须有会议时间（UTC）、参加角色、输入证据、结论（GO/NO-GO/GO_WITH_GATES）、未关闭风险和签字。仓库中不存在预填的会议或签字；空白签字栏只能填 `GATE`。

## 2. 切换前总门禁（R5/W26）

W26 的 Go/No-Go 不是形式会议；以下任一不满足，W27 不启动。

| 门禁 | 可执行证据 | 判定 |
|---|---|---|
| 三次迁移彩排 | `bash deploy/scripts/migration_rehearsal.sh --round=1`（2、3 同） | 三次均 exit 0；源记录 100% 可追溯 |
| 人数/组织/合同/工资总额对账 | `php artisan migrate:reconcile --tenant=1 --json --output=...`；SQL 只读抽查 | 未解释差异 0 |
| 迁移坏数据不猜测 | 坏数据 fixture `backend/database/migration-fixtures/broken/` 产生 issue，而非静默跳过 | issue 可解释、有 owner |
| 工资平行 | `php artisan payroll:parallel-check --period=YYYY-MM --old-file=...` | 至少 2 个完整周期；差异 ≤ 0.01 元且有签字例外；缺文件 exit 2 记为 **NOT_EXECUTED** |
| 假勤账本 | `php artisan ledger:reconcile --as-of=YYYY-MM-DD --tenant=1` | 未解释差异 0 |
| 回滚演练 | `bash deploy/scripts/rollback_drill.sh`；真实 MySQL/PITR 与路由演练另附 | 本地 pass 只覆盖 SQLite 路径；生产 ≤60min 是 **GATE** |
| 健康探针 | `curl -fsS .../v1/health/live`、`/v1/health/ready`、`/v1/health` | live/ready 语义正确；ready 503 不忽略 |
| 安全/UAT | E-01–E-17 证据包；UAT-01–13 | Sev1/Sev2 未关闭 = 0；P0 UAT 100% |
| 组织签认 | S-01–S-08 签字页 | **GATE**，见第 7 节 |

计划 W26 要求的直接命令（均在仓库根目录或 `backend/` 执行）：

```bash
cd backend
php artisan test --filter=MigrationReconciliationTest
php artisan test --filter=FeatureFlagTest
php artisan test --filter=HealthProbeTest
php artisan test --filter=RetentionTest
php artisan test --filter=CorrectionRunTest
php artisan ledger:reconcile --as-of=YYYY-MM-DD --tenant=1
php artisan payroll:reconciliation --period=YYYY-MM
cd ..
bash deploy/scripts/migration_rehearsal.sh --round=1
bash deploy/scripts/migration_rehearsal.sh --round=2
bash deploy/scripts/migration_rehearsal.sh --round=3
bash deploy/scripts/rollback_drill.sh
```

> `php` 可以是 PATH 中的 PHP 8.4，也可以是仓库 `.tools/php/php`。本仓库的本地默认数据库是 SQLite；MySQL 生产命令必须在真实环境执行并留证。

## 3. 冻结窗口规则

### 3.1 时间盒

| 阶段 | 要求 | 证据 |
|---|---|---|
| 第三次彩排/最终增量 | 冻结窗口 ≤ 4h；增量迁移 ≤ 30min | 彩排日志、开始/结束 UTC 时间 |
| W1 核心人事切换 | 建议在业务低峰，停写窗口由 go/no-go 确认；不得压缩对账 | 切换记录、对账输出 |
| W2 考勤/假勤切换 | 必须跨至少一个完整假勤周期验证；切换窗口内停止假勤写入 | 账本对账、周期报告 |
| W3 工资切换 | 只在工资批次关闭后、下一工资周期开始前；银行/税务 cutoff 前完成 | 批次号、财务签字、银行/税务回执安排 |
| W4 人才/自助/报表 | 可在业务时段灰度；但导出/通知/Offer 等不可逆动作需单独冻结 | 灰度记录、导出/通知证据 |

### 3.2 冻结规则（全部为强制）

- 冻结开始后**禁止 schema 变更**、发布新镜像、修改 `.env`、变更 feature flag 默认值或调整队列/调度。
- 冻结前必须完成数据库全量备份 + binlog 位点记录；备份未校验不得开始增量。
- 冻结窗口只允许经批准的数据迁移、必要的只读核对和切换操作；所有手工 SQL 需双人复核。
- 增量同步必须在 **30 分钟内**完成并核对；超过 30 分钟即 No-Go，恢复旧系统写入，不“边跑边补”。
- 每次切换必须指定一个**回滚点**（数据库快照/PITR 时点 + 旧系统只读边界 + 当前批次号），并写入 go/no-go 记录。
- 旧系统在观察期保持只读，禁止双写；若发现双写，立即停止切换并升级到 Sev1/Sev2。
- 任何未关闭 Sev1/Sev2、工资平行未通过、迁移对账有未解释差异、或签字缺失，均为 **No-Go**。

## 4. 分域切换波次

### W1：核心人事（组织、人员、合同）

**范围**：`legal_entities`、`org_units`/`org_unit_closure`、`persons`、`employments`、`assignments`、`employment_terms`、`employment_user_links` 及关联只读视图。
**前置**：R1 验收；三次迁移彩排通过；S-04/S-06 已签；HR 数据 owner 确认源清单。
**切换动作**：

1. W1 go/no-go，记录回滚点（数据库快照 hash/PITR 时点 + 旧系统只读开始时间）。
2. 停写核心人事域；冻结组织/人员/合同变更。
3. 按迁移顺序执行：组织 → 人员 → 任职 → 合同（迁移工具与 staging 表见 `backend/app/Modules/Platform/Migration/`）。
4. 执行增量迁移（≤30min），核对人数/组织/合同。
5. 验证员工目录、组织树、合同时间线、未来生效调岗；用 `people:as-of-check` 检查主任职重叠/孤儿。
6. 开启核心人事读写；旧系统保持只读。

**Go/No-Go 标准（全部满足）**：

- [ ] `migrate:reconcile --tenant=1` 通过；组织/人员/合同 staging 行 100% 有目标映射。
- [ ] 在职人数按法人、组织分布、合同有效版本数与旧系统/HR 报表一致（差异 0 或书面解释）。
- [ ] `people:as-of-check --date=YYYY-MM-DD` 退出 0。
- [ ] 关键 smoke：登录、员工查询、组织树、合同查看、未来生效调岗通过。
- [ ] 回滚点已验证；`docs/runbooks/rollback.md` 的停写/恢复步骤可执行。
- [ ] HR 数据 owner 与 QA 签字（**GATE**）。

**回滚点**：切换前 SQLite/MySQL 快照 + 旧系统只读边界 + 迁移 run id。若人数/组织/合同对账失败或核心查询不可用，回滚到快照并恢复旧系统写入。

**不可回滚项**：已发通知、已签合同。切换后发现问题只能发更正通知或合同补充协议，不得删除历史。

---

### W2：考勤与假勤

**范围**：排班/班次、打卡、考勤日、请假申请/审批、`leave_accounts`、`leave_balances`、`leave_ledger_entries` 及期初账本。
**前置**：W1 稳定 ≥1 个业务周期；假勤账本平衡；S-06 数据 owner 签认。
**切换动作**：

1. W2 go/no-go，记录回滚点（快照/PITR + 旧系统只读边界 + 当前假勤周期）。
2. 停止假勤写入和新审批；导出旧系统未结假期余额/期初。
3. 迁移假期期初账本（GRANT 分录），保留 source id；禁止用“手工改余额”替代账本。
4. 设置 `time_enabled` 为新系统所需状态（通过受权命令；实际路由覆盖为 **GATE**）。
5. 验证请假占用/撤销/结算、跨夜班次、假勤报表与账本恒等式。
6. 一个完整周期后复核，再关闭旧系统假勤写入。

**Go/No-Go 标准**：

- [ ] `ledger:reconcile --as-of=YYYY-MM-DD --tenant=1` 退出 0；未解释差异 0。
- [ ] 期初 + 发生 = 期末；每个期初 GRANT 可追溯到 staging/source id。
- [ ] 抽查跨夜/DST/法人时区边界，考勤日归属正确。
- [ ] 请假审批、撤销、结算无重复扣减；重复审批/重试不重复记账。
- [ ] 关键用户 smoke 与 HR 专家签认（**GATE**）。
- [ ] `FeatureFlagTest` 通过；若 `time_enabled` 未挂载到目标路由，必须用路由切回旧系统，不能宣称 flag 已下线。

**回滚点**：假勤切换前快照 + 旧系统只读边界 + 当前周期编号。若账本差异无法解释，停止假勤写入，回滚或保持旧系统只读，待 HR/财务对账。

**不可回滚项**：已审批假期、已结算假期。只能通过冲正/调整分录和人工对账修正。

---

### W3：工资（最后切换）

**范围**：工资方案/参数、输入快照、批次、工资条、累计预扣/YTD、更正链、银行/税务导出。
**前置**：W1/W2 稳定；**至少 2 个完整周期工资平行通过**；S-05 财务/HR/法务签认；工资参数版本和舍入规则已签字。
**切换动作**：

1. W3 go/no-go，记录回滚点（工资批次号 + 数据库快照/PITR + 旧系统只读边界）。
2. 确认上一批次已关闭、下一周期未开始、银行/税务 cutoff 允许；停止工资写入和新批次。
3. 迁移工资 YTD 期初余额（不是重算历史）；保留源文件/映射和总数。
4. 通过受权命令设置 `payroll_enabled`（实际路由覆盖为 **GATE**），执行新系统计算。
5. 对比旧系统导出：人头、应发、扣款、实发、雇主成本、税额；差异 > 1 分必须有书面例外和财务签字。
6. 复核/批准/发布/付款严格按职责分离；发布后工资条不可变。
7. 完成首个新系统工资周期后，旧系统工资域保持只读，至少 60–90 天。

**Go/No-Go 标准（任一失败即 No-Go）**：

- [ ] 两个完整周期 `payroll:parallel-check` 通过，或差异 ≤ 0.01 元且有财务签字例外；缺旧文件 exit 2 必须记 **NOT_EXECUTED**。
- [ ] `payroll:reconciliation --period=YYYY-MM` 退出 0：run = employee = payslip = line；更正链可对账。
- [ ] 人头 100% 覆盖；工资总额/净额/税额与旧系统或财务总账对平。
- [ ] 累计预扣、社保/公积金基数上下限、地区化舍入、跨月更正用例通过（UAT-11）。
- [ ] 已封闭批次/已发布工资条不可变测试通过；更正批次不删除原批次。
- [ ] 银行/税务导出字段白名单、哈希和审计可核验；真实回执为 **GATE**。
- [ ] 财务/薪酬负责人和法务签字（**GATE**）。

**回滚点**：切换前完整备份 + binlog/PITR 时点 + 旧系统工资批次边界 + 当前工资周期。若工资总额/净额差异超阈值、税务/银行文件错误或无法对账，**不发布、不付款**，按 `docs/runbooks/rollback.md` 回滚工资域。

**不可回滚项 / 强制规则**：已发工资、已报税、已发布工资条、外部银行/税务回执不能删除或覆盖历史；只能创建**更正批次**，按 `CorrectionService` 的差额链处理，并保留审计。`ReopenService` 只允许发布前受控重开；已发布/已付款/已关账批次只能更正。

---

### W4：人才、自助与报表

**范围**：招聘/Offer、绩效、培训、员工自助、通知、仪表盘与导出。
**前置**：R4 验收；UAT 签字；W3 工资稳定；导出/通知的不可逆动作有业务 owner 批准。
**切换动作**：

1. W4 go/no-go，记录回滚点（快照/PITR + 旧系统只读边界 + 已发 Offer/已确认绩效清单）。
2. 按功能灰度：先只读报表/自助查询，再开放申请/审批，最后开放 Offer/通知/导出。
3. 设置 `self_service_enabled`、`recruitment_enabled`、`export_enabled`（实际路由覆盖为 **GATE**）。
4. 验证员工只能看自己的工资条/假勤；经理只能看授权范围；导出与页面同权限。
5. 核对仪表盘数字与下钻列表一致；导出字段白名单、行数上限、水印、短效链接、审计。
6. 关闭旧系统对应入口；旧系统整体只读进入 Hypercare。

**Go/No-Go 标准**：

- [ ] UAT-10（仪表盘/下钻）与 UAT-09（越权）通过；IDOR 返回 404。
- [ ] 自助请假比例、工资条查看率可按计划 12.1 采集（上线后指标，不要求切换当天达标）。
- [ ] 导出与页面同权限；HIDDEN 字段不进入导出/日志/排序。
- [ ] 通知/Offer/绩效确认有幂等和审计；不可逆动作有撤回/更正流程。
- [ ] `retention:preview --all --tenant=1` 只读通过；法务保全命中不删除。
- [ ] 业务 owner 与安全签字（**GATE**）。

**回滚点**：切换前快照/PITR + 旧系统只读边界 + 已发 Offer/已确认绩效/已发通知清单。功能可关闭 flag 或路由切回；已发 Offer/通知/绩效只能走更正/撤回通知，不能删除记录。

**不可回滚项**：已发 Offer、已确认绩效、已发通知/公告、已生成导出文件。保留证据，按业务更正规程处理。

## 5. 对账检查（每波必须）

### 5.1 统一命令

```bash
cd backend
php artisan people:as-of-check --date=YYYY-MM-DD
php artisan ledger:reconcile --as-of=YYYY-MM-DD --tenant=1
php artisan payroll:reconciliation --period=YYYY-MM
php artisan payroll:parallel-check --period=YYYY-MM --old-file=/secure/exports/old-payroll-YYYY-MM.csv
php artisan migrate:reconcile --tenant=1 --json --output=build/ops/cutover/migrate-reconcile.json
php artisan retention:preview --all --tenant=1
cd ..
```

说明：

- `payroll:parallel-check` 无旧系统文件时退出 **2**，必须标记 **NOT_EXECUTED**，不能视为无差异。
- `migrate:reconcile` 无已应用 run 时返回失败；先在彩排/迁移环境生成 run，生产切换记录真实 run id。
- `demo:seed`、`demo:verify`、`iam:seed-demo`、`people:seed-demo` 只用于合成 DEMO 数据，**不能作为生产切换对账证据**；生产不得运行 DEMO 参数（`payroll:seed-parameters` 明示 DEMO-only）。
- 只读 SQL 只用于发现差异，最终以业务 owner 签字为准。

### 5.2 对账矩阵

| 维度 | W1 | W2 | W3 | W4 | 最低证据 |
|---|---|---|---|---|---|
| 人数（在职/离职/未来生效） | 必须 | 复核 | 复核 | 复核 | 只读 SQL + `people:as-of-check` + HR 签字 |
| 组织（数量/层级/closure/有效区间） | 必须 | 复核 | — | 报表口径 | `migrate:reconcile` + SQL + HR 签字 |
| 合同（版本/有效期/到期数） | 必须 | 复核 | — | — | SQL + 合同抽样 + 法务/HR 签字 |
| 假勤账本（期初+发生=期末） | — | 必须 | 工资输入复核 | — | `ledger:reconcile` 退出 0 |
| 工资人头/应发/扣款/实发/雇主成本 | — | — | 必须 | 报表复核 | `payroll:reconciliation` + `payroll:parallel-check` |
| 工资 YTD/税额/更正链 | — | — | 必须 | 报表复核 | `payroll:reconciliation`、`CorrectionRunTest` |
| Offer/绩效/通知/导出 | — | — | — | 必须 | 业务抽样 + 审计 + owner 签字 |
| 审计/保留/法务保全 | 必须 | 必须 | 必须 | 必须 | `retention:preview`、审计查询、法务 **GATE** |

### 5.3 只读 SQL 抽查

```sql
-- 在职人数（按法人）
SELECT legal_entity_id, COUNT(*) AS headcount
FROM employments
WHERE tenant_id = 1
  AND status IN ('ACTIVE', 'PROBATION')
  AND valid_from <= :as_of
  AND valid_to > :as_of
GROUP BY legal_entity_id;

-- 组织单元数
SELECT COUNT(*) AS org_units
FROM org_units
WHERE tenant_id = 1
  AND status = 'ACTIVE'
  AND valid_from <= :as_of
  AND valid_to > :as_of;

-- 有效合同覆盖人数
SELECT COUNT(DISTINCT employment_id) AS covered_employees
FROM employment_terms
WHERE tenant_id = 1
  AND effective_from <= :as_of
  AND effective_to > :as_of;

-- 工资已封存批次原始合计
SELECT period, SUM(gross_cents) AS gross,
       SUM(deductions_cents) AS deductions,
       SUM(net_cents) AS net,
       SUM(employer_cost_cents) AS employer_cost
FROM payroll_runs
WHERE tenant_id = 1
  AND status IN ('PUBLISHED', 'PAID', 'CLOSED')
GROUP BY period
ORDER BY period;
```

所有 SQL 在只读连接/只读账号执行；生产双人复核。不要在切换窗口直接 UPDATE/DELETE 修正对账差异。

## 6. 通信与事件节奏

### 6.1 通信矩阵（联系人/工具为 GATE）

| 时点 | 对象 | 内容 | 负责人 | 状态 |
|---|---|---|---|---|
| T-5 工作日 | 发起人/业务 owner/财务/安全 | 切换范围、冻结窗口、回滚点、值班表 | 交付经理 | **GATE** |
| T-1 工作日 | 试点员工/经理/HR | 操作变化、只读时间、支持渠道、FAQ | HR/产品 | **GATE** |
| T-0 开始 | 指挥链/值班 | 进入冻结、当前系统状态、下一检查点 | 事件指挥官 | **GATE** |
| 每波 Go/No-Go | 决策组 | 证据、风险、结论、回滚点 | 交付经理 | **GATE** |
| 每波完成 | 用户/客服/财务 | 新系统可用范围、仍只读的旧系统、问题入口 | 产品/HR | **GATE** |
| 事故/回滚 | 全员相关方 | Sev、影响、已采取动作、下次更新 | 事件指挥官 | **GATE** |
| 退出 Hypercare | 发起人/全员 | 指标、遗留风险、支持模式 | 技术负责人/交付经理 | **GATE** |

通信必须使用组织批准的工具；本仓库没有 Slack/邮件/短信/PagerDuty 配置，不提供虚构的收件人或值班电话。

### 6.2 建议时间线（示例，不是已执行记录）

```text
T-30min  值班到岗，确认备份/binlog/旧系统只读/回滚点
T-20min  最终 go/no-go，冻结开始，记录 UTC 时间
T-0      停写 -> 增量迁移（≤30min）-> 对账 -> smoke
T+30min  第一检查点：若增量 >30min 或对账失败 -> No-Go/回滚
T+60min  切换完成目标；未完成进入回滚评估
T+2h     业务观察与问题分流
T+1d     每日对账/工单复盘
```

实际时间盒以组织切换单和 `docs/runbooks/rollback.md` 为准；不得为了赶时间跳过对账。

## 7. 组织签认（S-01–S-08，全部为 GATE）

本表只登记**必须存在的组织签认**；本仓库没有、也不得预填任何姓名、签字或日期。未签认项按计划阻断对应 release，不允许用“口头同意”替代。

| ID | 签认事项 | 计划截止 | 对切换的影响 | 当前状态 |
|---|---|---|---|---|
| S-01 | V1.0 范围基线与不做清单（D-19） | W2 | 范围未冻结，R1 不启动；切换范围无基线 | **GATE** |
| S-02 | 30 周 P50 / 38–44 周 P80 与 8 FTE（D-22） | W2 | 资源不足时顺延，不得压缩测试/切换窗口 | **GATE** |
| S-03 | 发布门禁、豁免权与变更控制（D-20/D-24） | W3 | 门禁不可豁免；Sev1/2 未关闭不得 Go | **GATE** |
| S-04 | 试点范围、分域切换与外部集成边界（D-21） | W2 | 试点、四波顺序、旧系统只读 60–90 天、回滚 ≤60min | **GATE** |
| S-05 | 薪酬法定口径与财务/法务责任人（D-14/D-15/D-18） | W8 | 工资不能进入平行运行/切换；更正责任不清 | **GATE** |
| S-06 | 迁移源、数据 owner、保留期与法务保全（D-18/D-23） | W4 | 无 owner 不迁移；历史无法对账；法务保全风险 | **GATE** |
| S-07 | 预算、关键角色名单与外部供应商（D-22） | W2 | 团队/供应商未锁定，切换支持不可用 | **GATE** |
| S-08 | 身份、SSO、MFA 与 break-glass 托管（D-09–D-11） | W3 | 身份/授权内核不能验收；生产登录风险 | **GATE** |

**签认页要求**：每一行最终必须由计划 16.2 指定角色填写姓名、签字、日期和附件位置；没有签字时只能保持 `GATE`。本文件不是签字页，也不替代 `docs/10-joint-audit-acceptance.md` 的 E-17 证据包。

### 7.1 Go/No-Go 记录模板

```text
Wave: W1 | W2 | W3 | W4
会议时间（UTC）:
参加角色（不填姓名则 GATE）:
输入证据:
  - 迁移/对账:
  - 测试/UAT:
  - 回滚点（快照 hash / PITR 时点 / 批次号 / 旧系统只读边界）:
  - 未关闭 Sev1/Sev2:
  - 工资平行（W3）:
结论: GO / NO-GO / GO_WITH_GATES（只能由有权角色填写）
未关闭风险与 owner:
签字: GATE（待组织填写）
```

## 8. 切换后立即检查（每波）

```bash
# 公共健康探针；生产域名由 GATE 提供
curl -fsS https://hrms.example.invalid/v1/health/live
curl -fsS https://hrms.example.invalid/v1/health/ready
curl -fsS https://hrms.example.invalid/v1/health

# 业务/对账
cd backend
php artisan people:as-of-check --date=YYYY-MM-DD
php artisan ledger:reconcile --as-of=YYYY-MM-DD --tenant=1
php artisan payroll:reconciliation --period=YYYY-MM
php artisan migrate:reconcile --tenant=1
cd ..
```

检查要求：

- `/v1/health/live` 只证明进程存在；`/v1/health/ready` 才证明 DB/cache/storage 全部可用。
- `/v1/health` 在 cache/storage 故障时可能仍 200 `degraded`，不得当作 ready 通过。
- 任何新错误日志中的 PII/密钥/银行账号都按 Sev1 安全事件处理。
- 发现双写、工资差异、权限越权或账本差异，立即按 `docs/runbooks/rollback.md` 触发回滚评估。

## 9. 退出切换、进入 Hypercare

全部波次满足以下条件后，才把旧系统转为只读并进入 90 天 Hypercare：

- [ ] W1–W4 均有完整的 go/no-go 记录、回滚点和签字（**GATE**）。
- [ ] 人数/组织/合同/假勤/工资/更正链对账 0 未解释差异，或差异有书面解释和 owner 签字。
- [ ] 工资至少 2 个完整周期平行通过；首个新系统工资周期按时、无重大错发。
- [ ] P0 UAT 100%，Sev1/Sev2 未关闭 = 0；所有阻断证据 E-01–E-17 可复现。
- [ ] 监控、告警、备份恢复、PITR、路由切换演练在真实环境通过（**GATE**）。
- [ ] 用户培训、FAQ、支持渠道、值班表已就绪（**GATE**）。
- [ ] 旧系统只读截止日和退出 Hypercare 标准已写进运营日历。

计划 12.2 的 Hypercare 退出条件更严格（连续 2 个工资周期零重大差异、业务/财务签字、Sev2 在 SLA 内关闭、培训/手册/备份演练通过、发起人批准）；切换完成不等于 Hypercare 退出，见 `docs/runbooks/hypercare.md`。

## 10. 证据归档

建议目录（按事故/波次分目录，禁止放真实 PII/密钥）：

```text
build/ops/cutover/
  W1/
    go-no-go.txt
    migrate-reconcile.json
    people-as-of-check.txt
    cutover-timeline.txt
  W2/
    ledger-reconcile.txt
    leave-opening-trace.csv        # 脱敏/仅 id 与合计
  W3/
    payroll-parallel-check.txt
    payroll-reconciliation.txt
    batch-and-payment-reference.txt
  W4/
    dashboard-drilldown.txt
    export-scope.txt
  signoffs/                          # GATE：组织签字扫描/电子签，仓库不预填
```

E-13 需要“脚本输出 + 时长”；E-15 需要登录、撤权、导出、工资封存、break-glass 审计样本。二者均不能由本文件或 `rollback_drill.sh` 单独替代；必须按 `docs/10-joint-audit-acceptance.md` 生成并归档。所有未在真实环境执行的项保持 **NOT_EXECUTED**，所有未签署的项保持 **GATE**。
