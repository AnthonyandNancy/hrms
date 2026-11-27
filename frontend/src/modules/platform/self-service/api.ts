import { resourceGet } from '@/api/resourceClient'

/**
 * Self-service API boundary.
 *
 * Every operation is a caller-scoped read. The client never sends an
 * employment or user id, so these helpers cannot be turned into an employee
 * picker or an org-wide query. Unknown fields are dropped by an allow-list
 * projection; a missing profile field stays missing so the UI can say
 * "无权查看" instead of inventing a value.
 */
type Raw = Record<string, unknown>

export type SelfServicePhone = {
  display: string | null
  masked: boolean
  profile: string | null
  revealable: boolean
  exportable: boolean
}

export type SelfServiceProfile = {
  employmentId?: number | null
  employeeNo?: string | null
  name?: string | null
  initials?: string | null
  jobTitle?: string | null
  orgName?: string | null
  managerName?: string | null
  workLocation?: string | null
  email?: string | null
  phone?: SelfServicePhone | null
}

export type SelfServicePayslip = {
  id: number
  period: string | null
  payDate: string | null
  netCents: number | null
  state: string | null
}

export type SelfServiceWorkspace = {
  employmentLinked: boolean
  profile: SelfServiceProfile | null
  businessDate: string
  freshness: string | null
  payslips: SelfServicePayslip[]
}

export type SelfServiceTask = {
  taskId: number
  caseId: number
  caseNo: string | null
  title: string | null
  nodeKey: string | null
  applicationStatus: string | null
  effectStatus: string | null
  dueAt: string | null
  slaState: string
}

function isRecord(value: unknown): value is Raw {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function hasOwn(value: Raw, key: string): boolean {
  return Object.prototype.hasOwnProperty.call(value, key)
}

function nullableText(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value : null
}

function nullableInteger(value: unknown): number | null {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  if (typeof value === 'string' && /^-?\d+$/.test(value)) {
    const parsed = Number(value)
    if (Number.isSafeInteger(parsed)) return parsed
  }
  return null
}

function phoneOf(value: unknown): SelfServicePhone | null {
  if (!isRecord(value)) return null

  return {
    display: nullableText(value.display),
    masked: value.masked === true,
    profile: nullableText(value.profile),
    revealable: value.revealable === true,
    exportable: value.exportable === true,
  }
}

/**
 * Copy only fields the self-service contract actually publishes. A sensitive
 * column present in a future payload is intentionally ignored.
 */
function profileOf(value: unknown): SelfServiceProfile | null {
  if (!isRecord(value)) return null

  const profile: SelfServiceProfile = {}

  if (hasOwn(value, 'employmentId')) profile.employmentId = nullableInteger(value.employmentId)
  for (const key of ['employeeNo', 'name', 'initials', 'jobTitle', 'orgName', 'managerName', 'workLocation', 'email'] as const) {
    if (hasOwn(value, key)) profile[key] = nullableText(value[key])
  }
  if (hasOwn(value, 'phone')) profile.phone = phoneOf(value.phone)

  return profile
}

function payslipOf(value: Raw): SelfServicePayslip | null {
  const id = nullableInteger(value.id)
  if (id === null || id <= 0) return null

  return {
    id,
    period: nullableText(value.period),
    payDate: nullableText(value.payDate),
    netCents: nullableInteger(value.netCents),
    state: nullableText(value.state),
  }
}

export async function loadSelfServiceWorkspace(): Promise<SelfServiceWorkspace> {
  const payload = await resourceGet<unknown>('/v1/me/workspace')

  if (!isRecord(payload)) {
    throw new Error('服务端返回的自助投影不符合契约。')
  }

  return {
    employmentLinked: payload.employment_linked === true,
    profile: profileOf(payload.profile),
    businessDate: nullableText(payload.businessDate) ?? '',
    freshness: nullableText(payload.freshness),
    payslips: Array.isArray(payload.payslips)
      ? payload.payslips.filter(isRecord).map(payslipOf).filter((slip): slip is SelfServicePayslip => slip !== null)
      : [],
  }
}

function taskOf(value: Raw): SelfServiceTask | null {
  const taskId = nullableInteger(value.task_id)
  const caseId = nullableInteger(value.case_id)

  if (taskId === null || taskId <= 0 || caseId === null || caseId <= 0) return null

  return {
    taskId,
    caseId,
    caseNo: nullableText(value.case_no),
    title: nullableText(value.title),
    nodeKey: nullableText(value.node_key),
    applicationStatus: nullableText(value.application_status),
    effectStatus: nullableText(value.effect_status),
    dueAt: nullableText(value.due_at),
    slaState: nullableText(value.sla_state) ?? 'NONE',
  }
}

export async function loadMyTasks(): Promise<SelfServiceTask[]> {
  const payload = await resourceGet<unknown>('/v1/tasks/my')
  const rows = Array.isArray(payload)
    ? payload
    : isRecord(payload) && Array.isArray(payload.items)
      ? payload.items
      : null

  if (rows === null) {
    throw new Error('服务端返回的待办投影不符合契约。')
  }

  return rows.filter(isRecord).map(taskOf).filter((task): task is SelfServiceTask => task !== null)
}
