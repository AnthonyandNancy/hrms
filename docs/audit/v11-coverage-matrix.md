# V1.1 覆盖矩阵（机器生成事实 + 人工工作包映射）

> 本文件由 `node scripts/generate_coverage_matrix.mjs` 生成。`MATURE` 表示有专属业务视图；
> `FALLBACK` 表示注册路由可打开且走真实 API，但仍使用通用 ResourcePage；`MISSING` 表示无渲染器。
> 回退页不得在验收中声称业务完成。API 路径按 OpenAPI 精确匹配，带参数的路径需人工复核。

## 1. 汇总

- 注册表条目：**160**
- 专属业务视图（MATURE）：**14**
- 通用回退（FALLBACK）：**146**
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

## 3. 页面逐项状态

| # | 页面 | 路由 | 能力 | 状态 | 缺失 API 声明 |
|---:|---|---|---|---|---|
| 1 | login | `/login` | `public` | MATURE | — |
| 2 | workspace | `/workspace` | `iam.session.manage` | MATURE | — |
| 3 | forbidden | `/forbidden` | `public` | MATURE | — |
| 4 | not-found | `/not-found` | `public` | MATURE | — |
| 5 | account-mfa | `/account/mfa` | `iam.session.manage` | FALLBACK | — |
| 6 | account-sessions | `/account/sessions` | `iam.session.manage` | FALLBACK | — |
| 7 | dashboard | `/dashboard` | `reporting.dashboard.read` | MATURE | — |
| 8 | dashboard-departments | `/dashboard/departments` | `reporting.dashboard.read` | FALLBACK | — |
| 9 | dashboard-workforce | `/dashboard/workforce` | `reporting.dashboard.read` | FALLBACK | — |
| 10 | dashboard-changes | `/dashboard/changes` | `reporting.dashboard.read` | FALLBACK | — |
| 11 | dashboard-tasks | `/dashboard/tasks` | `reporting.dashboard.read` | FALLBACK | — |
| 12 | dashboard-recruitment | `/dashboard/recruitment` | `reporting.dashboard.read` | FALLBACK | — |
| 13 | reports | `/reports` | `reporting.export` | MATURE | — |
| 14 | reports-headcount | `/reports/headcount` | `reporting.export` | FALLBACK | — |
| 15 | reports-attendance | `/reports/attendance` | `reporting.export` | FALLBACK | — |
| 16 | reports-payroll | `/reports/payroll` | `reporting.export` | FALLBACK | — |
| 17 | reports-hiring | `/reports/hiring` | `reporting.export` | FALLBACK | — |
| 18 | reports-talent | `/reports/talent` | `reporting.export` | FALLBACK | — |
| 19 | reports-builder | `/reports/builder` | `reporting.export` | FALLBACK | — |
| 20 | reports-subscriptions | `/reports/subscriptions` | `reporting.export` | FALLBACK | — |
| 21 | reports-exports | `/reports/exports` | `reporting.export` | FALLBACK | — |
| 22 | hr-employees | `/hr/employees` | `people.employee.read` | MATURE | — |
| 23 | hr-employees-create | `/hr/employees/create` | `people.employee.read` | FALLBACK | — |
| 24 | employee-detail | `/hr/employees/:id` | `people.employee.read` | FALLBACK | — |
| 25 | hr-employees-by-id-education | `/hr/employees/:id/education` | `people.employee.read` | FALLBACK | — |
| 26 | hr-employees-by-id-work-history | `/hr/employees/:id/work-history` | `people.employee.read` | FALLBACK | — |
| 27 | hr-employees-by-id-contacts | `/hr/employees/:id/contacts` | `people.employee.read` | FALLBACK | — |
| 28 | hr-employees-by-id-credentials | `/hr/employees/:id/credentials` | `people.employee.read` | FALLBACK | — |
| 29 | hr-employees-by-id-history | `/hr/employees/:id/history` | `people.employee.read` | FALLBACK | — |
| 30 | hr-employees-by-id-files | `/hr/employees/:id/files` | `people.employee.read` | FALLBACK | — |
| 31 | hr-employees-by-id-changes | `/hr/employees/:id/changes` | `people.employee.read` | FALLBACK | — |
| 32 | hr-employees-imports | `/hr/employees/imports` | `people.employee.read` | FALLBACK | — |
| 33 | hr-departments | `/hr/departments` | `org.unit.read` | MATURE | — |
| 34 | hr-departments-list | `/hr/departments/list` | `org.unit.read` | FALLBACK | — |
| 35 | hr-departments-editor | `/hr/departments/editor` | `org.unit.read` | FALLBACK | — |
| 36 | hr-departments-members | `/hr/departments/members` | `org.unit.read` | FALLBACK | — |
| 37 | hr-departments-move | `/hr/departments/move` | `org.unit.read` | FALLBACK | — |
| 38 | hr-departments-staffing | `/hr/departments/staffing` | `org.unit.read` | FALLBACK | — |
| 39 | hr-positions | `/hr/positions` | `org.position.read` | FALLBACK | — |
| 40 | hr-positions-editor | `/hr/positions/editor` | `org.position.read` | FALLBACK | — |
| 41 | hr-positions-headcount | `/hr/positions/headcount` | `org.position.read` | FALLBACK | — |
| 42 | hr-positions-requirements | `/hr/positions/requirements` | `org.position.read` | FALLBACK | — |
| 43 | hr-positions-grades | `/hr/positions/grades` | `org.position.read` | FALLBACK | — |
| 44 | hr-positions-staff | `/hr/positions/staff` | `org.position.read` | FALLBACK | — |
| 45 | hr-movements | `/hr/movements` | `lifecycle.case.read` | FALLBACK | — |
| 46 | hr-movements-onboarding | `/hr/movements/onboarding` | `lifecycle.case.read` | FALLBACK | — |
| 47 | hr-movements-probation | `/hr/movements/probation` | `lifecycle.case.read` | FALLBACK | — |
| 48 | hr-movements-transfer | `/hr/movements/transfer` | `lifecycle.case.read` | FALLBACK | — |
| 49 | hr-movements-salary-change | `/hr/movements/salary-change` | `lifecycle.case.read` | FALLBACK | — |
| 50 | hr-movements-secondment | `/hr/movements/secondment` | `lifecycle.case.read` | FALLBACK | — |
| 51 | hr-movements-offboarding | `/hr/movements/offboarding` | `lifecycle.case.read` | FALLBACK | — |
| 52 | hr-movements-by-id | `/hr/movements/:id` | `lifecycle.case.read` | FALLBACK | — |
| 53 | hr-movements-calendar | `/hr/movements/calendar` | `lifecycle.case.read` | FALLBACK | — |
| 54 | hr-contracts | `/hr/contracts` | `people.contract.read` | FALLBACK | — |
| 55 | hr-contracts-editor | `/hr/contracts/editor` | `people.contract.read` | FALLBACK | — |
| 56 | hr-contracts-by-id | `/hr/contracts/:id` | `people.contract.read` | FALLBACK | — |
| 57 | hr-contracts-renewals | `/hr/contracts/renewals` | `people.contract.read` | FALLBACK | — |
| 58 | hr-contracts-changes | `/hr/contracts/changes` | `people.contract.read` | FALLBACK | — |
| 59 | hr-contracts-expiry | `/hr/contracts/expiry` | `people.contract.read` | FALLBACK | — |
| 60 | hr-contracts-templates | `/hr/contracts/templates` | `people.contract.read` | FALLBACK | — |
| 61 | attendance | `/attendance` | `time.attendance.read` | MATURE | — |
| 62 | attendance-punches | `/attendance/punches` | `time.attendance.read` | FALLBACK | — |
| 63 | attendance-calendar | `/attendance/calendar` | `time.attendance.read` | FALLBACK | — |
| 64 | attendance-rules | `/attendance/rules` | `time.attendance.read` | FALLBACK | — |
| 65 | attendance-shifts | `/attendance/shifts` | `time.attendance.read` | FALLBACK | — |
| 66 | attendance-schedules | `/attendance/schedules` | `time.attendance.read` | FALLBACK | — |
| 67 | attendance-corrections | `/attendance/corrections` | `time.attendance.read` | FALLBACK | — |
| 68 | attendance-exceptions | `/attendance/exceptions` | `time.attendance.read` | FALLBACK | — |
| 69 | attendance-closing | `/attendance/closing` | `time.attendance.read` | FALLBACK | — |
| 70 | attendance-devices | `/attendance/devices` | `time.attendance.read` | FALLBACK | — |
| 71 | leave | `/leave` | `time.leave.read` | MATURE | — |
| 72 | leave-requests | `/leave/requests` | `time.leave.read` | FALLBACK | — |
| 73 | leave-types | `/leave/types` | `time.leave.read` | FALLBACK | — |
| 74 | leave-policies | `/leave/policies` | `time.leave.read` | FALLBACK | — |
| 75 | leave-balances | `/leave/balances` | `time.leave.read` | FALLBACK | — |
| 76 | leave-calendar | `/leave/calendar` | `time.leave.read` | FALLBACK | — |
| 77 | leave-cancellations | `/leave/cancellations` | `time.leave.read` | FALLBACK | — |
| 78 | leave-reports | `/leave/reports` | `time.leave.read` | FALLBACK | — |
| 79 | overtime | `/overtime` | `time.overtime.read` | FALLBACK | — |
| 80 | overtime-requests | `/overtime/requests` | `time.overtime.read` | FALLBACK | — |
| 81 | overtime-policies | `/overtime/policies` | `time.overtime.read` | FALLBACK | — |
| 82 | overtime-confirm | `/overtime/confirm` | `time.overtime.read` | FALLBACK | — |
| 83 | overtime-timeoff | `/overtime/timeoff` | `time.overtime.read` | FALLBACK | — |
| 84 | overtime-settlement | `/overtime/settlement` | `time.overtime.read` | FALLBACK | — |
| 85 | travel | `/travel` | `time.travel.read` | FALLBACK | — |
| 86 | travel-requests | `/travel/requests` | `time.travel.read` | FALLBACK | — |
| 87 | travel-policies | `/travel/policies` | `time.travel.read` | FALLBACK | — |
| 88 | travel-approval | `/travel/approval` | `time.travel.read` | FALLBACK | — |
| 89 | travel-changes | `/travel/changes` | `time.travel.read` | FALLBACK | — |
| 90 | travel-reports | `/travel/reports` | `time.travel.read` | FALLBACK | — |
| 91 | travel-expenses | `/travel/expenses` | `time.travel.read` | FALLBACK | — |
| 92 | payroll | `/payroll` | `payroll.run.read` | FALLBACK | — |
| 93 | payroll-components | `/payroll/components` | `payroll.run.read` | FALLBACK | — |
| 94 | payroll-plans | `/payroll/plans` | `payroll.run.read` | FALLBACK | — |
| 95 | payroll-employee-plans | `/payroll/employee-plans` | `payroll.run.read` | FALLBACK | — |
| 96 | payroll-statutory | `/payroll/statutory` | `payroll.run.read` | FALLBACK | — |
| 97 | payroll-inputs | `/payroll/inputs` | `payroll.run.read` | FALLBACK | — |
| 98 | payroll-batches | `/payroll/batches` | `payroll.run.read` | MATURE | — |
| 99 | payroll-reviews | `/payroll/reviews` | `payroll.run.read` | FALLBACK | — |
| 100 | payroll-disbursement | `/payroll/disbursement` | `payroll.run.read` | FALLBACK | — |
| 101 | payroll-payslips | `/payroll/payslips` | `payroll.run.read` | MATURE | — |
| 102 | payroll-adjustments | `/payroll/adjustments` | `payroll.run.read` | FALLBACK | — |
| 103 | payroll-reports | `/payroll/reports` | `payroll.run.read` | FALLBACK | — |
| 104 | performance | `/performance` | `performance.cycle.read` | FALLBACK | — |
| 105 | performance-templates | `/performance/templates` | `performance.cycle.read` | FALLBACK | — |
| 106 | performance-indicators | `/performance/indicators` | `performance.cycle.read` | FALLBACK | — |
| 107 | performance-goals | `/performance/goals` | `performance.cycle.read` | FALLBACK | — |
| 108 | performance-reviews | `/performance/reviews` | `performance.cycle.read` | FALLBACK | — |
| 109 | performance-feedback | `/performance/feedback` | `performance.cycle.read` | FALLBACK | — |
| 110 | performance-calibration | `/performance/calibration` | `performance.cycle.read` | FALLBACK | — |
| 111 | performance-results | `/performance/results` | `performance.cycle.read` | FALLBACK | — |
| 112 | performance-appeals | `/performance/appeals` | `performance.cycle.read` | FALLBACK | — |
| 113 | performance-reports | `/performance/reports` | `performance.cycle.read` | FALLBACK | — |
| 114 | recruitment-requests | `/recruitment/requests` | `recruitment.requisition.read` | FALLBACK | — |
| 115 | recruitment-requests-create | `/recruitment/requests/create` | `recruitment.requisition.read` | FALLBACK | — |
| 116 | recruitment-requests-approval | `/recruitment/requests/approval` | `recruitment.requisition.read` | FALLBACK | — |
| 117 | recruitment-requests-openings | `/recruitment/requests/openings` | `recruitment.requisition.read` | FALLBACK | — |
| 118 | recruitment-requests-board | `/recruitment/requests/board` | `recruitment.requisition.read` | FALLBACK | — |
| 119 | recruitment-requests-budget | `/recruitment/requests/budget` | `recruitment.requisition.read` | FALLBACK | — |
| 120 | recruitment-requests-reports | `/recruitment/requests/reports` | `recruitment.requisition.read` | FALLBACK | — |
| 121 | recruitment-candidates | `/recruitment/candidates` | `recruitment.candidate.read` | FALLBACK | — |
| 122 | recruitment-candidates-by-id | `/recruitment/candidates/:id` | `recruitment.candidate.read` | FALLBACK | — |
| 123 | recruitment-candidates-imports | `/recruitment/candidates/imports` | `recruitment.candidate.read` | FALLBACK | — |
| 124 | recruitment-candidates-pipeline | `/recruitment/candidates/pipeline` | `recruitment.candidate.read` | FALLBACK | — |
| 125 | recruitment-candidates-interviews | `/recruitment/candidates/interviews` | `recruitment.candidate.read` | FALLBACK | — |
| 126 | recruitment-candidates-evaluations | `/recruitment/candidates/evaluations` | `recruitment.candidate.read` | FALLBACK | — |
| 127 | recruitment-candidates-offers | `/recruitment/candidates/offers` | `recruitment.candidate.read` | FALLBACK | — |
| 128 | recruitment-candidates-communications | `/recruitment/candidates/communications` | `recruitment.candidate.read` | FALLBACK | — |
| 129 | recruitment-candidates-talent-pool | `/recruitment/candidates/talent-pool` | `recruitment.candidate.read` | FALLBACK | — |
| 130 | recruitment-candidates-convert | `/recruitment/candidates/convert` | `recruitment.candidate.read` | FALLBACK | — |
| 131 | training | `/training` | `training.program.read` | FALLBACK | — |
| 132 | training-courses | `/training/courses` | `training.program.read` | FALLBACK | — |
| 133 | training-sessions | `/training/sessions` | `training.program.read` | FALLBACK | — |
| 134 | training-enrollments | `/training/enrollments` | `training.program.read` | FALLBACK | — |
| 135 | training-checkins | `/training/checkins` | `training.program.read` | FALLBACK | — |
| 136 | training-exams | `/training/exams` | `training.program.read` | FALLBACK | — |
| 137 | training-feedback | `/training/feedback` | `training.program.read` | FALLBACK | — |
| 138 | training-records | `/training/records` | `training.program.read` | FALLBACK | — |
| 139 | training-reports | `/training/reports` | `training.program.read` | FALLBACK | — |
| 140 | notices | `/notices` | `engagement.notice.read` | MATURE | — |
| 141 | notices-editor | `/notices/editor` | `engagement.notice.read` | FALLBACK | — |
| 142 | notices-approval | `/notices/approval` | `engagement.notice.read` | FALLBACK | — |
| 143 | notices-audience | `/notices/audience` | `engagement.notice.read` | FALLBACK | — |
| 144 | notices-reads | `/notices/reads` | `engagement.notice.read` | FALLBACK | — |
| 145 | notices-templates | `/notices/templates` | `engagement.notice.read` | FALLBACK | — |
| 146 | notices-archive | `/notices/archive` | `engagement.notice.read` | FALLBACK | — |
| 147 | account | `/account` | `iam.session.manage` | FALLBACK | — |
| 148 | system-users | `/system/users` | `iam.user.read` | MATURE | — |
| 149 | system-roles | `/system/roles` | `iam.role.manage` | FALLBACK | — |
| 150 | system-menus | `/system/menus` | `iam.menu.manage` | FALLBACK | — |
| 151 | system-permissions | `/system/permissions` | `iam.permission.read` | FALLBACK | — |
| 152 | system-data-scopes | `/system/data-scopes` | `iam.scope.manage` | FALLBACK | — |
| 153 | system-field-policies | `/system/field-policies` | `iam.field.manage` | FALLBACK | — |
| 154 | workflow | `/workflow` | `workflow.case.read` | FALLBACK | — |
| 155 | self-service | `/self-service` | `iam.session.manage` | FALLBACK | — |
| 156 | benefits | `/benefits` | `benefit.read` | FALLBACK | — |
| 157 | system-calendars | `/system/calendars` | `time.calendar.read` | FALLBACK | — |
| 158 | system-settings | `/system/settings` | `system.settings.manage` | FALLBACK | — |
| 159 | notifications | `/notifications` | `notification.read` | FALLBACK | — |
| 160 | system-audit | `/system/audit` | `iam.audit.read` | FALLBACK | — |

## 4. 验收口径

- `--require-mature` 必须在 160/160 成熟视图时通过；当前通过前不得声称页面 100%。
- 真实栈 E2E 与备份恢复演练必须在 Compose 栈执行并保留日志。
- 任何 API gap 必须对应显式“后端接口待补”页面状态，不得伪造数据。

