/**
 * Chinese presentation labels for platform codes.
 *
 * API codes remain the contract on the wire; every visible label goes through
 * this module so no raw enum code reaches the screen. Unknown values fall back
 * to a neutral Chinese label instead of echoing the code.
 */

function pick(map: Record<string, string>, code: string, fallback: string): string {
  return map[code] ?? fallback
}

const WORKFLOW_STATUS: Record<string, string> = {
  DRAFT: '草稿',
  SUBMITTED: '已提交',
  IN_REVIEW: '审批中',
  APPROVED: '已通过',
  REJECTED: '已驳回',
  WITHDRAWN: '已撤回',
  CANCELLED: '已取消',
}

export function workflowStatusLabel(code: string): string {
  return pick(WORKFLOW_STATUS, code, '其他申请状态')
}

export function workflowStatusTone(code: string): 'success' | 'warning' | 'danger' | 'info' | 'muted' {
  if (code === 'APPROVED') return 'success'
  if (code === 'REJECTED') return 'danger'
  if (code === 'WITHDRAWN' || code === 'CANCELLED') return 'muted'
  if (code === 'SUBMITTED' || code === 'IN_REVIEW') return 'warning'
  return 'info'
}

const EFFECT_STATUS: Record<string, string> = {
  NONE: '无生效要求',
  PENDING: '待生效',
  SCHEDULED: '待生效',
  APPLIED: '已生效',
  PARTIAL: '部分生效',
  FAILED: '生效失败',
  REVERSED: '已冲正',
}

export function effectStatusLabel(code: string): string {
  return pick(EFFECT_STATUS, code, '其他生效状态')
}

const CASE_TYPES: Record<string, string> = {
  LEAVE_REQUEST: '请假申请',
  ATTENDANCE_CORRECTION: '考勤更正',
  TRAVEL_REQUEST: '出差申请',
  TRANSFER_REQUEST: '调动申请',
  CONTRACT_RENEWAL: '合同续签',
  ONBOARDING_REQUEST: '入职办理',
  OFFBOARDING_REQUEST: '离职办理',
  PAYROLL_APPROVAL: '薪资审批',
  NOTICE_APPROVAL: '公告审批',
  REQUISITION_APPROVAL: '招聘需求审批',
  OFFER_APPROVAL: 'Offer 审批',
  PERFORMANCE_APPEAL: '绩效申诉',
  ONBOARDING: '入职办理',
  PROBATION_CONFIRM: '转正办理',
  ASSIGNMENT_CHANGE: '调岗/调部门',
  TERMS_CHANGE: '雇佣条款变更',
  OFFBOARD: '离职办理',
  CONTRACT_RENEW: '合同续签',
  REHIRE: '返聘办理',
  'leave.request': '请假申请',
  'attendance.correction': '考勤更正',
  'travel.request': '出差申请',
  'transfer.request': '调动申请',
  'contract.renewal': '合同续签',
  'onboarding.request': '入职办理',
  'offboarding.request': '离职办理',
  'payroll.approval': '薪资审批',
  'notice.approval': '公告审批',
}

export function caseTypeLabel(code: string): string {
  return pick(CASE_TYPES, code, '其他审批事项')
}

const SUBJECT_TYPES: Record<string, string> = {
  employment: '员工任职',
  employee: '员工',
  notice: '公告',
  payroll_run: '薪资批次',
  recruitment_requisition: '招聘需求',
  offer: 'Offer',
  performance_review: '绩效评估',
  person: '员工',
  intent: '异动意图',
  contract: '劳动合同',
  org_unit: '组织单元',
  case: '审批案例',
  requisition: '招聘需求',
  candidate: '候选人',
}

export function subjectTypeLabel(code: string): string {
  return pick(SUBJECT_TYPES, code, '业务对象')
}

const SLA_STATES: Record<string, string> = {
  BREACHED: '已超时',
  ON_TRACK: '进行中',
  NONE: '无时限',
}

export function slaStateLabel(code: string): string {
  return pick(SLA_STATES, code, '无时限')
}

export function slaStateTone(code: string): 'success' | 'warning' | 'danger' | 'muted' {
  if (code === 'BREACHED') return 'danger'
  if (code === 'ON_TRACK') return 'warning'
  return 'muted'
}

const WORKFLOW_NODES: Record<string, string> = {
  start: '发起环节',
  manager_approval: '直属经理审批',
  hr_review: '人事复核',
  approval: '审批处理',
  approve: '审批处理',
}

export function workflowNodeLabel(code: string): string {
  return pick(WORKFLOW_NODES, code, '审批处理中')
}

const DECISION_ACTIONS: Record<string, string> = {
  APPROVE: '同意',
  REJECT: '驳回',
  RETURN: '退回补充',
  WITHDRAW: '撤回',
  DELEGATE: '转交',
  CC: '抄送',
}

export function decisionActionLabel(code: string): string {
  return pick(DECISION_ACTIONS, code, '审批决定')
}

const WORKFLOW_EVENTS: Record<string, string> = {
  'case.created': '案例已创建',
  'case.submitted': '案例已提交',
  'case.withdrawn': '案例已撤回',
  'case.approved': '案例已通过',
  'case.rejected': '案例已驳回',
  'case.returned': '案例已退回',
  'case.cc': '案例已抄送',
  'decision.recorded': '审批决定已记录',
  'workflow.node_advanced': '流程进入下一环节',
  'task.delegated': '审批任务已转交',
  'task.reassigned': '审批任务已改派',
  'task.reassignment_exhausted': '审批任务改派已用尽',
  'effect.created': '生效任务已创建',
  'effect.scheduled': '生效任务已排期',
  'effect.applied': '生效任务已完成',
  'effect.failed': '生效任务失败',
  'effect.status_changed': '生效状态已变更',
}

export function workflowEventLabel(code: string): string {
  return pick(WORKFLOW_EVENTS, code, '流程事件')
}

const EFFECT_TYPES: Record<string, string> = {
  'workflow.noop': '流程占位处理',
  'workflow.notify.external': '外部通知',
  'workflow.leave.reserve': '假期额度占用',
  'workflow.payroll.publish': '薪资批次发布',
  'workflow.contract.renew': '劳动合同续签',
  'probe.local': '本地业务处理',
}

export function effectTypeLabel(code: string): string {
  if (code.startsWith('lifecycle.')) return '员工生命周期更新'
  return pick(EFFECT_TYPES, code, '业务生效处理')
}

const HOLIDAY_TYPES: Record<string, string> = {
  PUBLIC: '法定节假日',
  COMPANY: '公司假日',
  MAKEUP: '调休补班',
}

export function holidayTypeLabel(code: string): string {
  return pick(HOLIDAY_TYPES, code, '其他日期类型')
}

const HOLIDAY_SOURCES: Record<string, string> = {
  ADMIN_PUBLISH: '管理员发布',
  MANUAL: '手工维护',
  IMPORT: '批量导入',
}

export function holidaySourceLabel(code: string | null): string {
  if (code === null) return '系统维护'
  return pick(HOLIDAY_SOURCES, code, '系统维护')
}

const WEEKDAYS: Record<number, string> = {
  1: '周一',
  2: '周二',
  3: '周三',
  4: '周四',
  5: '周五',
  6: '周六',
  7: '周日',
}

export function workweekText(days: number[]): string {
  if (days.length === 0) return '未配置'
  return days.map((day) => WEEKDAYS[day] ?? '工作日').join('、')
}

const TIMEZONES: Record<string, string> = {
  'Asia/Shanghai': '中国标准时间（上海）',
  'Asia/Hong_Kong': '中国香港时间',
  'Asia/Singapore': '新加坡时间',
  UTC: '协调世界时',
  'America/New_York': '美国东部时间',
  'Europe/London': '英国时间',
}

export function timezoneLabel(code: string): string {
  return pick(TIMEZONES, code, '公司配置时区')
}

const FEATURE_FLAGS: Record<string, string> = {
  payroll_enabled: '薪资核算',
  time_enabled: '考勤假期',
  self_service_enabled: '员工自助',
  recruitment_enabled: '招聘管理',
  export_enabled: '报表导出',
}

export function featureFlagLabel(code: string): string {
  return pick(FEATURE_FLAGS, code, '其他平台功能')
}

const FEATURE_DESCRIPTIONS: Record<string, string> = {
  payroll_enabled: '控制薪资批次、工资条与薪资报表的访问。',
  time_enabled: '控制考勤、请假、加班与出差模块的访问。',
  self_service_enabled: '控制员工自助门户的访问。',
  recruitment_enabled: '控制招聘需求、候选人与 Offer 流程的访问。',
  export_enabled: '控制报表与敏感数据导出任务的创建。',
}

export function featureFlagDescription(code: string): string {
  return pick(FEATURE_DESCRIPTIONS, code, '平台运行开关。修改后会写入审计记录。')
}

const PARAMETER_SCOPES: Record<string, string> = {
  COMPANY: '本公司',
}

export function parameterScopeLabel(code: string): string {
  return pick(PARAMETER_SCOPES, code, '其他范围')
}

const NOTIFICATION_TYPES: Record<string, string> = {
  NOTICE_PUBLISHED: '公告发布',
  APPROVAL_PENDING: '待审批提醒',
  CASE_ASSIGNED: '审批任务指派',
  CASE_APPROVED: '审批已通过',
  CASE_REJECTED: '审批已驳回',
}

export function notificationTypeLabel(code: string): string {
  return pick(NOTIFICATION_TYPES, code, '业务通知')
}

const AUTH_EVENT_RESOURCES: Array<[RegExp, string]> = [
  [/^binding\./, '授权绑定'],
  [/^role\./, '角色授权'],
  [/^user\./, '用户账号'],
  [/^mfa\./, '多因素认证'],
  [/^(login|logout|password)\./, '账号会话'],
  [/^security\./, '安全控制'],
]

export function authEventResource(code: string): string {
  for (const [pattern, label] of AUTH_EVENT_RESOURCES) {
    if (pattern.test(code)) return label
  }
  return '平台安全'
}

const AUTH_EVENTS: Record<string, string> = {
  'login.failure': '登录失败',
  'login.succeeded': '登录成功',
  'login.mfa_challenge': '登录二次验证',
  logout: '退出登录',
  'logout.all': '退出全部会话',
  'password.change': '修改密码',
  'mfa.enrolled': '绑定多因素认证',
  'mfa.enroll.failure': '多因素认证绑定失败',
  'mfa.step_up': '多因素二次验证',
  'mfa.step_up.failure': '多因素二次验证失败',
  'binding.created': '授权绑定已创建',
  'binding.revoked': '授权绑定已撤销',
  'security.sod_risk_accepted': '职责分离风险已确认',
  'security.break_glass.activated': '应急授权已启用',
  'security.break_glass.revoked': '应急授权已撤销',
  'user.created': '用户已创建',
  'user.status.changed': '用户状态变更',
  'role.assigned': '角色已分配',
  'role.revoked': '角色已撤销',
  'cli.role.bound': '命令行角色绑定',
  'install.completed': '系统安装完成',
}

export function authEventLabel(code: string): string {
  return pick(AUTH_EVENTS, code, '其他安全事件')
}

const AUTH_RESULTS: Record<string, string> = {
  SUCCESS: '成功',
  FAILURE: '失败',
  DENIED: '已拒绝',
}

export function authResultLabel(code: string): string {
  return pick(AUTH_RESULTS, code, '其他结果')
}

export function authResultTone(code: string): 'success' | 'danger' | 'warning' | 'muted' {
  if (code === 'SUCCESS') return 'success'
  if (code === 'FAILURE') return 'danger'
  if (code === 'DENIED') return 'warning'
  return 'muted'
}

const METADATA_REASONS: Record<string, string> = {
  BAD_CREDENTIALS: '账号或密码不正确',
  LOCKED: '账号已锁定',
  RATE_LIMITED: '尝试过于频繁',
  BAD_TOTP: '动态口令不正确',
  BAD_CURRENT_PASSWORD: '当前密码不正确',
}

const METADATA_KEYS: Record<string, string> = {
  reason: '原因',
  session_id: '会话编号',
  factor_type: '验证因素',
  grant_id: '应急授权编号',
  capability: '权限项',
  anchor_type: '数据范围',
  anchor_id: '范围对象',
  role_code: '角色',
  binding_id: '绑定编号',
  target_user_id: '目标用户',
  method: '方式',
  outcome: '结果',
  risks: '风险项',
}

const ANCHOR_LABELS: Record<string, string> = {
  SELF: '仅本人',
  MANAGED: '下属',
  ORG_UNIT: '本部门',
  ORG_SUBTREE: '本部门及子部门',
  COMPANY: '本公司',
  CASE: '指定案例',
  PAYROLL_RUN: '指定工资批次',
}

const FACTOR_LABELS: Record<string, string> = {
  TOTP: '动态口令',
  WEBAUTHN: '安全密钥',
  RECOVERY_CODE: '恢复码',
}

export type MetadataRow = { label: string; value: string }

export function metadataRows(metadata: Record<string, unknown>): MetadataRow[] {
  const rows: MetadataRow[] = []
  for (const [key, raw] of Object.entries(metadata)) {
    const label = METADATA_KEYS[key]
    if (label === undefined) continue
    let value: string
    if (key === 'reason' && typeof raw === 'string') {
      value = METADATA_REASONS[raw] ?? '其他业务原因'
    } else if (key === 'anchor_type' && typeof raw === 'string') {
      value = ANCHOR_LABELS[raw] ?? '其他数据范围'
    } else if (key === 'factor_type' && typeof raw === 'string') {
      value = FACTOR_LABELS[raw] ?? '其他验证因素'
    } else if (key === 'capability') {
      value = '权限目录项'
    } else if (key === 'role_code' || key === 'method' || key === 'outcome') {
      value = '已记录'
    } else if (Array.isArray(raw)) {
      value = `${raw.length} 项`
    } else if (raw === null || raw === undefined || raw === '') {
      value = '—'
    } else if (typeof raw === 'object') {
      value = '结构化数据'
    } else {
      value = String(raw)
    }
    rows.push({ label, value })
  }
  return rows
}

export function displayValue(value: unknown): string {
  if (value === null || value === undefined) return '空值'
  if (typeof value === 'boolean') return value ? '是' : '否'
  if (typeof value === 'string' || typeof value === 'number') return String(value)
  try {
    return JSON.stringify(value)
  } catch {
    return '结构化数据'
  }
}
