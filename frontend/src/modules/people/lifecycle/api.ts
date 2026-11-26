import { ApiError } from '@/api/errors'
import { resourceGet, resourcePost } from '@/api/resourceClient'
import { nextIdempotencyKey } from '@/modules/people/peopleRead'
import { todayBusinessDate } from '@/utils/format'

/**
 * Employee movement API boundary.
 *
 * The backend is the authority for authorization, effective dating, workflow
 * transitions and assignment writes. This module only normalises real
 * projections and never synthesises a row. Money is transported and rendered
 * as integer minor units end to end; no float conversion is used.
 */

export type MovementKind =
  | 'onboarding'
  | 'probation'
  | 'transfer'
  | 'salary'
  | 'secondment'
  | 'offboarding'

export type MovementTypeDefinition = {
  value: MovementKind
  label: string
  caseType: string | null
  capability: string
  workflow: boolean
}

export const MOVEMENT_TYPES: readonly MovementTypeDefinition[] = [
  {
    value: 'onboarding',
    label: '入职办理',
    caseType: 'ONBOARDING',
    capability: 'lifecycle.onboarding.manage',
    workflow: true,
  },
  {
    value: 'probation',
    label: '试用期转正',
    caseType: 'PROBATION_CONFIRM',
    capability: 'lifecycle.probation.manage',
    workflow: true,
  },
  {
    value: 'transfer',
    label: '调岗/调部门',
    caseType: 'ASSIGNMENT_CHANGE',
    capability: 'lifecycle.transfer.manage',
    workflow: true,
  },
  {
    value: 'salary',
    label: '调薪',
    caseType: 'TERMS_CHANGE',
    capability: 'lifecycle.terms.manage',
    workflow: true,
  },
  {
    value: 'secondment',
    label: '借调',
    caseType: null,
    capability: 'lifecycle.secondment.manage',
    workflow: false,
  },
  {
    value: 'offboarding',
    label: '离职办理',
    caseType: 'OFFBOARD',
    capability: 'lifecycle.offboard.manage',
    workflow: true,
  },
] as const

const WORKFLOW_CASE_TYPES = MOVEMENT_TYPES.filter((type) => type.caseType !== null)
const EMPLOYEE_PAGE_SIZE = 100
const USER_PAGE_SIZE = 100
const MOVEMENT_PAGE_SIZE = 100

export type EmployeeOption = {
  employmentId: number
  name: string
  employeeNo: string
  status: string
  validFrom: string | null
}

export type OrgUnitOption = {
  id: number
  code: string
  name: string
}

export type UserOption = {
  id: number
  displayName: string
  username: string
}

export type WorkflowCase = {
  id: number
  caseNo: string
  type: string
  title: string
  payload: Record<string, unknown>
  applicationStatus: string
  effectStatus: string
  requesterUserId: number
  currentNodeKey: string | null
  submittedAt: string | null
  closedAt: string | null
  businessDate: string
  isMine: boolean
  isAwaitingMe: boolean
}

export type Secondment = {
  assignmentId: number
  employmentId: number
  employeeNo: string
  personName: string
  hostOrgUnitId: number
  positionId: number | null
  validFrom: string
  validTo: string
  assignmentType: string
  isPrimary: boolean
}

export type MovementListRow = {
  rowId: string
  kind: MovementKind
  caseId: number | null
  secondmentAssignmentId: number | null
  caseNo: string
  title: string
  employeeId: number | null
  employeeName: string
  employeeNo: string
  effectiveDate: string | null
  initiatorUserId: number | null
  applicationStatus: string
  effectStatus: string
  isAwaitingMe: boolean
  submittedAt: string | null
}

export type MovementListResult = {
  items: MovementListRow[]
  partial: boolean
  partialReason: string | null
}

export type MovementCaseDetail = {
  lifecycle: {
    caseId: number
    caseNo: string
    caseType: string
    applicationStatus: string
    effectStatus: string
    domainStatus: string
    subjectType: string
    subjectId: number | null
    payload: Record<string, unknown>
    employmentId: number | null
    resultEmploymentId: number | null
    employment: {
      employmentId: number
      personId: number
      employeeNo: string
      status: string
      validFrom: string
      validTo: string
    } | null
    checklist: Array<{
      id: number
      taskCode: string
      title: string
      status: string
      required: boolean
      waiverReason: string | null
      completedAt: string | null
    }>
  }
  workflow: WorkflowCase | null
  timeline: MovementTimelineData | null
  partial: boolean
  partialReason: string | null
}

export type TimelineItem = {
  type: string
  eventType: string
  occurredAt: string | null
  actorUserId: number | null
  payload: Record<string, unknown>
  decisionId: number | null
  action: string | null
  nodeKey: string | null
  comment: string | null
  effectType: string | null
  status: string | null
  attempts: number | null
  requiresEscalation: boolean
  scheduledFor: string | null
}

export type MovementTimelineData = {
  caseId: number
  applicationStatus: string
  submittedAt: string | null
  closedAt: string | null
  effectStatus: string
  items: TimelineItem[]
}

export type MovementCreateResult = {
  caseId: number | null
  assignmentId: number | null
  caseNo: string
  caseType: string
  applicationStatus: string
  effectStatus: string
  replayed: boolean
}

export type StableSubmissionKey = {
  fingerprint: string
  key: string
}

type RawRecord = Record<string, unknown>

function isRecord(value: unknown): value is RawRecord {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function text(value: unknown, fallback = ''): string {
  if (typeof value === 'string') return value
  if (typeof value === 'number' || typeof value === 'boolean') return String(value)
  return fallback
}

function nullableText(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

function integer(value: unknown): number | null {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  if (typeof value === 'string' && /^\d+$/.test(value.trim())) {
    const parsed = Number(value.trim())
    return Number.isSafeInteger(parsed) ? parsed : null
  }
  return null
}

function positiveInteger(value: unknown): number | null {
  const parsed = integer(value)
  return parsed !== null && parsed > 0 ? parsed : null
}

function readItems(value: unknown): RawRecord[] {
  if (Array.isArray(value)) return value.filter(isRecord)
  if (isRecord(value) && Array.isArray(value.items)) return value.items.filter(isRecord)
  return []
}

function readMeta(value: unknown): { total: number; partial: boolean; partialReason: string | null } {
  const payload = isRecord(value) ? value : {}
  const items = readItems(value)
  return {
    total: integer(payload.total) ?? items.length,
    partial: payload.partial === true,
    partialReason: nullableText(payload.partial_reason) ?? nullableText(payload.partialReason),
  }
}

export function movementTypeForCaseType(caseType: string): MovementTypeDefinition | null {
  return MOVEMENT_TYPES.find((type) => type.caseType === caseType) ?? null
}

export function movementTypeForKind(kind: MovementKind): MovementTypeDefinition {
  const definition = MOVEMENT_TYPES.find((type) => type.value === kind)
  if (definition === undefined) {
    throw new TypeError(`未知员工异动类型：${kind}`)
  }
  return definition
}

export function movementKindLabel(kind: MovementKind): string {
  return movementTypeForKind(kind).label
}

export function movementKindForCaseType(caseType: string): MovementKind | null {
  return movementTypeForCaseType(caseType)?.value ?? null
}

export function effectiveDateForPayload(
  kind: MovementKind,
  payload: Record<string, unknown>,
): string | null {
  if (kind === 'onboarding') return nullableText(payload.hire_date)
  if (kind === 'offboarding') return nullableText(payload.last_working_date)
  if (kind === 'secondment') {
    return nullableText(payload.effective_from) ?? nullableText(payload.valid_from)
  }
  return nullableText(payload.effective_date) ?? nullableText(payload.effective_from)
}

export function secondmentEffectiveDate(secondment: Secondment): string {
  return secondment.validFrom
}

export const APPLICATION_STATUS_LABELS: Readonly<Record<string, string>> = {
  DRAFT: '草稿',
  SUBMITTED: '已提交',
  IN_REVIEW: '审批中',
  APPROVED: '已批准',
  REJECTED: '已驳回',
  WITHDRAWN: '已撤回',
  CANCELLED: '已取消',
  SCHEDULED: '已排期',
  ACTIVE: '借调中',
  COMPLETED: '已结束',
  PENDING: '待处理',
}

export const EFFECT_STATUS_LABELS: Readonly<Record<string, string>> = {
  NONE: '未生效',
  PENDING: '待生效',
  SCHEDULED: '已排期',
  APPLIED: '已生效',
  PARTIAL: '部分生效',
  FAILED: '生效失败',
  REVERSED: '已撤销',
}

export const DOMAIN_STATUS_LABELS: Readonly<Record<string, string>> = {
  DRAFT: '未生效',
  EFFECTIVE: '已生效',
  FAILED: '生效失败',
}

export const DECISION_ACTION_LABELS: Readonly<Record<string, string>> = {
  APPROVE: '批准',
  REJECT: '驳回',
  RETURN: '退回',
  WITHDRAW: '撤回',
  DELEGATE: '转交',
  CC: '抄送',
}

export const WORKFLOW_EVENT_LABELS: Readonly<Record<string, string>> = {
  'case.created': '创建审批案例',
  'case.submitted': '提交审批',
  'decision.recorded': '记录审批决定',
  'effect.status_changed': '生效状态变更',
  'case.withdrawn': '撤回案例',
}

export const TERMINATION_TYPE_LABELS: Readonly<Record<string, string>> = {
  RESIGNATION: '主动离职',
  TERMINATION: '公司解除',
  RETIREMENT: '退休',
  CONTRACT_END: '合同到期',
  MUTUAL_AGREEMENT: '协商一致',
}

export function statusLabel(value: string): string {
  return APPLICATION_STATUS_LABELS[value] ?? '未知状态'
}

export function effectStatusLabel(value: string): string {
  return EFFECT_STATUS_LABELS[value] ?? '未知状态'
}

export function domainStatusLabel(value: string): string {
  return DOMAIN_STATUS_LABELS[value] ?? '未知状态'
}

export function decisionActionLabel(value: string): string {
  return DECISION_ACTION_LABELS[value] ?? '未知动作'
}

export function workflowEventLabel(value: string): string {
  return WORKFLOW_EVENT_LABELS[value] ?? '工作流事件'
}

export function terminationTypeLabel(value: string): string {
  return TERMINATION_TYPE_LABELS[value] ?? '其他离职类型'
}

export function isOpenApplicationStatus(value: string): boolean {
  return value === 'DRAFT' || value === 'SUBMITTED' || value === 'IN_REVIEW'
}

export function isDecidedApplicationStatus(value: string): boolean {
  return value === 'APPROVED' || value === 'REJECTED' || value === 'WITHDRAWN' || value === 'CANCELLED'
}

export function allowedMovementKinds(capabilities: readonly string[]): MovementKind[] {
  return MOVEMENT_TYPES.filter((type) => capabilities.includes(type.capability)).map(
    (type) => type.value,
  )
}

function normaliseWorkflowCase(raw: RawRecord): WorkflowCase | null {
  const id = positiveInteger(raw.id)
  if (id === null) return null
  return {
    id,
    caseNo: text(raw.case_no),
    type: text(raw.type).toUpperCase(),
    title: text(raw.title),
    payload: isRecord(raw.payload) ? { ...raw.payload } : {},
    applicationStatus: text(raw.application_status, 'DRAFT').toUpperCase(),
    effectStatus: text(raw.effect_status, 'NONE').toUpperCase(),
    requesterUserId: integer(raw.requester_user_id) ?? 0,
    currentNodeKey: nullableText(raw.current_node_key),
    submittedAt: nullableText(raw.submitted_at),
    closedAt: nullableText(raw.closed_at),
    businessDate: text(raw.business_date),
    isMine: raw.is_mine === true,
    isAwaitingMe: raw.is_awaiting_me === true,
  }
}

function normaliseSecondment(raw: RawRecord): Secondment | null {
  const assignmentId = positiveInteger(raw.assignment_id)
  const employmentId = positiveInteger(raw.employment_id)
  if (assignmentId === null || employmentId === null) return null
  return {
    assignmentId,
    employmentId,
    employeeNo: text(raw.employee_no),
    personName: text(raw.person_name),
    hostOrgUnitId: positiveInteger(raw.host_org_unit_id) ?? 0,
    positionId: positiveInteger(raw.position_id),
    validFrom: text(raw.valid_from),
    validTo: text(raw.valid_to),
    assignmentType: text(raw.assignment_type, 'SECONDMENT'),
    isPrimary: raw.is_primary === true,
  }
}

export async function listWorkflowCases(type?: string): Promise<{
  items: WorkflowCase[]
  partial: boolean
  partialReason: string | null
}> {
  const raw = await resourceGet<unknown>('/v1/cases', {
    ...(type === undefined ? {} : { type }),
    page: 1,
    page_size: MOVEMENT_PAGE_SIZE,
  })
  const items = readItems(raw)
    .map(normaliseWorkflowCase)
    .filter((row): row is WorkflowCase => row !== null)
  const meta = readMeta(raw)
  const partial = meta.partial || meta.total > items.length
  return {
    items,
    partial,
    partialReason: partial
      ? (meta.partialReason ?? `工作流案例超过单次读取上限，已加载 ${items.length} / ${meta.total} 条。`)
      : null,
  }
}

export async function listMovementCases(): Promise<MovementListResult> {
  const results = await Promise.allSettled(
    WORKFLOW_CASE_TYPES.map((type) => listWorkflowCases(type.caseType ?? undefined)),
  )

  const items: WorkflowCase[] = []
  const partialReasons: string[] = []
  let failures = 0

  results.forEach((result, index) => {
    const definition = WORKFLOW_CASE_TYPES[index]
    if (result.status === 'fulfilled') {
      items.push(...result.value.items)
      if (result.value.partial) {
        partialReasons.push(`${definition?.label ?? '异动'}：${result.value.partialReason ?? '部分数据'}`)
      }
      return
    }
    failures += 1
    if (result.reason instanceof ApiError) {
      partialReasons.push(`${definition?.label ?? '异动'}暂不可用（${result.reason.code}）`)
    } else {
      partialReasons.push(`${definition?.label ?? '异动'}暂不可用`)
    }
  })

  if (results.length > 0 && failures === results.length) {
    const first = results[0]
    throw first !== undefined && first.status === 'rejected'
      ? first.reason
      : new Error('员工异动案例暂不可用。')
  }

  const rowsById = new Map<number, WorkflowCase>()
  for (const item of items) rowsById.set(item.id, item)

  return {
    items: [...rowsById.values()].map(workflowCaseToRow).sort(compareMovementRows),
    partial: partialReasons.length > 0,
    partialReason: partialReasons.join('；'),
  }
}

function workflowCaseToRow(workflowCase: WorkflowCase): MovementListRow {
  const kind = movementKindForCaseType(workflowCase.type)
  if (kind === null) {
    throw new TypeError(`工作流案例 ${workflowCase.caseNo || workflowCase.id} 不是可识别的员工异动类型。`)
  }
  const employmentId = positiveInteger(workflowCase.payload.employment_id)
  const person = isRecord(workflowCase.payload.person) ? workflowCase.payload.person : {}
  const employeeName =
    kind === 'onboarding'
      ? text(person.legal_name) || text(person.preferred_name)
      : ''
  const employeeNo = text(workflowCase.payload.employee_no)

  return {
    rowId: String(workflowCase.id),
    kind,
    caseId: workflowCase.id,
    secondmentAssignmentId: null,
    caseNo: workflowCase.caseNo,
    title: workflowCase.title,
    employeeId: employmentId,
    employeeName,
    employeeNo,
    effectiveDate: effectiveDateForPayload(kind, workflowCase.payload),
    initiatorUserId: workflowCase.requesterUserId > 0 ? workflowCase.requesterUserId : null,
    applicationStatus: workflowCase.applicationStatus,
    effectStatus: workflowCase.effectStatus,
    isAwaitingMe: workflowCase.isAwaitingMe,
    submittedAt: workflowCase.submittedAt,
  }
}

export async function listSecondments(): Promise<{
  items: Secondment[]
  partial: boolean
  partialReason: string | null
}> {
  const asOf = todayBusinessDate()
  const raw = await resourceGet<unknown>('/v1/lifecycle/secondments', {
    as_of: asOf,
    page: 1,
    page_size: MOVEMENT_PAGE_SIZE,
  })
  const items = readItems(raw)
    .map(normaliseSecondment)
    .filter((row): row is Secondment => row !== null)
  const meta = readMeta(raw)
  const partial = meta.partial || meta.total > items.length
  return {
    items,
    partial,
    partialReason: partial
      ? (meta.partialReason ?? `借调任职超过单次读取上限，已加载 ${items.length} / ${meta.total} 条。`)
      : null,
  }
}

export function secondmentToRow(secondment: Secondment, asOf = todayBusinessDate()): MovementListRow {
  const status = secondmentWindowStatus(secondment, asOf)
  return {
    rowId: `s-${secondment.assignmentId}`,
    kind: 'secondment',
    caseId: null,
    secondmentAssignmentId: secondment.assignmentId,
    caseNo: `借调任职 #${secondment.assignmentId}`,
    title: `借调至组织 #${secondment.hostOrgUnitId}`,
    employeeId: secondment.employmentId,
    employeeName: secondment.personName,
    employeeNo: secondment.employeeNo,
    effectiveDate: secondment.validFrom,
    initiatorUserId: null,
    applicationStatus: status.application,
    effectStatus: status.effect,
    isAwaitingMe: false,
    submittedAt: null,
  }
}

function secondmentWindowStatus(
  secondment: Secondment,
  asOf: string,
): { application: string; effect: string } {
  if (secondment.validFrom > asOf) return { application: 'SCHEDULED', effect: 'SCHEDULED' }
  if (secondment.validTo <= asOf) return { application: 'COMPLETED', effect: 'REVERSED' }
  return { application: 'ACTIVE', effect: 'APPLIED' }
}

function compareMovementRows(left: MovementListRow, right: MovementListRow): number {
  const leftDate = left.effectiveDate ?? ''
  const rightDate = right.effectiveDate ?? ''
  return rightDate.localeCompare(leftDate) || right.rowId.localeCompare(left.rowId)
}

/**
 * Fallback read seam when the caller has lifecycle.case.read but not
 * workflow.case.read. It returns only the fields the lifecycle projection
 * actually carries; names and plan dates are deliberately left blank rather
 * than invented.
 */
export async function listLifecycleMovementCases(): Promise<MovementListResult> {
  const raw = await resourceGet<unknown>('/v1/lifecycle/cases', {
    page: 1,
    page_size: MOVEMENT_PAGE_SIZE,
  })
  const meta = readMeta(raw)
  const items: MovementListRow[] = []

  for (const row of readItems(raw)) {
    const caseId = positiveInteger(row.case_id)
    const caseType = text(row.case_type).toUpperCase()
    const kind = movementKindForCaseType(caseType)
    if (caseId === null || kind === null) continue
    items.push({
      rowId: String(caseId),
      kind,
      caseId,
      secondmentAssignmentId: null,
      caseNo: text(row.case_no),
      title: text(row.case_type),
      employeeId: positiveInteger(row.employment_id),
      employeeName: '',
      employeeNo: '',
      // The lifecycle collection returns the effect-application timestamp,
      // not the planned business effective date. Never rebadge one as the other.
      effectiveDate: null,
      initiatorUserId: null,
      applicationStatus: text(row.application_status, 'DRAFT').toUpperCase(),
      effectStatus: text(row.effect_status, 'NONE').toUpperCase(),
      isAwaitingMe: false,
      submittedAt: null,
    })
  }

  const partial = meta.partial || meta.total > items.length
  return {
    items: items.sort(compareMovementRows),
    partial,
    partialReason: partial ? (meta.partialReason ?? '生命周期投影为部分数据。') : null,
  }
}

export async function listEmployees(asOf = todayBusinessDate()): Promise<EmployeeOption[]> {
  const raw = await resourceGet<unknown>('/v1/employees', {
    as_of: asOf,
    page: 1,
    page_size: EMPLOYEE_PAGE_SIZE,
  })
  return readItems(raw)
    .map((row) => {
      const employmentId = positiveInteger(row.employment_id)
      if (employmentId === null) return null
      return {
        employmentId,
        name: text(row.person_name) || `员工 #${employmentId}`,
        employeeNo: text(row.employee_no),
        status: text(row.status),
        validFrom: nullableText(row.valid_from),
      }
    })
    .filter((row): row is EmployeeOption => row !== null)
    .sort((left, right) => left.employeeNo.localeCompare(right.employeeNo))
}

export async function listOrgUnits(asOf = todayBusinessDate()): Promise<OrgUnitOption[]> {
  const raw = await resourceGet<unknown>('/v1/departments', { as_of: asOf })
  return readItems(raw)
    .map((row) => {
      const id = positiveInteger(row.id)
      if (id === null) return null
      return {
        id,
        code: text(row.code),
        name: text(row.name) || `组织 #${id}`,
      }
    })
    .filter((row): row is OrgUnitOption => row !== null)
    .sort((left, right) => left.code.localeCompare(right.code))
}

export async function listUsers(): Promise<UserOption[]> {
  const raw = await resourceGet<unknown>('/v1/users', { page: 1, page_size: USER_PAGE_SIZE })
  return readItems(raw)
    .map((row) => {
      const id = positiveInteger(row.id)
      if (id === null) return null
      return {
        id,
        username: text(row.username),
        displayName: text(row.display_name) || text(row.username) || `用户 #${id}`,
      }
    })
    .filter((row): row is UserOption => row !== null)
}

function normaliseLifecycleDetail(raw: unknown): MovementCaseDetail['lifecycle'] {
  const payload = isRecord(raw) ? raw : {}
  const caseId = positiveInteger(payload.case_id)
  if (caseId === null) {
    throw new TypeError('生命周期案例详情接口缺少 case_id。')
  }
  const employment = isRecord(payload.employment) ? payload.employment : null
  return {
    caseId,
    caseNo: text(payload.case_no),
    caseType: text(payload.case_type).toUpperCase(),
    applicationStatus: text(payload.application_status, 'DRAFT').toUpperCase(),
    effectStatus: text(payload.effect_status, 'NONE').toUpperCase(),
    domainStatus: text(payload.domain_status, 'DRAFT').toUpperCase(),
    subjectType: text(payload.subject_type),
    subjectId: integer(payload.subject_id),
    payload: isRecord(payload.payload) ? { ...payload.payload } : {},
    employmentId: integer(payload.employment_id),
    resultEmploymentId: integer(payload.result_employment_id),
    employment:
      employment === null
        ? null
        : {
            employmentId: positiveInteger(employment.employment_id) ?? 0,
            personId: integer(employment.person_id) ?? 0,
            employeeNo: text(employment.employee_no),
            status: text(employment.status),
            validFrom: text(employment.valid_from),
            validTo: text(employment.valid_to),
          },
    checklist: Array.isArray(payload.checklist)
      ? payload.checklist.filter(isRecord).map((task) => ({
          id: integer(task.id) ?? 0,
          taskCode: text(task.task_code),
          title: text(task.title),
          status: text(task.status),
          required: task.required === true,
          waiverReason: nullableText(task.waiver_reason),
          completedAt: nullableText(task.completed_at),
        }))
      : [],
  }
}

function normaliseTimelineItem(raw: RawRecord): TimelineItem {
  return {
    type: text(raw.type),
    eventType: text(raw.event_type),
    occurredAt: nullableText(raw.occurred_at),
    actorUserId: integer(raw.actor_user_id),
    payload: isRecord(raw.payload) ? { ...raw.payload } : {},
    decisionId: integer(raw.decision_id),
    action: nullableText(raw.action)?.toUpperCase() ?? null,
    nodeKey: nullableText(raw.node_key),
    comment: nullableText(raw.comment),
    effectType: nullableText(raw.effect_type),
    status: nullableText(raw.status)?.toUpperCase() ?? null,
    attempts: integer(raw.attempts),
    requiresEscalation: raw.requires_escalation === true,
    scheduledFor: nullableText(raw.scheduled_for),
  }
}

function normaliseTimeline(raw: unknown): MovementTimelineData | null {
  if (!isRecord(raw)) return null
  const application = isRecord(raw.application) ? raw.application : {}
  const effect = isRecord(raw.effect) ? raw.effect : {}
  const caseId = positiveInteger(raw.case_id)
  if (caseId === null || !Array.isArray(raw.items)) return null
  return {
    caseId,
    applicationStatus: text(application.status, 'DRAFT').toUpperCase(),
    submittedAt: nullableText(application.submitted_at),
    closedAt: nullableText(application.closed_at),
    effectStatus: text(effect.status, 'NONE').toUpperCase(),
    items: raw.items.filter(isRecord).map(normaliseTimelineItem),
  }
}

export async function loadMovementCaseDetail(caseId: number): Promise<MovementCaseDetail> {
  const lifecycleRaw = await resourceGet<unknown>(`/v1/lifecycle/cases/${caseId}`)
  const lifecycle = normaliseLifecycleDetail(lifecycleRaw)

  const [workflowResult, timelineResult] = await Promise.allSettled([
    resourceGet<unknown>(`/v1/cases/${caseId}`),
    resourceGet<unknown>(`/v1/cases/${caseId}/timeline`),
  ])

  const partialReasons: string[] = []
  let workflow: WorkflowCase | null = null
  let timeline: MovementTimelineData | null = null

  if (workflowResult.status === 'fulfilled') {
    workflow = isRecord(workflowResult.value) ? normaliseWorkflowCase(workflowResult.value) : null
    if (workflow === null) partialReasons.push('工作流案例详情缺少有效记录')
  } else {
    partialReasons.push('工作流案例详情暂不可用')
  }

  if (timelineResult.status === 'fulfilled') {
    timeline = normaliseTimeline(timelineResult.value)
    if (timeline === null) partialReasons.push('审批时间线缺少有效记录')
  } else {
    partialReasons.push('审批时间线暂不可用')
  }

  // The lifecycle projection is older than a concurrent decision; prefer the
  // workflow's application/effect state when both are present.
  if (workflow !== null) {
    lifecycle.applicationStatus = workflow.applicationStatus
    lifecycle.effectStatus = workflow.effectStatus
  }

  return {
    lifecycle,
    workflow,
    timeline,
    partial: partialReasons.length > 0,
    partialReason: partialReasons.join('；'),
  }
}

export async function loadSecondmentDetail(assignmentId: number): Promise<Secondment | null> {
  const result = await listSecondments()
  return result.items.find((item) => item.assignmentId === assignmentId) ?? null
}

function movementPath(kind: MovementKind): string {
  const paths: Record<MovementKind, string> = {
    onboarding: '/v1/lifecycle/onboarding',
    probation: '/v1/lifecycle/probation-confirmations',
    transfer: '/v1/lifecycle/transfers',
    salary: '/v1/lifecycle/salary-changes',
    secondment: '/v1/lifecycle/secondments',
    offboarding: '/v1/lifecycle/offboardings',
  }
  return paths[kind]
}

export async function createMovement(
  kind: MovementKind,
  payload: Record<string, unknown>,
  submit: boolean,
  idempotencyKey: string,
): Promise<MovementCreateResult> {
  const definition = movementTypeForKind(kind)
  const body = definition.workflow ? { ...payload, submit } : { ...payload }
  const raw = await resourcePost<unknown>(movementPath(kind), body, { idempotencyKey })

  if (!isRecord(raw)) {
    throw new TypeError('员工异动接口未返回结果。')
  }

  return {
    caseId: positiveInteger(raw.case_id),
    assignmentId: positiveInteger(raw.assignment_id),
    caseNo: text(raw.case_no),
    caseType: text(raw.case_type, definition.caseType ?? 'SECONDMENT'),
    applicationStatus: text(raw.application_status, 'DRAFT').toUpperCase(),
    effectStatus: text(raw.effect_status, definition.workflow ? 'NONE' : 'APPLIED').toUpperCase(),
    replayed: raw.replayed === true,
  }
}

export async function submitMovementDecision(
  caseId: number,
  action: 'APPROVE' | 'REJECT',
  comment: string,
  idempotencyKey: string,
): Promise<void> {
  await resourcePost<unknown>(
    `/v1/cases/${caseId}/decisions`,
    { action, ...(comment.trim() === '' ? {} : { comment: comment.trim() }) },
    { idempotencyKey },
  )
}

export function stableSubmissionKey(
  previous: StableSubmissionKey | null,
  prefix: string,
  payload: unknown,
): StableSubmissionKey {
  const fingerprint = JSON.stringify(payload)
  if (previous !== null && previous.fingerprint === fingerprint) return previous
  return { fingerprint, key: nextIdempotencyKey(prefix) }
}

function currencyDigits(currency: string): number {
  return (
    new Intl.NumberFormat('en-US', { style: 'currency', currency }).resolvedOptions()
      .maximumFractionDigits ?? 2
  )
}

/**
 * Format integer minor units without converting the amount to a float.
 * Unsupported currency metadata falls back to an explicit minor-unit string
 * instead of silently assuming two decimal places.
 */
export function formatMinorUnits(
  minor: number | null | undefined,
  currency: string | null | undefined,
): string {
  if (minor === null || minor === undefined || !Number.isSafeInteger(minor)) return '—'
  if (currency === null || currency === undefined || !/^[A-Z]{3}$/.test(currency)) return '—'

  const sign = minor < 0 ? '-' : ''
  const digitsText = String(Math.abs(minor))

  try {
    const digits = currencyDigits(currency)
    const padded = digitsText.padStart(digits + 1, '0')
    const wholeText = digits === 0 ? padded : padded.slice(0, -digits)
    const fractionText = digits === 0 ? '' : padded.slice(-digits)
    const groupedWhole = new Intl.NumberFormat('zh-CN', {
      useGrouping: true,
      maximumFractionDigits: 0,
    }).format(BigInt(wholeText))
    const formatter = new Intl.NumberFormat('zh-CN', {
      style: 'currency',
      currency,
      currencyDisplay: 'narrowSymbol',
      minimumFractionDigits: digits,
      maximumFractionDigits: digits,
    })
    const rendered = formatter
      .formatToParts(0)
      .map((part) => {
        if (part.type === 'integer') return groupedWhole
        if (part.type === 'fraction') return fractionText
        return part.value
      })
      .join('')
    return `${sign}${rendered}`
  } catch {
    return `${sign}${digitsText} ${currency}（最小货币单位）`
  }
}

/** Parse an integer minor-unit input; decimals, signs and spaces are rejected. */
export function parseMinorUnitsInput(value: string): number | null {
  const trimmed = value.trim()
  if (!/^\d+$/.test(trimmed)) return null
  const parsed = Number(trimmed)
  return Number.isSafeInteger(parsed) ? parsed : null
}

export function employeeLabel(employee: EmployeeOption): string {
  return employee.employeeNo === ''
    ? employee.name
    : `${employee.name}（${employee.employeeNo}）`
}

export function orgUnitLabel(orgUnit: OrgUnitOption): string {
  return orgUnit.code === '' ? orgUnit.name : `${orgUnit.name}（${orgUnit.code}）`
}

export function orgUnitName(
  orgUnitId: number | null | undefined,
  orgUnits: readonly OrgUnitOption[],
): string {
  if (orgUnitId === null || orgUnitId === undefined) return '—'
  return orgUnits.find((unit) => unit.id === orgUnitId)?.name ?? `组织 #${orgUnitId}`
}
