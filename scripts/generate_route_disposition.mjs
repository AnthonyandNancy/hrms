#!/usr/bin/env node
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const registryPath = resolve(repoRoot, 'frontend/src/router/registry.ts')
const routeDispositionPath = resolve(repoRoot, 'docs/audit/2026-11-26-v2-route-disposition.md')
const capabilityEquivalencePath = resolve(repoRoot, 'docs/audit/2026-11-26-v2-capability-equivalence.md')

export const DISPOSITIONS = new Set(['keep', 'action', 'view', 'flow', 'deprecate'])
export const MAPPED_DISPOSITIONS = new Set(['keep', 'action', 'view', 'flow'])

/**
 * The Task 3 brief still calls the employee detail page `hr-employees-by-id`.
 * The registry already converged that code to `employee-detail` in an earlier
 * remediation commit. Keep the required mapping verbatim and resolve it
 * through this explicit alias instead of inventing a duplicate decision.
 */
export const LEGACY_PAGE_CODE_ALIASES = Object.freeze({
  'hr-employees-by-id': 'employee-detail',
})

/**
 * Required mappings copied verbatim from the Task 3 brief. They are asserted
 * against DECISIONS by main() so a later edit cannot silently drift.
 */
export const BRIEF_REQUIRED_MAPPINGS = Object.freeze([
  ['hr-employees', 'keep', 'employees'],
  ['hr-employees-create', 'action', 'employees'],
  ['hr-employees-by-id', 'keep', 'employee-detail'],
  ['hr-employees-by-id-education', 'view', 'employee-detail'],
  ['hr-employees-by-id-work-history', 'view', 'employee-detail'],
  ['hr-employees-by-id-contacts', 'view', 'employee-detail'],
  ['hr-employees-by-id-credentials', 'view', 'employee-detail'],
  ['hr-employees-by-id-history', 'view', 'employee-detail'],
  ['hr-employees-by-id-files', 'view', 'employee-detail'],
  ['hr-employees-by-id-changes', 'view', 'employee-detail'],
  ['hr-employees-imports', 'flow', 'employees-import'],
  ['hr-departments', 'keep', 'departments'],
  ['hr-departments-editor', 'action', 'departments'],
  ['hr-positions', 'keep', 'positions'],
  ['hr-positions-editor', 'action', 'positions'],
  ['hr-movements', 'keep', 'movements'],
  ['hr-movements-by-id', 'keep', 'movement-detail'],
  ['hr-contracts', 'keep', 'contracts'],
  ['hr-contracts-editor', 'action', 'contracts'],
  ['system-users', 'keep', 'system-users'],
  ['system-roles', 'keep', 'system-roles'],
])

/**
 * One independent, complete decision per registry page code. The evidence is
 * deliberately page-specific: the generator must never infer or inherit it.
 */
export const DECISIONS = Object.freeze({
  login: { disposition: 'keep', targetPageCode: 'login', owner: 'Platform', evidence: '基础登录页保留，作为账号密码单步登录入口。' },
  workspace: { disposition: 'keep', targetPageCode: 'workspace', owner: 'Platform', evidence: '我的工作台保留，汇总当前用户有权进入的待办与入口。' },
  forbidden: { disposition: 'keep', targetPageCode: 'forbidden', owner: 'Platform', evidence: '无权限基础页保留，用于统一 403 状态。' },
  'not-found': { disposition: 'keep', targetPageCode: 'not-found', owner: 'Platform', evidence: '页面不存在基础页保留，用于统一 404 状态。' },
  'account-mfa': { disposition: 'keep', targetPageCode: 'account-mfa', owner: 'Platform', evidence: 'MFA 作为可选安全能力保留在账号安全页，默认不启用。' },
  'account-sessions': { disposition: 'keep', targetPageCode: 'account-sessions', owner: 'Platform', evidence: '会话管理页保留，支持查看并注销当前账号会话。' },

  dashboard: { disposition: 'keep', targetPageCode: 'dashboard', owner: 'Reporting', evidence: '首页看板保留为 V2.1 登录后的默认工作台。' },
  'dashboard-departments': { disposition: 'keep', targetPageCode: 'dashboard-departments', owner: 'Reporting', evidence: '部门人数分析保留为独立看板工作区，查询同一仪表盘数据域。' },
  'dashboard-workforce': { disposition: 'keep', targetPageCode: 'dashboard-workforce', owner: 'Reporting', evidence: '员工构成看板保留为独立分析工作区。' },
  'dashboard-changes': { disposition: 'keep', targetPageCode: 'dashboard-changes', owner: 'Reporting', evidence: '近期人事动态看板保留，继续展示授权范围内的人事变更。' },
  'dashboard-tasks': { disposition: 'keep', targetPageCode: 'dashboard-tasks', owner: 'Reporting', evidence: '工作提醒看板保留，承载当前用户待办聚合。' },
  'dashboard-recruitment': { disposition: 'keep', targetPageCode: 'dashboard-recruitment', owner: 'Reporting', evidence: '招聘进展看板保留，展示授权范围内招聘漏斗指标。' },

  reports: { disposition: 'keep', targetPageCode: 'reports', owner: 'Reporting', evidence: '报表目录保留为报表中心主工作台。' },
  'reports-headcount': { disposition: 'view', targetPageCode: 'reports', owner: 'Reporting', evidence: '人力资源报表并入 reports 的报表视图页签，共用报表定义与导出权限。' },
  'reports-attendance': { disposition: 'view', targetPageCode: 'reports', owner: 'Reporting', evidence: '考勤假期报表并入 reports 的报表视图页签，共用报表定义与导出权限。' },
  'reports-payroll': { disposition: 'view', targetPageCode: 'reports', owner: 'Reporting', evidence: '薪资成本报表并入 reports 的报表视图页签，字段与导出仍受 P3 策略约束。' },
  'reports-hiring': { disposition: 'view', targetPageCode: 'reports', owner: 'Reporting', evidence: '招聘转化报表并入 reports 的报表视图页签，保留招聘统计口径。' },
  'reports-talent': { disposition: 'view', targetPageCode: 'reports', owner: 'Reporting', evidence: '绩效培训报表并入 reports 的报表视图页签，保留绩效与培训统计口径。' },
  'reports-builder': { disposition: 'flow', targetPageCode: 'reports-builder', owner: 'Reporting', evidence: '自定义报表设计保留为可保存草稿并发布的长流程页面。' },
  'reports-subscriptions': { disposition: 'flow', targetPageCode: 'reports-subscriptions', owner: 'Reporting', evidence: '报表订阅保留为创建、暂停和删除订阅的长流程页面。' },
  'reports-exports': { disposition: 'keep', targetPageCode: 'reports-exports', owner: 'Reporting', evidence: '导出任务保留为独立工作区，用于查看任务、字段白名单和审计结果。' },

  'hr-employees': { disposition: 'keep', targetPageCode: 'employees', owner: 'People', evidence: '员工列表收敛为 employees 主工作台，承载查询、新建入口与行操作。' },
  'hr-employees-create': { disposition: 'action', targetPageCode: 'employees', owner: 'People', evidence: '新增员工并入 employees 页头的新建抽屉，由原子建档命令一次提交。' },
  'employee-detail': { disposition: 'keep', targetPageCode: 'employee-detail', owner: 'People', evidence: '员工档案详情保留为 employee-detail 深链接；brief 中旧码 hr-employees-by-id 已收敛为当前注册表码。' },
  'hr-employees-by-id-education': { disposition: 'view', targetPageCode: 'employee-detail', owner: 'People', evidence: '教育经历并入 employee-detail 的同一员工视图页签。' },
  'hr-employees-by-id-work-history': { disposition: 'view', targetPageCode: 'employee-detail', owner: 'People', evidence: '工作经历并入 employee-detail 的同一员工视图页签。' },
  'hr-employees-by-id-contacts': { disposition: 'view', targetPageCode: 'employee-detail', owner: 'People', evidence: '家庭与紧急联系人并入 employee-detail 的同一员工视图页签。' },
  'hr-employees-by-id-credentials': { disposition: 'view', targetPageCode: 'employee-detail', owner: 'People', evidence: '证照与资格并入 employee-detail 的同一员工视图页签。' },
  'hr-employees-by-id-history': { disposition: 'view', targetPageCode: 'employee-detail', owner: 'People', evidence: '任职与异动履历并入 employee-detail 的同一员工视图页签。' },
  'hr-employees-by-id-files': { disposition: 'view', targetPageCode: 'employee-detail', owner: 'People', evidence: '员工附件并入 employee-detail 的同一员工视图页签，仍按附件策略鉴权。' },
  'hr-employees-by-id-changes': { disposition: 'view', targetPageCode: 'employee-detail', owner: 'People', evidence: '档案变更审批记录并入 employee-detail 的同一员工视图页签。' },
  'hr-employees-imports': { disposition: 'flow', targetPageCode: 'employees-import', owner: 'People', evidence: '员工导入保留为 employees-import 可恢复的校验与错误修正长流程。' },

  'hr-departments': { disposition: 'keep', targetPageCode: 'departments', owner: 'Organization', evidence: '组织架构树收敛为 departments 主工作台。' },
  'hr-departments-list': { disposition: 'view', targetPageCode: 'departments', owner: 'Organization', evidence: '部门列表并入 departments 的树表切换视图，保持同一组织范围。' },
  'hr-departments-editor': { disposition: 'action', targetPageCode: 'departments', owner: 'Organization', evidence: '新建与编辑部门并入 departments 的编辑抽屉动作。' },
  'hr-departments-members': { disposition: 'view', targetPageCode: 'departments', owner: 'Organization', evidence: '部门成员并入 departments 选中部门的成员视图。' },
  'hr-departments-move': { disposition: 'action', targetPageCode: 'departments', owner: 'Organization', evidence: '部门迁移并入 departments 选中部门的移动动作，提交前确认影响范围。' },
  'hr-departments-staffing': { disposition: 'view', targetPageCode: 'departments', owner: 'Organization', evidence: '部门编制并入 departments 选中部门的编制视图与占用统计。' },

  'hr-positions': { disposition: 'keep', targetPageCode: 'positions', owner: 'Organization', evidence: '岗位目录收敛为 positions 主工作台。' },
  'hr-positions-editor': { disposition: 'action', targetPageCode: 'positions', owner: 'Organization', evidence: '岗位编辑并入 positions 的版本化编辑抽屉动作。' },
  'hr-positions-headcount': { disposition: 'view', targetPageCode: 'positions', owner: 'Organization', evidence: '岗位编制并入 positions 的岗位编制视图。' },
  'hr-positions-requirements': { disposition: 'view', targetPageCode: 'positions', owner: 'Organization', evidence: '岗位任职资格并入 positions 的岗位资格视图，保留版本历史。' },
  'hr-positions-grades': { disposition: 'view', targetPageCode: 'positions', owner: 'Organization', evidence: '职级与职等并入 positions 的职级目录视图。' },
  'hr-positions-staff': { disposition: 'view', targetPageCode: 'positions', owner: 'Organization', evidence: '岗位任职人员并入 positions 的岗位在岗人员视图。' },

  'hr-movements': { disposition: 'keep', targetPageCode: 'movements', owner: 'Lifecycle', evidence: '异动列表收敛为 movements 主工作台。' },
  'hr-movements-onboarding': { disposition: 'flow', targetPageCode: 'movement-detail', owner: 'Lifecycle', evidence: '入职办理保留为 movement-detail 的可恢复长流程与清单。' },
  'hr-movements-probation': { disposition: 'flow', targetPageCode: 'movement-detail', owner: 'Lifecycle', evidence: '转正管理保留为 movement-detail 的可恢复审批长流程。' },
  'hr-movements-transfer': { disposition: 'flow', targetPageCode: 'movement-detail', owner: 'Lifecycle', evidence: '调岗与调部门保留为 movement-detail 的可恢复长流程。' },
  'hr-movements-salary-change': { disposition: 'flow', targetPageCode: 'movement-detail', owner: 'Lifecycle', evidence: '调薪管理保留为 movement-detail 的长流程，金额字段继续受 P3 策略约束。' },
  'hr-movements-secondment': { disposition: 'flow', targetPageCode: 'movement-detail', owner: 'Lifecycle', evidence: '借调与兼岗保留为 movement-detail 的可恢复长流程。' },
  'hr-movements-offboarding': { disposition: 'flow', targetPageCode: 'movement-detail', owner: 'Lifecycle', evidence: '离职办理保留为 movement-detail 的可恢复长流程与权限回收清单。' },
  'hr-movements-by-id': { disposition: 'keep', targetPageCode: 'movement-detail', owner: 'Lifecycle', evidence: '异动审批详情保留为 movement-detail 深链接，承载审批轨迹与操作。' },
  'hr-movements-calendar': { disposition: 'view', targetPageCode: 'movements', owner: 'Lifecycle', evidence: '异动日历并入 movements 的日历视图，保持同一授权范围。' },

  'hr-contracts': { disposition: 'keep', targetPageCode: 'contracts', owner: 'Contracts', evidence: '合同台账收敛为 contracts 主工作台。' },
  'hr-contracts-editor': { disposition: 'action', targetPageCode: 'contracts', owner: 'Contracts', evidence: '合同录入与续签并入 contracts 的编辑抽屉动作。' },
  'hr-contracts-by-id': { disposition: 'keep', targetPageCode: 'contract-detail', owner: 'Contracts', evidence: '合同详情保留为 contract-detail 深链接，承载版本与附件。' },
  'hr-contracts-renewals': { disposition: 'flow', targetPageCode: 'contract-detail', owner: 'Contracts', evidence: '合同续签保留为 contract-detail 的版本化续签长流程。' },
  'hr-contracts-changes': { disposition: 'flow', targetPageCode: 'contract-detail', owner: 'Contracts', evidence: '合同变更与终止保留为 contract-detail 的版本化长流程。' },
  'hr-contracts-expiry': { disposition: 'view', targetPageCode: 'contracts', owner: 'Contracts', evidence: '到期预警并入 contracts 的到期筛选视图。' },
  'hr-contracts-templates': { disposition: 'flow', targetPageCode: 'contract-templates', owner: 'Contracts', evidence: '合同模板保留为 contract-templates 的版本化发布长流程。' },

  attendance: { disposition: 'keep', targetPageCode: 'attendance', owner: 'Attendance', evidence: '我的打卡收敛为 attendance 主工作台。' },
  'attendance-punches': { disposition: 'view', targetPageCode: 'attendance', owner: 'Attendance', evidence: '打卡明细并入 attendance 的个人打卡明细视图。' },
  'attendance-calendar': { disposition: 'view', targetPageCode: 'attendance', owner: 'Attendance', evidence: '考勤日历并入 attendance 的个人考勤日历视图。' },
  'attendance-rules': { disposition: 'flow', targetPageCode: 'attendance-rules', owner: 'Attendance', evidence: '考勤规则保留为 attendance-rules 的版本化配置长流程。' },
  'attendance-shifts': { disposition: 'flow', targetPageCode: 'attendance-shifts', owner: 'Attendance', evidence: '班次管理保留为 attendance-shifts 的班次模板长流程。' },
  'attendance-schedules': { disposition: 'flow', targetPageCode: 'attendance-schedules', owner: 'Attendance', evidence: '排班管理保留为 attendance-schedules 的排班发布长流程。' },
  'attendance-corrections': { disposition: 'flow', targetPageCode: 'attendance-corrections', owner: 'Attendance', evidence: '补卡申请保留为 attendance-corrections 的审批 Case 长流程。' },
  'attendance-exceptions': { disposition: 'view', targetPageCode: 'attendance', owner: 'Attendance', evidence: '异常处理并入 attendance 的异常明细视图，处理动作单独授权。' },
  'attendance-closing': { disposition: 'flow', targetPageCode: 'attendance-closing', owner: 'Attendance', evidence: '考勤月结保留为 attendance-closing 的锁定、重开与审计长流程。' },
  'attendance-devices': { disposition: 'flow', targetPageCode: 'attendance-devices', owner: 'Attendance', evidence: '设备对接保留为 attendance-devices 的导入与健康检查长流程。' },

  leave: { disposition: 'keep', targetPageCode: 'leave', owner: 'Leave', evidence: '请假申请收敛为 leave 主工作台。' },
  'leave-requests': { disposition: 'view', targetPageCode: 'leave', owner: 'Leave', evidence: '请假台账并入 leave 的申请记录视图。' },
  'leave-types': { disposition: 'flow', targetPageCode: 'leave-policies', owner: 'Leave', evidence: '假期类型并入 leave-policies 的版本化规则长流程。' },
  'leave-policies': { disposition: 'flow', targetPageCode: 'leave-policies', owner: 'Leave', evidence: '假期规则保留为 leave-policies 的版本化配置长流程。' },
  'leave-balances': { disposition: 'view', targetPageCode: 'leave', owner: 'Leave', evidence: '假期余额并入 leave 的余额与台账视图。' },
  'leave-calendar': { disposition: 'view', targetPageCode: 'leave', owner: 'Leave', evidence: '假期日历并入 leave 的团队请假日历视图。' },
  'leave-cancellations': { disposition: 'action', targetPageCode: 'leave', owner: 'Leave', evidence: '销假与撤销并入 leave 的申请行动作，并释放余额预占。' },
  'leave-reports': { disposition: 'view', targetPageCode: 'leave', owner: 'Leave', evidence: '假期汇总并入 leave 的汇总统计视图。' },

  overtime: { disposition: 'keep', targetPageCode: 'overtime', owner: 'Overtime', evidence: '加班申请收敛为 overtime 主工作台。' },
  'overtime-requests': { disposition: 'view', targetPageCode: 'overtime', owner: 'Overtime', evidence: '加班台账并入 overtime 的申请与确认记录视图。' },
  'overtime-policies': { disposition: 'flow', targetPageCode: 'overtime-policies', owner: 'Overtime', evidence: '加班规则保留为 overtime-policies 的版本化配置长流程。' },
  'overtime-confirm': { disposition: 'action', targetPageCode: 'overtime', owner: 'Overtime', evidence: '加班确认并入 overtime 的申请行确认动作，区分申报与实绩。' },
  'overtime-timeoff': { disposition: 'view', targetPageCode: 'overtime', owner: 'Overtime', evidence: '调休余额并入 overtime 的调休余额视图。' },
  'overtime-settlement': { disposition: 'flow', targetPageCode: 'overtime-settlement', owner: 'Overtime', evidence: '加班结算保留为 overtime-settlement 的工资或调休结算长流程。' },

  travel: { disposition: 'keep', targetPageCode: 'travel', owner: 'Travel', evidence: '出差申请收敛为 travel 主工作台。' },
  'travel-requests': { disposition: 'view', targetPageCode: 'travel', owner: 'Travel', evidence: '出差台账并入 travel 的申请与审批状态视图。' },
  'travel-policies': { disposition: 'flow', targetPageCode: 'travel-policies', owner: 'Travel', evidence: '差旅政策保留为 travel-policies 的版本化发布长流程。' },
  'travel-approval': { disposition: 'flow', targetPageCode: 'travel-approval', owner: 'Travel', evidence: '出差审批保留为 travel-approval 的审批 Case 长流程。' },
  'travel-changes': { disposition: 'action', targetPageCode: 'travel', owner: 'Travel', evidence: '出差变更与销单并入 travel 的申请行动作。' },
  'travel-reports': { disposition: 'view', targetPageCode: 'travel', owner: 'Travel', evidence: '出差统计并入 travel 的部门统计视图。' },
  'travel-expenses': { disposition: 'flow', targetPageCode: 'travel-expenses', owner: 'Travel', evidence: '费用交接保留为 travel-expenses 的报销单据长流程。' },

  payroll: { disposition: 'keep', targetPageCode: 'payroll', owner: 'Payroll', evidence: '薪资概览收敛为 payroll 主工作台。' },
  'payroll-components': { disposition: 'flow', targetPageCode: 'payroll-components', owner: 'Payroll', evidence: '薪资项目保留为 payroll-components 的版本化配置长流程。' },
  'payroll-plans': { disposition: 'flow', targetPageCode: 'payroll-plans', owner: 'Payroll', evidence: '薪酬方案保留为 payroll-plans 的版本化方案长流程。' },
  'payroll-employee-plans': { disposition: 'view', targetPageCode: 'payroll', owner: 'Payroll', evidence: '员工薪资档案并入 payroll 的员工薪酬视图，金额继续按 P3 遮罩。' },
  'payroll-statutory': { disposition: 'flow', targetPageCode: 'payroll-statutory', owner: 'Payroll', evidence: '社保公积金参数保留为 payroll-statutory 的有效期与证据长流程。' },
  'payroll-inputs': { disposition: 'view', targetPageCode: 'payroll', owner: 'Payroll', evidence: '计薪数据汇总并入 payroll 的批次输入视图。' },
  'payroll-batches': { disposition: 'flow', targetPageCode: 'payroll-batches', owner: 'Payroll', evidence: '工资核算批次保留为 payroll-batches 的核算、复核、发布长流程。' },
  'payroll-reviews': { disposition: 'flow', targetPageCode: 'payroll-batches', owner: 'Payroll', evidence: '工资复核并入 payroll-batches 的复核步骤，保留双人复核约束。' },
  'payroll-disbursement': { disposition: 'action', targetPageCode: 'payroll-batches', owner: 'Payroll', evidence: '工资发放登记并入 payroll-batches 已发布批次的发放动作。' },
  'payroll-payslips': { disposition: 'view', targetPageCode: 'payroll-payslips', owner: 'Payroll', evidence: '工资条管理保留为 payroll-payslips 的批次工资条视图与发放结果。' },
  'payroll-adjustments': { disposition: 'flow', targetPageCode: 'payroll-adjustments', owner: 'Payroll', evidence: '调薪记录保留为 payroll-adjustments 的审批与生效长流程。' },
  'payroll-reports': { disposition: 'view', targetPageCode: 'payroll', owner: 'Payroll', evidence: '薪资报表并入 payroll 的成本统计视图，导出仍单独授权。' },

  performance: { disposition: 'keep', targetPageCode: 'performance', owner: 'Performance', evidence: '绩效周期收敛为 performance 主工作台。' },
  'performance-templates': { disposition: 'flow', targetPageCode: 'performance-templates', owner: 'Performance', evidence: '考核模板保留为 performance-templates 的版本化发布长流程。' },
  'performance-indicators': { disposition: 'flow', targetPageCode: 'performance-indicators', owner: 'Performance', evidence: '指标库保留为 performance-indicators 的指标维护长流程。' },
  'performance-goals': { disposition: 'view', targetPageCode: 'performance', owner: 'Performance', evidence: '目标计划并入 performance 的目标录入与进度视图。' },
  'performance-reviews': { disposition: 'flow', targetPageCode: 'performance-reviews', owner: 'Performance', evidence: '自评与主管评分保留为 performance-reviews 的提交与校准前长流程。' },
  'performance-feedback': { disposition: 'view', targetPageCode: 'performance', owner: 'Performance', evidence: '360 反馈并入 performance 的反馈记录视图。' },
  'performance-calibration': { disposition: 'flow', targetPageCode: 'performance-calibration', owner: 'Performance', evidence: '绩效校准保留为 performance-calibration 的校准会议与发布长流程。' },
  'performance-results': { disposition: 'flow', targetPageCode: 'performance-results', owner: 'Performance', evidence: '结果发布保留为 performance-results 的冻结与发布长流程。' },
  'performance-appeals': { disposition: 'flow', targetPageCode: 'performance-appeals', owner: 'Performance', evidence: '绩效申诉保留为 performance-appeals 的审批 Case 长流程。' },
  'performance-reports': { disposition: 'view', targetPageCode: 'performance', owner: 'Performance', evidence: '绩效报表并入 performance 的分布与趋势视图。' },

  'recruitment-requests': { disposition: 'keep', targetPageCode: 'recruitment-requests', owner: 'Recruitment', evidence: '招聘需求列表收敛为 recruitment-requests 主工作台。' },
  'recruitment-requests-create': { disposition: 'action', targetPageCode: 'recruitment-requests', owner: 'Recruitment', evidence: '招聘需求新建并入 recruitment-requests 的创建抽屉动作。' },
  'recruitment-requests-approval': { disposition: 'flow', targetPageCode: 'recruitment-requests', owner: 'Recruitment', evidence: '招聘需求审批并入 recruitment-requests 的审批 Case 长流程。' },
  'recruitment-requests-openings': { disposition: 'action', targetPageCode: 'recruitment-requests', owner: 'Recruitment', evidence: '招聘岗位发布并入 recruitment-requests 已批准需求的发布动作。' },
  'recruitment-requests-board': { disposition: 'view', targetPageCode: 'recruitment-requests', owner: 'Recruitment', evidence: '招聘进度看板并入 recruitment-requests 的看板视图。' },
  'recruitment-requests-budget': { disposition: 'view', targetPageCode: 'recruitment-requests', owner: 'Recruitment', evidence: '招聘预算与编制并入 recruitment-requests 的预算占用视图。' },
  'recruitment-requests-reports': { disposition: 'view', targetPageCode: 'recruitment-requests', owner: 'Recruitment', evidence: '招聘统计并入 recruitment-requests 的统计视图。' },

  'recruitment-candidates': { disposition: 'keep', targetPageCode: 'recruitment-candidates', owner: 'Recruitment', evidence: '候选人列表收敛为 recruitment-candidates 主工作台。' },
  'recruitment-candidates-by-id': { disposition: 'keep', targetPageCode: 'recruitment-candidate-detail', owner: 'Recruitment', evidence: '候选人档案保留为 recruitment-candidate-detail 深链接。' },
  'recruitment-candidates-imports': { disposition: 'action', targetPageCode: 'recruitment-candidates', owner: 'Recruitment', evidence: '简历解析与导入并入 recruitment-candidates 的导入动作与结果抽屉。' },
  'recruitment-candidates-pipeline': { disposition: 'view', targetPageCode: 'recruitment-candidates', owner: 'Recruitment', evidence: '招聘流程看板并入 recruitment-candidates 的漏斗看板视图。' },
  'recruitment-candidates-interviews': { disposition: 'flow', targetPageCode: 'recruitment-candidates-interviews', owner: 'Recruitment', evidence: '面试安排保留为 recruitment-candidates-interviews 的排期与反馈长流程。' },
  'recruitment-candidates-evaluations': { disposition: 'view', targetPageCode: 'recruitment-candidate-detail', owner: 'Recruitment', evidence: '面试评价并入 recruitment-candidate-detail 的评价记录视图。' },
  'recruitment-candidates-offers': { disposition: 'flow', targetPageCode: 'recruitment-candidate-detail', owner: 'Recruitment', evidence: 'Offer 管理保留为 recruitment-candidate-detail 的版本化审批长流程。' },
  'recruitment-candidates-communications': { disposition: 'view', targetPageCode: 'recruitment-candidate-detail', owner: 'Recruitment', evidence: '候选人沟通并入 recruitment-candidate-detail 的沟通记录视图。' },
  'recruitment-candidates-talent-pool': { disposition: 'view', targetPageCode: 'recruitment-candidates', owner: 'Recruitment', evidence: '人才库与标签并入 recruitment-candidates 的人才库筛选视图。' },
  'recruitment-candidates-convert': { disposition: 'action', targetPageCode: 'recruitment-candidate-detail', owner: 'Recruitment', evidence: '录用转入职并入 recruitment-candidate-detail 的转换动作，成功后跳转员工详情。' },

  training: { disposition: 'keep', targetPageCode: 'training', owner: 'Training', evidence: '培训计划收敛为 training 主工作台。' },
  'training-courses': { disposition: 'flow', targetPageCode: 'training-courses', owner: 'Training', evidence: '课程库保留为 training-courses 的课程与教材维护长流程。' },
  'training-sessions': { disposition: 'flow', targetPageCode: 'training-sessions', owner: 'Training', evidence: '培训场次保留为 training-sessions 的排期与容量长流程。' },
  'training-enrollments': { disposition: 'action', targetPageCode: 'training-sessions', owner: 'Training', evidence: '培训报名并入 training-sessions 的报名与名额动作。' },
  'training-checkins': { disposition: 'action', targetPageCode: 'training-sessions', owner: 'Training', evidence: '培训签到并入 training-sessions 的签到动作。' },
  'training-exams': { disposition: 'flow', targetPageCode: 'training-exams', owner: 'Training', evidence: '培训考试保留为 training-exams 的组卷与发布长流程。' },
  'training-feedback': { disposition: 'view', targetPageCode: 'training-sessions', owner: 'Training', evidence: '培训反馈并入 training-sessions 的课后反馈视图。' },
  'training-records': { disposition: 'view', targetPageCode: 'training', owner: 'Training', evidence: '培训档案并入 training 的员工培训记录视图。' },
  'training-reports': { disposition: 'view', targetPageCode: 'training-reports', owner: 'Training', evidence: '培训报表保留为 training-reports 的参训与完成率视图。' },

  notices: { disposition: 'keep', targetPageCode: 'notices', owner: 'Engagement', evidence: '公告列表收敛为 notices 主工作台。' },
  'notices-editor': { disposition: 'action', targetPageCode: 'notices', owner: 'Engagement', evidence: '公告编辑并入 notices 的编辑抽屉动作。' },
  'notices-approval': { disposition: 'flow', targetPageCode: 'notices', owner: 'Engagement', evidence: '公告审核并入 notices 的审批 Case 长流程。' },
  'notices-audience': { disposition: 'view', targetPageCode: 'notices', owner: 'Engagement', evidence: '公告发布范围并入 notices 编辑中的受众视图。' },
  'notices-reads': { disposition: 'view', targetPageCode: 'notices', owner: 'Engagement', evidence: '公告阅读记录并入 notices 的阅读统计视图。' },
  'notices-templates': { disposition: 'flow', targetPageCode: 'notices-templates', owner: 'Engagement', evidence: '公告模板保留为 notices-templates 的模板维护长流程。' },
  'notices-archive': { disposition: 'action', targetPageCode: 'notices', owner: 'Engagement', evidence: '公告归档并入 notices 的归档动作，保留审计与可恢复状态。' },

  account: { disposition: 'keep', targetPageCode: 'account', owner: 'Platform', evidence: '我的账号平台页保留，聚合账号安全与自助入口。' },
  'system-users': { disposition: 'keep', targetPageCode: 'system-users', owner: 'Platform', evidence: '用户管理保留为 system-users 治理工作台。' },
  'system-roles': { disposition: 'keep', targetPageCode: 'system-roles', owner: 'Platform', evidence: '角色管理保留为 system-roles 治理工作台。' },
  'system-menus': { disposition: 'keep', targetPageCode: 'system-menus', owner: 'Platform', evidence: '菜单与页面管理保留为 system-menus 治理工作台。' },
  'system-permissions': { disposition: 'keep', targetPageCode: 'system-permissions', owner: 'Platform', evidence: '操作权限配置保留为 system-permissions 治理工作台。' },
  'system-data-scopes': { disposition: 'keep', targetPageCode: 'system-data-scopes', owner: 'Platform', evidence: '数据范围权限保留为 system-data-scopes 治理工作台。' },
  'system-field-policies': { disposition: 'keep', targetPageCode: 'system-field-policies', owner: 'Platform', evidence: '字段级权限保留为 system-field-policies 治理工作台。' },
  workflow: { disposition: 'keep', targetPageCode: 'workflow', owner: 'Platform', evidence: '审批流程中心保留为 workflow 治理与待办工作台。' },
  'self-service': { disposition: 'keep', targetPageCode: 'self-service', owner: 'Platform', evidence: '员工自助门户保留为 self-service 个人工作台。' },
  benefits: { disposition: 'keep', targetPageCode: 'benefits', owner: 'Platform', evidence: '社保公积金与福利保留为 benefits 治理工作台。' },
  'system-calendars': { disposition: 'keep', targetPageCode: 'system-calendars', owner: 'Platform', evidence: '节假日与工作日历保留为 system-calendars 治理工作台。' },
  'system-settings': { disposition: 'keep', targetPageCode: 'system-settings', owner: 'Platform', evidence: '字典、参数与文件保留为 system-settings 治理工作台。' },
  notifications: { disposition: 'keep', targetPageCode: 'notifications', owner: 'Platform', evidence: '消息与通知中心保留为 notifications 个人与投递工作台。' },
  'system-audit': { disposition: 'keep', targetPageCode: 'system-audit', owner: 'Platform', evidence: '日志审计与运维保留为 system-audit 治理工作台。' },
})

function requireNonEmptyString(value, field, pageName) {
  if (typeof value !== 'string' || value.trim() === '') {
    throw new Error(`missing ${field} for ${pageName}`)
  }
  return value.trim()
}

function hasWrittenUserApproval(evidence) {
  return /(用户|user)/i.test(evidence)
    && /(批准|签认|同意|approved)/i.test(evidence)
    && /[“”"]/.test(evidence)
}

export function buildDispositionRows({ registry, decisions }) {
  return registry.map((page) => {
    const decision = decisions[page.name]
    if (decision === undefined) throw new Error(`missing disposition for ${page.name}`)
    if (!DISPOSITIONS.has(decision.disposition)) throw new Error(`invalid disposition for ${page.name}`)

    const targetPageCode = requireNonEmptyString(decision.targetPageCode, 'targetPageCode', page.name)
    const owner = requireNonEmptyString(decision.owner, 'owner', page.name)
    const evidence = requireNonEmptyString(decision.evidence, 'evidence', page.name)

    if (decision.disposition === 'deprecate' && !hasWrittenUserApproval(evidence)) {
      throw new Error(`deprecate requires written user approval in evidence: ${page.name}`)
    }

    return {
      pageCode: page.name,
      path: page.path,
      layer: page.layer,
      capability: page.capability,
      disposition: decision.disposition,
      targetPageCode,
      owner,
      evidence,
    }
  })
}

function stripComments(source) {
  let output = ''
  let index = 0
  let quote = null
  let escaped = false
  let lineComment = false
  let blockComment = false

  while (index < source.length) {
    const char = source[index]
    const next = source[index + 1]

    if (lineComment) {
      if (char === '\n') {
        lineComment = false
        output += char
      }
      index += 1
      continue
    }
    if (blockComment) {
      if (char === '*' && next === '/') {
        blockComment = false
        index += 2
      } else {
        index += 1
      }
      continue
    }
    if (quote !== null) {
      output += char
      if (escaped) escaped = false
      else if (char === '\\') escaped = true
      else if (char === quote) quote = null
      index += 1
      continue
    }
    if (char === '/' && next === '/') {
      lineComment = true
      index += 2
      continue
    }
    if (char === '/' && next === '*') {
      blockComment = true
      index += 2
      continue
    }
    if (char === "'" || char === '"' || char === '`') quote = char
    output += char
    index += 1
  }

  return output
}

function sliceBalanced(source, start, open, close) {
  let depth = 0
  let quote = null
  let escaped = false

  for (let index = start; index < source.length; index += 1) {
    const char = source[index]

    if (quote !== null) {
      if (escaped) escaped = false
      else if (char === '\\') escaped = true
      else if (char === quote) quote = null
      continue
    }
    if (char === "'" || char === '"' || char === '`') {
      quote = char
      continue
    }
    if (char === open) depth += 1
    else if (char === close) {
      depth -= 1
      if (depth === 0) return source.slice(start + 1, index)
    }
  }

  throw new Error(`unbalanced ${open}${close} block`)
}

function readStringProperty(objectSource, property) {
  const match = objectSource.match(new RegExp(`\\b${property}\\s*:\\s*(['"\`])((?:\\\\.|(?!\\1)[\\s\\S])*)\\1`))
  if (match === null) return undefined
  return match[2].replace(/\\(['"`\\])/g, '$1')
}

/**
 * Parse PAGE_REGISTRY without importing TypeScript or evaluating repository
 * code. The scanner handles both one-line compact objects and multi-line
 * objects, nested action/menu objects, and comments inside the array.
 */
export function parseRegistryPages(source) {
  const stripped = stripComments(source)
  const declaration = stripped.indexOf('export const PAGE_REGISTRY')
  if (declaration === -1) throw new Error('PAGE_REGISTRY declaration not found')

  const assignment = stripped.indexOf('=', declaration)
  if (assignment === -1) throw new Error('PAGE_REGISTRY assignment not found')
  const arrayStart = stripped.indexOf('[', assignment)
  if (arrayStart === -1) throw new Error('PAGE_REGISTRY array not found')
  const arrayBody = sliceBalanced(stripped, arrayStart, '[', ']')

  const pages = []
  let depth = 0
  let objectStart = -1
  let quote = null
  let escaped = false

  for (let index = 0; index < arrayBody.length; index += 1) {
    const char = arrayBody[index]

    if (quote !== null) {
      if (escaped) escaped = false
      else if (char === '\\') escaped = true
      else if (char === quote) quote = null
      continue
    }
    if (char === "'" || char === '"' || char === '`') {
      quote = char
      continue
    }
    if (char === '{') {
      if (depth === 0) objectStart = index
      depth += 1
      continue
    }
    if (char === '}') {
      depth -= 1
      if (depth === 0) {
        const objectSource = arrayBody.slice(objectStart, index + 1)
        const page = {
          name: readStringProperty(objectSource, 'name'),
          path: readStringProperty(objectSource, 'path'),
          layer: readStringProperty(objectSource, 'layer'),
          capability: readStringProperty(objectSource, 'capability'),
        }
        if (page.name === undefined || page.path === undefined || page.layer === undefined || page.capability === undefined) {
          throw new Error(`registry page at offset ${objectStart} is missing name, path, layer or capability`)
        }
        if (!['business', 'platform', 'base'].includes(page.layer)) {
          throw new Error(`registry page ${page.name} has invalid layer ${page.layer}`)
        }
        pages.push(page)
      }
      continue
    }
    if (depth === 0 && char === ']') break
  }

  if (pages.length === 0) throw new Error('PAGE_REGISTRY parser found no pages')
  const names = new Set()
  for (const page of pages) {
    if (names.has(page.name)) throw new Error(`duplicate registry page name ${page.name}`)
    names.add(page.name)
  }

  return pages
}

function countBy(items, selector) {
  const counts = new Map()
  for (const item of items) {
    const key = selector(item)
    counts.set(key, (counts.get(key) ?? 0) + 1)
  }
  return counts
}

export function summarizeRows(rows) {
  const byLayer = countBy(rows, (row) => row.layer)
  const byDisposition = countBy(rows, (row) => row.disposition)
  const businessRows = rows.filter((row) => row.layer === 'business')
  const mappedBusiness = businessRows.filter((row) => MAPPED_DISPOSITIONS.has(row.disposition)).length

  return {
    total: rows.length,
    business: byLayer.get('business') ?? 0,
    platform: byLayer.get('platform') ?? 0,
    base: byLayer.get('base') ?? 0,
    keep: byDisposition.get('keep') ?? 0,
    action: byDisposition.get('action') ?? 0,
    view: byDisposition.get('view') ?? 0,
    flow: byDisposition.get('flow') ?? 0,
    deprecate: byDisposition.get('deprecate') ?? 0,
    mappedBusiness,
    userApprovedDeprecated: businessRows.filter((row) => row.disposition === 'deprecate').length,
    unmappedBusiness: businessRows.filter((row) => !MAPPED_DISPOSITIONS.has(row.disposition) && row.disposition !== 'deprecate').length,
  }
}

function markdownCell(value) {
  return String(value).replaceAll('\\', '\\\\').replaceAll('|', '\\|').replaceAll('\n', ' ')
}

export function buildRouteDispositionMarkdown(rows) {
  const summary = summarizeRows(rows)
  const lines = [
    '# FactoryHR V2.1 160 路由最终处置表',
    '',
    '- 生成命令：`node scripts/generate_route_disposition.mjs`',
    '- 来源：`frontend/src/router/registry.ts` 的 `PAGE_REGISTRY`（唯一注册表来源）',
    `- 注册表条目：${summary.total}（业务 ${summary.business} / 平台 ${summary.platform} / 基础 ${summary.base}）`,
    `- 处置统计：keep ${summary.keep} / action ${summary.action} / view ${summary.view} / flow ${summary.flow} / deprecate ${summary.deprecate}`,
    '- 处置规则：主工作台保留；新增、编辑、导入、导出合并为动作；详情子页与同对象区域合并为视图；审批、批次和可恢复流程保留为长流程。',
    '- 废弃规则：系统尚未上线，旧 URL 与旧页面码可重分类；任何 deprecate 必须有用户书面签认原文。本表没有 deprecate。',
    '- 别名说明：brief 的 `hr-employees-by-id` 与当前注册表已收敛的 `employee-detail` 为同一页面，生成器按显式别名校验该必填映射。',
    '',
    '| # | 旧页面码 | 路径 | 层 | 旧能力码 | 处置 | 目标页面码 | Owner | 证据 |',
    '| ---: | --- | --- | --- | --- | --- | --- | --- | --- |',
  ]

  rows.forEach((row, index) => {
    lines.push(
      `| ${index + 1} | \`${markdownCell(row.pageCode)}\` | \`${markdownCell(row.path)}\` | ${markdownCell(row.layer)} | \`${markdownCell(row.capability)}\` | ${markdownCell(row.disposition)} | \`${markdownCell(row.targetPageCode)}\` | ${markdownCell(row.owner)} | ${markdownCell(row.evidence)} |`,
    )
  })

  lines.push(
    '',
    '## 完整性断言',
    '',
    `- 注册表页面条目：${summary.total}`,
    `- 已有处置：${summary.total}`,
    `- 缺少处置：0`,
    `- 非法处置：0`,
    `- 缺少目标页面码：0`,
    `- 缺少 Owner：0`,
    `- 缺少证据：0`,
    `- 用户签认废弃：${summary.deprecate}`,
    '',
  )

  return `${lines.join('\n')}`
}

export function buildCapabilityEquivalenceMarkdown(rows) {
  const summary = summarizeRows(rows)
  const lines = [
    '# FactoryHR V2.1 能力等价表',
    '',
    '- 生成命令：`node scripts/generate_route_disposition.mjs`',
    '- 来源：`frontend/src/router/registry.ts` 的 160 条页面能力域与 `HRMS-V1.1实施书.md` 的 140 个 03A 业务页面/能力点。',
    '- 等价口径：V1.1 的每个业务能力点都保留一个 keep/action/view/flow 去向；合并只改变入口形态，不删除能力、API、权限或测试要求。',
    '- 统计口径：业务能力点按注册表 `layer: business` 的 140 条计数；平台 14 条与基础 6 条同样列出，但不计入 V1.1 业务能力点。',
    '- 废弃规则：本表没有用户签认废弃项，因此 `deprecate` 为 0。',
    '',
    '| # | V1.1 能力点（旧页面码） | 路径 | 层 | 能力码（保持） | 处置 | 目标页面码 | Owner | 证据 |',
    '| ---: | --- | --- | --- | --- | --- | --- | --- | --- |',
  ]

  rows.forEach((row, index) => {
    lines.push(
      `| ${index + 1} | \`${markdownCell(row.pageCode)}\` | \`${markdownCell(row.path)}\` | ${markdownCell(row.layer)} | \`${markdownCell(row.capability)}\` | ${markdownCell(row.disposition)} | \`${markdownCell(row.targetPageCode)}\` | ${markdownCell(row.owner)} | ${markdownCell(row.evidence)} |`,
    )
  })

  lines.push(
    '',
    '## 完整性断言',
    '',
    `- V1.1 业务能力点：${summary.business}`,
    `- 已映射到 keep/action/view/flow：${summary.mappedBusiness}`,
    `- 用户签认废弃：${summary.userApprovedDeprecated}`,
    `- 未映射：${summary.unmappedBusiness}`,
    '',
    '## 判定',
    '',
    `- 140 个业务能力点全部有目标页面码与处置，无未映射项。`,
    `- 用户签认废弃为 ${summary.userApprovedDeprecated}；本表不依赖任何未签认废弃。`,
    `- 平台 14 条与基础 6 条也全部有处置，注册表总计 ${summary.total} 条。`,
    '',
  )

  return `${lines.join('\n')}`
}

function assertExpectedCounts(summary) {
  const expected = { total: 160, business: 140, platform: 14, base: 6, mappedBusiness: 140, userApprovedDeprecated: 0, unmappedBusiness: 0 }
  for (const [key, value] of Object.entries(expected)) {
    if (summary[key] !== value) {
      throw new Error(`expected ${key}=${value}, got ${summary[key]}`)
    }
  }
}

function assertRequiredMappings(decisions) {
  for (const [requiredPageName, requiredDisposition, requiredTarget] of BRIEF_REQUIRED_MAPPINGS) {
    const canonicalName = LEGACY_PAGE_CODE_ALIASES[requiredPageName] ?? requiredPageName
    const decision = decisions[canonicalName]
    if (decision === undefined) throw new Error(`required mapping has no decision: ${requiredPageName}`)
    if (decision.disposition !== requiredDisposition || decision.targetPageCode !== requiredTarget) {
      throw new Error(
        `required mapping drift for ${requiredPageName}: expected ${requiredDisposition} ${requiredTarget}, got ${decision.disposition} ${decision.targetPageCode}`,
      )
    }
  }
}

function main() {
  const registry = parseRegistryPages(readFileSync(registryPath, 'utf8'))
  if (registry.length !== 160) throw new Error(`expected 160 registry pages, got ${registry.length}`)

  const registryNames = new Set(registry.map((page) => page.name))
  const staleDecisions = Object.keys(DECISIONS).filter((name) => !registryNames.has(name))
  if (staleDecisions.length > 0) throw new Error(`decisions reference unknown registry pages: ${staleDecisions.join(', ')}`)
  assertRequiredMappings(DECISIONS)

  const rows = buildDispositionRows({ registry, decisions: DECISIONS })
  const summary = summarizeRows(rows)
  assertExpectedCounts(summary)

  mkdirSync(dirname(routeDispositionPath), { recursive: true })
  writeFileSync(routeDispositionPath, buildRouteDispositionMarkdown(rows))
  writeFileSync(capabilityEquivalencePath, buildCapabilityEquivalenceMarkdown(rows))

  console.log(`route disposition: ${rows.length} rows -> ${routeDispositionPath}`)
  console.log(`capability equivalence: ${rows.length} rows -> ${capabilityEquivalencePath}`)
  console.log(`business mapped: ${summary.mappedBusiness}/${summary.business}; user-approved deprecated: ${summary.userApprovedDeprecated}; unmapped: ${summary.unmappedBusiness}`)
}

if (process.argv[1] !== undefined && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main()
}
