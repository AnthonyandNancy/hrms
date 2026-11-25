# V1.1 覆盖矩阵（机器生成事实 + 人工工作包映射）

> 本文件由 `node scripts/generate_coverage_matrix.mjs` 生成。`MATURE` 表示有专属业务视图；
> `FALLBACK` 表示注册路由可打开且走真实 API，但仍使用通用 ResourcePage；`MISSING` 表示无渲染器。
> 回退页不得在验收中声称业务完成。API 路径按 OpenAPI 精确匹配，带参数的路径需人工复核。

## 1. 汇总

- 注册表条目：**160**
- 专属业务视图（MATURE）：**160**
- 通用回退（FALLBACK）：**0**
- 无渲染器（MISSING）：**0**
- 有 API 路径未在 OpenAPI 中登记的页面：**0**

## 2. 工作包状态

| 工作包 | 代码 | 测试 | 真实 API | 页面成熟度 | 真实栈 E2E | 结论 |
|---|---|---|---|---|---|---|
| WP01 | DONE | DONE | DONE | n/a | PENDING | 基础工程与契约生成本地门禁通过，真实栈待最终验证 |
| WP02 | DONE | DONE | DONE | PARTIAL | PENDING | IAM 授权快照、14 角色与 160 页目录完成；页面接入待验收 |
| WP03 | DONE | DONE | DONE | PARTIAL | PENDING | 字段投影、锚点与负测存在；平台字段策略页待成熟视图 |
| WP04 | DONE | DONE | DONE | PARTIAL | PENDING | 工作流内核完成；通知/审计平台页待成熟视图 |
| WP05 | DONE | DONE | DONE | PARTIAL | PENDING | 部门/岗位 API 与编制完成；页面待成熟视图 |
| WP06 | DONE | DONE | DONE | PARTIAL | PENDING | 员工档案子资源 API 完成；扩展页待成熟视图 |
| WP07 | DONE | DONE | DONE | PARTIAL | PENDING | 生命周期内核完成；异动页面待成熟视图 |
| WP08 | DONE | DONE | DONE | PARTIAL | PENDING | 合同台账/续签完成；页面待成熟视图 |
| WP09 | DONE | DONE | DONE | PARTIAL | PENDING | 日历/班次 API 完成；规则页待成熟视图 |
| WP10 | DONE | DONE | DONE | PARTIAL | PENDING | 月结/重算锁定完成；页面待成熟视图 |
| WP11 | DONE | DONE | DONE | PARTIAL | PENDING | 假期账本与类型/政策读接口完成；页面待成熟视图 |
| WP12 | DONE | DONE | DONE | PARTIAL | PENDING | 加班单次结算完成；页面待成熟视图 |
| WP13 | DONE | DONE | DONE | PARTIAL | PENDING | 出差申请/取消完成；政策/费用页面待成熟视图 |
| WP14 | DONE | DONE | DONE | PARTIAL | PENDING | 薪资参数/方案 API 存在；页面待成熟视图 |
| WP15 | DONE | DONE | DONE | PARTIAL | PENDING | 批次/工资条/冲正内核完成；页面待成熟视图 |
| WP16 | DONE | DONE | DONE | PARTIAL | PENDING | 绩效模板权重红线完成；页面待成熟视图 |
| WP17 | DONE | DONE | DONE | PARTIAL | PENDING | 招聘需求/开放岗位红线完成；页面待成熟视图 |
| WP18 | DONE | DONE | DONE | PARTIAL | PENDING | 候选人/Offer API 存在；页面待成熟视图 |
| WP19 | DONE | DONE | DONE | PARTIAL | PENDING | 培训报名幂等完成；页面待成熟视图 |
| WP20 | DONE | DONE | DONE | PARTIAL | PENDING | 公告 API 存在；页面待成熟视图 |
| WP21 | DONE | DONE | DONE | PARTIAL | PENDING | 无任职 409 与自助空态完成；自助门户待成熟视图 |
| WP22 | DONE | DONE | DONE | PARTIAL | PENDING | 仪表盘 API 存在；6 个子页待成熟视图 |
| WP23 | DONE | DONE | DONE | PARTIAL | PENDING | 报表 API 存在；9 个子页待成熟视图 |
| WP24 | DONE | DONE | DONE | PARTIAL | PENDING | 审计/文件内核存在；平台页待成熟视图 |
| WP25 | DONE | PARTIAL | n/a | n/a | PENDING | 安全/混沌/压测脚本存在；真实栈与备份演练待执行 |
| WP26 | DONE | PARTIAL | n/a | PARTIAL | PENDING | 发布追溯已贯通；最终全量验收待执行 |

## 3. 能力集群（K1–K12）

| 集群 | 名称 | 页面数 | 成熟视图 | 后端缺口页 | 缺失 API 声明 |
|---|---|---:|---:|---:|---:|
| K1 | 组织岗位完整性 | 4 | 4 | 4 | 0 |
| K2 | 合同全生命周期 | 2 | 2 | 0 | 0 |
| K3 | 员工文档 | 1 | 1 | 1 | 0 |
| K4 | 考勤规则与排班 | 4 | 4 | 4 | 0 |
| K5 | 加班与调休 | 4 | 4 | 0 | 0 |
| K6 | 差旅与费用 | 3 | 3 | 3 | 0 |
| K7 | 薪酬档案与调薪 | 2 | 2 | 0 | 0 |
| K8 | 绩效全周期 | 6 | 6 | 0 | 0 |
| K9 | 招聘全漏斗 | 10 | 10 | 10 | 0 |
| K10 | 培训全周期 | 7 | 7 | 7 | 0 |
| K11 | 平台治理与公告 | 15 | 15 | 15 | 0 |
| K12 | 报表与订阅 | 5 | 5 | 5 | 0 |

- 集群状态：**IN_PROGRESS**
- 集群页面合计：**63**（契约 63）。
- 仍有后端缺口标记的页面：**49**（目标 0；按域 people 5、timepay 7、talent 17、platform 20）。
- 解析失败（fail-closed 计数）：**0**；Vue 中硬编码缺口面板：**1**。
- 仍有缺失 API 声明的集群：**无**。


## 4. 页面逐项状态

| # | 页面 | 路由 | 能力 | 集群 | 状态 | 缺失 API 声明 |
|---:|---|---|---|---|---|---|
| 1 | login | `/login` | `public` | — | MATURE | — |
| 2 | workspace | `/workspace` | `iam.session.manage` | — | MATURE | — |
| 3 | forbidden | `/forbidden` | `public` | — | MATURE | — |
| 4 | not-found | `/not-found` | `public` | — | MATURE | — |
| 5 | account-mfa | `/account/mfa` | `iam.session.manage` | — | MATURE | — |
| 6 | account-sessions | `/account/sessions` | `iam.session.manage` | — | MATURE | — |
| 7 | dashboard | `/dashboard` | `reporting.dashboard.read` | — | MATURE | — |
| 8 | dashboard-departments | `/dashboard/departments` | `reporting.dashboard.read` | — | MATURE | — |
| 9 | dashboard-workforce | `/dashboard/workforce` | `reporting.dashboard.read` | — | MATURE | — |
| 10 | dashboard-changes | `/dashboard/changes` | `reporting.dashboard.read` | — | MATURE | — |
| 11 | dashboard-tasks | `/dashboard/tasks` | `reporting.dashboard.read` | — | MATURE | — |
| 12 | dashboard-recruitment | `/dashboard/recruitment` | `reporting.dashboard.read` | — | MATURE | — |
| 13 | reports | `/reports` | `reporting.export` | — | MATURE | — |
| 14 | reports-headcount | `/reports/headcount` | `reporting.export` | — | MATURE | — |
| 15 | reports-attendance | `/reports/attendance` | `reporting.export` | K12 | MATURE | — |
| 16 | reports-payroll | `/reports/payroll` | `reporting.export` | — | MATURE | — |
| 17 | reports-hiring | `/reports/hiring` | `reporting.export` | — | MATURE | — |
| 18 | reports-talent | `/reports/talent` | `reporting.export` | K12 | MATURE | — |
| 19 | reports-builder | `/reports/builder` | `reporting.export` | K12 | MATURE | — |
| 20 | reports-subscriptions | `/reports/subscriptions` | `reporting.export` | K12 | MATURE | — |
| 21 | reports-exports | `/reports/exports` | `reporting.export` | K12 | MATURE | — |
| 22 | hr-employees | `/hr/employees` | `people.employee.read` | — | MATURE | — |
| 23 | hr-employees-create | `/hr/employees/create` | `people.employee.read` | — | MATURE | — |
| 24 | employee-detail | `/hr/employees/:id` | `people.employee.read` | — | MATURE | — |
| 25 | hr-employees-by-id-education | `/hr/employees/:id/education` | `people.employee.read` | — | MATURE | — |
| 26 | hr-employees-by-id-work-history | `/hr/employees/:id/work-history` | `people.employee.read` | — | MATURE | — |
| 27 | hr-employees-by-id-contacts | `/hr/employees/:id/contacts` | `people.employee.read` | — | MATURE | — |
| 28 | hr-employees-by-id-credentials | `/hr/employees/:id/credentials` | `people.employee.read` | — | MATURE | — |
| 29 | hr-employees-by-id-history | `/hr/employees/:id/history` | `people.employee.read` | — | MATURE | — |
| 30 | hr-employees-by-id-files | `/hr/employees/:id/files` | `people.employee.read` | K3 | MATURE | — |
| 31 | hr-employees-by-id-changes | `/hr/employees/:id/changes` | `people.employee.read` | — | MATURE | — |
| 32 | hr-employees-imports | `/hr/employees/imports` | `people.employee.read` | — | MATURE | — |
| 33 | hr-departments | `/hr/departments` | `org.unit.read` | — | MATURE | — |
| 34 | hr-departments-list | `/hr/departments/list` | `org.unit.read` | — | MATURE | — |
| 35 | hr-departments-editor | `/hr/departments/editor` | `org.unit.read` | — | MATURE | — |
| 36 | hr-departments-members | `/hr/departments/members` | `org.unit.read` | — | MATURE | — |
| 37 | hr-departments-move | `/hr/departments/move` | `org.unit.read` | — | MATURE | — |
| 38 | hr-departments-staffing | `/hr/departments/staffing` | `org.unit.read` | — | MATURE | — |
| 39 | hr-positions | `/hr/positions` | `org.position.read` | — | MATURE | — |
| 40 | hr-positions-editor | `/hr/positions/editor` | `org.position.read` | — | MATURE | — |
| 41 | hr-positions-headcount | `/hr/positions/headcount` | `org.position.read` | — | MATURE | — |
| 42 | hr-positions-requirements | `/hr/positions/requirements` | `org.position.read` | K1 | MATURE | — |
| 43 | hr-positions-grades | `/hr/positions/grades` | `org.position.read` | K1 | MATURE | — |
| 44 | hr-positions-staff | `/hr/positions/staff` | `org.position.read` | K1 | MATURE | — |
| 45 | hr-movements | `/hr/movements` | `lifecycle.case.read` | — | MATURE | — |
| 46 | hr-movements-onboarding | `/hr/movements/onboarding` | `lifecycle.case.read` | — | MATURE | — |
| 47 | hr-movements-probation | `/hr/movements/probation` | `lifecycle.case.read` | — | MATURE | — |
| 48 | hr-movements-transfer | `/hr/movements/transfer` | `lifecycle.case.read` | — | MATURE | — |
| 49 | hr-movements-salary-change | `/hr/movements/salary-change` | `lifecycle.case.read` | — | MATURE | — |
| 50 | hr-movements-secondment | `/hr/movements/secondment` | `lifecycle.case.read` | K1 | MATURE | — |
| 51 | hr-movements-offboarding | `/hr/movements/offboarding` | `lifecycle.case.read` | — | MATURE | — |
| 52 | hr-movements-by-id | `/hr/movements/:id` | `lifecycle.case.read` | — | MATURE | — |
| 53 | hr-movements-calendar | `/hr/movements/calendar` | `lifecycle.case.read` | — | MATURE | — |
| 54 | hr-contracts | `/hr/contracts` | `people.contract.read` | — | MATURE | — |
| 55 | hr-contracts-editor | `/hr/contracts/editor` | `people.contract.read` | — | MATURE | — |
| 56 | hr-contracts-by-id | `/hr/contracts/:id` | `people.contract.read` | — | MATURE | — |
| 57 | hr-contracts-renewals | `/hr/contracts/renewals` | `people.contract.read` | — | MATURE | — |
| 58 | hr-contracts-changes | `/hr/contracts/changes` | `people.contract.read` | K2 | MATURE | — |
| 59 | hr-contracts-expiry | `/hr/contracts/expiry` | `people.contract.read` | — | MATURE | — |
| 60 | hr-contracts-templates | `/hr/contracts/templates` | `people.contract.read` | K2 | MATURE | — |
| 61 | attendance | `/attendance` | `time.attendance.read` | — | MATURE | — |
| 62 | attendance-punches | `/attendance/punches` | `time.attendance.read` | — | MATURE | — |
| 63 | attendance-calendar | `/attendance/calendar` | `time.attendance.read` | — | MATURE | — |
| 64 | attendance-rules | `/attendance/rules` | `time.attendance.read` | K4 | MATURE | — |
| 65 | attendance-shifts | `/attendance/shifts` | `time.attendance.read` | — | MATURE | — |
| 66 | attendance-schedules | `/attendance/schedules` | `time.attendance.read` | K4 | MATURE | — |
| 67 | attendance-corrections | `/attendance/corrections` | `time.attendance.read` | — | MATURE | — |
| 68 | attendance-exceptions | `/attendance/exceptions` | `time.attendance.read` | — | MATURE | — |
| 69 | attendance-closing | `/attendance/closing` | `time.attendance.read` | K4 | MATURE | — |
| 70 | attendance-devices | `/attendance/devices` | `time.attendance.read` | K4 | MATURE | — |
| 71 | leave | `/leave` | `time.leave.read` | — | MATURE | — |
| 72 | leave-requests | `/leave/requests` | `time.leave.read` | — | MATURE | — |
| 73 | leave-types | `/leave/types` | `time.leave.read` | — | MATURE | — |
| 74 | leave-policies | `/leave/policies` | `time.leave.read` | — | MATURE | — |
| 75 | leave-balances | `/leave/balances` | `time.leave.read` | — | MATURE | — |
| 76 | leave-calendar | `/leave/calendar` | `time.leave.read` | — | MATURE | — |
| 77 | leave-cancellations | `/leave/cancellations` | `time.leave.read` | K5 | MATURE | — |
| 78 | leave-reports | `/leave/reports` | `time.leave.read` | — | MATURE | — |
| 79 | overtime | `/overtime` | `time.overtime.read` | K5 | MATURE | — |
| 80 | overtime-requests | `/overtime/requests` | `time.overtime.read` | — | MATURE | — |
| 81 | overtime-policies | `/overtime/policies` | `time.overtime.read` | — | MATURE | — |
| 82 | overtime-confirm | `/overtime/confirm` | `time.overtime.read` | K5 | MATURE | — |
| 83 | overtime-timeoff | `/overtime/timeoff` | `time.overtime.read` | K5 | MATURE | — |
| 84 | overtime-settlement | `/overtime/settlement` | `time.overtime.read` | — | MATURE | — |
| 85 | travel | `/travel` | `time.travel.read` | — | MATURE | — |
| 86 | travel-requests | `/travel/requests` | `time.travel.read` | — | MATURE | — |
| 87 | travel-policies | `/travel/policies` | `time.travel.read` | K6 | MATURE | — |
| 88 | travel-approval | `/travel/approval` | `time.travel.read` | K6 | MATURE | — |
| 89 | travel-changes | `/travel/changes` | `time.travel.read` | — | MATURE | — |
| 90 | travel-reports | `/travel/reports` | `time.travel.read` | — | MATURE | — |
| 91 | travel-expenses | `/travel/expenses` | `time.travel.read` | K6 | MATURE | — |
| 92 | payroll | `/payroll` | `payroll.run.read` | — | MATURE | — |
| 93 | payroll-components | `/payroll/components` | `payroll.run.read` | — | MATURE | — |
| 94 | payroll-plans | `/payroll/plans` | `payroll.run.read` | — | MATURE | — |
| 95 | payroll-employee-plans | `/payroll/employee-plans` | `payroll.run.read` | K7 | MATURE | — |
| 96 | payroll-statutory | `/payroll/statutory` | `payroll.run.read` | — | MATURE | — |
| 97 | payroll-inputs | `/payroll/inputs` | `payroll.run.read` | — | MATURE | — |
| 98 | payroll-batches | `/payroll/batches` | `payroll.run.read` | — | MATURE | — |
| 99 | payroll-reviews | `/payroll/reviews` | `payroll.run.read` | — | MATURE | — |
| 100 | payroll-disbursement | `/payroll/disbursement` | `payroll.run.read` | — | MATURE | — |
| 101 | payroll-payslips | `/payroll/payslips` | `payroll.run.read` | — | MATURE | — |
| 102 | payroll-adjustments | `/payroll/adjustments` | `payroll.run.read` | K7 | MATURE | — |
| 103 | payroll-reports | `/payroll/reports` | `payroll.run.read` | — | MATURE | — |
| 104 | performance | `/performance` | `performance.cycle.read` | — | MATURE | — |
| 105 | performance-templates | `/performance/templates` | `performance.cycle.read` | — | MATURE | — |
| 106 | performance-indicators | `/performance/indicators` | `performance.cycle.read` | K8 | MATURE | — |
| 107 | performance-goals | `/performance/goals` | `performance.cycle.read` | K8 | MATURE | — |
| 108 | performance-reviews | `/performance/reviews` | `performance.cycle.read` | — | MATURE | — |
| 109 | performance-feedback | `/performance/feedback` | `performance.cycle.read` | K8 | MATURE | — |
| 110 | performance-calibration | `/performance/calibration` | `performance.cycle.read` | K8 | MATURE | — |
| 111 | performance-results | `/performance/results` | `performance.cycle.read` | K8 | MATURE | — |
| 112 | performance-appeals | `/performance/appeals` | `performance.cycle.read` | — | MATURE | — |
| 113 | performance-reports | `/performance/reports` | `performance.cycle.read` | K8 | MATURE | — |
| 114 | recruitment-requests | `/recruitment/requests` | `recruitment.requisition.read` | — | MATURE | — |
| 115 | recruitment-requests-create | `/recruitment/requests/create` | `recruitment.requisition.read` | — | MATURE | — |
| 116 | recruitment-requests-approval | `/recruitment/requests/approval` | `recruitment.requisition.read` | K9 | MATURE | — |
| 117 | recruitment-requests-openings | `/recruitment/requests/openings` | `recruitment.requisition.read` | — | MATURE | — |
| 118 | recruitment-requests-board | `/recruitment/requests/board` | `recruitment.requisition.read` | K9 | MATURE | — |
| 119 | recruitment-requests-budget | `/recruitment/requests/budget` | `recruitment.requisition.read` | K9 | MATURE | — |
| 120 | recruitment-requests-reports | `/recruitment/requests/reports` | `recruitment.requisition.read` | K9 | MATURE | — |
| 121 | recruitment-candidates | `/recruitment/candidates` | `recruitment.candidate.read` | — | MATURE | — |
| 122 | recruitment-candidates-by-id | `/recruitment/candidates/:id` | `recruitment.candidate.read` | — | MATURE | — |
| 123 | recruitment-candidates-imports | `/recruitment/candidates/imports` | `recruitment.candidate.read` | K9 | MATURE | — |
| 124 | recruitment-candidates-pipeline | `/recruitment/candidates/pipeline` | `recruitment.candidate.read` | K9 | MATURE | — |
| 125 | recruitment-candidates-interviews | `/recruitment/candidates/interviews` | `recruitment.candidate.read` | — | MATURE | — |
| 126 | recruitment-candidates-evaluations | `/recruitment/candidates/evaluations` | `recruitment.candidate.read` | K9 | MATURE | — |
| 127 | recruitment-candidates-offers | `/recruitment/candidates/offers` | `recruitment.candidate.read` | — | MATURE | — |
| 128 | recruitment-candidates-communications | `/recruitment/candidates/communications` | `recruitment.candidate.read` | K9 | MATURE | — |
| 129 | recruitment-candidates-talent-pool | `/recruitment/candidates/talent-pool` | `recruitment.candidate.read` | K9 | MATURE | — |
| 130 | recruitment-candidates-convert | `/recruitment/candidates/convert` | `recruitment.candidate.read` | K9 | MATURE | — |
| 131 | training | `/training` | `training.program.read` | K10 | MATURE | — |
| 132 | training-courses | `/training/courses` | `training.program.read` | K10 | MATURE | — |
| 133 | training-sessions | `/training/sessions` | `training.program.read` | — | MATURE | — |
| 134 | training-enrollments | `/training/enrollments` | `training.program.read` | — | MATURE | — |
| 135 | training-checkins | `/training/checkins` | `training.program.read` | K10 | MATURE | — |
| 136 | training-exams | `/training/exams` | `training.program.read` | K10 | MATURE | — |
| 137 | training-feedback | `/training/feedback` | `training.program.read` | K10 | MATURE | — |
| 138 | training-records | `/training/records` | `training.program.read` | K10 | MATURE | — |
| 139 | training-reports | `/training/reports` | `training.program.read` | K10 | MATURE | — |
| 140 | notices | `/notices` | `engagement.notice.read` | — | MATURE | — |
| 141 | notices-editor | `/notices/editor` | `engagement.notice.read` | — | MATURE | — |
| 142 | notices-approval | `/notices/approval` | `engagement.notice.read` | K11 | MATURE | — |
| 143 | notices-audience | `/notices/audience` | `engagement.notice.read` | K11 | MATURE | — |
| 144 | notices-reads | `/notices/reads` | `engagement.notice.read` | K11 | MATURE | — |
| 145 | notices-templates | `/notices/templates` | `engagement.notice.read` | K11 | MATURE | — |
| 146 | notices-archive | `/notices/archive` | `engagement.notice.read` | K11 | MATURE | — |
| 147 | account | `/account` | `iam.session.manage` | — | MATURE | — |
| 148 | system-users | `/system/users` | `iam.user.read` | — | MATURE | — |
| 149 | system-roles | `/system/roles` | `iam.role.manage` | K11 | MATURE | — |
| 150 | system-menus | `/system/menus` | `iam.menu.manage` | K11 | MATURE | — |
| 151 | system-permissions | `/system/permissions` | `iam.permission.read` | K11 | MATURE | — |
| 152 | system-data-scopes | `/system/data-scopes` | `iam.scope.manage` | K11 | MATURE | — |
| 153 | system-field-policies | `/system/field-policies` | `iam.field.manage` | K11 | MATURE | — |
| 154 | workflow | `/workflow` | `workflow.case.read` | — | MATURE | — |
| 155 | self-service | `/self-service` | `iam.session.manage` | — | MATURE | — |
| 156 | benefits | `/benefits` | `benefit.read` | K11 | MATURE | — |
| 157 | system-calendars | `/system/calendars` | `time.calendar.read` | K11 | MATURE | — |
| 158 | system-settings | `/system/settings` | `system.settings.manage` | K11 | MATURE | — |
| 159 | notifications | `/notifications` | `notification.read` | K11 | MATURE | — |
| 160 | system-audit | `/system/audit` | `iam.audit.read` | K11 | MATURE | — |

## 5. 验收口径

- `--require-mature` 必须在 160/160 成熟视图时通过；当前通过前不得声称页面 100%。
- 真实栈 E2E 与备份恢复演练必须在 Compose 栈执行并保留日志。
- 任何 API gap 必须对应显式“后端接口待补”页面状态，不得伪造数据。
- 集群页面数之和必须恒等于 63；任一集群的 `gap`/`pending` 标记清零才可标记完成。

