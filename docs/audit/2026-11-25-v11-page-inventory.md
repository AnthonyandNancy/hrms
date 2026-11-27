# V1.1 页面与平台模块清单（机器生成）

> 来源：`HRMS-V1.1实施书.md` 第 03A/03B 章。不要手改本文件；运行 `python scripts/docs/generate_v11_inventory.py` 重新生成。
> 源文件 SHA-256：0c58983e6786f65c

## 1. 汇总

- 03A 截图菜单：**17** 个
- 03A 页面：**133** 个
- ADR-0035 动作页（不计为页面）：**7** 个（创建/编辑/归档表单）
- 03B 平台模块：**14** 个

| 波次 | 页面数 | 范围 |
|---|---:|---|
| Wave 2 | 35 | 组织与人事：员工、部门、岗位、异动、合同 |
| Wave 3 | 43 | 假勤与薪酬：考勤、请假、加班、出差、薪资 |
| Wave 4 | 35 | 绩效、招聘、培训 |
| Wave 5 | 20 | 概览、报表、公告，以及 03B 平台页面 |

| 菜单 | 页面数 | 主能力域 | 波次 |
|---|---:|---|---|
| 仪表盘 | 6 | `reporting.dashboard.read` | Wave 5 |
| 报表中心 | 9 | `reporting.export` | Wave 5 |
| 员工档案 | 10 | `people.employee.read` | Wave 2 |
| 部门管理 | 5 | `org.unit.read` | Wave 2 |
| 岗位管理 | 5 | `org.position.read` | Wave 2 |
| 员工异动 | 9 | `lifecycle.case.read` | Wave 2 |
| 劳动合同 | 6 | `people.contract.read` | Wave 2 |
| 考勤打卡 | 10 | `time.attendance.read` | Wave 3 |
| 请假管理 | 8 | `time.leave.read` | Wave 3 |
| 加班管理 | 6 | `time.overtime.read` | Wave 3 |
| 出差管理 | 7 | `time.travel.read` | Wave 3 |
| 薪资管理 | 12 | `payroll.run.read` | Wave 3 |
| 绩效考核 | 10 | `performance.cycle.read` | Wave 4 |
| 招聘需求 | 6 | `recruitment.requisition.read` | Wave 4 |
| 候选人 | 10 | `recruitment.candidate.read` | Wave 4 |
| 培训管理 | 9 | `training.program.read` | Wave 4 |
| 公告通知 | 5 | `engagement.notice.read` | Wave 5 |

## 2. 03A 页面清单

| # | 菜单 | 页面 | 路由 | 主能力域 | 波次 |
|---:|---|---|---|---|---|
| 1 | 仪表盘 | 首页看板 | `/dashboard` | `reporting.dashboard.read` | Wave 5 |
| 2 | 仪表盘 | 部门人数分析 | `/dashboard/departments` | `reporting.dashboard.read` | Wave 5 |
| 3 | 仪表盘 | 员工构成 | `/dashboard/workforce` | `reporting.dashboard.read` | Wave 5 |
| 4 | 仪表盘 | 近期人事动态 | `/dashboard/changes` | `reporting.dashboard.read` | Wave 5 |
| 5 | 仪表盘 | 工作提醒 | `/dashboard/tasks` | `reporting.dashboard.read` | Wave 5 |
| 6 | 仪表盘 | 招聘进展 | `/dashboard/recruitment` | `reporting.dashboard.read` | Wave 5 |
| 7 | 报表中心 | 报表目录 | `/reports` | `reporting.export` | Wave 5 |
| 8 | 报表中心 | 人力资源报表 | `/reports/headcount` | `reporting.export` | Wave 5 |
| 9 | 报表中心 | 考勤假期报表 | `/reports/attendance` | `reporting.export` | Wave 5 |
| 10 | 报表中心 | 薪资成本报表 | `/reports/payroll` | `reporting.export` | Wave 5 |
| 11 | 报表中心 | 招聘转化报表 | `/reports/hiring` | `reporting.export` | Wave 5 |
| 12 | 报表中心 | 绩效培训报表 | `/reports/talent` | `reporting.export` | Wave 5 |
| 13 | 报表中心 | 自定义报表 | `/reports/builder` | `reporting.export` | Wave 5 |
| 14 | 报表中心 | 报表订阅 | `/reports/subscriptions` | `reporting.export` | Wave 5 |
| 15 | 报表中心 | 导出任务 | `/reports/exports` | `reporting.export` | Wave 5 |
| 16 | 员工档案 | 员工列表 | `/hr/employees` | `people.employee.read` | Wave 2 |
| 18 | 员工档案 | 档案详情 | `/hr/employees/:id` | `people.employee.read` | Wave 2 |
| 19 | 员工档案 | 教育经历 | `/hr/employees/:id/education` | `people.employee.read` | Wave 2 |
| 20 | 员工档案 | 工作经历 | `/hr/employees/:id/work-history` | `people.employee.read` | Wave 2 |
| 21 | 员工档案 | 家庭/紧急联系人 | `/hr/employees/:id/contacts` | `people.employee.read` | Wave 2 |
| 22 | 员工档案 | 证照与资格 | `/hr/employees/:id/credentials` | `people.employee.read` | Wave 2 |
| 23 | 员工档案 | 任职/异动履历 | `/hr/employees/:id/history` | `people.employee.read` | Wave 2 |
| 24 | 员工档案 | 员工附件 | `/hr/employees/:id/files` | `people.employee.read` | Wave 2 |
| 25 | 员工档案 | 员工档案变更审批 | `/hr/employees/:id/changes` | `people.employee.read` | Wave 2 |
| 26 | 员工档案 | 导入校验结果 | `/hr/employees/imports` | `people.employee.read` | Wave 2 |
| 27 | 部门管理 | 组织架构树 | `/hr/departments` | `org.unit.read` | Wave 2 |
| 28 | 部门管理 | 部门列表 | `/hr/departments/list` | `org.unit.read` | Wave 2 |
| 30 | 部门管理 | 部门成员 | `/hr/departments/members` | `org.unit.read` | Wave 2 |
| 31 | 部门管理 | 部门迁移 | `/hr/departments/move` | `org.unit.read` | Wave 2 |
| 32 | 部门管理 | 部门编制 | `/hr/departments/staffing` | `org.unit.read` | Wave 2 |
| 33 | 岗位管理 | 岗位目录 | `/hr/positions` | `org.position.read` | Wave 2 |
| 35 | 岗位管理 | 岗位编制 | `/hr/positions/headcount` | `org.position.read` | Wave 2 |
| 36 | 岗位管理 | 岗位任职资格 | `/hr/positions/requirements` | `org.position.read` | Wave 2 |
| 37 | 岗位管理 | 职级/职等 | `/hr/positions/grades` | `org.position.read` | Wave 2 |
| 38 | 岗位管理 | 岗位任职人员 | `/hr/positions/staff` | `org.position.read` | Wave 2 |
| 39 | 员工异动 | 异动列表 | `/hr/movements` | `lifecycle.case.read` | Wave 2 |
| 40 | 员工异动 | 入职办理 | `/hr/movements/onboarding` | `lifecycle.case.read` | Wave 2 |
| 41 | 员工异动 | 转正管理 | `/hr/movements/probation` | `lifecycle.case.read` | Wave 2 |
| 42 | 员工异动 | 调岗/调部门 | `/hr/movements/transfer` | `lifecycle.case.read` | Wave 2 |
| 43 | 员工异动 | 调薪管理 | `/hr/movements/salary-change` | `lifecycle.case.read` | Wave 2 |
| 44 | 员工异动 | 借调/兼岗 | `/hr/movements/secondment` | `lifecycle.case.read` | Wave 2 |
| 45 | 员工异动 | 离职办理 | `/hr/movements/offboarding` | `lifecycle.case.read` | Wave 2 |
| 46 | 员工异动 | 异动审批详情 | `/hr/movements/:id` | `lifecycle.case.read` | Wave 2 |
| 47 | 员工异动 | 异动日历 | `/hr/movements/calendar` | `lifecycle.case.read` | Wave 2 |
| 48 | 劳动合同 | 合同台账 | `/hr/contracts` | `people.contract.read` | Wave 2 |
| 50 | 劳动合同 | 合同详情 | `/hr/contracts/:id` | `people.contract.read` | Wave 2 |
| 51 | 劳动合同 | 合同续签 | `/hr/contracts/renewals` | `people.contract.read` | Wave 2 |
| 52 | 劳动合同 | 合同变更/终止 | `/hr/contracts/changes` | `people.contract.read` | Wave 2 |
| 53 | 劳动合同 | 到期预警 | `/hr/contracts/expiry` | `people.contract.read` | Wave 2 |
| 54 | 劳动合同 | 合同模板 | `/hr/contracts/templates` | `people.contract.read` | Wave 2 |
| 55 | 考勤打卡 | 我的打卡 | `/attendance` | `time.attendance.read` | Wave 3 |
| 56 | 考勤打卡 | 打卡明细 | `/attendance/punches` | `time.attendance.read` | Wave 3 |
| 57 | 考勤打卡 | 考勤日历 | `/attendance/calendar` | `time.attendance.read` | Wave 3 |
| 58 | 考勤打卡 | 考勤规则 | `/attendance/rules` | `time.attendance.read` | Wave 3 |
| 59 | 考勤打卡 | 班次管理 | `/attendance/shifts` | `time.attendance.read` | Wave 3 |
| 60 | 考勤打卡 | 排班管理 | `/attendance/schedules` | `time.attendance.read` | Wave 3 |
| 61 | 考勤打卡 | 补卡申请 | `/attendance/corrections` | `time.attendance.read` | Wave 3 |
| 62 | 考勤打卡 | 异常处理 | `/attendance/exceptions` | `time.attendance.read` | Wave 3 |
| 63 | 考勤打卡 | 考勤月结 | `/attendance/closing` | `time.attendance.read` | Wave 3 |
| 64 | 考勤打卡 | 设备对接 | `/attendance/devices` | `time.attendance.read` | Wave 3 |
| 65 | 请假管理 | 请假申请 | `/leave` | `time.leave.read` | Wave 3 |
| 66 | 请假管理 | 请假台账 | `/leave/requests` | `time.leave.read` | Wave 3 |
| 67 | 请假管理 | 假期类型 | `/leave/types` | `time.leave.read` | Wave 3 |
| 68 | 请假管理 | 假期规则 | `/leave/policies` | `time.leave.read` | Wave 3 |
| 69 | 请假管理 | 假期余额 | `/leave/balances` | `time.leave.read` | Wave 3 |
| 70 | 请假管理 | 假期日历 | `/leave/calendar` | `time.leave.read` | Wave 3 |
| 71 | 请假管理 | 销假/撤销 | `/leave/cancellations` | `time.leave.read` | Wave 3 |
| 72 | 请假管理 | 假期汇总 | `/leave/reports` | `time.leave.read` | Wave 3 |
| 73 | 加班管理 | 加班申请 | `/overtime` | `time.overtime.read` | Wave 3 |
| 74 | 加班管理 | 加班台账 | `/overtime/requests` | `time.overtime.read` | Wave 3 |
| 75 | 加班管理 | 加班规则 | `/overtime/policies` | `time.overtime.read` | Wave 3 |
| 76 | 加班管理 | 加班确认 | `/overtime/confirm` | `time.overtime.read` | Wave 3 |
| 77 | 加班管理 | 调休余额 | `/overtime/timeoff` | `time.overtime.read` | Wave 3 |
| 78 | 加班管理 | 加班结算 | `/overtime/settlement` | `time.overtime.read` | Wave 3 |
| 79 | 出差管理 | 出差申请 | `/travel` | `time.travel.read` | Wave 3 |
| 80 | 出差管理 | 出差台账 | `/travel/requests` | `time.travel.read` | Wave 3 |
| 81 | 出差管理 | 差旅政策 | `/travel/policies` | `time.travel.read` | Wave 3 |
| 82 | 出差管理 | 出差审批 | `/travel/approval` | `time.travel.read` | Wave 3 |
| 83 | 出差管理 | 出差变更/销单 | `/travel/changes` | `time.travel.read` | Wave 3 |
| 84 | 出差管理 | 出差统计 | `/travel/reports` | `time.travel.read` | Wave 3 |
| 85 | 出差管理 | 费用交接 | `/travel/expenses` | `time.travel.read` | Wave 3 |
| 86 | 薪资管理 | 薪资概览 | `/payroll` | `payroll.run.read` | Wave 3 |
| 87 | 薪资管理 | 薪资项目 | `/payroll/components` | `payroll.run.read` | Wave 3 |
| 88 | 薪资管理 | 薪酬方案 | `/payroll/plans` | `payroll.run.read` | Wave 3 |
| 89 | 薪资管理 | 员工薪资档案 | `/payroll/employee-plans` | `payroll.run.read` | Wave 3 |
| 90 | 薪资管理 | 社保公积金参数 | `/payroll/statutory` | `payroll.run.read` | Wave 3 |
| 91 | 薪资管理 | 计薪数据汇总 | `/payroll/inputs` | `payroll.run.read` | Wave 3 |
| 92 | 薪资管理 | 工资核算批次 | `/payroll/batches` | `payroll.run.read` | Wave 3 |
| 93 | 薪资管理 | 工资复核 | `/payroll/reviews` | `payroll.run.read` | Wave 3 |
| 94 | 薪资管理 | 工资发放登记 | `/payroll/disbursement` | `payroll.run.read` | Wave 3 |
| 95 | 薪资管理 | 工资条管理 | `/payroll/payslips` | `payroll.run.read` | Wave 3 |
| 96 | 薪资管理 | 调薪记录 | `/payroll/adjustments` | `payroll.run.read` | Wave 3 |
| 97 | 薪资管理 | 薪资报表 | `/payroll/reports` | `payroll.run.read` | Wave 3 |
| 98 | 绩效考核 | 绩效周期 | `/performance` | `performance.cycle.read` | Wave 4 |
| 99 | 绩效考核 | 考核模板 | `/performance/templates` | `performance.cycle.read` | Wave 4 |
| 100 | 绩效考核 | 指标库 | `/performance/indicators` | `performance.cycle.read` | Wave 4 |
| 101 | 绩效考核 | 目标计划 | `/performance/goals` | `performance.cycle.read` | Wave 4 |
| 102 | 绩效考核 | 自评/主管评分 | `/performance/reviews` | `performance.cycle.read` | Wave 4 |
| 103 | 绩效考核 | 360反馈 | `/performance/feedback` | `performance.cycle.read` | Wave 4 |
| 104 | 绩效考核 | 绩效校准 | `/performance/calibration` | `performance.cycle.read` | Wave 4 |
| 105 | 绩效考核 | 结果发布 | `/performance/results` | `performance.cycle.read` | Wave 4 |
| 106 | 绩效考核 | 绩效申诉 | `/performance/appeals` | `performance.cycle.read` | Wave 4 |
| 107 | 绩效考核 | 绩效报表 | `/performance/reports` | `performance.cycle.read` | Wave 4 |
| 108 | 招聘需求 | 招聘需求列表 | `/recruitment/requests` | `recruitment.requisition.read` | Wave 4 |
| 110 | 招聘需求 | 招聘需求审批 | `/recruitment/requests/approval` | `recruitment.requisition.read` | Wave 4 |
| 111 | 招聘需求 | 招聘岗位发布 | `/recruitment/requests/openings` | `recruitment.requisition.read` | Wave 4 |
| 112 | 招聘需求 | 招聘进度看板 | `/recruitment/requests/board` | `recruitment.requisition.read` | Wave 4 |
| 113 | 招聘需求 | 招聘预算与编制 | `/recruitment/requests/budget` | `recruitment.requisition.read` | Wave 4 |
| 114 | 招聘需求 | 招聘统计 | `/recruitment/requests/reports` | `recruitment.requisition.read` | Wave 4 |
| 115 | 候选人 | 候选人列表 | `/recruitment/candidates` | `recruitment.candidate.read` | Wave 4 |
| 116 | 候选人 | 候选人档案 | `/recruitment/candidates/:id` | `recruitment.candidate.read` | Wave 4 |
| 117 | 候选人 | 简历解析/导入 | `/recruitment/candidates/imports` | `recruitment.candidate.read` | Wave 4 |
| 118 | 候选人 | 招聘流程看板 | `/recruitment/candidates/pipeline` | `recruitment.candidate.read` | Wave 4 |
| 119 | 候选人 | 面试安排 | `/recruitment/candidates/interviews` | `recruitment.candidate.read` | Wave 4 |
| 120 | 候选人 | 面试评价 | `/recruitment/candidates/evaluations` | `recruitment.candidate.read` | Wave 4 |
| 121 | 候选人 | Offer管理 | `/recruitment/candidates/offers` | `recruitment.candidate.read` | Wave 4 |
| 122 | 候选人 | 候选人沟通 | `/recruitment/candidates/communications` | `recruitment.candidate.read` | Wave 4 |
| 123 | 候选人 | 人才库与标签 | `/recruitment/candidates/talent-pool` | `recruitment.candidate.read` | Wave 4 |
| 124 | 候选人 | 录用转入职 | `/recruitment/candidates/convert` | `recruitment.candidate.read` | Wave 4 |
| 125 | 培训管理 | 培训计划 | `/training` | `training.program.read` | Wave 4 |
| 126 | 培训管理 | 课程库 | `/training/courses` | `training.program.read` | Wave 4 |
| 127 | 培训管理 | 培训场次 | `/training/sessions` | `training.program.read` | Wave 4 |
| 128 | 培训管理 | 培训报名 | `/training/enrollments` | `training.program.read` | Wave 4 |
| 129 | 培训管理 | 培训签到 | `/training/checkins` | `training.program.read` | Wave 4 |
| 130 | 培训管理 | 培训考试 | `/training/exams` | `training.program.read` | Wave 4 |
| 131 | 培训管理 | 培训反馈 | `/training/feedback` | `training.program.read` | Wave 4 |
| 132 | 培训管理 | 培训档案 | `/training/records` | `training.program.read` | Wave 4 |
| 133 | 培训管理 | 培训报表 | `/training/reports` | `training.program.read` | Wave 4 |
| 134 | 公告通知 | 公告列表 | `/notices` | `engagement.notice.read` | Wave 5 |
| 136 | 公告通知 | 公告审核 | `/notices/approval` | `engagement.notice.read` | Wave 5 |
| 137 | 公告通知 | 公告发布范围 | `/notices/audience` | `engagement.notice.read` | Wave 5 |
| 138 | 公告通知 | 公告阅读记录 | `/notices/reads` | `engagement.notice.read` | Wave 5 |
| 139 | 公告通知 | 公告模板 | `/notices/templates` | `engagement.notice.read` | Wave 5 |

## 3. 03B 平台模块清单

| 编号 | 模块 | 建议路由 | 页面/功能 | 主要实体 | 验收用例 |
|---|---|---|---|---|---|
| 03B.1 | 登录与账号安全 | `/login` | 账号密码、验证码/限流、MFA、密码重置、会话管理、账户冻结、首次激活。 | `sys_users`, `sys_sessions`, `sys_mfa_credentials`, `sys_login_logs`。 | 离职或禁用账号的历史Token立即失效。 |
| 03B.2 | 用户管理 | `/system/users` | 用户列表、创建/停用、关联员工、分配角色、部门数据范围、强制退出、权限预览。 | `sys_users`, `sys_user_roles`, `sys_user_scopes`。 | 回收角色后旧会话不能继续使用高权限API。 |
| 03B.3 | 角色管理 | `/system/roles` | 角色列表、角色复制、角色授权树、数据范围设定、字段策略、授权预览、角色成员、变更审计。 | `sys_roles`, `sys_role_permissions`, `sys_user_roles`, `sys_role_data_scopes`。 | 修改角色权限后所有关联用户访问立即按新权限执行。 |
| 03B.4 | 菜单/页面管理 | `/system/menus` | 菜单树、创建分组/目录/页面、路由注册名、排序、启停、图标、默认展开与显示。 | `sys_menus`, `sys_permissions`。 | 没有页面权限者复制URL访问也应得到403。 |
| 03B.5 | 操作权限配置 | `/system/permissions` | 权限资源列表、按模块分组、新增/编辑/删除/审批/导入/导出/打印等操作权限、权限覆盖检测。 | `sys_permissions`, `sys_role_permissions`。 | 只有查看权限者不能调用新增、导出或审批API。 |
| 03B.6 | 数据范围权限 | `/system/data-scopes` | 本人、本部门、本部门及子部门、指定部门、本公司、指定公司、全集团范围配置。 | `sys_data_scopes`, `sys_role_data_scopes`, `sys_user_scopes`。 | 部门经理不能通过改query参数访问其他部门员工。 |
| 03B.7 | 字段级权限 | `/system/field-policies` | 敏感字段清单、明文/脱敏/不可见/可编辑策略、导出列约束、下载控制。 | `sys_sensitive_fields`, `sys_field_policies`。 | 普通员工通过直接调用API也不能获取隐藏的工资字段。 |
| 03B.8 | 审批流程中心 | `/workflow` | 流程模板、发起、我的待办/已办、抄送、转交、退回、撤回、催办、审批轨迹、代理审批规则。 | `workflow_definitions`, `workflow_versions`, `workflow_instances`, `workflow_tasks`, `workflow_actions`。 | 不同流程模板可按公司/部门/条件选择审批人。 |
| 03B.9 | 员工自助门户（并入工作台） | `/workspace` | 我的档案、资料更正、我的打卡/考勤、请假加班出差、我的薪资条、我的绩效、我的培训、公告、待办；V2.3 M01 已删除独立 `/self-service` 页面，能力并入工作台。 | `employee_change_requests`, `self_service_preferences`。 | 更改employee_id参数不能读取别人的工资条和申请。 |
| 03B.10 | 社保公积金与福利 | `/benefits` | 属地基数方案、个人/单位缴费项、月度增减员记录、员工福利项目、福利发放台账。 | `statutory_rules`, `employee_insurance_enrollments`, `benefit_plans`, `benefit_transactions`。 | 参保基数变更保留历史，月度费用可关联工资批次。 |
| 03B.11 | 节假日与工作日历 | `/system/calendars` | 法定节假日、自定义休息日、调休工作日、公司日历、地区和时区。 | `work_calendars`, `calendar_days`, `calendar_versions`。 | 跨地区员工使用对应日历，跨夜班考勤归属正确。 |
| 03B.12 | 字典、参数与文件 | `/system/settings` | 员工状态、合同类型、假别、申请原因等字典；全局参数；文件密级、访问审计。 | `sys_dictionaries`, `sys_parameters`, `files`, `file_access_logs`。 | 知道文件ID不能越权下载合同或简历附件。 |
| 03B.13 | 消息与通知中心 | `/notifications` | 站内信、待办提醒、阅读状态、失败重试、模板、消息投递日志。 | `notifications`, `notification_templates`, `notification_outbox`, `notification_deliveries`。 | 重复审批事件不生成重复通知；消息只发送到授权受众。 |
| 03B.14 | 日志审计与运维 | `/system/audit` | 登录/授权操作日志、业务变更日志、审计筛选、异常告警、备份恢复、任务监控。 | `audit_events`, `login_logs`, `export_jobs`, `scheduler_runs`, `backup_records`。 | 完成一次权限升降后能定位操作人、对象、时间、前后值和请求ID。 |

## 4. 验收规则

- 页面注册表必须与本清单一一对应；多一个或少一个都失败。
- 03A 的 133 个页面是主验收范围；另有 7 个创建/编辑/归档表单按 ADR-0035 作为工作区动作，不注册页面路由。
- 03B 的 14 个模块必须各有独立路由、权限、API、空/错/无权限态与真实 E2E。
- 主能力域是页面默认所需的最小能力；具体动作（create/update/approve/export）在角色分配中单独授予。
- 页面可见性 = 页面授权 AND 动作能力；仅有菜单授权不能调用 API，仅有动作能力未授权页面也不会出现在菜单。
