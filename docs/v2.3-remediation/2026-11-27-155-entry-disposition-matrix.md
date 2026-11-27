# FactoryHR V2.3｜155 条旧入口逐项处置矩阵

> **状态：待用户确认。** 本矩阵逐条覆盖当前 `frontend/src/router/registry.ts` 中的 155 条页面。`KEEP` 表示保留为目标主工作区或详情深链，不表示现状已合格；`FOLD` 表示删除独立路由，能力迁入目标页面的指定 Tab/动作。

## 汇总

| 模块 | 名称 | 原入口 | 保留 | 合并删除 |
|---|---|---:|---:|---:|
| M01 | 登录、个人中心与员工自助 | 8 | 5 | 3 |
| M02 | 首页仪表盘 | 6 | 1 | 5 |
| M03 | 报表与导出中心 | 9 | 1 | 8 |
| M04 | 员工档案 | 10 | 2 | 8 |
| M05 | 部门与组织结构 | 5 | 1 | 4 |
| M06 | 岗位、职级和任职资格 | 5 | 1 | 4 |
| M07 | 员工入转调离与异动 | 9 | 2 | 7 |
| M08 | 劳动合同 | 6 | 2 | 4 |
| M09 | 考勤打卡与月结 | 10 | 1 | 9 |
| M10 | 请假与假期余额 | 8 | 1 | 7 |
| M11 | 加班与调休 | 6 | 1 | 5 |
| M12 | 出差管理 | 7 | 1 | 6 |
| M13 | 薪资核算 | 13 | 2 | 11 |
| M14 | 社保公积金与福利 | 1 | 1 | 0 |
| M15 | 绩效考核 | 10 | 1 | 9 |
| M16 | 招聘需求与候选人 | 16 | 3 | 13 |
| M17 | 培训管理 | 9 | 1 | 8 |
| M18 | 公告与通知 | 6 | 2 | 4 |
| M19 | 用户角色与细粒度权限 | 6 | 2 | 4 |
| M20 | 审批流程中心 | 2 | 2 | 0 |
| M21 | 公司基础设置与审计运维 | 3 | 2 | 1 |
| **合计** |  | **155** | **35** | **120** |

## 逐项处置

### M01 登录、个人中心与员工自助

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 1 | `/login` | KEEP | `/login` | 独立登录页 |
| 2 | `/workspace` | KEEP | `/workspace` | 我的工作台 |
| 3 | `/forbidden` | KEEP | `/forbidden` | 系统结果页 |
| 4 | `/not-found` | KEEP | `/not-found` | 系统结果页 |
| 5 | `/account/mfa` | FOLD | `/account` | 账号安全 |
| 6 | `/account/sessions` | FOLD | `/account` | 账号安全 |
| 141 | `/account` | KEEP | `/account` | 账号安全 |
| 150 | `/self-service` | FOLD | `/workspace` | 工作台快捷入口 |

### M02 首页仪表盘

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 7 | `/dashboard` | KEEP | `/dashboard` | 首页看板 |
| 8 | `/dashboard/departments` | FOLD | `/dashboard` | 首页分区 |
| 9 | `/dashboard/workforce` | FOLD | `/dashboard` | 首页分区 |
| 10 | `/dashboard/changes` | FOLD | `/dashboard` | 首页分区 |
| 11 | `/dashboard/tasks` | FOLD | `/dashboard` | 首页分区 |
| 12 | `/dashboard/recruitment` | FOLD | `/dashboard` | 首页分区 |

### M03 报表与导出中心

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 13 | `/reports` | KEEP | `/reports` | 报表中心 |
| 14 | `/reports/headcount` | FOLD | `/reports` | 报表类型/子页 |
| 15 | `/reports/attendance` | FOLD | `/reports` | 报表类型/子页 |
| 16 | `/reports/payroll` | FOLD | `/reports` | 报表类型/子页 |
| 17 | `/reports/hiring` | FOLD | `/reports` | 报表类型/子页 |
| 18 | `/reports/talent` | FOLD | `/reports` | 报表类型/子页 |
| 19 | `/reports/builder` | FOLD | `/reports` | 报表类型/子页 |
| 20 | `/reports/subscriptions` | FOLD | `/reports` | 报表类型/子页 |
| 21 | `/reports/exports` | FOLD | `/reports` | 报表类型/子页 |

### M04 员工档案

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 22 | `/hr/employees` | KEEP | `/hr/employees` | 员工档案工作区 |
| 23 | `/hr/employees/:id` | KEEP | `/hr/employees/:id` | 员工详情深链 |
| 24 | `/hr/employees/:id/education` | FOLD | `/hr/employees/:id` | 员工详情 Tab |
| 25 | `/hr/employees/:id/work-history` | FOLD | `/hr/employees/:id` | 员工详情 Tab |
| 26 | `/hr/employees/:id/contacts` | FOLD | `/hr/employees/:id` | 员工详情 Tab |
| 27 | `/hr/employees/:id/credentials` | FOLD | `/hr/employees/:id` | 员工详情 Tab |
| 28 | `/hr/employees/:id/history` | FOLD | `/hr/employees/:id` | 员工详情 Tab |
| 29 | `/hr/employees/:id/files` | FOLD | `/hr/employees/:id` | 员工详情 Tab |
| 30 | `/hr/employees/:id/changes` | FOLD | `/hr/employees/:id` | 员工详情 Tab |
| 31 | `/hr/employees/imports` | FOLD | `/hr/employees` | 员工列表导入动作 |

### M05 部门与组织结构

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 32 | `/hr/departments` | KEEP | `/hr/departments` | 部门与组织工作区 |
| 33 | `/hr/departments/list` | FOLD | `/hr/departments` | 部门详情 Tab/动作 |
| 34 | `/hr/departments/members` | FOLD | `/hr/departments` | 部门详情 Tab/动作 |
| 35 | `/hr/departments/move` | FOLD | `/hr/departments` | 部门详情 Tab/动作 |
| 36 | `/hr/departments/staffing` | FOLD | `/hr/departments` | 部门详情 Tab/动作 |

### M06 岗位、职级和任职资格

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 37 | `/hr/positions` | KEEP | `/hr/positions` | 岗位管理工作区 |
| 38 | `/hr/positions/headcount` | FOLD | `/hr/positions` | 岗位详情 Tab/配置 |
| 39 | `/hr/positions/requirements` | FOLD | `/hr/positions` | 岗位详情 Tab/配置 |
| 40 | `/hr/positions/grades` | FOLD | `/hr/positions` | 岗位详情 Tab/配置 |
| 41 | `/hr/positions/staff` | FOLD | `/hr/positions` | 岗位详情 Tab/配置 |

### M07 员工入转调离与异动

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 42 | `/hr/movements` | KEEP | `/hr/movements` | 异动台账 |
| 43 | `/hr/movements/onboarding` | FOLD | `/hr/movements` | 异动类型/日历视图 |
| 44 | `/hr/movements/probation` | FOLD | `/hr/movements` | 异动类型/日历视图 |
| 45 | `/hr/movements/transfer` | FOLD | `/hr/movements` | 异动类型/日历视图 |
| 46 | `/hr/movements/salary-change` | FOLD | `/hr/movements` | 异动类型/日历视图 |
| 47 | `/hr/movements/secondment` | FOLD | `/hr/movements` | 异动类型/日历视图 |
| 48 | `/hr/movements/offboarding` | FOLD | `/hr/movements` | 异动类型/日历视图 |
| 49 | `/hr/movements/:id` | KEEP | `/hr/movements/:id` | 异动详情深链 |
| 50 | `/hr/movements/calendar` | FOLD | `/hr/movements` | 异动类型/日历视图 |

### M08 劳动合同

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 51 | `/hr/contracts` | KEEP | `/hr/contracts` | 合同台账 |
| 52 | `/hr/contracts/:id` | KEEP | `/hr/contracts/:id` | 合同详情深链 |
| 53 | `/hr/contracts/renewals` | FOLD | `/hr/contracts` | 合同详情动作/视图 |
| 54 | `/hr/contracts/changes` | FOLD | `/hr/contracts` | 合同详情动作/视图 |
| 55 | `/hr/contracts/expiry` | FOLD | `/hr/contracts` | 合同详情动作/视图 |
| 56 | `/hr/contracts/templates` | FOLD | `/hr/contracts` | 合同详情动作/视图 |

### M09 考勤打卡与月结

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 57 | `/attendance` | KEEP | `/attendance` | 我的打卡 |
| 58 | `/attendance/punches` | FOLD | `/attendance` | 考勤管理 Tab |
| 59 | `/attendance/calendar` | FOLD | `/attendance` | 考勤管理 Tab |
| 60 | `/attendance/rules` | FOLD | `/attendance` | 考勤管理 Tab |
| 61 | `/attendance/shifts` | FOLD | `/attendance` | 考勤管理 Tab |
| 62 | `/attendance/schedules` | FOLD | `/attendance` | 考勤管理 Tab |
| 63 | `/attendance/corrections` | FOLD | `/attendance` | 考勤管理 Tab |
| 64 | `/attendance/exceptions` | FOLD | `/attendance` | 考勤管理 Tab |
| 65 | `/attendance/closing` | FOLD | `/attendance` | 考勤管理 Tab |
| 66 | `/attendance/devices` | FOLD | `/attendance` | 考勤管理 Tab |

### M10 请假与假期余额

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 67 | `/leave` | KEEP | `/leave` | 我的请假 |
| 68 | `/leave/requests` | FOLD | `/leave` | 请假管理 Tab |
| 69 | `/leave/types` | FOLD | `/leave` | 请假管理 Tab |
| 70 | `/leave/policies` | FOLD | `/leave` | 请假管理 Tab |
| 71 | `/leave/balances` | FOLD | `/leave` | 请假管理 Tab |
| 72 | `/leave/calendar` | FOLD | `/leave` | 请假管理 Tab |
| 73 | `/leave/cancellations` | FOLD | `/leave` | 请假管理 Tab |
| 74 | `/leave/reports` | FOLD | `/leave` | 请假管理 Tab |

### M11 加班与调休

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 75 | `/overtime` | KEEP | `/overtime` | 我的加班 |
| 76 | `/overtime/requests` | FOLD | `/overtime` | 加班管理 Tab |
| 77 | `/overtime/policies` | FOLD | `/overtime` | 加班管理 Tab |
| 78 | `/overtime/confirm` | FOLD | `/overtime` | 加班管理 Tab |
| 79 | `/overtime/timeoff` | FOLD | `/overtime` | 加班管理 Tab |
| 80 | `/overtime/settlement` | FOLD | `/overtime` | 加班管理 Tab |

### M12 出差管理

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 81 | `/travel` | KEEP | `/travel` | 我的出差 |
| 82 | `/travel/requests` | FOLD | `/travel` | 出差管理 Tab |
| 83 | `/travel/policies` | FOLD | `/travel` | 出差管理 Tab |
| 84 | `/travel/approval` | FOLD | `/travel` | 出差管理 Tab |
| 85 | `/travel/changes` | FOLD | `/travel` | 出差管理 Tab |
| 86 | `/travel/reports` | FOLD | `/travel` | 出差管理 Tab |
| 87 | `/travel/expenses` | FOLD | `/travel` | 出差管理 Tab |

### M13 薪资核算

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 88 | `/payroll` | KEEP | `/payroll` | 薪资工作台 |
| 89 | `/payroll/runs/:id` | KEEP | `/payroll/runs/:id` | 工资批次详情深链 |
| 90 | `/payroll/components` | FOLD | `/payroll` | 薪资配置/批次 Tab |
| 91 | `/payroll/plans` | FOLD | `/payroll` | 薪资配置/批次 Tab |
| 92 | `/payroll/employee-plans` | FOLD | `/payroll` | 薪资配置/批次 Tab |
| 93 | `/payroll/statutory` | FOLD | `/payroll` | 薪资配置/批次 Tab |
| 94 | `/payroll/inputs` | FOLD | `/payroll` | 薪资配置/批次 Tab |
| 95 | `/payroll/batches` | FOLD | `/payroll` | 薪资配置/批次 Tab |
| 96 | `/payroll/reviews` | FOLD | `/payroll` | 薪资配置/批次 Tab |
| 97 | `/payroll/disbursement` | FOLD | `/payroll` | 薪资配置/批次 Tab |
| 98 | `/payroll/payslips` | FOLD | `/payroll` | 薪资配置/批次 Tab |
| 99 | `/payroll/adjustments` | FOLD | `/payroll` | 薪资配置/批次 Tab |
| 100 | `/payroll/reports` | FOLD | `/payroll` | 薪资配置/批次 Tab |

### M14 社保公积金与福利

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 151 | `/benefits` | KEEP | `/benefits` | 社保与福利唯一工作区 |

### M15 绩效考核

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 101 | `/performance` | KEEP | `/performance` | 绩效周期工作区 |
| 102 | `/performance/templates` | FOLD | `/performance` | 绩效周期详情 Tab |
| 103 | `/performance/indicators` | FOLD | `/performance` | 绩效周期详情 Tab |
| 104 | `/performance/goals` | FOLD | `/performance` | 绩效周期详情 Tab |
| 105 | `/performance/reviews` | FOLD | `/performance` | 绩效周期详情 Tab |
| 106 | `/performance/feedback` | FOLD | `/performance` | 绩效周期详情 Tab |
| 107 | `/performance/calibration` | FOLD | `/performance` | 绩效周期详情 Tab |
| 108 | `/performance/results` | FOLD | `/performance` | 绩效周期详情 Tab |
| 109 | `/performance/appeals` | FOLD | `/performance` | 绩效周期详情 Tab |
| 110 | `/performance/reports` | FOLD | `/performance` | 绩效周期详情 Tab |

### M16 招聘需求与候选人

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 111 | `/recruitment/requests` | KEEP | `/recruitment/requests` | 招聘需求工作区 |
| 112 | `/recruitment/requests/approval` | FOLD | `/recruitment/requests` | 招聘需求详情 Tab |
| 113 | `/recruitment/requests/openings` | FOLD | `/recruitment/requests` | 招聘需求详情 Tab |
| 114 | `/recruitment/requests/board` | FOLD | `/recruitment/requests` | 招聘需求详情 Tab |
| 115 | `/recruitment/requests/budget` | FOLD | `/recruitment/requests` | 招聘需求详情 Tab |
| 116 | `/recruitment/requests/reports` | FOLD | `/recruitment/requests` | 招聘需求详情 Tab |
| 117 | `/recruitment/candidates` | KEEP | `/recruitment/candidates` | 候选人工作区 |
| 118 | `/recruitment/candidates/:id` | KEEP | `/recruitment/candidates/:id` | 候选人详情深链 |
| 119 | `/recruitment/candidates/imports` | FOLD | `/recruitment/candidates/:id` | 候选人详情 Tab/动作 |
| 120 | `/recruitment/candidates/pipeline` | FOLD | `/recruitment/candidates/:id` | 候选人详情 Tab/动作 |
| 121 | `/recruitment/candidates/interviews` | FOLD | `/recruitment/candidates/:id` | 候选人详情 Tab/动作 |
| 122 | `/recruitment/candidates/evaluations` | FOLD | `/recruitment/candidates/:id` | 候选人详情 Tab/动作 |
| 123 | `/recruitment/candidates/offers` | FOLD | `/recruitment/candidates/:id` | 候选人详情 Tab/动作 |
| 124 | `/recruitment/candidates/communications` | FOLD | `/recruitment/candidates/:id` | 候选人详情 Tab/动作 |
| 125 | `/recruitment/candidates/talent-pool` | FOLD | `/recruitment/candidates/:id` | 候选人详情 Tab/动作 |
| 126 | `/recruitment/candidates/convert` | FOLD | `/recruitment/candidates/:id` | 候选人详情 Tab/动作 |

### M17 培训管理

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 127 | `/training` | KEEP | `/training` | 培训管理工作区 |
| 128 | `/training/courses` | FOLD | `/training` | 培训计划/场次 Tab |
| 129 | `/training/sessions` | FOLD | `/training` | 培训计划/场次 Tab |
| 130 | `/training/enrollments` | FOLD | `/training` | 培训计划/场次 Tab |
| 131 | `/training/checkins` | FOLD | `/training` | 培训计划/场次 Tab |
| 132 | `/training/exams` | FOLD | `/training` | 培训计划/场次 Tab |
| 133 | `/training/feedback` | FOLD | `/training` | 培训计划/场次 Tab |
| 134 | `/training/records` | FOLD | `/training` | 培训计划/场次 Tab |
| 135 | `/training/reports` | FOLD | `/training` | 培训计划/场次 Tab |

### M18 公告与通知

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 136 | `/notices` | KEEP | `/notices` | 公告管理工作区 |
| 137 | `/notices/approval` | FOLD | `/notices` | 公告详情 Tab/发布动作 |
| 138 | `/notices/audience` | FOLD | `/notices` | 公告详情 Tab/发布动作 |
| 139 | `/notices/reads` | FOLD | `/notices` | 公告详情 Tab/发布动作 |
| 140 | `/notices/templates` | FOLD | `/notices` | 公告详情 Tab/发布动作 |
| 154 | `/notifications` | KEEP | `/notifications` | 我的消息 |

### M19 用户角色与细粒度权限

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 142 | `/system/users` | KEEP | `/system/users` | 用户管理 |
| 143 | `/system/roles` | KEEP | `/system/roles` | 角色管理 |
| 144 | `/system/menus` | FOLD | `/system/pages` | 页面目录 |
| 145 | `/system/permissions` | FOLD | `/system/pages` | 页面目录 |
| 146 | `/system/data-scopes` | FOLD | `/system/roles` | 角色授权 Tab |
| 147 | `/system/field-policies` | FOLD | `/system/roles` | 角色授权 Tab |

### M20 审批流程中心

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 148 | `/workflow` | KEEP | `/workflow` | 审批工作台 |
| 149 | `/workflow/cases/:id` | KEEP | `/workflow/cases/:id` | 审批详情深链 |

### M21 公司基础设置与审计运维

| # | 原路由 | 处置 | 目标页面 | 迁入位置 |
|---:|---|---|---|---|
| 152 | `/system/calendars` | FOLD | `/system/settings` | 系统设置·工作日历 Tab |
| 153 | `/system/settings` | KEEP | `/system/settings` | 系统设置（唯一工作区） |
| 155 | `/system/audit` | KEEP | `/system/audit` | 审计与运维 |

## 执行约束

1. `FOLD` 行必须先完成目标页面能力，再删除旧路由/组件；不得先删后补。
2. 每行删除必须附 `rg` 残留扫描、旧测试删除、OpenAPI operation 删除与 capability 孤儿检查。
3. `KEEP` 页面仍需通过模块计划的读写闭环、中文、权限与 E2E 验收，不得因保留而免检。
4. 目标页面 `/system/pages` 为新增，`/system/settings` 为保留并吸收 `/system/calendars`。
5. 任何无法映射到目标页面的原入口，禁止删除。
6. 旧 URL 不保留重定向；删除后直接 404，避免隐藏入口继续暴露旧壳。
