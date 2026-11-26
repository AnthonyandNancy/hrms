# FactoryHR V2.1 能力等价表

- 生成命令：`node scripts/generate_route_disposition.mjs`
- 来源：`frontend/src/router/registry.ts` 的 155 条页面能力域与 `HRMS-V1.1实施书.md` 的 133 个 03A 页面/能力点。
- 等价口径：V1.1 的每个业务能力点都保留一个 keep/action/view/flow 去向；合并只改变入口形态，不删除能力、API、权限或测试要求。
- 统计口径：业务能力点按注册表 `layer: business` 的 134 条计数；平台 15 条与基础 6 条同样列出，但不计入 V1.1 业务能力点。
- 废弃规则：本表没有用户签认废弃项，因此 `deprecate` 为 0。

| # | V1.1 能力点（旧页面码） | 路径 | 层 | 能力码（保持） | 处置 | 目标页面码 | Owner | 证据 |
| ---: | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | `login` | `/login` | base | `public` | keep | `login` | Platform | 基础登录页保留，作为账号密码单步登录入口。 |
| 2 | `workspace` | `/workspace` | base | `iam.session.manage` | keep | `workspace` | Platform | 我的工作台保留，汇总当前用户有权进入的待办与入口。 |
| 3 | `forbidden` | `/forbidden` | base | `public` | keep | `forbidden` | Platform | 无权限基础页保留，用于统一 403 状态。 |
| 4 | `not-found` | `/not-found` | base | `public` | keep | `not-found` | Platform | 页面不存在基础页保留，用于统一 404 状态。 |
| 5 | `account-mfa` | `/account/mfa` | base | `iam.session.manage` | keep | `account-mfa` | Platform | MFA 作为可选安全能力保留在账号安全页，默认不启用。 |
| 6 | `account-sessions` | `/account/sessions` | base | `iam.session.manage` | keep | `account-sessions` | Platform | 会话管理页保留，支持查看并注销当前账号会话。 |
| 7 | `dashboard` | `/dashboard` | business | `reporting.dashboard.read` | keep | `dashboard` | Reporting | 首页看板保留为 V2.1 登录后的默认工作台。 |
| 8 | `dashboard-departments` | `/dashboard/departments` | business | `reporting.dashboard.read` | keep | `dashboard-departments` | Reporting | 部门人数分析保留为独立看板工作区，查询同一仪表盘数据域。 |
| 9 | `dashboard-workforce` | `/dashboard/workforce` | business | `reporting.dashboard.read` | keep | `dashboard-workforce` | Reporting | 员工构成看板保留为独立分析工作区。 |
| 10 | `dashboard-changes` | `/dashboard/changes` | business | `reporting.dashboard.read` | keep | `dashboard-changes` | Reporting | 近期人事动态看板保留，继续展示授权范围内的人事变更。 |
| 11 | `dashboard-tasks` | `/dashboard/tasks` | business | `reporting.dashboard.read` | keep | `dashboard-tasks` | Reporting | 工作提醒看板保留，承载当前用户待办聚合。 |
| 12 | `dashboard-recruitment` | `/dashboard/recruitment` | business | `reporting.dashboard.read` | keep | `dashboard-recruitment` | Reporting | 招聘进展看板保留，展示授权范围内招聘漏斗指标。 |
| 13 | `reports` | `/reports` | business | `reporting.export` | keep | `reports` | Reporting | 报表目录保留为报表中心主工作台。 |
| 14 | `reports-headcount` | `/reports/headcount` | business | `reporting.export` | view | `reports` | Reporting | 人力资源报表并入 reports 的报表视图页签，共用报表定义与导出权限。 |
| 15 | `reports-attendance` | `/reports/attendance` | business | `reporting.export` | view | `reports` | Reporting | 考勤假期报表并入 reports 的报表视图页签，共用报表定义与导出权限。 |
| 16 | `reports-payroll` | `/reports/payroll` | business | `reporting.export` | view | `reports` | Reporting | 薪资成本报表并入 reports 的报表视图页签，字段与导出仍受 P3 策略约束。 |
| 17 | `reports-hiring` | `/reports/hiring` | business | `reporting.export` | view | `reports` | Reporting | 招聘转化报表并入 reports 的报表视图页签，保留招聘统计口径。 |
| 18 | `reports-talent` | `/reports/talent` | business | `reporting.export` | view | `reports` | Reporting | 绩效培训报表并入 reports 的报表视图页签，保留绩效与培训统计口径。 |
| 19 | `reports-builder` | `/reports/builder` | business | `reporting.export` | flow | `reports-builder` | Reporting | 自定义报表设计保留为可保存草稿并发布的长流程页面。 |
| 20 | `reports-subscriptions` | `/reports/subscriptions` | business | `reporting.export` | flow | `reports-subscriptions` | Reporting | 报表订阅保留为创建、暂停和删除订阅的长流程页面。 |
| 21 | `reports-exports` | `/reports/exports` | business | `reporting.export` | keep | `reports-exports` | Reporting | 导出任务保留为独立工作区，用于查看任务、字段白名单和审计结果。 |
| 22 | `hr-employees` | `/hr/employees` | business | `people.employee.read` | keep | `employees` | People | 员工列表收敛为 employees 主工作台，承载查询、新建入口与行操作。 |
| 23 | `employee-detail` | `/hr/employees/:id` | business | `people.employee.read` | keep | `employee-detail` | People | 员工档案详情保留为 employee-detail 深链接；brief 中旧码 hr-employees-by-id 已收敛为当前注册表码。 |
| 24 | `hr-employees-by-id-education` | `/hr/employees/:id/education` | business | `people.employee.read` | view | `employee-detail` | People | 教育经历并入 employee-detail 的同一员工视图页签。 |
| 25 | `hr-employees-by-id-work-history` | `/hr/employees/:id/work-history` | business | `people.employee.read` | view | `employee-detail` | People | 工作经历并入 employee-detail 的同一员工视图页签。 |
| 26 | `hr-employees-by-id-contacts` | `/hr/employees/:id/contacts` | business | `people.employee.read` | view | `employee-detail` | People | 家庭与紧急联系人并入 employee-detail 的同一员工视图页签。 |
| 27 | `hr-employees-by-id-credentials` | `/hr/employees/:id/credentials` | business | `people.employee.read` | view | `employee-detail` | People | 证照与资格并入 employee-detail 的同一员工视图页签。 |
| 28 | `hr-employees-by-id-history` | `/hr/employees/:id/history` | business | `people.employee.read` | view | `employee-detail` | People | 任职与异动履历并入 employee-detail 的同一员工视图页签。 |
| 29 | `hr-employees-by-id-files` | `/hr/employees/:id/files` | business | `people.employee.read` | view | `employee-detail` | People | 员工附件并入 employee-detail 的同一员工视图页签，仍按附件策略鉴权。 |
| 30 | `hr-employees-by-id-changes` | `/hr/employees/:id/changes` | business | `people.employee.read` | view | `employee-detail` | People | 档案变更审批记录并入 employee-detail 的同一员工视图页签。 |
| 31 | `hr-employees-imports` | `/hr/employees/imports` | business | `people.employee.read` | flow | `employees-import` | People | 员工导入保留为 employees-import 可恢复的校验与错误修正长流程。 |
| 32 | `hr-departments` | `/hr/departments` | business | `org.unit.read` | keep | `departments` | Organization | 组织架构树收敛为 departments 主工作台。 |
| 33 | `hr-departments-list` | `/hr/departments/list` | business | `org.unit.read` | view | `departments` | Organization | 部门列表并入 departments 的树表切换视图，保持同一组织范围。 |
| 34 | `hr-departments-members` | `/hr/departments/members` | business | `org.unit.read` | view | `departments` | Organization | 部门成员并入 departments 选中部门的成员视图。 |
| 35 | `hr-departments-move` | `/hr/departments/move` | business | `org.unit.read` | action | `departments` | Organization | 部门迁移并入 departments 选中部门的移动动作，提交前确认影响范围。 |
| 36 | `hr-departments-staffing` | `/hr/departments/staffing` | business | `org.unit.read` | view | `departments` | Organization | 部门编制并入 departments 选中部门的编制视图与占用统计。 |
| 37 | `hr-positions` | `/hr/positions` | business | `org.position.read` | keep | `positions` | Organization | 岗位目录收敛为 positions 主工作台。 |
| 38 | `hr-positions-headcount` | `/hr/positions/headcount` | business | `org.position.read` | view | `positions` | Organization | 岗位编制并入 positions 的岗位编制视图。 |
| 39 | `hr-positions-requirements` | `/hr/positions/requirements` | business | `org.position.read` | view | `positions` | Organization | 岗位任职资格并入 positions 的岗位资格视图，保留版本历史。 |
| 40 | `hr-positions-grades` | `/hr/positions/grades` | business | `org.position.read` | view | `positions` | Organization | 职级与职等并入 positions 的职级目录视图。 |
| 41 | `hr-positions-staff` | `/hr/positions/staff` | business | `org.position.read` | view | `positions` | Organization | 岗位任职人员并入 positions 的岗位在岗人员视图。 |
| 42 | `hr-movements` | `/hr/movements` | business | `lifecycle.case.read` | keep | `movements` | Lifecycle | 异动列表收敛为 movements 主工作台。 |
| 43 | `hr-movements-onboarding` | `/hr/movements/onboarding` | business | `lifecycle.case.read` | flow | `movement-detail` | Lifecycle | 入职办理保留为 movement-detail 的可恢复长流程与清单。 |
| 44 | `hr-movements-probation` | `/hr/movements/probation` | business | `lifecycle.case.read` | flow | `movement-detail` | Lifecycle | 转正管理保留为 movement-detail 的可恢复审批长流程。 |
| 45 | `hr-movements-transfer` | `/hr/movements/transfer` | business | `lifecycle.case.read` | flow | `movement-detail` | Lifecycle | 调岗与调部门保留为 movement-detail 的可恢复长流程。 |
| 46 | `hr-movements-salary-change` | `/hr/movements/salary-change` | business | `lifecycle.case.read` | flow | `movement-detail` | Lifecycle | 调薪管理保留为 movement-detail 的长流程，金额字段继续受 P3 策略约束。 |
| 47 | `hr-movements-secondment` | `/hr/movements/secondment` | business | `lifecycle.case.read` | flow | `movement-detail` | Lifecycle | 借调与兼岗保留为 movement-detail 的可恢复长流程。 |
| 48 | `hr-movements-offboarding` | `/hr/movements/offboarding` | business | `lifecycle.case.read` | flow | `movement-detail` | Lifecycle | 离职办理保留为 movement-detail 的可恢复长流程与权限回收清单。 |
| 49 | `hr-movements-by-id` | `/hr/movements/:id` | business | `lifecycle.case.read` | keep | `movement-detail` | Lifecycle | 异动审批详情保留为 movement-detail 深链接，承载审批轨迹与操作。 |
| 50 | `hr-movements-calendar` | `/hr/movements/calendar` | business | `lifecycle.case.read` | view | `movements` | Lifecycle | 异动日历并入 movements 的日历视图，保持同一授权范围。 |
| 51 | `hr-contracts` | `/hr/contracts` | business | `people.contract.read` | keep | `contracts` | Contracts | 合同台账收敛为 contracts 主工作台。 |
| 52 | `hr-contracts-by-id` | `/hr/contracts/:id` | business | `people.contract.read` | keep | `contract-detail` | Contracts | 合同详情保留为 contract-detail 深链接，承载版本与附件。 |
| 53 | `hr-contracts-renewals` | `/hr/contracts/renewals` | business | `people.contract.read` | flow | `contract-detail` | Contracts | 合同续签保留为 contract-detail 的版本化续签长流程。 |
| 54 | `hr-contracts-changes` | `/hr/contracts/changes` | business | `people.contract.read` | flow | `contract-detail` | Contracts | 合同变更与终止保留为 contract-detail 的版本化长流程。 |
| 55 | `hr-contracts-expiry` | `/hr/contracts/expiry` | business | `people.contract.read` | view | `contracts` | Contracts | 到期预警并入 contracts 的到期筛选视图。 |
| 56 | `hr-contracts-templates` | `/hr/contracts/templates` | business | `people.contract.read` | flow | `contract-templates` | Contracts | 合同模板保留为 contract-templates 的版本化发布长流程。 |
| 57 | `attendance` | `/attendance` | business | `time.attendance.read` | keep | `attendance` | Attendance | 我的打卡收敛为 attendance 主工作台。 |
| 58 | `attendance-punches` | `/attendance/punches` | business | `time.attendance.read` | view | `attendance` | Attendance | 打卡明细并入 attendance 的个人打卡明细视图。 |
| 59 | `attendance-calendar` | `/attendance/calendar` | business | `time.attendance.read` | view | `attendance` | Attendance | 考勤日历并入 attendance 的个人考勤日历视图。 |
| 60 | `attendance-rules` | `/attendance/rules` | business | `time.attendance.read` | flow | `attendance-rules` | Attendance | 考勤规则保留为 attendance-rules 的版本化配置长流程。 |
| 61 | `attendance-shifts` | `/attendance/shifts` | business | `time.attendance.read` | flow | `attendance-shifts` | Attendance | 班次管理保留为 attendance-shifts 的班次模板长流程。 |
| 62 | `attendance-schedules` | `/attendance/schedules` | business | `time.attendance.read` | flow | `attendance-schedules` | Attendance | 排班管理保留为 attendance-schedules 的排班发布长流程。 |
| 63 | `attendance-corrections` | `/attendance/corrections` | business | `time.attendance.read` | flow | `attendance-corrections` | Attendance | 补卡申请保留为 attendance-corrections 的审批 Case 长流程。 |
| 64 | `attendance-exceptions` | `/attendance/exceptions` | business | `time.attendance.read` | view | `attendance` | Attendance | 异常处理并入 attendance 的异常明细视图，处理动作单独授权。 |
| 65 | `attendance-closing` | `/attendance/closing` | business | `time.attendance.read` | flow | `attendance-closing` | Attendance | 考勤月结保留为 attendance-closing 的锁定、重开与审计长流程。 |
| 66 | `attendance-devices` | `/attendance/devices` | business | `time.attendance.read` | flow | `attendance-devices` | Attendance | 设备对接保留为 attendance-devices 的导入与健康检查长流程。 |
| 67 | `leave` | `/leave` | business | `time.leave.read` | keep | `leave` | Leave | 请假申请收敛为 leave 主工作台。 |
| 68 | `leave-requests` | `/leave/requests` | business | `time.leave.read` | view | `leave` | Leave | 请假台账并入 leave 的申请记录视图。 |
| 69 | `leave-types` | `/leave/types` | business | `time.leave.read` | flow | `leave-policies` | Leave | 假期类型并入 leave-policies 的版本化规则长流程。 |
| 70 | `leave-policies` | `/leave/policies` | business | `time.leave.read` | flow | `leave-policies` | Leave | 假期规则保留为 leave-policies 的版本化配置长流程。 |
| 71 | `leave-balances` | `/leave/balances` | business | `time.leave.read` | view | `leave` | Leave | 假期余额并入 leave 的余额与台账视图。 |
| 72 | `leave-calendar` | `/leave/calendar` | business | `time.leave.read` | view | `leave` | Leave | 假期日历并入 leave 的团队请假日历视图。 |
| 73 | `leave-cancellations` | `/leave/cancellations` | business | `time.leave.read` | action | `leave` | Leave | 销假与撤销并入 leave 的申请行动作，并释放余额预占。 |
| 74 | `leave-reports` | `/leave/reports` | business | `time.leave.read` | view | `leave` | Leave | 假期汇总并入 leave 的汇总统计视图。 |
| 75 | `overtime` | `/overtime` | business | `time.overtime.read` | keep | `overtime` | Overtime | 加班申请收敛为 overtime 主工作台。 |
| 76 | `overtime-requests` | `/overtime/requests` | business | `time.overtime.read` | view | `overtime` | Overtime | 加班台账并入 overtime 的申请与确认记录视图。 |
| 77 | `overtime-policies` | `/overtime/policies` | business | `time.overtime.read` | flow | `overtime-policies` | Overtime | 加班规则保留为 overtime-policies 的版本化配置长流程。 |
| 78 | `overtime-confirm` | `/overtime/confirm` | business | `time.overtime.read` | action | `overtime` | Overtime | 加班确认并入 overtime 的申请行确认动作，区分申报与实绩。 |
| 79 | `overtime-timeoff` | `/overtime/timeoff` | business | `time.overtime.read` | view | `overtime` | Overtime | 调休余额并入 overtime 的调休余额视图。 |
| 80 | `overtime-settlement` | `/overtime/settlement` | business | `time.overtime.read` | flow | `overtime-settlement` | Overtime | 加班结算保留为 overtime-settlement 的工资或调休结算长流程。 |
| 81 | `travel` | `/travel` | business | `time.travel.read` | keep | `travel` | Travel | 出差申请收敛为 travel 主工作台。 |
| 82 | `travel-requests` | `/travel/requests` | business | `time.travel.read` | view | `travel` | Travel | 出差台账并入 travel 的申请与审批状态视图。 |
| 83 | `travel-policies` | `/travel/policies` | business | `time.travel.read` | flow | `travel-policies` | Travel | 差旅政策保留为 travel-policies 的版本化发布长流程。 |
| 84 | `travel-approval` | `/travel/approval` | business | `time.travel.read` | flow | `travel-approval` | Travel | 出差审批保留为 travel-approval 的审批 Case 长流程。 |
| 85 | `travel-changes` | `/travel/changes` | business | `time.travel.read` | action | `travel` | Travel | 出差变更与销单并入 travel 的申请行动作。 |
| 86 | `travel-reports` | `/travel/reports` | business | `time.travel.read` | view | `travel` | Travel | 出差统计并入 travel 的部门统计视图。 |
| 87 | `travel-expenses` | `/travel/expenses` | business | `time.travel.read` | flow | `travel-expenses` | Travel | 费用交接保留为 travel-expenses 的报销单据长流程。 |
| 88 | `payroll` | `/payroll` | business | `payroll.run.read` | keep | `payroll` | Payroll | 薪资概览收敛为 payroll 主工作台。 |
| 89 | `payroll-run-detail` | `/payroll/runs/:id` | business | `payroll.run.read` | keep | `payroll-run-detail` | Payroll | 工资批次详情保留为 payroll 深链接，menu: null，由批次列表进入。 |
| 90 | `payroll-components` | `/payroll/components` | business | `payroll.run.read` | flow | `payroll-components` | Payroll | 薪资项目保留为 payroll-components 的版本化配置长流程。 |
| 91 | `payroll-plans` | `/payroll/plans` | business | `payroll.run.read` | flow | `payroll-plans` | Payroll | 薪酬方案保留为 payroll-plans 的版本化方案长流程。 |
| 92 | `payroll-employee-plans` | `/payroll/employee-plans` | business | `payroll.run.read` | view | `payroll` | Payroll | 员工薪资档案并入 payroll 的员工薪酬视图，金额继续按 P3 遮罩。 |
| 93 | `payroll-statutory` | `/payroll/statutory` | business | `payroll.run.read` | flow | `payroll-statutory` | Payroll | 社保公积金参数保留为 payroll-statutory 的有效期与证据长流程。 |
| 94 | `payroll-inputs` | `/payroll/inputs` | business | `payroll.run.read` | view | `payroll` | Payroll | 计薪数据汇总并入 payroll 的批次输入视图。 |
| 95 | `payroll-batches` | `/payroll/batches` | business | `payroll.run.read` | flow | `payroll-batches` | Payroll | 工资核算批次保留为 payroll-batches 的核算、复核、发布长流程。 |
| 96 | `payroll-reviews` | `/payroll/reviews` | business | `payroll.run.read` | flow | `payroll-batches` | Payroll | 工资复核并入 payroll-batches 的复核步骤，保留双人复核约束。 |
| 97 | `payroll-disbursement` | `/payroll/disbursement` | business | `payroll.run.read` | action | `payroll-batches` | Payroll | 工资发放登记并入 payroll-batches 已发布批次的发放动作。 |
| 98 | `payroll-payslips` | `/payroll/payslips` | business | `payroll.run.read` | view | `payroll-payslips` | Payroll | 工资条管理保留为 payroll-payslips 的批次工资条视图与发放结果。 |
| 99 | `payroll-adjustments` | `/payroll/adjustments` | business | `payroll.run.read` | flow | `payroll-adjustments` | Payroll | 调薪记录保留为 payroll-adjustments 的审批与生效长流程。 |
| 100 | `payroll-reports` | `/payroll/reports` | business | `payroll.run.read` | view | `payroll` | Payroll | 薪资报表并入 payroll 的成本统计视图，导出仍单独授权。 |
| 101 | `performance` | `/performance` | business | `performance.cycle.read` | keep | `performance` | Performance | 绩效周期收敛为 performance 主工作台。 |
| 102 | `performance-templates` | `/performance/templates` | business | `performance.cycle.read` | flow | `performance-templates` | Performance | 考核模板保留为 performance-templates 的版本化发布长流程。 |
| 103 | `performance-indicators` | `/performance/indicators` | business | `performance.cycle.read` | flow | `performance-indicators` | Performance | 指标库保留为 performance-indicators 的指标维护长流程。 |
| 104 | `performance-goals` | `/performance/goals` | business | `performance.cycle.read` | view | `performance` | Performance | 目标计划并入 performance 的目标录入与进度视图。 |
| 105 | `performance-reviews` | `/performance/reviews` | business | `performance.cycle.read` | flow | `performance-reviews` | Performance | 自评与主管评分保留为 performance-reviews 的提交与校准前长流程。 |
| 106 | `performance-feedback` | `/performance/feedback` | business | `performance.cycle.read` | view | `performance` | Performance | 360 反馈并入 performance 的反馈记录视图。 |
| 107 | `performance-calibration` | `/performance/calibration` | business | `performance.cycle.read` | flow | `performance-calibration` | Performance | 绩效校准保留为 performance-calibration 的校准会议与发布长流程。 |
| 108 | `performance-results` | `/performance/results` | business | `performance.cycle.read` | flow | `performance-results` | Performance | 结果发布保留为 performance-results 的冻结与发布长流程。 |
| 109 | `performance-appeals` | `/performance/appeals` | business | `performance.cycle.read` | flow | `performance-appeals` | Performance | 绩效申诉保留为 performance-appeals 的审批 Case 长流程。 |
| 110 | `performance-reports` | `/performance/reports` | business | `performance.cycle.read` | view | `performance` | Performance | 绩效报表并入 performance 的分布与趋势视图。 |
| 111 | `recruitment-requests` | `/recruitment/requests` | business | `recruitment.requisition.read` | keep | `recruitment-requests` | Recruitment | 招聘需求列表收敛为 recruitment-requests 主工作台。 |
| 112 | `recruitment-requests-approval` | `/recruitment/requests/approval` | business | `recruitment.requisition.read` | flow | `recruitment-requests` | Recruitment | 招聘需求审批并入 recruitment-requests 的审批 Case 长流程。 |
| 113 | `recruitment-requests-openings` | `/recruitment/requests/openings` | business | `recruitment.requisition.read` | action | `recruitment-requests` | Recruitment | 招聘岗位发布并入 recruitment-requests 已批准需求的发布动作。 |
| 114 | `recruitment-requests-board` | `/recruitment/requests/board` | business | `recruitment.requisition.read` | view | `recruitment-requests` | Recruitment | 招聘进度看板并入 recruitment-requests 的看板视图。 |
| 115 | `recruitment-requests-budget` | `/recruitment/requests/budget` | business | `recruitment.requisition.read` | view | `recruitment-requests` | Recruitment | 招聘预算与编制并入 recruitment-requests 的预算占用视图。 |
| 116 | `recruitment-requests-reports` | `/recruitment/requests/reports` | business | `recruitment.requisition.read` | view | `recruitment-requests` | Recruitment | 招聘统计并入 recruitment-requests 的统计视图。 |
| 117 | `recruitment-candidates` | `/recruitment/candidates` | business | `recruitment.candidate.read` | keep | `recruitment-candidates` | Recruitment | 候选人列表收敛为 recruitment-candidates 主工作台。 |
| 118 | `recruitment-candidates-by-id` | `/recruitment/candidates/:id` | business | `recruitment.candidate.read` | keep | `recruitment-candidate-detail` | Recruitment | 候选人档案保留为 recruitment-candidate-detail 深链接。 |
| 119 | `recruitment-candidates-imports` | `/recruitment/candidates/imports` | business | `recruitment.candidate.read` | action | `recruitment-candidates` | Recruitment | 简历解析与导入并入 recruitment-candidates 的导入动作与结果抽屉。 |
| 120 | `recruitment-candidates-pipeline` | `/recruitment/candidates/pipeline` | business | `recruitment.candidate.read` | view | `recruitment-candidates` | Recruitment | 招聘流程看板并入 recruitment-candidates 的漏斗看板视图。 |
| 121 | `recruitment-candidates-interviews` | `/recruitment/candidates/interviews` | business | `recruitment.candidate.read` | flow | `recruitment-candidates-interviews` | Recruitment | 面试安排保留为 recruitment-candidates-interviews 的排期与反馈长流程。 |
| 122 | `recruitment-candidates-evaluations` | `/recruitment/candidates/evaluations` | business | `recruitment.candidate.read` | view | `recruitment-candidate-detail` | Recruitment | 面试评价并入 recruitment-candidate-detail 的评价记录视图。 |
| 123 | `recruitment-candidates-offers` | `/recruitment/candidates/offers` | business | `recruitment.candidate.read` | flow | `recruitment-candidate-detail` | Recruitment | Offer 管理保留为 recruitment-candidate-detail 的版本化审批长流程。 |
| 124 | `recruitment-candidates-communications` | `/recruitment/candidates/communications` | business | `recruitment.candidate.read` | view | `recruitment-candidate-detail` | Recruitment | 候选人沟通并入 recruitment-candidate-detail 的沟通记录视图。 |
| 125 | `recruitment-candidates-talent-pool` | `/recruitment/candidates/talent-pool` | business | `recruitment.candidate.read` | view | `recruitment-candidates` | Recruitment | 人才库与标签并入 recruitment-candidates 的人才库筛选视图。 |
| 126 | `recruitment-candidates-convert` | `/recruitment/candidates/convert` | business | `recruitment.candidate.read` | action | `recruitment-candidate-detail` | Recruitment | 录用转入职并入 recruitment-candidate-detail 的转换动作，成功后跳转员工详情。 |
| 127 | `training` | `/training` | business | `training.program.read` | keep | `training` | Training | 培训计划收敛为 training 主工作台。 |
| 128 | `training-courses` | `/training/courses` | business | `training.program.read` | flow | `training-courses` | Training | 课程库保留为 training-courses 的课程与教材维护长流程。 |
| 129 | `training-sessions` | `/training/sessions` | business | `training.program.read` | flow | `training-sessions` | Training | 培训场次保留为 training-sessions 的排期与容量长流程。 |
| 130 | `training-enrollments` | `/training/enrollments` | business | `training.program.read` | action | `training-sessions` | Training | 培训报名并入 training-sessions 的报名与名额动作。 |
| 131 | `training-checkins` | `/training/checkins` | business | `training.program.read` | action | `training-sessions` | Training | 培训签到并入 training-sessions 的签到动作。 |
| 132 | `training-exams` | `/training/exams` | business | `training.program.read` | flow | `training-exams` | Training | 培训考试保留为 training-exams 的组卷与发布长流程。 |
| 133 | `training-feedback` | `/training/feedback` | business | `training.program.read` | view | `training-sessions` | Training | 培训反馈并入 training-sessions 的课后反馈视图。 |
| 134 | `training-records` | `/training/records` | business | `training.program.read` | view | `training` | Training | 培训档案并入 training 的员工培训记录视图。 |
| 135 | `training-reports` | `/training/reports` | business | `training.program.read` | view | `training-reports` | Training | 培训报表保留为 training-reports 的参训与完成率视图。 |
| 136 | `notices` | `/notices` | business | `engagement.notice.read` | keep | `notices` | Engagement | 公告列表收敛为 notices 主工作台。 |
| 137 | `notices-approval` | `/notices/approval` | business | `engagement.notice.read` | flow | `notices` | Engagement | 公告审核并入 notices 的审批 Case 长流程。 |
| 138 | `notices-audience` | `/notices/audience` | business | `engagement.notice.read` | view | `notices` | Engagement | 公告发布范围并入 notices 编辑中的受众视图。 |
| 139 | `notices-reads` | `/notices/reads` | business | `engagement.notice.read` | view | `notices` | Engagement | 公告阅读记录并入 notices 的阅读统计视图。 |
| 140 | `notices-templates` | `/notices/templates` | business | `engagement.notice.read` | flow | `notices-templates` | Engagement | 公告模板保留为 notices-templates 的模板维护长流程。 |
| 141 | `account` | `/account` | platform | `iam.session.manage` | keep | `account` | Platform | 我的账号平台页保留，聚合账号安全与自助入口。 |
| 142 | `system-users` | `/system/users` | platform | `iam.user.read` | keep | `system-users` | Platform | 用户管理保留为 system-users 治理工作台。 |
| 143 | `system-roles` | `/system/roles` | platform | `iam.role.manage` | keep | `system-roles` | Platform | 角色管理保留为 system-roles 治理工作台。 |
| 144 | `system-menus` | `/system/menus` | platform | `iam.menu.manage` | keep | `system-menus` | Platform | 菜单与页面管理保留为 system-menus 治理工作台。 |
| 145 | `system-permissions` | `/system/permissions` | platform | `iam.permission.read` | keep | `system-permissions` | Platform | 操作权限配置保留为 system-permissions 治理工作台。 |
| 146 | `system-data-scopes` | `/system/data-scopes` | platform | `iam.scope.manage` | keep | `system-data-scopes` | Platform | 数据范围权限保留为 system-data-scopes 治理工作台。 |
| 147 | `system-field-policies` | `/system/field-policies` | platform | `iam.field.manage` | keep | `system-field-policies` | Platform | 字段级权限保留为 system-field-policies 治理工作台。 |
| 148 | `workflow` | `/workflow` | platform | `workflow.case.read` | keep | `workflow` | Platform | 审批流程中心保留为 workflow 治理与待办工作台。 |
| 149 | `workflow-case-detail` | `/workflow/cases/:id` | platform | `workflow.case.read` | keep | `workflow-case-detail` | Platform | 审批案例详情保留为 workflow 深链接，menu: null，由案例列表进入。 |
| 150 | `self-service` | `/self-service` | platform | `iam.session.manage` | keep | `self-service` | Platform | 员工自助门户保留为 self-service 个人工作台。 |
| 151 | `benefits` | `/benefits` | platform | `benefit.read` | keep | `benefits` | Platform | 社保公积金与福利保留为 benefits 治理工作台。 |
| 152 | `system-calendars` | `/system/calendars` | platform | `time.calendar.read` | keep | `system-calendars` | Platform | 节假日与工作日历保留为 system-calendars 治理工作台。 |
| 153 | `system-settings` | `/system/settings` | platform | `system.settings.manage` | keep | `system-settings` | Platform | 字典、参数与文件保留为 system-settings 治理工作台。 |
| 154 | `notifications` | `/notifications` | platform | `notification.read` | keep | `notifications` | Platform | 消息与通知中心保留为 notifications 个人与投递工作台。 |
| 155 | `system-audit` | `/system/audit` | platform | `iam.audit.read` | keep | `system-audit` | Platform | 日志审计与运维保留为 system-audit 治理工作台。 |

## 完整性断言

- V1.1 业务能力点：134
- 已映射到 keep/action/view/flow：134
- 用户签认废弃：0
- 未映射：0

## 判定

- 134 个业务能力点全部有目标页面码与处置，无未映射项。
- 用户签认废弃为 0；本表不依赖任何未签认废弃。
- 平台 15 条与基础 6 条也全部有处置，注册表总计 155 条。
