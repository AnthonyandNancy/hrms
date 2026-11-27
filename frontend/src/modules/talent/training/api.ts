import { ApiError } from '@/api/errors'
import { resourceGet, resourcePost } from '@/api/resourceClient'
import { todayBusinessDate } from '@/utils/format'

/**
 * Training workspace API boundary.
 *
 * The backend owns authorization, capacity and the completion state machine.
 * This module only normalises live projections from the real training routes.
 * Cost is transported as integer minor units and formatted at render time.
 * No row is ever synthesised: a missing required identity rejects the row.
 */

export type DeliveryMode = 'ONSITE' | 'ONLINE' | 'HYBRID'

export type ProgramStatus = 'ACTIVE' | 'ARCHIVED'

export type EnrollmentStatus = 'ENROLLED' | 'ATTENDED' | 'COMPLETED' | 'CANCELLED'

export type CompletionResult = 'PASS' | 'FAIL' | 'ATTENDED'

export type TrainingProgram = {
  id: number
  code: string
  title: string
  category: string | null
  deliveryMode: DeliveryMode | null
  capacity: number
  enrolledCount: number
  completedCount: number
  remainingSeats: number
  sessionCount: number
  /** Integer minor units; absent when the caller may not see training cost. */
  trainingCostCents?: number
  status: ProgramStatus
}

export type TrainingSession = {
  id: number
  programId: number
  planId: number | null
  courseId: number | null
  startsOn: string
  endsOn: string
  startsAt: string | null
  endsAt: string | null
  capacity: number
  location: string | null
  status: string
}

export type TrainingEnrollment = {
  id: number
  sessionId: number
  employmentId: number
  status: EnrollmentStatus
  attended: boolean
  completionResult: CompletionResult | null
  score: number | null
  completedAt: string | null
}

export type EnrollmentDisplayRow = TrainingEnrollment & {
  employeeName: string
  employeeNo: string
  sessionLabel: string
}

export type EmployeeOption = {
  employmentId: number
  name: string
  employeeNo: string
  status: string
}

export type ProgramDraft = {
  code: string
  title: string
  defaultCapacity: number
}

export type CreatedProgram = {
  id: number
  code: string
  title: string
  status: ProgramStatus
}

type Raw = Record<string, unknown>

const DELIVERY_MODES: readonly DeliveryMode[] = ['ONSITE', 'ONLINE', 'HYBRID']
const PROGRAM_STATUSES: readonly ProgramStatus[] = ['ACTIVE', 'ARCHIVED']
const ENROLLMENT_STATUSES: readonly EnrollmentStatus[] = [
  'ENROLLED',
  'ATTENDED',
  'COMPLETED',
  'CANCELLED',
]

function isRecord(value: unknown): value is Raw {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function contractError(field: string): never {
  throw new ApiError('INTERNAL', `服务端返回的培训数据不符合契约：${field}。`, { status: 502 })
}

function integer(value: unknown, field: string): number {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  contractError(field)
}

function nullableInteger(value: unknown, field: string): number | null {
  if (value === null || value === undefined) return null
  return integer(value, field)
}

function text(value: unknown, field: string): string {
  if (typeof value === 'string' && value !== '') return value
  contractError(field)
}

function nullableText(value: unknown, field: string): string | null {
  if (value === null || value === undefined || value === '') return null
  if (typeof value === 'string') return value
  contractError(field)
}

function record(value: unknown, field: string): Raw {
  if (isRecord(value)) return value
  contractError(field)
}

function items(value: unknown, field: string): unknown[] {
  if (isRecord(value) && Array.isArray(value.items)) return value.items
  if (Array.isArray(value)) return value
  contractError(field)
}

export function normaliseProgram(raw: unknown): TrainingProgram {
  const row = record(raw, 'program')
  const status = text(row.status, 'status').toUpperCase() as ProgramStatus
  if (!PROGRAM_STATUSES.includes(status)) contractError('status')

  const rawMode = nullableText(row.delivery_mode, 'delivery_mode')
  const deliveryMode = rawMode === null ? null : (rawMode.toUpperCase() as DeliveryMode)
  if (deliveryMode !== null && !DELIVERY_MODES.includes(deliveryMode)) {
    contractError('delivery_mode')
  }

  const cost = row.training_cost_cents

  return {
    id: integer(row.program_id, 'program_id'),
    code: text(row.code, 'code'),
    title: text(row.title, 'title'),
    category: nullableText(row.category, 'category'),
    deliveryMode,
    capacity: integer(row.capacity, 'capacity'),
    enrolledCount: integer(row.enrolled_count, 'enrolled_count'),
    completedCount: integer(row.completed_count, 'completed_count'),
    remainingSeats: integer(row.remaining_seats, 'remaining_seats'),
    sessionCount: integer(row.session_count, 'session_count'),
    trainingCostCents:
      cost === undefined ? undefined : cost === null ? undefined : integer(cost, 'training_cost_cents'),
    status,
  }
}

export function normaliseSession(raw: unknown): TrainingSession {
  const row = record(raw, 'session')
  return {
    id: integer(row.session_id, 'session_id'),
    programId: integer(row.program_id, 'program_id'),
    planId: nullableInteger(row.plan_id, 'plan_id'),
    courseId: nullableInteger(row.course_id, 'course_id'),
    startsOn: text(row.starts_on, 'starts_on'),
    endsOn: text(row.ends_on, 'ends_on'),
    startsAt: nullableText(row.starts_at, 'starts_at'),
    endsAt: nullableText(row.ends_at, 'ends_at'),
    capacity: integer(row.capacity, 'capacity'),
    location: nullableText(row.location, 'location'),
    status: text(row.status, 'status'),
  }
}

export function normaliseEnrollment(raw: unknown): TrainingEnrollment {
  const row = record(raw, 'enrollment')
  const status = text(row.status, 'status').toUpperCase() as EnrollmentStatus
  if (!ENROLLMENT_STATUSES.includes(status)) contractError('status')

  const result = nullableText(row.completion_result, 'completion_result')

  return {
    id: integer(row.enrollment_id, 'enrollment_id'),
    sessionId: integer(row.session_id, 'session_id'),
    employmentId: integer(row.employment_id, 'employment_id'),
    status,
    attended: row.attended === true,
    completionResult: result === null ? null : (result.toUpperCase() as CompletionResult),
    score: nullableInteger(row.score, 'score'),
    completedAt: nullableText(row.completed_at, 'completed_at'),
  }
}

function normaliseEmployee(raw: unknown): EmployeeOption | null {
  if (!isRecord(raw)) return null
  const id = raw.employment_id
  if (typeof id !== 'number' || !Number.isSafeInteger(id) || id <= 0) return null
  const name =
    typeof raw.person_name === 'string' && raw.person_name !== ''
      ? raw.person_name
      : typeof raw.display_name === 'string' && raw.display_name !== ''
        ? raw.display_name
        : `员工 #${id}`
  return {
    employmentId: id,
    name,
    employeeNo: typeof raw.employee_no === 'string' ? raw.employee_no : '',
    status: typeof raw.status === 'string' ? raw.status : '',
  }
}

export function businessDate(): string {
  return todayBusinessDate()
}

export async function listPrograms(asOf = businessDate()): Promise<TrainingProgram[]> {
  const raw = await resourceGet<unknown>('/v1/training/programs', { as_of: asOf })
  return items(raw, 'programs').map(normaliseProgram)
}

export async function listSessions(programId: number): Promise<TrainingSession[]> {
  const raw = await resourceGet<unknown>('/v1/training/sessions', { program_id: programId })
  return items(raw, 'sessions')
    .map(normaliseSession)
    .filter((session) => session.programId === programId)
}

export async function listEnrollments(): Promise<TrainingEnrollment[]> {
  const raw = await resourceGet<unknown>('/v1/training/enrollments')
  return items(raw, 'enrollments').map(normaliseEnrollment)
}

export async function listEmployees(): Promise<EmployeeOption[]> {
  const raw = await resourceGet<unknown>('/v1/employees', {
    as_of: businessDate(),
    page: 1,
    page_size: 100,
    include_archived: false,
  })
  return items(raw, 'employees')
    .map(normaliseEmployee)
    .filter((employee): employee is EmployeeOption => employee !== null)
}

export async function createProgram(draft: ProgramDraft): Promise<CreatedProgram> {
  const raw = await resourcePost<unknown>(
    '/v1/training/programs',
    {
      code: draft.code,
      title: draft.title,
      default_capacity: draft.defaultCapacity,
    },
    { idempotencyKey: newTrainingIdempotencyKey('program') },
  )

  // The create response carries only the stored program identity. Live
  // aggregates arrive on the next list read and are never invented here.
  const row = record(raw, 'program')
  return {
    id: integer(row.program_id, 'program_id'),
    code: text(row.code, 'code'),
    title: text(row.title, 'title'),
    status: text(row.status, 'status').toUpperCase() as ProgramStatus,
  }
}

export async function enroll(sessionId: number, employmentId: number): Promise<TrainingEnrollment> {
  const raw = await resourcePost<unknown>(
    '/v1/training/enrollments',
    { session_id: sessionId, employment_id: employmentId },
    { idempotencyKey: newTrainingIdempotencyKey('enroll') },
  )
  return normaliseEnrollment(raw)
}

export type CompletionInput = {
  completionResult: CompletionResult
  attended: boolean
  score: number | null
  completedOn: string
}

export async function completeEnrollment(
  enrollmentId: number,
  input: CompletionInput,
): Promise<TrainingEnrollment> {
  const body: Record<string, unknown> = {
    completion_result: input.completionResult,
    attended: input.attended,
    completed_on: input.completedOn,
  }
  if (input.score !== null) body.score = input.score

  const raw = await resourcePost<unknown>(
    `/v1/training/enrollments/${enrollmentId}/complete`,
    body,
    { idempotencyKey: newTrainingIdempotencyKey('complete') },
  )
  return normaliseEnrollment(raw)
}

export function newTrainingIdempotencyKey(prefix: string): string {
  const suffix =
    typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`
  return `training-${prefix}-${suffix}`.slice(0, 128)
}

/* ------------------------------------------------------------------ */
/* Render-only Chinese copy. Values never feed back into a domain model. */
/* ------------------------------------------------------------------ */

export const DELIVERY_MODE_LABELS: Record<DeliveryMode, string> = {
  ONSITE: '线下',
  ONLINE: '线上',
  HYBRID: '混合',
}

export const PROGRAM_STATUS_LABELS: Record<ProgramStatus, string> = {
  ACTIVE: '启用中',
  ARCHIVED: '已归档',
}

export const ENROLLMENT_STATUS_LABELS: Record<EnrollmentStatus, string> = {
  ENROLLED: '已报名',
  ATTENDED: '已签到',
  COMPLETED: '已完成',
  CANCELLED: '已取消',
}

export const SESSION_STATUS_LABELS: Record<string, string> = {
  SCHEDULED: '已排期',
  CANCELLED: '已取消',
  COMPLETED: '已结束',
}

export const COMPLETION_RESULT_LABELS: Record<CompletionResult, string> = {
  PASS: '通过',
  FAIL: '未通过',
  ATTENDED: '仅出席',
}

export function deliveryModeLabel(mode: DeliveryMode | null): string {
  return mode === null ? '未设置' : DELIVERY_MODE_LABELS[mode]
}

export function programStatusLabel(status: ProgramStatus): string {
  return PROGRAM_STATUS_LABELS[status]
}

export function enrollmentStatusLabel(status: EnrollmentStatus): string {
  return ENROLLMENT_STATUS_LABELS[status]
}

export function sessionStatusLabel(status: string): string {
  return SESSION_STATUS_LABELS[status] ?? '其他状态'
}

export function isActiveEnrollment(status: EnrollmentStatus): boolean {
  return status === 'ENROLLED' || status === 'ATTENDED'
}

export function enrollmentActionReason(status: EnrollmentStatus): string {
  return isActiveEnrollment(status) ? '' : `当前报名状态为${enrollmentStatusLabel(status)}，不能重复登记完成。`
}

/**
 * Stable Chinese explanation for a failed training command. Raw provider
 * reasons are never shown to the operator.
 */
export function trainingActionErrorCopy(error: unknown): string {
  if (error instanceof ApiError) {
    switch (error.serverReason) {
      case 'TRAINING_CAPACITY_REACHED': {
        const capacity = error.details.capacity
        return typeof capacity === 'number'
          ? `该场次名额已满（容量 ${capacity} 人），无法继续报名。请选择其他场次或联系培训管理员扩容。`
          : '该场次名额已满，无法继续报名。请选择其他场次或联系培训管理员扩容。'
      }
      case 'TRAINING_ENROLLMENT_NOT_ACTIVE':
        return '仅处于“已报名”或“已签到”状态的报名可以登记完成；已取消或已完成的报名不会重复写入。'
      case 'TRAINING_ENROLLMENT_REQUIRED':
        return '该员工尚未报名此场次，不能登记签到或完成。'
      case 'CAPABILITY_DENIED':
        return '当前账号没有执行该培训操作的权限。'
      case 'IDEMPOTENCY_PAYLOAD_MISMATCH':
        return '同一幂等键已用于不同的提交内容，请刷新后重新提交。'
    }

    if (error.code === 'FORBIDDEN') return '当前账号没有执行该培训操作的权限。'
    if (error.code === 'VALIDATION_FAILED') {
      const fields = Object.values(error.fieldErrors).flat()
      if (fields.length > 0) return `提交内容未通过校验：${fields.join('；')}`
      return '提交内容未通过校验，请检查必填项与格式。'
    }
    if (error.code === 'NOT_FOUND') return '未找到该培训记录，可能已被撤回或不在当前数据范围内。'
  }

  return error instanceof Error && error.message !== '' ? error.message : '操作未完成，请稍后重试。'
}
