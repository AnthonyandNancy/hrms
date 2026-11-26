import { ApiError } from '@/api/errors'
import { resourceGet, resourcePost } from '@/api/resourceClient'

/**
 * Attendance workspace API boundary.
 *
 * The server returns business dates and times; this module only normalises
 * transport casing. It never derives a business date, month or "today" from
 * the browser clock.
 */
export type AttendanceDayStatus =
  | 'PRESENT'
  | 'LATE'
  | 'EARLY'
  | 'ABSENT'
  | 'LEAVE'
  | 'REMOTE'
  | 'REST'
  | 'HOLIDAY'
  | 'INCOMPLETE'
  | string

export type AttendanceDay = {
  businessDate: string
  status: AttendanceDayStatus
  employeeId: number | null
  employeeName: string
  firstIn: string | null
  lastOut: string | null
  scheduledMinutes: number
  workedMinutes: number
  overtimeMinutes: number
  lateMinutes: number
  earlyLeaveMinutes: number
  absenceMinutes: number
  exceptionLabel: string | null
  ruleVersion: string
}

export type AttendancePeriod = {
  month: string
  timezone: string
  employeeName: string
  employmentId: number | null
  days: AttendanceDay[]
  partial: boolean
  partialReason: string | null
}

export type AttendanceExceptionStatus = 'OPEN' | 'RESOLVED' | 'CLOSED' | string

export type AttendanceExceptionRow = {
  id: number | null
  businessDate: string
  employeeId: number | null
  employeeName: string
  type: string
  status: AttendanceExceptionStatus
  label: string | null
  note: string | null
}

export type AttendanceExceptionPage = {
  items: AttendanceExceptionRow[]
  total: number
  partial: boolean
  partialReason: string | null
}

export type AttendanceDaysQuery = {
  month?: string
  employeeId?: number | null
  orgUnitId?: number | null
}

export type AttendanceCorrectionType = 'MISSING_PUNCH' | 'WRONG_TIME' | 'WRONG_STATUS'

export type AttendanceCorrectionInput = {
  date: string
  correctionType: AttendanceCorrectionType
  reason: string
  firstIn?: string
  lastOut?: string
  employmentId: number | null
}

export type AttendanceCorrectionResult = {
  accepted: boolean
  requestNo: string
  caseId: number | null
  correctionId: number | null
  businessDate: string
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

function integer(value: unknown, fallback = 0): number {
  if (typeof value === 'number' && Number.isFinite(value)) return Math.trunc(value)
  if (typeof value === 'string' && /^-?\d+$/.test(value.trim())) return Number(value)
  return fallback
}

function nullableInteger(value: unknown): number | null {
  const parsed = integer(value, Number.NaN)
  return Number.isSafeInteger(parsed) ? parsed : null
}

function nullableText(value: unknown): string | null {
  if (value === null || value === undefined || value === '') return null
  return text(value)
}

function firstValue(row: RawRecord, keys: string[]): unknown {
  for (const key of keys) {
    if (row[key] !== undefined) return row[key]
  }
  return undefined
}

function normaliseDay(raw: unknown): AttendanceDay | null {
  if (!isRecord(raw)) return null
  const businessDate = text(firstValue(raw, ['business_date', 'businessDate', 'date']))
  if (businessDate === '') return null

  return {
    businessDate,
    status: text(firstValue(raw, ['status', 'attendance_status']), 'UNKNOWN').toUpperCase(),
    employeeId: nullableInteger(firstValue(raw, ['employment_id', 'employee_id', 'employmentId', 'employeeId'])),
    employeeName: text(firstValue(raw, ['employee_name', 'employeeName', 'person_name', 'personName'])),
    firstIn: nullableText(firstValue(raw, ['first_in', 'firstIn', 'clock_in', 'clockIn'])),
    lastOut: nullableText(firstValue(raw, ['last_out', 'lastOut', 'clock_out', 'clockOut'])),
    scheduledMinutes: integer(firstValue(raw, ['scheduled_minutes', 'scheduledMinutes'])),
    workedMinutes: integer(firstValue(raw, ['worked_minutes', 'workedMinutes', 'computed_minutes', 'computedMinutes'])),
    overtimeMinutes: integer(firstValue(raw, ['overtime_minutes', 'overtimeMinutes'])),
    lateMinutes: integer(firstValue(raw, ['late_minutes', 'lateMinutes'])),
    earlyLeaveMinutes: integer(firstValue(raw, ['early_leave_minutes', 'earlyLeaveMinutes'])),
    absenceMinutes: integer(firstValue(raw, ['absence_minutes', 'absenceMinutes'])),
    exceptionLabel: nullableText(firstValue(raw, ['exception_label', 'exceptionLabel'])),
    ruleVersion: text(firstValue(raw, ['rule_version', 'ruleVersion'])),
  }
}

function normalisePeriod(raw: unknown): AttendancePeriod {
  const payload = isRecord(raw) ? raw : {}
  const rawDays = firstValue(payload, ['days', 'items', 'attendance_days', 'attendanceDays'])
  const days = Array.isArray(rawDays)
    ? rawDays.map(normaliseDay).filter((value): value is AttendanceDay => value !== null)
    : []
  const firstDay = days[0]

  return {
    month: text(firstValue(payload, ['month', 'business_month', 'businessMonth']), firstDay?.businessDate.slice(0, 7) ?? ''),
    timezone: text(firstValue(payload, ['timezone', 'business_timezone', 'businessTimezone'])),
    employeeName: text(firstValue(payload, ['employee_name', 'employeeName', 'person_name', 'personName'])),
    employmentId: nullableInteger(firstValue(payload, ['employment_id', 'employmentId', 'employee_id', 'employeeId'])) ?? firstDay?.employeeId ?? null,
    days,
    partial: payload.partial === true,
    partialReason: nullableText(firstValue(payload, ['partial_reason', 'partialReason'])),
  }
}

function normaliseException(raw: unknown): AttendanceExceptionRow | null {
  if (!isRecord(raw)) return null
  const businessDate = text(firstValue(raw, ['business_date', 'businessDate', 'date']))
  if (businessDate === '') return null

  return {
    id: nullableInteger(firstValue(raw, ['id', 'exception_id', 'exceptionId'])),
    businessDate,
    employeeId: nullableInteger(firstValue(raw, ['employment_id', 'employee_id', 'employmentId', 'employeeId'])),
    employeeName: text(firstValue(raw, ['employee_name', 'employeeName', 'person_name', 'personName'])),
    type: text(firstValue(raw, ['type', 'exception_type', 'exceptionType']), 'UNKNOWN').toUpperCase(),
    status: text(firstValue(raw, ['status']), 'OPEN').toUpperCase(),
    label: nullableText(firstValue(raw, ['label', 'exception_label', 'exceptionLabel'])),
    note: nullableText(firstValue(raw, ['note', 'resolution_note', 'resolutionNote'])),
  }
}

function normaliseExceptionPage(raw: unknown): AttendanceExceptionPage {
  const payload = isRecord(raw) ? raw : {}
  const rawItems = firstValue(payload, ['items', 'exceptions'])
  const items = Array.isArray(rawItems)
    ? rawItems.map(normaliseException).filter((value): value is AttendanceExceptionRow => value !== null)
    : []

  return {
    items,
    total: integer(firstValue(payload, ['total', 'count']), items.length),
    partial: payload.partial === true,
    partialReason: nullableText(firstValue(payload, ['partial_reason', 'partialReason'])),
  }
}

function queryParams(query: AttendanceDaysQuery): Record<string, unknown> {
  const params: Record<string, unknown> = {}
  if (query.month !== undefined && query.month !== '') params.month = query.month
  if (query.employeeId !== null && query.employeeId !== undefined) params.employment_id = query.employeeId
  if (query.orgUnitId !== null && query.orgUnitId !== undefined) params.org_unit_id = query.orgUnitId
  return params
}

export async function getAttendanceDays(query: AttendanceDaysQuery = {}): Promise<AttendancePeriod> {
  const raw = await resourceGet<unknown>('/v1/time/attendance/days', queryParams(query))
  return normalisePeriod(raw)
}

export async function getAttendanceExceptions(query: AttendanceDaysQuery = {}): Promise<AttendanceExceptionPage> {
  const raw = await resourceGet<unknown>('/v1/time/attendance/exceptions', queryParams(query))
  return normaliseExceptionPage(raw)
}

export async function requestAttendanceCorrection(
  input: AttendanceCorrectionInput,
  idempotencyKey: string,
): Promise<AttendanceCorrectionResult> {
  const body: Record<string, unknown> = {
    date: input.date,
    correctionType: input.correctionType,
    reason: input.reason,
  }
  if (input.firstIn !== undefined && input.firstIn !== '') body.firstIn = input.firstIn
  if (input.lastOut !== undefined && input.lastOut !== '') body.lastOut = input.lastOut
  if (input.employmentId !== null) body.employment_id = input.employmentId

  const raw = await resourcePost<RawRecord>('/v1/time/attendance/corrections', body, { idempotencyKey })
  return {
    accepted: raw.accepted !== false,
    requestNo: text(firstValue(raw, ['requestNo', 'request_no'])),
    caseId: nullableInteger(firstValue(raw, ['caseId', 'case_id'])),
    correctionId: nullableInteger(firstValue(raw, ['correctionId', 'correction_id'])),
    businessDate: text(firstValue(raw, ['businessDate', 'business_date']), input.date),
  }
}

export async function resolveAttendanceException(
  exceptionId: number,
  note: string,
  idempotencyKey: string,
): Promise<void> {
  if (!Number.isSafeInteger(exceptionId) || exceptionId < 1) {
    throw new ApiError('VALIDATION_FAILED', '异常记录缺少有效编号。', { status: 422 })
  }

  await resourcePost<unknown>(
    `/v1/time/attendance/exceptions/${exceptionId}/resolve`,
    { note },
    { idempotencyKey },
  )
}

export function newAttendanceIdempotencyKey(prefix: string): string {
  const suffix =
    typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`
  return `${prefix}-${suffix}`.slice(0, 128)
}
