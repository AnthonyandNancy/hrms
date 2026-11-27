export type DictionaryEntry = { label: string; tone?: 'success' | 'warning' | 'danger' | 'info' }

/**
 * Stable protocol codes used by forms and comparisons.
 *
 * Templates and user-facing copy reference these aliases instead of raw enum
 * tokens; the values remain byte-for-byte identical to the API contract.
 */
export const DISPLAY_CODES = {
  employeeStatus: {
    active: 'ACTIVE',
    probation: 'PROBATION',
    leave: 'LEAVE',
    onLeave: 'ON_LEAVE',
    suspended: 'SUSPENDED',
    terminated: 'TERMINATED',
    archived: 'ARCHIVED',
  },
  employmentType: {
    fullTime: 'FULL_TIME',
    partTime: 'PART_TIME',
    fixedTerm: 'FIXED_TERM',
    contract: 'CONTRACT',
    intern: 'INTERN',
    contractor: 'CONTRACTOR',
  },
  positionStatus: {
    active: 'ACTIVE',
    disabled: 'DISABLED',
  },
  departmentStatus: {
    active: 'ACTIVE',
    inactive: 'INACTIVE',
  },
  contractType: {
    fixedTerm: 'FIXED_TERM',
    openEnded: 'OPEN_ENDED',
    internship: 'INTERNSHIP',
    consultant: 'CONSULTANT',
  },
  contractStatus: {
    draft: 'DRAFT',
    active: 'ACTIVE',
    effective: 'EFFECTIVE',
    expiring: 'EXPIRING',
    expired: 'EXPIRED',
    renewed: 'RENEWED',
    terminated: 'TERMINATED',
    archived: 'ARCHIVED',
  },
  applicationStatus: {
    draft: 'DRAFT',
    submitted: 'SUBMITTED',
    inReview: 'IN_REVIEW',
    approved: 'APPROVED',
    rejected: 'REJECTED',
    withdrawn: 'WITHDRAWN',
    cancelled: 'CANCELLED',
    scheduled: 'SCHEDULED',
    active: 'ACTIVE',
    completed: 'COMPLETED',
    pending: 'PENDING',
  },
  terminationType: {
    resignation: 'RESIGNATION',
    termination: 'TERMINATION',
    retirement: 'RETIREMENT',
    contractEnd: 'CONTRACT_END',
    mutualAgreement: 'MUTUAL_AGREEMENT',
  },
  userStatus: {
    active: 'ACTIVE',
    locked: 'LOCKED',
    disabled: 'DISABLED',
  },
  fieldProfile: {
    p0Public: 'P0_PUBLIC',
    p1Internal: 'P1_INTERNAL',
    p2Sensitive: 'P2_SENSITIVE',
    p3Restricted: 'P3_RESTRICTED',
  },
  performanceCycleStatus: {
    draft: 'DRAFT',
    active: 'ACTIVE',
    scoring: 'SCORING',
    published: 'PUBLISHED',
  },
  trainingProgramStatus: {
    active: 'ACTIVE',
    archived: 'ARCHIVED',
  },
} as const

const DICTIONARY: Record<string, Record<string, DictionaryEntry>> = {
  'people.employeeStatus': {
    ACTIVE: { label: '在职', tone: 'success' },
    PROBATION: { label: '试用', tone: 'warning' },
    LEAVE: { label: '休假中', tone: 'warning' },
    ON_LEAVE: { label: '休假中', tone: 'warning' },
    SUSPENDED: { label: '停职', tone: 'warning' },
    TERMINATED: { label: '已离职', tone: 'info' },
    ARCHIVED: { label: '已归档', tone: 'info' },
  },
  'people.employmentType': {
    FULL_TIME: { label: '全职', tone: 'success' },
    PART_TIME: { label: '兼职', tone: 'info' },
    FIXED_TERM: { label: '固定期限', tone: 'info' },
    CONTRACT: { label: '合同制', tone: 'info' },
    INTERN: { label: '实习', tone: 'warning' },
    CONTRACTOR: { label: '外包/顾问', tone: 'info' },
  },
  'people.positionStatus': {
    ACTIVE: { label: '启用', tone: 'success' },
    DISABLED: { label: '停用', tone: 'info' },
  },
  'people.departmentStatus': {
    ACTIVE: { label: '启用', tone: 'success' },
    INACTIVE: { label: '停用', tone: 'info' },
  },
  'people.contractType': {
    FIXED_TERM: { label: '固定期限', tone: 'info' },
    OPEN_ENDED: { label: '无固定期限', tone: 'success' },
    INTERNSHIP: { label: '实习', tone: 'warning' },
    CONSULTANT: { label: '顾问', tone: 'info' },
  },
  'people.contractStatus': {
    DRAFT: { label: '草稿', tone: 'info' },
    ACTIVE: { label: '生效中', tone: 'success' },
    EFFECTIVE: { label: '生效中', tone: 'success' },
    EXPIRING: { label: '即将到期', tone: 'warning' },
    EXPIRED: { label: '已到期', tone: 'danger' },
    RENEWED: { label: '已续签', tone: 'info' },
    TERMINATED: { label: '已终止', tone: 'info' },
    ARCHIVED: { label: '已归档', tone: 'info' },
  },
  'people.applicationStatus': {
    DRAFT: { label: '草稿', tone: 'info' },
    SUBMITTED: { label: '已提交', tone: 'info' },
    IN_REVIEW: { label: '审批中', tone: 'warning' },
    APPROVED: { label: '已批准', tone: 'success' },
    REJECTED: { label: '已驳回', tone: 'danger' },
    WITHDRAWN: { label: '已撤回', tone: 'info' },
    CANCELLED: { label: '已取消', tone: 'info' },
    SCHEDULED: { label: '已排期', tone: 'info' },
    ACTIVE: { label: '借调中', tone: 'success' },
    COMPLETED: { label: '已结束', tone: 'info' },
    PENDING: { label: '待处理', tone: 'warning' },
  },
  'people.terminationType': {
    RESIGNATION: { label: '主动离职', tone: 'info' },
    TERMINATION: { label: '公司解除', tone: 'warning' },
    RETIREMENT: { label: '退休', tone: 'info' },
    CONTRACT_END: { label: '合同到期', tone: 'info' },
    MUTUAL_AGREEMENT: { label: '协商一致', tone: 'info' },
  },
  'platform.userStatus': {
    ACTIVE: { label: '在职', tone: 'success' },
    LOCKED: { label: '已锁定', tone: 'warning' },
    DISABLED: { label: '已停用', tone: 'danger' },
  },
  'platform.fieldProfile': {
    P0_PUBLIC: { label: 'P0 公开', tone: 'success' },
    P1_INTERNAL: { label: 'P1 内部', tone: 'info' },
    P2_SENSITIVE: { label: 'P2 敏感', tone: 'warning' },
    P3_RESTRICTED: { label: 'P3 受限', tone: 'danger' },
  },
  'talent.performanceCycleStatus': {
    DRAFT: { label: '草稿', tone: 'info' },
    ACTIVE: { label: '进行中', tone: 'success' },
    SCORING: { label: '评分中', tone: 'warning' },
    PUBLISHED: { label: '已结束', tone: 'info' },
  },
  'talent.trainingProgramStatus': {
    ACTIVE: { label: '启用中', tone: 'success' },
    ARCHIVED: { label: '已归档', tone: 'info' },
  },
  'reporting.reportStatus': {
    ACTIVE: { label: '在职', tone: 'success' },
    PROBATION: { label: '试用期', tone: 'warning' },
    LEAVE: { label: '休假中', tone: 'warning' },
    SUSPENDED: { label: '停职', tone: 'warning' },
    TERMINATED: { label: '已离职', tone: 'info' },
    PENDING: { label: '待处理', tone: 'warning' },
    IN_APPROVAL: { label: '审批中', tone: 'warning' },
    APPROVED: { label: '已批准', tone: 'success' },
    REJECTED: { label: '已驳回', tone: 'danger' },
  },
  'payroll.amountUnit': {
    MINOR_UNITS: { label: '最小货币单位', tone: 'info' },
  },
}

export function translate(
  domain: string,
  field: string,
  code: string | null | undefined,
  fallback = '未知',
): DictionaryEntry {
  if (code === null || code === undefined || code.trim() === '') return { label: '—' }
  const entry = DICTIONARY[`${domain}.${field}`]?.[code]
  if (entry !== undefined) return entry
  if (import.meta.env.DEV) console.warn(`[dictionary] unknown code ${domain}.${field}=${code}`)
  return { label: fallback, tone: 'warning' }
}
