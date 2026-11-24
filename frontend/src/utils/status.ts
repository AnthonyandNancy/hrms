import type {
  ApplicationStatus,
  EffectStatus,
  PayrollRunState,
  NoticeStatus,
  EmployeeStatus,
  AttendanceStatus,
  FieldProfile,
} from '@/api/contracts'

export type StatusTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'muted'

export type StatusPresentation = {
  label: string
  tone: StatusTone
  symbol: string
}

export const APPLICATION_STATUS: Record<ApplicationStatus, StatusPresentation> = {
  DRAFT: { label: '草稿', tone: 'muted', symbol: '·' },
  SUBMITTED: { label: '已提交', tone: 'info', symbol: '↑' },
  IN_APPROVAL: { label: '审批中', tone: 'warning', symbol: '◷' },
  APPROVED: { label: '已通过', tone: 'success', symbol: '✓' },
  REJECTED: { label: '已驳回', tone: 'danger', symbol: '✕' },
  WITHDRAWN: { label: '已撤回', tone: 'neutral', symbol: '↩' },
}

export const EFFECT_STATUS: Record<EffectStatus, StatusPresentation> = {
  SCHEDULED: { label: '待生效', tone: 'info', symbol: '◷' },
  APPLIED: { label: '已生效', tone: 'success', symbol: '✓' },
  FAILED: { label: '生效失败', tone: 'danger', symbol: '!' },
  REVERSED: { label: '已冲正', tone: 'warning', symbol: '↺' },
}

export const EMPLOYEE_STATUS: Record<EmployeeStatus, StatusPresentation> = {
  ACTIVE: { label: '在职', tone: 'success', symbol: '✓' },
  PROBATION: { label: '试用期', tone: 'info', symbol: '◷' },
  ON_LEAVE: { label: '休假中', tone: 'warning', symbol: '◷' },
  TERMINATED: { label: '已离职', tone: 'muted', symbol: '·' },
}

export const ATTENDANCE_STATUS: Record<AttendanceStatus, StatusPresentation> = {
  PRESENT: { label: '正常出勤', tone: 'success', symbol: '✓' },
  LATE: { label: '迟到', tone: 'warning', symbol: '!' },
  ABSENT: { label: '缺勤', tone: 'danger', symbol: '✕' },
  LEAVE: { label: '休假', tone: 'info', symbol: '◷' },
  REMOTE: { label: '远程办公', tone: 'info', symbol: '⌂' },
  REST: { label: '休息日', tone: 'muted', symbol: '·' },
  HOLIDAY: { label: '法定节假日', tone: 'neutral', symbol: '·' },
}

export const PAYROLL_STATE: Record<PayrollRunState, StatusPresentation> = {
  DRAFT: { label: '草稿', tone: 'muted', symbol: '·' },
  INPUT_LOCKED: { label: '输入已锁定', tone: 'info', symbol: '▣' },
  CALCULATED: { label: '已计算', tone: 'info', symbol: 'Σ' },
  REVIEWED: { label: '已复核', tone: 'warning', symbol: '◷' },
  APPROVED: { label: '已审批', tone: 'success', symbol: '✓' },
  PUBLISHED: { label: '已发布', tone: 'success', symbol: '↑' },
  PAID: { label: '已发放', tone: 'success', symbol: '✓' },
  CLOSED: { label: '已关账', tone: 'neutral', symbol: '▣' },
}

export const NOTICE_STATUS: Record<NoticeStatus, StatusPresentation> = {
  DRAFT: { label: '草稿', tone: 'muted', symbol: '·' },
  PUBLISHED: { label: '已发布', tone: 'success', symbol: '✓' },
  RETRACTED: { label: '已撤回', tone: 'neutral', symbol: '↩' },
}

export const FIELD_PROFILE_LABEL: Record<FieldProfile, string> = {
  P0_PUBLIC: 'P0 公开',
  P1_INTERNAL: 'P1 内部',
  P2_SENSITIVE: 'P2 敏感',
  P3_RESTRICTED: 'P3 受限',
}

export function effectStatusText(status: EffectStatus | null | undefined): string {
  if (!status) return '无生效要求'
  return EFFECT_STATUS[status].label
}

export function applicationStatusText(status: ApplicationStatus): string {
  return APPLICATION_STATUS[status].label
}

/**
 * Dashboard metric labels.
 *
 * The API returns a stable English `code` plus an English `label`. Codes are
 * the contract; the label is presentation. Mapping by code here keeps the
 * interface consistently Chinese without making the metric code locale-specific.
 *
 * Unknown codes fall back to the API label so a newly added metric is still
 * visible rather than blank.
 */
export const METRIC_LABEL: Record<string, string> = {
  headcount: '在职人数',
  probation: '试用期人数',
  turnover: '主动离职率',
  pending_approvals: '待审批',
  contract_expiring: '合同到期',
  leave_liability: '假期负债',
  payroll_cost_by_entity: '分法人人力成本',
  recruitment_funnel: '招聘漏斗',
}

export function metricLabel(code: string, fallback?: string | null): string {
  return METRIC_LABEL[code] ?? fallback ?? code
}

/**
 * Metric units. The API uses stable machine units; the interface shows Chinese.
 */
export const METRIC_UNIT_LABEL: Record<string, string> = {
  COUNT: '人',
  MINUTES: '分钟',
  MINOR_UNITS: '分',
  CENTS: '分',
  RATIO: '%',
}

export function metricUnitLabel(unit: string): string {
  return METRIC_UNIT_LABEL[unit] ?? unit
}
