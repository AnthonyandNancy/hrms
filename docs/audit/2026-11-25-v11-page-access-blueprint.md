# V1.1 页面、功能与权限分配蓝皮书

**状态：** 待用户确认  
**日期：** 2026-11-25  
**适用：** 内网生产部署，无外部租户，允许破坏性重构  
**事实来源：** `HRMS-V1.1实施书.md`；页面清单由 `scripts/docs/generate_v11_inventory.py` 机器生成，见 `docs/audit/2026-11-25-v11-page-inventory.md`

---

## 0. 用户已确认的四条硬约束

1. **管理员拥有至高权限，可以读工资。** `SUPER_ADMIN` / `SYSTEM_ADMIN` 的能力覆盖全部业务域，包括 P3 薪资字段；不再做“wildcard 不含 P3”的收敛。
2. **必须明确实现的功能和页面，以及权限页面/角色权限如何分配。** 本文逐条列出 17 菜单、140 页面、14 平台模块，以及页面权限、动作权限、数据范围、字段档位、角色模板、用户分配与权限预览的实现方式。
3. **环境变量/密钥不构成约束。** 系统仅在内网部署，无外网暴露；仍保留审计、最小惊讶和可追溯，但不以“密钥管理/外部渗透/公网合规”作为本轮交付门槛。
4. **只要能实现功能，允许任何破坏性重构。** 可以重命名 API、重做 IAM 表、替换路由、重排菜单；不要求兼容旧前端/旧接口；但必须保留数据迁移、对账与可回滚路径。

> 这四条覆盖此前设计中的 C6 与 C7 建议：C6 改为管理员全权（含 P3），C7 改为内网 MFA 可选且不阻断交付。本文以用户决定为准。

---

## 1. 总体功能范围

### 1.1 三层页面

| 层 | 范围 | 数量 | 验收 |
|---|---|---:|---|
| 截图业务菜单 | 03A 的 17 个菜单及其全部子页面 | 140 | 每页真实 API、权限、五态、E2E |
| 平台模块 | 03B.1–03B.14 | 14 | 每模块独立路由/页面组、API、权限、E2E |
| 工作台与安全页 | 登录、工作台、403、404、MFA、会话 | 6 | 已有/补齐；纳入注册表 |

**总页面注册表：140 + 14 个模块入口 + 6 个基础页 = 160 条。**

### 1.2 不做的内容

- 不做公网 SaaS、多租户计费、原生 App、人脸识别算法、税务申报引擎、银行清算系统。
- 不做通用 BPMN 设计器；保留有限状态机 + 模板/节点规则。
- 不做任意 SQL 数据范围；自定义范围只允许白名单模板。
- 不为了“功能看起来全”而伪造接口或 E2E；所有页面必须接真实后端。

---

## 2. 17 个截图菜单与 140 个页面

> 完整路由表见 `docs/audit/2026-11-25-v11-page-inventory.md`。以下按菜单给出功能边界、页面权限、动作权限和角色分配。

### 2.1 概览 / 仪表盘（6 页，Wave 5）

| 页面 | 路由 | 页面权限 | 主要动作 |
|---|---|---|---|
| 首页看板 | `/dashboard` | `reporting.dashboard.read` | 查看指标、切换公司/部门/周期、保存偏好、卡片显隐/排序、下钻 |
| 部门人数分析 | `/dashboard/departments` | 同上 | 按部门/子部门下钻、导出 |
| 员工构成 | `/dashboard/workforce` | 同上 | 按性别/年龄/用工类型/司龄筛选；受字段权限约束 |
| 近期人事动态 | `/dashboard/changes` | 同上 | 查看入转调离事件、跳转案例 |
| 工作提醒 | `/dashboard/tasks` | 同上 | 查看待办/合同到期/试用期到期 |
| 招聘进展 | `/dashboard/recruitment` | 同上 | 查看需求/候选人漏斗、跳转招聘 |

**动作权限：** `reporting.dashboard.read`（查看）、`reporting.export`（导出）。  
**数据范围：** SELF / MANAGED / ORG_UNIT / ORG_SUBTREE / LEGAL_ENTITY；管理员为 LEGAL_ENTITY。  
**角色：** SUPER_ADMIN、SYSTEM_ADMIN、HR_ADMIN、HR_SPECIALIST、LINE_MANAGER、PAYROLL_SPECIALIST、RECRUITER、EMPLOYEE（仅 SELF 视图）。

### 2.2 概览 / 报表中心（9 页，Wave 5）

| 页面 | 路由 | 页面权限 | 主要动作 |
|---|---|---|---|
| 报表目录 | `/reports` | `reporting.export` | 查看可用报表、收藏 |
| 人力资源报表 | `/reports/headcount` | 同上 | 筛选、图表/表格、导出 |
| 考勤假期报表 | `/reports/attendance` | 同上 | 同上 |
| 薪资成本报表 | `/reports/payroll` | 同上 | 仅薪资授权；导出需额外导出权限 |
| 招聘转化报表 | `/reports/hiring` | 同上 | 同上 |
| 绩效培训报表 | `/reports/talent` | 同上 | 同上 |
| 自定义报表 | `/reports/builder` | 同上 | 选维度/指标/列、保存模板 |
| 报表订阅 | `/reports/subscriptions` | 同上 | 创建/暂停/删除订阅 |
| 导出任务 | `/reports/exports` | 同上 | 查看进度、下载（短效 token）、重试 |

**动作权限：** `reporting.export`、`reporting.report.read`、`reporting.report.create`、`reporting.subscription.manage`。  
**角色：** 管理员全量；HR_ADMIN/HR_SPECIALIST 业务报表；PAYROLL_SPECIALIST 薪资报表；LINE_MANAGER 本部门非敏感报表；EMPLOYEE 仅本人允许范围。

### 2.3 组织与人事 / 员工档案（11 页，Wave 2）

| 页面 | 路由 | 页面权限 | 主要动作 |
|---|---|---|---|
| 员工列表 | `/hr/employees` | `people.employee.read` | 筛选/排序/分页/列设置/批量选择/导出 |
| 新增员工 | `/hr/employees/create` | `people.employee.manage` | 建档（Person+Employment+Assignment+Terms） |
| 档案详情 | `/hr/employees/:id` | `people.employee.read` | 查看全量（按字段档位）、编辑、归档 |
| 教育经历 | `/hr/employees/:id/education` | 同上 | 新增/编辑/删除（版本化） |
| 工作履历 | `/hr/employees/:id/work-history` | 同上 | 同上 |
| 家庭/紧急联系人 | `/hr/employees/:id/contacts` | 同上 | 同上；P2 字段按权限 |
| 证件与资格 | `/hr/employees/:id/credentials` | 同上 | 上传/查看/下载（短效 token） |
| 任职/异动历史 | `/hr/employees/:id/history` | 同上 | 查看时间线、跳转案例 |
| 员工附件 | `/hr/employees/:id/files` | 同上 | 上传/下载/删除（审计） |
| 员工变更申请 | `/hr/employees/:id/changes` | 同上 | 发起/查看/撤回 |
| 导入校验结果 | `/hr/employees/imports` | `people.employee.manage` | 下载模板、上传、预检、确认导入、错误行 |

**动作权限：** `people.employee.read`、`people.employee.manage`、`people.employee.export`、`people.assignment.manage`、`people.contract.read/manage`。  
**字段档位：** P0 姓名/工号/组织；P1 联系方式；P2 证件/地址/银行卡；P3 薪资。  
**角色：** SUPER_ADMIN/SYSTEM_ADMIN 全量；HR_ADMIN 法人范围；HR_SPECIALIST 部门范围；LINE_MANAGER 本部门只读；EMPLOYEE 仅本人。

### 2.4 组织与人事 / 部门管理（6 页，Wave 2）

| 页面 | 路由 | 页面权限 | 主要动作 |
|---|---|---|---|
| 组织架构图 | `/hr/departments` | `org.unit.read` | 查看树、展开/折叠、按 as-of |
| 部门列表 | `/hr/departments/list` | 同上 | 筛选/排序/导出 |
| 新建/编辑部门 | `/hr/departments/editor` | `org.unit.manage` | 创建/编辑/停用/负责人/成本中心 |
| 部门成员 | `/hr/departments/members` | 同上 | 查看/调整成员 |
| 部门迁移 | `/hr/departments/move` | 同上 | 影响预览（员工/主管/成本中心）、执行迁移 |
| 部门编制 | `/hr/departments/staffing` | 同上 | 编制设置、在编对比 |

**动作权限：** `org.unit.read`、`org.unit.manage`、`org.tree.read`、`org.unit.export`。  
**角色：** 管理员全量；HR_ADMIN 法人；HR_SPECIALIST 部门；LINE_MANAGER 只读本部门树。

### 2.5 组织与人事 / 岗位管理（6 页，Wave 2）

| 页面 | 路由 | 页面权限 | 主要动作 |
|---|---|---|---|
| 岗位目录 | `/hr/positions` | `org.position.read` | 筛选/查看编制/状态 |
| 岗位编辑 | `/hr/positions/editor` | `org.position.manage` | 新建/编辑/停用 |
| 岗位编制 | `/hr/positions/headcount` | 同上 | 调整编制、查看占用 |
| 任职资格 | `/hr/positions/requirements` | 同上 | 配置资格/技能 |
| 职级/职等 | `/hr/positions/grades` | 同上 | 配置职级 |
| 岗位在岗人员 | `/hr/positions/staff` | `org.position.read` | 查看在岗、跳转员工 |

**动作权限：** `org.position.read`、`org.position.manage`、`org.position.export`。  
**角色：** 管理员全量；HR_ADMIN/HR_SPECIALIST 管理；LINE_MANAGER 只读。

### 2.6 组织与人事 / 员工异动（9 页，Wave 2）

| 页面 | 路由 | 页面权限 | 主要动作 |
|---|---|---|---|
| 异动列表 | `/hr/movements` | `lifecycle.case.read` | 筛选/查看状态/导出 |
| 入职办理 | `/hr/movements/onboarding` | `lifecycle.onboarding.manage` | 发起/清单/完成 |
| 转正办理 | `/hr/movements/probation` | `lifecycle.probation.manage` | 发起/审批/确认 |
| 调岗/调部门 | `/hr/movements/transfer` | `lifecycle.transfer.manage` | 发起/审批/未来生效 |
| 调薪办理 | `/hr/movements/salary-change` | `lifecycle.terms.manage` | 发起/审批（P3） |
| 借调/外派 | `/hr/movements/secondment` | `lifecycle.transfer.manage` | 发起/审批 |
| 离职办理 | `/hr/movements/offboarding` | `lifecycle.offboard.manage` | 发起/交接/账号回收 |
| 异动详情 | `/hr/movements/:id` | `lifecycle.case.read` | 时间线、审批、生效状态 |
| 异动日历 | `/hr/movements/calendar` | 同上 | 按生效日查看 |

**动作权限：** `lifecycle.case.read`、`lifecycle.onboarding.manage`、`lifecycle.probation.manage`、`lifecycle.transfer.manage`、`lifecycle.terms.manage`、`lifecycle.offboard.manage`、`lifecycle.checklist.manage`、`workflow.case.decide`。  
**角色：** 管理员全量；HR_ADMIN 全流程；HR_SPECIALIST 部门；LINE_MANAGER 审批本部门；EMPLOYEE 本人步骤。

### 2.7 组织与人事 / 劳动合同（7 页，Wave 2）

| 页面 | 路由 | 页面权限 | 主要动作 |
|---|---|---|---|
| 合同台账 | `/hr/contracts` | `people.contract.read` | 筛选/查看状态/导出 |
| 合同录入/续签 | `/hr/contracts/editor` | `people.contract.manage` | 新建/续签/变更 |
| 合同详情 | `/hr/contracts/:id` | `people.contract.read` | 查看版本/附件/审计 |
| 合同续签 | `/hr/contracts/renewals` | `people.contract.manage` | 发起续签/审批 |
| 合同变更/终止 | `/hr/contracts/changes` | 同上 | 变更/终止（版本化） |
| 到期预警 | `/hr/contracts/expiry` | `people.contract.read` | 60/30/7 天预警、批量提醒 |
| 合同模板 | `/hr/contracts/templates` | `people.contract.manage` | 模板管理 |

**动作权限：** `people.contract.read`、`people.contract.manage`、`people.contract.export`、`people.document.download`。  
**字段档位：** P2 合同扫描件；P3 薪资条款。  
**角色：** 管理员全量；HR_ADMIN 法人；HR_SPECIALIST 部门；LINE_MANAGER 不可下载附件；EMPLOYEE 本人合同摘要。

> 实现备注：`people.document.download` 已在路由中使用，但当前 `CapabilityCatalog` 尚未登记；Wave 2 必须先补目录项与负测，否则 capability:audit 只检查路由“有守卫”，无法证明能力码可授予。

### 2.8 考勤与假期 / 考勤打卡（10 页，Wave 3）

| 页面 | 路由 | 页面权限 | 主要动作 |
|---|---|---|---|
| 我的打卡 | `/attendance` | `time.attendance.read` | 打卡、查看今日/本月 |
| 打卡明细 | `/attendance/punches` | 同上 | 原始打卡查询、设备来源 |
| 考勤日历 | `/attendance/calendar` | 同上 | 日历视图、异常高亮 |
| 考勤规则 | `/attendance/rules` | `time.attendance.manage` | 规则版本、生效日 |
| 班次管理 | `/attendance/shifts` | `time.shift.read` / `manage` | 新建/编辑/停用 |
| 排班管理 | `/attendance/schedules` | `time.attendance.manage` | 排班、批量、冲突检查 |
| 补正管理 | `/attendance/corrections` | `time.attendance.correct.request` | 发起/审批补正 |
| 异常处理 | `/attendance/exceptions` | `time.attendance.manage` | 迟到/缺卡/早退处理 |
| 月度结账 | `/attendance/closing` | `time.attendance.manage` | 月结、锁定、解锁审批 |
| 设备对接 | `/attendance/devices` | `time.punch.import` | 设备/导入流水、幂等重放 |

**动作权限：** `time.attendance.read`、`time.attendance.manage`、`time.attendance.correct.request`、`time.punch.import`、`time.shift.read`、`time.calendar.read`。  
**角色：** 管理员全量；HR_ADMIN/ATTENDANCE_SPECIALIST 法人/部门；LINE_MANAGER 本部门；EMPLOYEE 本人。

### 2.9 考勤与假期 / 请假管理（8 页，Wave 3）

| 页面 | 路由 | 页面权限 | 主要动作 |
|---|---|---|---|
| 我的假期 | `/leave` | `time.leave.read` | 余额、申请入口 |
| 请假台账 | `/leave/requests` | 同上 | 列表/筛选/详情 |
| 假期类型 | `/leave/types` | `time.leave.admin` | 新建/编辑/停用 |
| 假期政策 | `/leave/policies` | 同上 | 发放/结转/有效期 |
| 假期余额 | `/leave/balances` | `time.leave.read` | 余额与账本下钻 |
| 假期日历 | `/leave/calendar` | 同上 | 团队/本人日历 |
| 销假/撤销 | `/leave/cancellations` | `time.leave.request.cancel` | 撤销/销假 |
| 假期报表 | `/leave/reports` | `time.leave.ledger.read` | 使用率/余额/到期 |

**动作权限：** `time.leave.read`、`time.leave.request`、`time.leave.request.cancel`、`time.leave.admin`、`time.leave.ledger.read`。  
**角色：** 管理员全量；HR_ADMIN/ATTENDANCE_SPECIALIST 管理；LINE_MANAGER 审批本部门；EMPLOYEE 本人。

### 2.10 考勤与假期 / 加班管理（6 页，Wave 3）

| 页面 | 路由 | 页面权限 | 主要动作 |
|---|---|---|---|
| 加班申请 | `/overtime` | `time.overtime.read` | 新建/查看本人 |
| 加班台账 | `/overtime/requests` | 同上 | 列表/筛选/审批状态 |
| 加班政策 | `/overtime/policies` | `time.overtime.manage` | 费率/补偿规则 |
| 加班确认 | `/overtime/confirm` | 同上 | 实际工时确认 |
| 调休管理 | `/overtime/timeoff` | 同上 | 转调休、余额 |
| 加班结算 | `/overtime/settlement` | 同上 | 工资/调休单一结算、冲正 |

**动作权限：** `time.overtime.read`、`time.overtime.request`、`time.overtime.manage`、`time.overtime.settle`。  
**角色：** 管理员全量；HR_ADMIN/ATTENDANCE_SPECIALIST 管理；LINE_MANAGER 审批；EMPLOYEE 本人。

### 2.11 考勤与假期 / 出差管理（7 页，Wave 3）

| 页面 | 路由 | 页面权限 | 主要动作 |
|---|---|---|---|
| 出差申请 | `/travel` | `time.travel.read` | 新建/查看本人 |
| 出差台账 | `/travel/requests` | 同上 | 列表/筛选 |
| 出差政策 | `/travel/policies` | `time.travel.manage` | 标准/城市/预算 |
| 出差审批 | `/travel/approval` | `workflow.case.decide` | 审批/退回 |
| 变更/取消 | `/travel/changes` | `time.travel.request` | 变更/取消 |
| 出差统计 | `/travel/reports` | `time.travel.read` | 天数/费用/部门 |
| 费用登记 | `/travel/expenses` | `time.travel.expense` | 登记/附件/预算校验 |

**动作权限：** `time.travel.read`、`time.travel.request`、`time.travel.manage`、`time.travel.expense`。  
**角色：** 管理员全量；HR_ADMIN/ATTENDANCE_SPECIALIST 管理；LINE_MANAGER 审批；EMPLOYEE 本人。

### 2.12 薪酬与绩效 / 薪资管理（12 页，Wave 3）

| 页面 | 路由 | 页面权限 | 主要动作 |
|---|---|---|---|
| 薪资概览 | `/payroll` | `payroll.run.read` | 周期/批次/异常 |
| 薪资项目 | `/payroll/components` | `payroll.run.prepare` | 新建/编辑/停用 |
| 薪资方案 | `/payroll/plans` | 同上 | 方案/版本/适用 |
| 员工薪资档案 | `/payroll/employee-plans` | `payroll.parameters.manage` | 员工方案/基数（P3） |
| 社保公积金参数 | `/payroll/statutory` | `payroll.parameters.read/manage` | 参数版本/证据 |
| 计薪输入 | `/payroll/inputs` | `payroll.run.prepare` | 考勤/假勤/补扣快照 |
| 工资批次 | `/payroll/batches` | `payroll.run.read` | 创建/锁定/计算/复核/发布 |
| 工资复核 | `/payroll/reviews` | `payroll.run.review` | 差异、异常、审批 |
| 工资发放登记 | `/payroll/disbursement` | `payroll.run.publish` | 发放确认、回执 |
| 工资条 | `/payroll/payslips` | `payroll.payslip.read` | 查看/导出/重发 |
| 补发/扣款 | `/payroll/adjustments` | `payroll.run.correct` | 调整、更正批次 |
| 薪资报表 | `/payroll/reports` | `payroll.export` | 成本/个税/社保报表 |

**动作权限：** `payroll.run.read/prepare/review/approve/publish/close/correct/reopen`、`payroll.payslip.read`、`payroll.export`、`payroll.parameters.read/manage`。  
**字段档位：** P3 工资/个税/银行卡；管理员可读；导出单独授权并审计。  
**角色：** **SUPER_ADMIN/SYSTEM_ADMIN 全量（含 P3）**；PAYROLL_SPECIALIST 法人/批次准备与导出；PAYROLL_APPROVER 复核/批准/发布；HR_ADMIN 默认无工资；EMPLOYEE 仅本人工资条。

### 2.13 薪酬与绩效 / 绩效考核（10 页，Wave 4）

| 页面 | 路由 | 页面权限 | 主要动作 |
|---|---|---|---|
| 绩效周期 | `/performance` | `performance.cycle.read` | 列表/状态/进度 |
| 考核模板 | `/performance/templates` | `performance.cycle.manage` | 模板/权重（必须 100%） |
| 考核指标 | `/performance/indicators` | 同上 | 指标库/权重 |
| 目标计划 | `/performance/goals` | 同上 | 目标设定/确认 |
| 评分/评语 | `/performance/reviews` | `performance.review.manage` | 自评/他评/提交 |
| 360 反馈 | `/performance/feedback` | `performance.review.submit` | 邀请/收集 |
| 绩效校准 | `/performance/calibration` | `performance.calibration.manage` | 校准会议/等级 |
| 结果发布 | `/performance/results` | `performance.cycle.manage` | 发布/修订 |
| 绩效申诉 | `/performance/appeals` | `performance.appeal.manage` | 发起/复核 |
| 绩效报表 | `/performance/reports` | `performance.report.read` | 分布/趋势/部门 |

**动作权限：** `performance.cycle.read/manage`、`performance.review.manage/submit`、`performance.calibration.manage`、`performance.appeal.manage`、`performance.report.read`。  
**角色：** 管理员全量；HR_ADMIN 法人；LINE_MANAGER 本部门评审；EMPLOYEE 本人自评/申诉。

### 2.14 招聘与培训 / 招聘需求（7 页，Wave 4）

| 页面 | 路由 | 页面权限 | 主要动作 |
|---|---|---|---|
| 招聘需求列表 | `/recruitment/requests` | `recruitment.requisition.read` | 筛选/状态/导出 |
| 新建招聘需求 | `/recruitment/requests/create` | `recruitment.requisition.manage` | 创建/提交 |
| 需求审批 | `/recruitment/requests/approval` | `workflow.case.decide` | 审批/退回 |
| 开放岗位 | `/recruitment/requests/openings` | `recruitment.requisition.manage` | 仅已批准需求可开放 |
| 招聘看板 | `/recruitment/requests/board` | `recruitment.requisition.read` | 渠道/进度/漏斗 |
| 招聘预算 | `/recruitment/requests/budget` | `recruitment.requisition.manage` | 预算/实际 |
| 招聘统计 | `/recruitment/requests/reports` | 同上 | 完成率/周期 |

**动作权限：** `recruitment.requisition.read/manage`、`workflow.case.decide`。  
**角色：** 管理员全量；HR_ADMIN 法人；RECRUITER 部门；LINE_MANAGER 本部门只读。

### 2.15 招聘与培训 / 候选人（10 页，Wave 4）

| 页面 | 路由 | 页面权限 | 主要动作 |
|---|---|---|---|
| 候选人列表 | `/recruitment/candidates` | `recruitment.candidate.read` | 筛选/状态/导出 |
| 候选人详情 | `/recruitment/candidates/:id` | 同上 | 资料/面试/Offer/时间线 |
| 简历导入/去重 | `/recruitment/candidates/imports` | `recruitment.candidate.manage` | 上传/解析/去重提示 |
| 招聘漏斗 | `/recruitment/candidates/pipeline` | 同上 | 阶段看板/拖拽 |
| 面试安排 | `/recruitment/candidates/interviews` | `recruitment.interview.manage` | 安排/面试官/反馈 |
| 面试评估 | `/recruitment/candidates/evaluations` | 同上 | 评分/评语 |
| Offer 管理 | `/recruitment/candidates/offers` | `recruitment.offer.manage` | 版本/审批/发送 |
| 候选人沟通 | `/recruitment/candidates/communications` | `recruitment.candidate.manage` | 模板/记录 |
| 人才库 | `/recruitment/candidates/talent-pool` | `recruitment.candidate.read` | 标签/搜索 |
| 录用转入职 | `/recruitment/candidates/convert` | `recruitment.offer.accept` | Offer→员工（幂等） |

**动作权限：** `recruitment.candidate.read/manage`、`recruitment.interview.manage`、`recruitment.offer.manage/accept`。  
**字段档位：** P2 手机号/邮箱；P3 Offer 薪资。  
**角色：** 管理员全量；RECRUITER 部门；HR_ADMIN 法人；LINE_MANAGER 面试反馈（受控）。

### 2.16 招聘与培训 / 培训管理（9 页，Wave 4）

| 页面 | 路由 | 页面权限 | 主要动作 |
|---|---|---|---|
| 培训计划 | `/training` | `training.program.read` | 计划/预算/审批 |
| 课程库 | `/training/courses` | `training.program.manage` | 新建/编辑/停用 |
| 培训场次 | `/training/sessions` | `training.session.manage` | 时间/容量/讲师 |
| 培训报名 | `/training/enrollments` | `training.enrollment.manage` | 报名/审核/取消 |
| 培训签到 | `/training/checkins` | `training.completion.manage` | 签到/补签 |
| 培训考试 | `/training/exams` | 同上 | 题库/考试/成绩 |
| 培训反馈 | `/training/feedback` | `training.enrollment.manage` | 问卷/评分 |
| 培训记录 | `/training/records` | `training.program.read` | 个人/部门记录 |
| 培训报表 | `/training/reports` | `training.report.read` | 完成率/费用 |

**动作权限：** `training.program.read/manage`、`training.session.manage`、`training.enrollment.manage`、`training.completion.manage`、`training.report.read`。  
**角色：** 管理员全量；HR_ADMIN 法人；LINE_MANAGER 本部门报表；EMPLOYEE 本人报名/记录。

### 2.17 行政与系统 / 公告通知（7 页，Wave 5）

| 页面 | 路由 | 页面权限 | 主要动作 |
|---|---|---|---|
| 公告列表 | `/notices` | `engagement.notice.read` | 筛选/查看/确认 |
| 公告编辑 | `/notices/editor` | `engagement.notice.manage` | 起草/保存/预览 |
| 公告审批 | `/notices/approval` | 同上 | 审批/驳回 |
| 发布范围 | `/notices/audience` | 同上 | 全员/组织/角色/指定 |
| 阅读记录 | `/notices/reads` | 同上 | 已读率/明细/导出 |
| 公告模板 | `/notices/templates` | 同上 | 模板/变量 |
| 公告归档 | `/notices/archive` | 同上 | 归档/检索 |

**动作权限：** `engagement.notice.read/manage`、`engagement.notice.publish`、`engagement.notice.export`。  
**角色：** 管理员全量；HR_ADMIN 发布；其他员工只读；LINE_MANAGER 只读。

---

## 3. 14 个平台模块

> 03B 模块不包含在 140 个 03A 页面内，必须有独立入口、页面组、API 和权限。

| 模块 | 路由 | 页面/功能 | 页面权限 | 主要动作 |
|---|---|---|---|---|
| 登录与账号安全 | `/login`、`/account/security` | 密码、MFA、会话、冻结、首次激活 | 公共 / `iam.session.manage` | 登录、登出、改密、MFA、会话撤销 |
| 用户管理 | `/system/users` | 列表、创建、停用、关联员工、分配角色、强制退出、权限预览 | `iam.user.read` / `iam.user.manage` | CRUD、分配角色、撤销会话 |
| 角色管理 | `/system/roles` | 列表、复制、授权树、数据范围、字段策略、预览、成员、审计 | `iam.role.manage` | 创建/复制/编辑/授予/回收 |
| 菜单与页面管理 | `/system/menus` | 菜单树、创建/排序/启停、路由注册名、图标 | `iam.menu.manage` | CRUD、排序、启停 |
| 操作权限配置 | `/system/permissions` | 权限资源列表、按模块分组、覆盖检测 | `iam.permission.read` | 查看、模拟、覆盖检测 |
| 数据范围权限 | `/system/data-scopes` | 本人/本部门/本部门及子部门/指定部门/本公司/指定公司/全集团 | `iam.scope.manage` | 配置、预览、成员 |
| 字段级权限 | `/system/field-policies` | 敏感字段清单、明文/脱敏/不可见/可编辑、导出列约束、下载控制 | `iam.field.manage` | 配置、预览、审计 |
| 审批流程中心 | `/workflow` | 模板、发起、待办/已办、抄送、转交、退回、撤回、催办、轨迹、代理 | `workflow.case.read` / `workflow.template.manage` | 发车、决定、转交、代理 |
| 员工自助门户 | `/self-service` | 我的档案/更正/考勤/假期/工资条/绩效/培训/公告/待办 | SELF 能力 | 自助申请/查看 |
| 社保公积金与福利 | `/benefits` | 基数方案、缴费项、月度增减员、福利项目、发放台账 | `benefit.read` / `benefit.manage` | 配置、登记、对账 |
| 节假日与工作日历 | `/system/calendars` | 法定节假日、自定义休息日、调休工作日、公司日历、地区时区 | `time.calendar.read` / `time.calendar.manage` | 配置、发布、版本 |
| 字典、参数与文件 | `/system/settings` | 字典、全局参数、文件密级、访问审计 | `system.settings.manage` | CRUD、上传/下载 |
| 消息与通知中心 | `/notifications` | 站内信、待办提醒、阅读状态、失败重试、模板、投递日志 | `notification.read` / `notification.manage` | 查看/标记已读/重试/模板 |
| 日志审计与运维 | `/system/audit` | 登录/授权/业务变更日志、筛选、异常告警、备份恢复、任务监控 | `iam.audit.read` | 查询、导出、告警 |

**平台模块验收：** 每个模块必须有其 `页面组 + API + 权限负测 + 空/错/无权限态 + 真实 E2E`；03B.15/03B.16 是菜单树与可视化授权要求，不单独计模块。

---

## 3A. 能力码补齐清单

当前 `CapabilityCatalog` 有 76 个能力码；蓝皮书需要 96 个左右的动作码。以下为必须新增/登记的条目。Wave 1 必须一次性完成，并同步 `CapabilityCatalog`、路由中间件、OpenAPI `x-capability`、负测矩阵与 `capability:audit`。

| 能力码 | 名称 | 说明 |
|---|---|---|
| `org.position.read` | 岗位读取 | 岗位目录/在岗 |
| `org.position.manage` | 岗位管理 | 新建/编辑/停用/编制 |
| `org.position.export` | 岗位导出 | 导出岗位 |
| `time.overtime.read` | 加班读取 | 台账/我的加班 |
| `time.overtime.request` | 加班申请 | 发起/撤回 |
| `time.overtime.manage` | 加班管理 | 政策/确认/调休 |
| `time.overtime.settle` | 加班结算 | 工资/调休单一结算 |
| `time.travel.read` | 出差读取 | 台账/我的出差 |
| `time.travel.request` | 出差申请 | 发起/变更/取消 |
| `time.travel.manage` | 出差管理 | 政策/审批/统计 |
| `time.travel.expense` | 出差费用 | 费用登记/附件 |
| `performance.calibration.manage` | 绩效校准 | 校准会议/等级 |
| `performance.report.read` | 绩效报表 | 分布/趋势 |
| `iam.menu.manage` | 菜单管理 | 菜单树/排序/启停 |
| `iam.permission.read` | 权限查看 | 权限资源/覆盖检测 |
| `iam.scope.manage` | 数据范围管理 | 范围配置/预览 |
| `iam.field.manage` | 字段策略管理 | P0–P3 策略 |
| `workflow.template.manage` | 审批模板管理 | 模板/版本/节点 |
| `benefit.read` | 福利读取 | 基数/缴费/台账 |
| `benefit.manage` | 福利管理 | 方案/登记/对账 |
| `time.calendar.manage` | 日历管理 | 节假日/调休/版本 |
| `system.settings.manage` | 系统设置 | 字典/参数/文件密级 |
| `notification.read` | 通知读取 | 站内信/阅读状态 |
| `notification.manage` | 通知管理 | 模板/重试/投递日志 |
| `engagement.notice.publish` | 公告发布 | 定时/撤回/置顶 |
| `engagement.notice.export` | 公告导出 | 阅读率/明细 |
| `people.employee.export` | 员工导出 | 受字段白名单约束 |
| `people.contract.export` | 合同导出 | 受 P2/P3 约束 |
| `people.document.download` | 文件下载 | 已在路由使用，但目录缺失；必须补登记 |
| `org.unit.export` | 组织导出 | 组织架构导出 |
| `recruitment.requisition.export` | 招聘需求导出 | 需求/预算导出 |
| `reporting.report.read` | 报表读取 | 报表目录/运行 |
| `reporting.report.create` | 报表创建 | 自定义报表/保存 |
| `reporting.subscription.manage` | 报表订阅 | 创建/暂停/删除 |

> 能力码必须代码拥有：新增条目要同步 `CapabilityDefinition`（资源、动作、锚点、字段上限、风险、SoD 组、描述），不允许管理员在 UI 里创建能力码。

## 4. 权限模型与分配机制

### 4.1 四个层次

| 层 | 存什么 | 谁可改 | 判定方式 |
|---|---|---|---|
| 能力码 Capability | 代码拥有的动作语义（`payroll.run.publish`） | 只能改代码 + ADR | 路由中间件 + Authorizer |
| 角色 Role | 能力包（模板/例外） | 管理员在 `/system/roles` 配置 | 角色 → 能力 |
| 授权绑定 AuthorityBinding | 用户 × 能力 × 锚点 × 字段档位 × 有效期 | 管理员在 `/system/users` 或 `/system/roles` | 逐请求编译为 SQL 谓词 |
| 数据范围 Scope | 锚点集合（本人/部门/子部门/法人/案例/批次） | 角色配置或用户覆盖 | 与绑定同一指针 |
| 字段策略 FieldPolicy | P0–P3 字段可见/可编辑/可导出 | 管理员在 `/system/field-policies` | 序列化时裁剪 |

### 4.2 页面权限与动作权限分离

- **页面权限**决定菜单/路由是否可见；只证明“能进入”，不证明“能操作”。
- **动作权限**决定按钮/接口是否可用；每个写操作/导出/审批单独校验。
- **父菜单授权不等于子页面授权**：菜单目录只是导航容器；每个页面必须单独授予。
- **只有页面权限、没有动作权限**：页面可见，按钮隐藏/禁用；直接调 API 返回 403。
- **只有动作权限、没有页面权限**：菜单不显示；直接访问 URL 由路由守卫 + 后端 403 拦截。

### 4.3 角色模板（建议 14 个）

| 角色 | 用途 | 默认锚点 | 关键能力 |
|---|---|---|---|
| `SUPER_ADMIN` | 最高权限、应急、全量配置 | LEGAL_ENTITY | `*`（含 P3 工资） |
| `SYSTEM_ADMIN` | 系统管理、账号/角色/菜单/参数/审计 | LEGAL_ENTITY | `*`（含 P3 工资） |
| `HR_ADMIN` | 组织人事、合同、假勤、招聘、培训、公告 | LEGAL_ENTITY | 人事全模块（不含工资） |
| `HR_SPECIALIST` | 部门级人事日常 | ORG_UNIT | 员工/合同/异动/假勤 |
| `RECRUITER` | 招聘全流程 | ORG_UNIT | 需求/候选人/面试/Offer |
| `PAYROLL_SPECIALIST` | 薪资准备、核算、导出 | PAYROLL_RUN / LEGAL_ENTITY | 薪资准备/核算/P3 读取 |
| `PAYROLL_APPROVER` | 薪资复核/批准/发布 | PAYROLL_RUN | 复核/批准/发布/关账 |
| `ATTENDANCE_SPECIALIST` | 考勤/假勤/加班/出差 | ORG_UNIT | 考勤管理/假期管理/结算 |
| `LINE_MANAGER` | 本部门审批、团队视图、绩效 | MANAGED | 审批/团队/绩效 |
| `EMPLOYEE` | 员工自助 | SELF | 本人档案/假勤/工资条/公告 |
| `AUDITOR` | 只读审计与合规 | LEGAL_ENTITY | 审计/授权/报表只读 |
| `TRAINING_ADMIN` | 培训管理 | LEGAL_ENTITY | 课程/场次/报名/考试 |
| `BENEFITS_ADMIN` | 社保福利 | LEGAL_ENTITY | 基数/缴费/福利 |
| `REPORT_VIEWER` | 报表查看/导出 | ORG_UNIT | 报表/导出（按字段） |

**管理员至高权限的实现：** `SUPER_ADMIN` 与 `SYSTEM_ADMIN` 的 wildcard 展开为**全部能力码**，包括 `payroll.*` 与 P3 字段。这不是“绕过审计”：每次 P3 读取/导出仍写审计；但不再以“系统管理员默认不可读工资”限制管理员。

### 4.4 数据范围

| UI 名称 | 可执行锚点 | 语义 |
|---|---|---|
| 仅本人 | SELF | 当前账号绑定的有效任职 |
| 本部门 | ORG_UNIT | 当前任职组织 |
| 本部门及子部门 | ORG_SUBTREE | 组织闭包 |
| 指定部门 | ORG_UNIT（多条） | 管理员显式选择 |
| 本公司/法人 | LEGAL_ENTITY | 雇主边界 |
| 指定公司 | LEGAL_ENTITY（多条） | 多法人 |
| 全集团 | LEGAL_ENTITY（全部） | 当前实现用全部法人 |
| 指定案例/批次 | CASE / PAYROLL_RUN | 流程/工资对象 |
| 下属 | MANAGED | 汇报线 |
| 自定义 | 白名单模板 | 禁止任意 SQL |

### 4.5 字段档位

| 档位 | 默认内容 | 管理员（SUPER/SYSTEM） | HR | 经理 | 员工 |
|---|---|---|---|---|---|
| P0 | 姓名/工号/组织/岗位 | 可见 | 可见 | 可见 | 本人 |
| P1 | 手机/邮箱/地址 | 可见 | 按范围 | 按范围 | 本人 |
| P2 | 证件/银行卡/合同扫描件 | 可见 | 按授权 | 不可见 | 本人摘要 |
| P3 | 工资/个税/Offer 薪资 | **可见** | 默认不可见 | 不可见 | 本人工资条 |

**导出：** P3 导出单独能力 `payroll.export`；管理员默认拥有；导出写审计、带水印、短效 token。

### 4.6 权限页面如何分配（可视化流程）

1. **建角色**：`/system/roles` → 新建/复制；填写名称、编码、用途、有效期、owner。
2. **勾页面**：左侧菜单树勾选页面；父节点只控制分组展开，不自动授予子页面。
3. **勾动作**：选中页面后列出查看/新建/编辑/删除/审批/导入/导出/发放等原子动作；默认不全选。
4. **设数据范围**：为每组动作选择 SELF/ORG_UNIT/ORG_SUBTREE/LEGAL_ENTITY/CASE/PAYROLL_RUN；指定部门用树多选。
5. **设字段档位**：P0–P3 可见/可编辑/可导出；P3 导出单独开关。
6. **分配用户**：`/system/users` → 选择用户 → 分配角色 → 设生效/到期 → 填授权说明。
7. **预览**：`/system/users/{id}/permissions-preview` 显示有效角色、可见页面、动作、数据范围、字段结果；支持“模拟访问指定员工”。
8. **提交**：显示差异预览；高风险变更二次确认；写审计（操作人/对象/前后值/request_id）。
9. **即时生效**：保存后 `auth_version` 重放；下一次请求按新权限执行；旧标签页请求 401/403。

### 4.7 负测清单（每页至少 4 类）

| 场景 | 预期 |
|---|---|
| 未登录直接访问 | 401 / 跳登录 |
| 有页面无动作，直接调写接口 | 403 |
| 有动作无页面，直接访问 URL | 前端 403 / 后端 403 |
| 跨部门/跨法人改 ID | 404 或 403（不泄露存在性） |
| 无 P3 读工资 | 403 或字段缺失，不返回明文 |
| 无导出能力导出 | 403；不生成文件 |
| 导出链接被转发 | 404/410；token 失效 |
| 撤权后旧标签页再请求 | 401/403 立即生效 |

---

## 5. 管理员全权与审计的边界

用户已决定“管理员拥有至高权限，可以读工资”。实现上：

- `SUPER_ADMIN` / `SYSTEM_ADMIN` 的 wildcard 展开**全部 76+ 能力**，包括 P3。
- 管理员可以进入所有 140 个页面 + 14 个平台模块；不再出现“系统管理员看不到工资/报表”的 403。
- **仍保留审计：** P3 读取、导出、授权变更、工资发布/更正都写不可变审计；可按 `request_id` 追踪。
- **仍保留身份边界：** 管理员不是员工；访问 `/self-service`、`/leave`、`/attendance` 等 SELF 页面时，若无任职，显示“当前账号未绑定员工”的空态，并提供“切换到治理视图/员工档案”的跳转；不返回 404、不白屏。
- **仍保留 SoD 提醒但不阻断管理员：** 管理员可执行任何操作；若触发自我提权/自批工资等高风险组合，写 `security.sod_risk_accepted` 审计并提示，不阻止其完成应急操作。

---

## 6. 页面实现规范（每页交付定义）

每个页面必须同时具备：

1. **注册表**：`path/name/component/capability/menu/group/requiredApis/e2e` 一条。
2. **真实 API**：列表/详情/写操作/状态动作；OpenAPI schema；运行时校验。
3. **权限**：页面权限 + 动作权限 + 数据范围 + 字段档位；至少 4 类负测。
4. **UI 五态**：loading / empty / error / forbidden / partial；危险操作二次确认。
5. **组件测试**：用真实后端 payload 挂载；不得只用 mock。
6. **真实栈 E2E**：快乐路径 + 拒绝路径；在 Compose 栈运行。
7. **审计**：敏感读取/导出/状态变更写审计；可按 `request_id` 查询。
8. **可访问性**：键盘可达、标签、对比度；axe critical=0。

---

## 7. 交付波次与页面数量

| 波次 | 页面 | 平台模块 | 出口 |
|---|---:|---:|---|
| Wave 0 | 0（修内核） | 0 | 授权重放/身份边界/错误隔离/真实栈基线 |
| Wave 1 | 0（建契约与注册表） | 0 | OpenAPI 生成、Zod、160 条注册表、权限词汇 |
| Wave 2 | 39（组织人事） | 0 | 5 菜单全部子页 + 主链路 E2E |
| Wave 3 | 43（假勤薪酬） | 0 | 5 菜单全部子页 + 请假→考勤→工资 |
| Wave 4 | 36（绩效招聘培训） | 0 | 3 菜单全部子页 + 招聘→入职 |
| Wave 5 | 22（概览/报表/公告） | 14 | 03B 全部模块 + 系统管理可视化授权 |
| Wave 6 | 0（验收） | 0 | 140/140 + 14/14 + 真实栈全量 |

---

## 8. 最终验收口径

- **页面：** 140/140 个 03A 页面 + 14/14 个 03B 模块，全部有真实 API、权限、五态、E2E。
- **权限：** 管理员全量可读（含 P3）；每个页面至少 4 类负测；角色/页面/动作/范围/字段五层可解释。
- **主链路：** 入职→合同→调岗→考勤→请假→工资→工资条；招聘→Offer→入职；绩效→申诉；培训→报名。
- **真实栈：** Compose 起 MySQL/Redis/MinIO/PHP/nginx，安装器→登录→全量巡检 0 错误边界、0 非预期 401/403/404。
- **可追溯：** 每个页面有注册表条目、OpenAPI 操作、测试、E2E、审计证据。
- **无演示数据：** 生产安装路径只创建管理员与参考数据，不创建合成员工/工资/案例。

---

## 9. 已确认

用户已确认：

1. **管理员拥有至高权限，可以读工资。** wildcard 展开全部能力（含 P3）；保留 P3 审计与 SoD 提示，但不阻断管理员。
2. **页面与权限范围以本蓝皮书为准。** 160 条注册表（140 业务页 + 14 平台模块入口 + 6 基础页）；每页必须有页面权限、动作权限、数据范围、字段档位与角色映射。
3. **环境变量/密钥不构成本轮门槛。** 系统仅内网部署；仍保留审计与可追溯；不以公网渗透、KMS、密钥轮换作为阻塞项。
4. **允许破坏性重构。** 可以重命名 API、重做 IAM 表、替换路由、重排菜单；必须保留数据迁移、对账与回滚路径。

**执行入口：** `docs/superpowers/plans/2026-11-25-v11-production-completion-implementation-plan.md`，从 Wave 0 开始。
