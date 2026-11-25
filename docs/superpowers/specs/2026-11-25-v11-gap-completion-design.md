# HRMS V1.1 缺口补全：第一性原理解决方案

- **状态：** Accepted — 2026-11-25（用户裁定：不惜一切代价，按生产上线标准补齐前后端所有功能）
- **日期：** 2026-11-25
- **输入文档：** `HRMS-V1.1实施书.md`、`docs/HRMS_V1.0_解决方案_第一性原理重构.md`、`docs/HRMS_V1.0_实施计划_30周路线图.md`、`docs/superpowers/specs/2026-11-25-v11-production-completion-design.md`、`docs/superpowers/plans/2026-11-25-v11-production-completion-implementation-plan.md`、`docs/audit/2026-11-25-v11-page-inventory.md`、`docs/audit/2026-11-25-v11-page-access-blueprint.md`、`docs/audit/2026-11-25-v11-gap-audit.md`、ADR-0001～0032
- **关联 ADR：** 0028（能力闭环/投影契约/平台内核）、0029（投影契约与新鲜度）、0030（命令闭环与 Effect 重试）、0031（平台内核归属）、0032（数据范围与字段策略写入）
- **实施计划：** `docs/superpowers/plans/2026-11-25-v11-gap-completion-implementation-plan.md`（待确认后生成）

---

## 1. 结论先行

V1.1 当前状态是：**160 页全部有成熟视图、193 个 API 操作、真实栈 160 页审计通过**，但页面目录中仍有 **63 处显式“后端接口待补”**。这 63 项不是 63 个孤立页面，而是 **12 个能力集群** 的表层症状。

第一性原理结论：

> **系统缺的不是 63 个端点，而是“写命令闭环、读投影契约、平台内核”三类可复用骨架。**
> 没有这三类骨架，每补一页就复制一次授权、字段、分页、幂等、审计与审批；补得越多，漂移与维护成本越高。

因此本方案不采用“逐页直连补齐”，而采用 **A. 能力闭环 + 投影契约 + 平台内核**（用户已确认）：

1. 先建 3 个内核（Command Envelope、Projection Contract、Platform Kernel）与 5 个 ADR；
2. 把 63 项归并为 12 个能力集群，每集群一次交付“命令/查询/权限/五态/负测/真实 E2E”；
3. 用覆盖矩阵按集群验收，避免“页面数完成但能力缺失”重演。

---

## 2. 事实基线（可复核）

| 指标 | 当前值 | 证据 |
|---|---:|---|
| 页面注册表 | 160 | `node scripts/audit_route_coverage.mjs --require-mature` |
| 成熟视图 | 160/160 | 同上 |
| API 操作 | 193 | `node deploy/scripts/openapi-lint.mjs` |
| 能力码 / 角色模板 | 112 / 14 | `CapabilityCatalog`、`RoleTemplateCatalog` |
| 后端测试 | 903 passed / 45,367 assertions | `evidence/v11-local-gates-*.log` |
| 前端测试 | 168 passed / 29 files | 同上 |
| 真实栈 160 页审计 | 通过 | `evidence/v11-real-stack-20261125T073946Z.log` |
| 页面缺口标记 | **63** | 本方案第 3 节逐项 |
| 现有表 | 117 | `information_schema` |

63 项分布：People/Org 7、Time/Payroll 13、Talent 23、Platform 20。

---

## 3. 63 项缺口逐项台账

### 3.1 People / Organization（7）

| # | 页面 | 缺口 | 现有基础 | 集群 |
|---:|---|---|---|---|
| P1 | `/hr/employees/:id/files` | 员工附件列表/上传/下载/删除 | `documents` + `file_objects` 已有，仅缺列表与签名下载 | K3 |
| P2 | `/hr/positions/requirements` | 岗位任职资格版本化配置 | `positions` 已有，缺资格表 | K1 |
| P3 | `/hr/positions/grades` | 独立职级/职等目录 | `positions.grade` 只有字符串 | K1 |
| P4 | `/hr/positions/staff` | 按岗位查询当前/历史人员 | `assignments` 已有，缺子查询端点 | K1 |
| P5 | `/hr/movements/secondment` | 借调/兼岗生命周期 | `assignments.type=SECONDMENT` 已有，缺命令 | K1 |
| P6 | `/hr/contracts/changes` | 合同变更/终止 | `employment_contracts` + `_versions` 已有，缺命令 | K2 |
| P7 | `/hr/contracts/templates` | 合同模板与版本发布 | 无表 | K2 |

### 3.2 Time / Payroll（13）

| # | 页面 | 缺口 | 现有基础 | 集群 |
|---:|---|---|---|---|
| T1 | `/attendance/rules` | 考勤规则读接口 | `attendance_rule_versions` 已有 | K4 |
| T2 | `/attendance/schedules` | 排班读写 | `shift_assignments`/`shift_templates` 已有 | K4 |
| T3 | `/attendance/closing` | 月结列表 | `attendance_period_closures` 已有（写已通） | K4 |
| T4 | `/attendance/devices` | 设备健康列表 | 无表 | K4 |
| T5 | `/leave/cancellations` | 销假台账 | `leave_ledger_entries` + 取消动作已有 | K5 |
| T6 | `/overtime` | 加班申请写 | `overtime_records` 已有（读/结算已通） | K5 |
| T7 | `/overtime/confirm` | 实际工时确认 | `overtime_records` 缺确认字段/命令 | K5 |
| T8 | `/overtime/timeoff` | 调休余额投影 | `overtime_settlements` 已有 | K5 |
| T9 | `/travel/policies` | 差旅政策 | 无表 | K6 |
| T10 | `/travel/approval` | 出差审批决策 | `business_trip_records` 已有，接 Workflow | K6 |
| T11 | `/travel/expenses` | 费用单据 | 无表 | K6 |
| T12 | `/payroll/employee-plans` | 员工薪酬档案 | `employment_terms` 有薪资字段，缺版本化档案 | K7 |
| T13 | `/payroll/adjustments` | 调薪记录 | `payroll_adjustments` 是工资项，非调薪单 | K7 |

### 3.3 Talent（23）

| # | 页面 | 缺口 | 现有基础 | 集群 |
|---:|---|---|---|---|
| A1 | `/performance/indicators` | 指标库 | 无表 | K8 |
| A2 | `/performance/goals` | 目标计划 | 无表 | K8 |
| A3 | `/performance/feedback` | 360 反馈 | 无表 | K8 |
| A4 | `/performance/calibration` | 校准会议/等级 | `performance_reviews` 有 calibrated_rating | K8 |
| A5 | `/performance/results` | 结果发布/归档 | `performance_reviews.status` | K8 |
| A6 | `/performance/reports` | 绩效报表 | Reporting 投影缺 | K8/K12 |
| A7 | `workflow/cases/{id}/decisions`（招聘审批页引用） | 决策查询 | `decisions` 已有，缺 GET | K9 |
| A8 | `/recruitment/requests/board` | 进度看板 | `recruitment_requisitions` + openings | K9 |
| A9 | `/recruitment/requests/budget` | 招聘预算 | 需求有预算字段? 需核对 | K9 |
| A10 | `/recruitment/requests/reports` | 招聘统计 | Reporting 投影缺 | K9/K12 |
| A11 | `/recruitment/candidates/imports` | 简历解析/导入 | 无表 | K9 |
| A12 | `/recruitment/candidates/pipeline` | 阶段看板 | `candidates.status` | K9 |
| A13 | `/recruitment/candidates/:id/evaluations` | 结构化面试评价 | `interviews` 有，缺评价 | K9 |
| A14 | `/recruitment/candidates/:id/communications` | 沟通记录 | 无表 | K9 |
| A15 | `/recruitment/candidates/talent-pool` | 人才库/标签 | 无表 | K9 |
| A16 | `/recruitment/candidates/:id/convert` | 转员工幂等 | `offers`→onboarding 已有部分 | K9 |
| A17 | `/training` | 培训计划 | 无表 | K10 |
| A18 | `/training/courses` | 课程库 | `training_programs` 近似，缺课程实体 | K10 |
| A19 | `/training/sessions/:id/checkins` | 签到 | `training_enrollments` 有，缺签到 | K10 |
| A20 | `/training/exams` | 考试/成绩 | 无表 | K10 |
| A21 | `/training/feedback` | 满意度/效果 | 无表 | K10 |
| A22 | `/training/records` | 个人培训档案 | `training_enrollments` 可派生 | K10 |
| A23 | `/training/reports` | 培训报表 | Reporting 投影缺 | K10/K12 |

### 3.4 Platform（20）

| # | 页面 | 缺口 | 现有基础 | 集群 |
|---:|---|---|---|---|
| S1 | `/system/system-roles` | `POST /v1/roles` 创建/复制 | `roles` 表已有，`SYSTEM_ADMIN` 通配写 | K11 |
| S2 | `/system/system-menus` | 菜单树 CRUD/排序 | 无覆盖表，注册表只读 | K11 |
| S3 | `/system/system-permissions` | 角色能力覆盖写入/模拟 | 有角色能力读 | K11 |
| S4 | `/system/system-data-scopes` | 范围模板配置/成员预览 | 有 Anchor 读 | K11 |
| S5 | `/system/system-field-policies` | P0–P3 字段策略写入 | 有字段投影读 | K11 |
| S6 | `/benefits` | 社保公积金与福利 | 无表 | K11 |
| S7 | `/system/system-calendars` | 日历版本发布/调休编辑 | `legal_entity_calendars`/`holidays` 已有 | K11 |
| S8 | `/system/system-settings` | 字典/参数/文件密级 | 无表 | K11 |
| S9 | `/notifications` | 投递日志/失败重试/模板 | `notifications` 读已有，缺投递 | K11 |
| S10 | `/system/system-audit` | 备份恢复/任务监控 | 无运维任务表 | K11 |
| S11 | `/reports/attendance` | 考勤专属报表定义 | Reporting 有 7 个定义 | K12 |
| S12 | `/reports/talent` | 绩效培训报表定义 | 同上 | K12 |
| S13 | `/reports/builder` | 自定义报表保存/预览 | 无自定义定义表 | K12 |
| S14 | `/reports/subscriptions` | 订阅列表/启停 | 无表 | K12 |
| S15 | `/reports/exports` | 导出任务历史 | `export_jobs` 已有，缺列表 | K12 |
| S16 | `/notices/approval` | 公告审核/驳回 | `notices.status` | K11 |
| S17 | `/notices/audience` | 受众预览/校验 | `audience_json` 已有 | K11 |
| S18 | `/notices/reads` | 阅读人明细/导出 | `notice_acknowledgements` 已有 | K11 |
| S19 | `/notices/templates` | 公告模板/变量 | 无表 | K11 |
| S20 | `/notices/archive` | 归档检索/恢复 | `notices.status` | K11 |

> 说明：A9/A11–A16/A17–A23 等部分项已有近似实体，方案要求先复用/扩展现有表，不重复造表；具体字段与迁移在实施计划逐项列出。

---

## 4. 第一性原理：为什么是这三类内核

### 4.1 从“系统在管理什么”出发

V1.0 第一性原理文档给出五个不可约问题：**人是谁、与谁建立雇佣、在哪个组织岗位、时间如何计量、钱如何结算**。V1.1 的 63 项缺口全部落在这五个问题之上：

- 合同、文档、岗位资格、借调 → “雇佣关系的时间化事实”
- 考勤规则/排班/加班/调休/差旅 → “时间如何计量”
- 薪酬档案/调薪/福利 → “钱如何结算”
- 绩效/招聘/培训 → “外围域引用内核 ID”
- 平台治理 → “谁可以看/改哪些事实”

因此补全的最小充分条件不是“每页一个接口”，而是：

1. 事实变更必须走**同一条命令闭环**（否则审批、幂等、审计、补偿各写一套）；
2. 事实读取必须走**同一条投影契约**（否则报表、看板、列表、导出四套口径）；
3. 横切能力必须沉淀到**平台内核**（否则字典/通知/文件/菜单/日历/订阅各域重复）。

### 4.2 直接原因 vs 本质原因

| 层级 | 直接原因 | 本质原因 |
|---|---|---|
| 合同变更缺失 | 没有 terminate/amend 端点 | 版本化事实没有统一“追加新版本 + 关闭旧版本 + 生效日”命令骨架 |
| 加班申请/确认缺失 | 只有结算读接口 | 时间事实缺少“申请→确认→结算”状态机与唯一结算不变量 |
| 招聘转员工缺失 | 没有 convert 端点 | 跨域副作用没有 Effect/幂等骨架，怕重复建任职 |
| 福利/字典/通知缺失 | 没有表 | 平台内核缺位，横切能力被误当业务域 |
| 报表订阅/自定义缺失 | 没有表 | 读路径没有统一投影契约，报表无法声明“字段/过滤/订阅” |
| 同一缺陷反复出现 | 每次修一个页面 | 缺少跨模块不变量与单一权威实现 |

### 4.3 三条不变量（本方案强制）

- **I1 命令闭环**：任何事实变更 = 幂等键 + 能力/锚点 + 不变量 + 事务 + Effect + 审计；缺一不算完成。
- **I2 投影契约**：任何读模型 = `as_of` + 授权范围 + 字段档位 + 分页 + `freshness` + `source_hash`；报表/看板/列表/导出同源。
- **I3 平台归属**：横切能力归 Platform，业务模块只经 Contracts 使用；平台不反向依赖业务。

---

## 5. 方案设计

### 5.1 三层内核

#### 5.1.1 Command Envelope（写闭环）

```php
final class CommandDefinition {
    public function __construct(
        public readonly string $name,             // contract.terminate
        public readonly string $capability,       // people.contract.manage
        public readonly AnchorType $anchor,       // LEGAL_ENTITY
        public readonly bool $idempotent,         // true
        /** @var list<class-string<Invariant>> */
        public readonly array $invariants,
        public readonly ?string $effect,          // ContractTerminationEffect
        public readonly string $auditAction,      // people.contract.terminated
    ) {}
}
```

执行管线：

```text
POST /v1/... (Idempotency-Key)
  → auth.session
  → capability + anchor
  → validate (FormRequest/DTO)
  → invariants (pure guards)
  → DB::transaction
      → idempotency.run(scope,key,payload)
      → domain service (append version / state transition)
      → optional Workflow Case
      → Effect (sync or outbox)
      → AuditRecorder
  → 201/200 + body (+ Idempotency-Replayed)
```

失败语义：

| 情况 | HTTP | code | 说明 |
|---|---|---|---|
| 缺幂等键 | 422 | `VALIDATION_FAILED` | 与现有命令一致 |
| 重放同 payload | 200 | 原 code | `Idempotency-Replayed: true` |
| 同 key 不同 payload | 409 | `IDEMPOTENCY_CONFLICT` | 不执行 |
| 不变量失败 | 409 | 稳定 reason | 如 `CONTRACT_ALREADY_TERMINATED` |
| Effect 失败 | 202/200 + `effect_status=FAILED` | 可重试 | 业务事实未变或已标记待补偿 |

#### 5.1.2 Projection Contract（读契约）

```json
{
  "items": [],
  "page": 1,
  "page_size": 20,
  "total": 0,
  "as_of": "2026-11-25",
  "freshness": "LIVE",
  "source_hash": "sha256:...",
  "partial": false,
  "partial_reason": null,
  "drilldown": { "route": "/dashboard/metric/headcount", "filters": {} }
}
```

- `LIVE`：模块 Contract 直接查询；`MATERIALIZED`：`ProjectionBuilder` 重建；`STALE`：投影缺行且重建失败，必须带 `partial_reason`。
- 所有列表/报表/看板/导出共用 `MetricQueryBuilder` 谓词；禁止报表另写 SQL。
- 前端 `ResourcePage` 与四个域工作台统一消费；删除 `rowsFrom/recordFrom/partialFlag` 猜测逻辑。

#### 5.1.3 Platform Kernel（横切能力）

| 能力 | 表 | 对外 Contract | 复用集群 |
|---|---|---|---|
| 字典/参数 | `sys_dictionaries`、`sys_parameters` | `DictionaryReader`、`ParameterReader` | K1–K12 |
| 通知投递 | `notification_templates`、`notification_outbox`、`notification_deliveries` | `NotificationDispatcher` | K2/K5/K6/K8/K9/K10/K11 |
| 文件密级 | `file_objects`、`file_access_logs` | `PrivateFileStore`、`SignedDownload` | K2/K3/K9/K11 |
| 菜单覆盖 | `menu_overrides` | `MenuOverrideReader` | K11 |
| 日历发布 | `legal_entity_calendars`、`calendar_versions` | `WorkCalendar` | K4/K11 |
| 报表订阅 | `report_subscriptions`、`report_exports` | `ReportSubscriptionService` | K12 |
| 运维任务 | `scheduler_runs`、`backup_records` | `OpsTaskReader` | K11 |

### 5.2 12 个能力集群

| # | 集群 | 页面数 | 新增/扩展表 | 关键不变量 | 真实 E2E |
|---:|---|---:|---|---|---|
| K1 | 组织岗位完整性 | 4 | `position_qualifications`、`position_grades`、`assignment` 扩展 | 停用前无在岗；借调=Assignment；职级代码拥有 | 岗位→借调→回岗 |
| K2 | 合同全生命周期 | 2 | `contract_templates`、`_versions` 扩展 | 版本只追加；终止需生效日；模板发布需版本 | 续签→变更→终止 |
| K3 | 员工文档 | 1 | 复用 `documents`/`file_objects` | 删除需保留类/法律保留；签名下载 | 上传→列表→下载→删除拒绝 |
| K4 | 考勤规则与排班 | 4 | `attendance_rules` 读模型、`shift_schedules`、`attendance_devices` | 规则版本化；排班冲突；月结锁定 | 排班→打卡→月结→重开 |
| K5 | 加班与调休 | 4 | `overtime_requests`、`overtime_confirmations`、`timeoff_balances` 投影 | 申请≠确认；同一工时只结算一次 | 申请→确认→工资/调休→重复拒绝 |
| K6 | 差旅与费用 | 3 | `travel_policies`、`travel_expenses`、审批接 Workflow | 政策版本化；审批走 Case；费用不支付 | 申请→审批→费用→取消 |
| K7 | 薪酬档案与调薪 | 2 | `employee_compensations`、`salary_adjustments` | 有效期版本化；调薪敏感审批；整数分 | 档案→调薪→工资批次引用 |
| K8 | 绩效全周期 | 6 | `performance_indicators`、`goals`、`feedback`、`calibration`、`results` | 权重=100；校准不改原始分；发布后不可改 | 目标→自评→校准→发布→申诉 |
| K9 | 招聘全漏斗 | 10 | `candidate_evaluations`、`candidate_communications`、`talent_pool_tags`、`candidate_imports` | 需求批准才发布；转员工幂等；PII 字段档位 | 需求→发布→面试→Offer→转员工 |
| K10 | 培训全周期 | 7 | `training_plans`、`courses`、`checkins`、`exams`、`feedback` | 容量/重复报名；签到幂等；学时派生 | 计划→课程→报名→签到→考试→档案 |
| K11 | 平台治理 | 15 | 见 5.1.3 | 能力码代码拥有；范围白名单；P3 不可放宽；outbox 幂等 | 建角色→授权→预览→生效；字典/日历/通知 |
| K12 | 报表与订阅 | 5 | `report_definitions` 扩展、`report_subscriptions`、`report_exports` | 报表不写业务表；订阅再鉴权；导出白名单 | 自定义报表→订阅→导出→下载审计 |

### 5.3 新增能力码

按现有命名 `<domain>.<resource>.<action>`，一次性加入 `CapabilityCatalog`（代码拥有），并同步路由/OpenAPI/负测/角色模板。新增清单见 5.3.1（精确 44 个）：

| 域 | 新增能力码（示例） |
|---|---|
| org | `org.position.qualification.manage`、`org.position.grade.manage`、`org.position.staff.read` |
| lifecycle | `lifecycle.secondment.manage` |
| people | `people.document.read`、`people.contract.template.manage`、`people.contract.amend`、`people.contract.terminate` |
| time | `time.attendance.rule.manage`、`time.shift.schedule.manage`、`time.attendance.device.read`、`time.overtime.request`、`time.overtime.confirm`、`time.timeoff.read`、`time.travel.policy.manage`、`time.travel.approve`、`time.travel.expense.manage` |
| payroll | `payroll.compensation.read`、`payroll.compensation.manage`、`payroll.salary-adjustment.manage` |
| performance | `performance.indicator.manage`、`performance.goal.manage`、`performance.feedback.manage`、`performance.calibration.manage`（已有）、`performance.result.publish` |
| recruitment | `recruitment.board.read`、`recruitment.budget.read`、`recruitment.candidate.import`、`recruitment.evaluation.manage`、`recruitment.communication.manage`、`recruitment.talent-pool.manage`、`recruitment.candidate.convert` |
| training | `training.plan.manage`、`training.course.manage`、`training.checkin.manage`、`training.exam.manage`、`training.feedback.manage`、`training.record.read` |
| platform | `iam.role.create`（或复用 `iam.role.manage`）、`system.dictionary.manage`、`system.parameter.manage`、`system.backup.manage`、`notification.template.manage`、`notification.delivery.manage` |
| reporting | `reporting.definition.manage`、`reporting.subscription.manage`（已有）、`reporting.export.read` |

> 原则：能复用现有能力码的不新增；新增必须代码拥有并分配至少一个角色模板，否则 `capability:audit` 的 G-COV-1 失败。


### 5.3.1 能力码精确清单（新增 44 个，复用 8 组现有码）

> 原则：能复用现有能力码的不新增。下表为逐项核对后的最终清单；“复用”列表示不新增能力码，沿用现有码。

| # | 能力码 | 集群 | 复用/新增 | 角色模板授予 |
|---:|---|---|---|---|
| 1 | `org.position.qualification.manage` | K1 | 新增 | HR_ADMIN, HR_SPECIALIST |
| 2 | `org.position.grade.manage` | K1 | 新增 | HR_ADMIN |
| 3 | `org.position.staff.read` | K1 | 新增 | HR_ADMIN, HR_SPECIALIST, LINE_MANAGER |
| 4 | `lifecycle.secondment.manage` | K1 | 新增 | HR_ADMIN, HR_SPECIALIST |
| 5 | `people.document.read` | K3 | 新增 | HR_ADMIN, HR_SPECIALIST, EMPLOYEE(SELF) |
| 6 | `people.contract.template.manage` | K2 | 新增 | HR_ADMIN |
| 7 | `people.contract.amend` | K2 | 新增 | HR_ADMIN, HR_SPECIALIST |
| 8 | `people.contract.terminate` | K2 | 新增 | HR_ADMIN |
| 9 | `time.attendance.rule.read` | K4 | 新增 | ATTENDANCE_SPECIALIST, HR_ADMIN |
| 10 | `time.attendance.rule.manage` | K4 | 新增 | ATTENDANCE_SPECIALIST |
| 11 | `time.shift.schedule.read` | K4 | 新增 | ATTENDANCE_SPECIALIST, LINE_MANAGER |
| 12 | `time.shift.schedule.manage` | K4 | 新增 | ATTENDANCE_SPECIALIST |
| 13 | `time.attendance.device.read` | K4 | 新增 | ATTENDANCE_SPECIALIST, SYSTEM_ADMIN |
| 14 | `time.overtime.request` | K5 | 新增 | EMPLOYEE, LINE_MANAGER |
| 15 | `time.overtime.confirm` | K5 | 新增 | LINE_MANAGER, ATTENDANCE_SPECIALIST |
| 16 | `time.timeoff.read` | K5 | 新增 | EMPLOYEE(SELF), LINE_MANAGER, HR_ADMIN |
| 17 | `time.travel.policy.read` | K6 | 新增 | HR_ADMIN, EMPLOYEE |
| 18 | `time.travel.policy.manage` | K6 | 新增 | HR_ADMIN |
| 19 | `time.travel.approve` | K6 | 新增 | LINE_MANAGER, HR_ADMIN |
| 20 | `time.travel.expense.manage` | K6 | 新增 | HR_ADMIN, HR_SPECIALIST |
| 21 | `payroll.compensation.read` | K7 | 新增 | PAYROLL_SPECIALIST, PAYROLL_APPROVER, HR_ADMIN(P2) |
| 22 | `payroll.compensation.manage` | K7 | 新增 | PAYROLL_SPECIALIST |
| 23 | `payroll.salary-adjustment.manage` | K7 | 新增 | HR_ADMIN, PAYROLL_APPROVER |
| 24 | `performance.indicator.manage` | K8 | 新增 | HR_ADMIN, TRAINING_ADMIN |
| 25 | `performance.goal.manage` | K8 | 新增 | LINE_MANAGER, EMPLOYEE(SELF), HR_ADMIN |
| 26 | `performance.feedback.manage` | K8 | 新增 | HR_ADMIN, LINE_MANAGER |
| 27 | `performance.result.publish` | K8 | 新增 | HR_ADMIN |
| 28 | `recruitment.candidate.import` | K9 | 新增 | RECRUITER, HR_ADMIN |
| 29 | `recruitment.evaluation.manage` | K9 | 新增 | RECRUITER, LINE_MANAGER |
| 30 | `recruitment.communication.manage` | K9 | 新增 | RECRUITER |
| 31 | `recruitment.talent-pool.manage` | K9 | 新增 | RECRUITER, HR_ADMIN |
| 32 | `recruitment.candidate.convert` | K9 | 新增 | RECRUITER, HR_ADMIN |
| 33 | `training.plan.manage` | K10 | 新增 | TRAINING_ADMIN, HR_ADMIN |
| 34 | `training.course.manage` | K10 | 新增 | TRAINING_ADMIN |
| 35 | `training.checkin.manage` | K10 | 新增 | TRAINING_ADMIN |
| 36 | `training.exam.manage` | K10 | 新增 | TRAINING_ADMIN |
| 37 | `training.feedback.manage` | K10 | 新增 | TRAINING_ADMIN, LINE_MANAGER |
| 38 | `training.record.read` | K10 | 新增 | TRAINING_ADMIN, HR_ADMIN, EMPLOYEE(SELF) |
| 39 | `system.dictionary.manage` | K11 | 新增 | SYSTEM_ADMIN |
| 40 | `system.parameter.manage` | K11 | 新增 | SYSTEM_ADMIN |
| 41 | `system.backup.manage` | K11 | 新增 | SYSTEM_ADMIN |
| 42 | `notification.template.manage` | K11 | 新增 | SYSTEM_ADMIN |
| 43 | `notification.delivery.manage` | K11 | 新增 | SYSTEM_ADMIN |
| 44 | `reporting.definition.manage` | K12 | 新增 | REPORT_VIEWER, HR_ADMIN |
| — | `iam.role.manage` | K11 | 复用 | 已有 |
| — | `iam.scope.manage` | K11 | 复用 | 已有 |
| — | `iam.field.manage` | K11 | 复用 | 已有 |
| — | `benefit.read` / `benefit.manage` | K11 | 复用 | 已有 |
| — | `time.calendar.manage` | K11 | 复用 | 已有 |
| — | `time.overtime.read` / `time.overtime.settle` | K5 | 复用 | 已有 |
| — | `engagement.notice.*` | K11 | 复用 | 已有 |
| — | `reporting.report.read` / `reporting.report.create` / `reporting.subscription.manage` / `reporting.export` | K12 | 复用 | 已有 |

**精确计数：新增 44 个能力码，复用 8 组现有码；能力码总数 112 → 156。**

### 5.3.2 新增表精确清单（新增 23 张主表，扩展 6 张）

| # | 表 | 集群 | 归属模块 | 说明 |
|---:|---|---|---|---|
| 1 | `position_qualifications` | K1 | Organization | 岗位资格版本 |
| 2 | `position_grades` | K1 | Organization | 职级/职等目录 |
| 3 | `contract_templates` | K2 | People | 合同模板与版本 |
| 4 | `attendance_devices` | K4 | Time | 设备与健康状态 |
| 5 | `shift_schedules` | K4 | Time | 排班表 |
| 6 | `overtime_requests` | K5 | Time | 加班申请/确认 |
| 7 | `travel_policies` | K6 | Time | 差旅政策版本 |
| 8 | `travel_expenses` | K6 | Time | 差旅费用单据 |
| 9 | `employee_compensations` | K7 | Payroll | 薪酬档案版本 |
| 10 | `salary_adjustments` | K7 | Payroll | 调薪单 |
| 11 | `performance_indicators` / `performance_goals` / `performance_feedback` / `performance_calibrations` | K8 | Talent | 4 张 |
| 12 | `candidate_evaluations` / `candidate_communications` / `talent_pool_tags` / `candidate_imports` | K9 | Talent | 4 张 |
| 13 | `training_plans` / `training_courses` / `training_checkins` / `training_exams` / `training_feedback` | K10 | Talent | 5 张 |
| 14 | `sys_dictionaries` / `sys_parameters` / `notification_templates` / `notification_outbox` / `notification_deliveries` / `menu_overrides` / `report_subscriptions` / `scheduler_runs` / `backup_records` | K11/K12 | Platform/Reporting | 9 张 |

> 说明：第 11–14 行为逻辑分组；按表名逐项计数为 **23 张主表**（K1 2 + K2 1 + K4 2 + K5 1 + K6 2 + K7 2 + K8 4 + K9 4 + K10 5 = 23；K11/K12 的 9 张平台表已含在上述表名计数之外，实施计划按迁移逐个列出并以实际迁移数为准）。扩展表 6 张：`assignments`、`overtime_records`、`notices`、`legal_entity_calendars`、`report_definitions`、`export_jobs`。

### 5.4 前端改造

1. 四个域工作台（people/timepay/talent/platform）统一消费 Projection envelope；删除形状猜测。
2. 每个集群页面从 `gap` 态切换为真实操作；缺口标记清零作为集群 DoD。
3. 新增“数据新鲜度/部分数据”统一组件；503 语义只用于依赖不可用。
4. 命令提交统一走 `resourcePost(..., { idempotencyKey })`；成功/冲突/部分失败三类反馈统一。

### 5.5 数据与迁移策略

- 全部**前向迁移**，不修改已合并迁移；新表以 `2026_11_25_1xxxxx_*` 递增。
- 复用优先：能扩展现有表（如 `assignments`、`overtime_records`、`notices`）就不新建平行表。
- 每张新表声明 owning module；跨模块只经 Contracts。
- 迁移必须可在空库与现有 5174 库上重复执行；`migrate` 幂等。

---

## 6. 二阶/三阶影响推演

### 6.1 二阶影响（相邻系统与用户行为）

| 改动 | 二阶影响 | 缓解 |
|---|---|---|
| 写路径统一 Command | 控制器变薄，但所有命令必须声明不变量与 Effect；开发者需要学习新骨架 | 提供 `make:command` 模板、示例与架构测试 |
| 读路径统一 Projection | 前端删除兼容逻辑，页面形状一致；但旧端点形状不一致 | 新端点强制新契约；旧端点触碰时迁移，不做一次性大爆炸 |
| 平台内核 | 字典/通知/文件/菜单/订阅复用；但平台成为热点 | 平台只做通用能力，不承载业务规则；Contract 稳定 |
| 菜单覆盖表 | 管理员可隐藏/排序菜单；但可能误以为“隐藏=无权限” | 覆盖只影响展示，路由与 API 仍按能力判定；文档与 UI 明示 |
| 数据范围写入 | 管理员可配置；但错误配置可能扩大可见范围 | 白名单模板 + 前后差异预览 + 快照重放 + 审计 |
| 字段策略写入 | 可收紧 P2/P3；但“放宽 P3”会成为提权路径 | 只允许收紧；P3 读取/导出仍审计；负测覆盖 |
| Effect 重试 | 失败可恢复；但可能重复副作用 | Effect 必须幂等或带去重键；最大重试+人工队列 |

### 6.2 三阶影响（组织、复杂度、长期维护）

1. **边际交付成本下降**：新增页面从“8 处改动 + 自写授权/分页/审计”降为“注册表 + catalog + 复用命令/查询”。
2. **安全可取证**：能力/字段/审计只有单一入口，越权漏检概率下降；P3 读取/导出可对账。
3. **报表争议减少**：同一 metric 谓词 + `source_hash`，看板与下钻可对账；投影可重建。
4. **审批与事实不再互相冒充**：Case/Decision/Effect 分离，避免“审批通过但未生效”的静默错误。
5. **复杂度预算**：新增 3 个 Shared/Platform 内核 + 23 张表 + 44 个能力码；若不做内核，63 项将新增约 63 套重复实现，长期维护成本更高。
6. **组织学习成本**：团队需理解命令/投影/平台三层；通过模板、架构测试与示例降低。
7. **可逆性**：新集群可独立回滚（feature flag + 迁移前向）；旧端点保留到迁移完成。

### 6.3 失败模式与反向推演

| 若… | 会发生什么 | 预防 |
|---|---|---|
| 跳过内核直接补 63 项 | 6 个月后路由 >300，授权/字段/审计重复，漂移重现 | 本方案拒绝该路线 |
| 平台内核承载业务规则 | 平台反向依赖业务，模块边界崩塌 | 架构测试禁止 |
| 投影与报表各写 SQL | 看板与下钻不一致，审计争议 | 统一 MetricQueryBuilder |
| Effect 不幂等 | 重试导致重复发薪/重复转员工 | Effect 去重键 + 唯一约束 |
| 范围/字段写入无白名单 | 管理员可造任意条件/放宽 P3 | ADR-0032 强制白名单与只收紧 |
| 一次性大爆炸迁移 | 高风险、难回滚 | 按集群增量 + feature flag + 前向迁移 |

---

## 7. 跨领域参照

| 领域 | 机制 | 落点 | 不照搬 |
|---|---|---|---|
| OS 内核/系统调用 | 稳定接口先于驱动 | Command/Projection/Platform 三内核 | 不做微内核/微服务 |
| 编译器 IR | 前端只消费一种中间表示 | Projection envelope | 不做通用查询语言 |
| 数据库迁移 | schema version + forward-only | 前向迁移、快照重放 | 不做双写 |
| 银行复式记账 | 只追加、可对账 | 合同版本、薪酬档案、调休账本 | 不做事件溯源主存 |
| dbt 语义层 | 指标定义与物化分离 | MetricQueryBuilder + ProjectionBuilder | 不引入 dbt 运行时 |
| Kubernetes | spec/status + controller 收敛 | Effect 失败可重试、投影可重建 | 不做 CRD/operator |
| 航空检查单 | 强制门禁 | 每集群 DoD + 覆盖矩阵 | 不增加人工审批层级 |
| 医疗临床路径 | 标准路径 + 变异记录 | 命令不变量 + 例外审计 | 不做工作流引擎 |

---

## 8. 验收标准

### 8.1 集群级 DoD（每个 K1–K12）

1. 命令/查询全部走内核；控制器无直接模型写（架构测试）。
2. OpenAPI 条目 + `x-capability` + 路由中间件 + `endpoint_matrix` 四者一致。
3. 至少 4 类负测：未认证、无能力、错锚点、跨法人/字段拒绝。
4. 五态：loading/empty/error(request_id)/forbidden/partial。
5. 真实栈 E2E：该集群主链路 + 1 条拒绝路径。
6. 页面 `gap` 标记删除；覆盖矩阵该集群 `CAPABILITY_COMPLETE`。

### 8.2 系统级

- `capability:audit` 零警告；`arch:test` 零违规。
- 后端 `php artisan test`、pint、phpstan 全绿。
- 前端 typecheck/lint/test/build/openapi:check 全绿。
- 160 页真实栈审计零未解释 5xx。
- MySQL 备份恢复零差异；压测 p95 在预算内。
- 覆盖矩阵 `--require-mature --require-capabilities` 通过。

---

## 9. YAGNI 边界（不做）

- 不做通用 BPMN 设计器、微服务拆分、事件溯源主存。
- 不做任意 SQL/条件 JSON 数据范围；自定义只允许白名单模板。
- 不做多租户 SaaS 计费、原生 App、人脸识别、税务申报引擎、银行清算。
- 不为“功能看起来全”伪造接口/数据/E2E。
- 不一次性重写 193 条旧路由；新集群强制新内核，旧路由触碰时迁移。

---

## 10. 交付顺序

```text
Phase A（内核）
  A1 Command Envelope + 测试
  A2 Projection Contract + 测试
  A3 Platform Kernel 基础表/Contract
  A4 能力码 + 角色模板 + 负测矩阵骨架

Phase B（高价值闭环）
  K2 合同 → K5 加班/调休 → K8 绩效 → K9 招聘 → K7 薪酬

Phase C（时间与组织）
  K1 组织岗位 → K4 考勤 → K6 差旅 → K3 文档

Phase D（平台与报表）
  K11 平台治理 → K10 培训 → K12 报表订阅

Phase E（验收）
  真实栈 E2E、负测、压测、备份恢复、覆盖矩阵、验收报告
```

> 顺序理由：先做“有审批/有金额/有跨域副作用”的集群，能最早暴露命令闭环与 Effect 重试的设计缺陷；平台治理放在 D 阶段，是因为它依赖 A 阶段内核且会被 B/C 反向验证。

---

## 11. 风险与缓解

| 风险 | 概率 | 影响 | 缓解 |
|---|---|---|---|
| 内核抽象过度，拖慢交付 | 中 | 高 | 先做最小可用版（A1–A3），用 K2 真实集群验证后再扩展 |
| 12 集群并行导致冲突 | 中 | 中 | 按表/模块划分子代理；平台内核先冻结 Contract |
| 旧端点与新契约并存 | 高 | 中 | 新端点强制新契约；旧端点标记 `legacy` 并排期迁移 |
| 迁移在现有库失败 | 中 | 高 | 前向迁移 + 空库/现有库双跑 + 备份恢复演练 |
| 权限码膨胀 | 高 | 中 | 能复用不新增；每码必须有角色模板与负测 |
| 真实 E2E 时长 | 中 | 中 | 按集群分片；160 页审计保持单次 3 分钟内 |

---

## 12. 已确认决策（用户裁定）

> 用户裁定（2026-11-25）：**“不惜一切代价，按生产上线的标准，补齐前后端所有功能。”**
> 以下四项不再作为待确认项，全部按“生产上线标准”执行；任何降级、跳过、延后都必须回到本文档重新裁决，不得在执行中自行放宽。

| # | 决策 | 裁定 | 生产上线标准（不可降级） |
|---:|---|---|---|
| D1 | 5 个新 ADR（0028–0032） | **接受为架构约束** | 所有新集群必须遵守；违反由 `arch:test` 与代码评审阻断 |
| D2 | 12 集群交付顺序 | **接受 A→B→C→D→E** | 不压缩测试；Phase 未过全量门禁不得进入下一 Phase |
| D3 | 44 个能力码 + 23 张主表 + 9 张平台表 | **接受** | 每码有角色授予与负测；每表有 owning module 与前向迁移 |
| D4 | 旧路由不一次性重写 | **接受，但增加硬约束** | 旧路由必须标注 `legacy` 并登记迁移 Task；不得新增 legacy 路由；新功能一律走内核 |

### 12.1 “生产上线标准”定义（本方案的验收底线）

1. **功能完整**：63 处 gap 标记全部清零；160 页每页真实 API、真实操作、无“后端接口待补”。
2. **契约完整**：每个新端点有 OpenAPI 3.1 schema、`x-capability`、运行时校验、生成类型；`openapi:check` 与 Redocly 通过。
3. **权限完整**：每路由 4 类负测（未认证/无能力/错锚点/跨法人或字段）；`capability:audit` 零警告；P3 读取/导出有审计。
4. **数据完整**：迁移在空库与现有库双跑通过；备份/恢复零差异；无浮点金额；无跨模块写表。
5. **真实栈完整**：160 页 + 12 集群真实 E2E 通过；零未解释 5xx；语义 503 必须渲染专门状态。
6. **可运维**：release SHA 可追溯；健康/就绪/指标可用；失败可重试；审计可按 `request_id` 追溯。
7. **可回归**：后端 test/pint/phpstan、前端 typecheck/lint/test/build、契约 lint、覆盖矩阵全绿。
8. **不留尾巴**：已知缺口只能以“已登记 Task + 明确排期”存在，不能以“以后再说”存在。

### 12.2 执行纪律

- 任何 Task 未达到 12.1 的对应条目，视为未完成，不得勾选。
- 任何“为了赶进度”降低测试/权限/审计的做法，一律拒绝。
- 发现 Spec 与代码冲突时，先更新 Spec/ADR，再继续；禁止静默偏离。
- 每个 Phase 结束必须产出证据日志并更新验收报告。

---

## 13. 下一步

修改计划已生成：`docs/superpowers/plans/2026-11-25-v11-gap-completion-implementation-plan.md`（**24 个 Task**：A1–A6 内核与台账、B1–B5、C1–C4、D1–D4、E0–E4 验收）。

**不存在待确认事项**：D1–D4 已按用户“不惜一切代价、生产上线标准”裁定；执行中如需变更，必须回到本文档 12.1/12.2 重新裁决。
执行方式：Subagent-Driven，每 Task 一个子代理，lead 逐 Task 复核；A 阶段串行，B/C/D 可按集群并行，E 阶段统一验收。
