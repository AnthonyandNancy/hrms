import { ApiError } from '@/api/errors'
import { resourceGet, resourcePatch, resourcePost } from '@/api/resourceClient'

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



function queryParams(query: AttendanceDaysQuery): Record<string, unknown> {
  const params: Record<string, unknown> = {}
  if (query.month !== undefined && query.month !== '') params.month = query.month
  if (query.employeeId !== null && query.employeeId !== undefined) params.employment_id = query.employeeId
  if (query.orgUnitId !== null && query.orgUnitId !== undefined) params.org_unit_id = query.orgUnitId
  return params
}

export async function getAttendanceDays(query: AttendanceDaysQuery = {}): Promise<AttendancePeriod> {
  // GET /v1/time/attendance?month=YYYY-MM is the real self-service contract and
  // the backend currently requires a month token. The caller must supply it;
  // omitting it is an explicit contract error rather than a browser-derived
  // "current month" guess.
  if (query.employeeId !== null && query.employeeId !== undefined) {
    if ((query.month ?? '') === '') {
      throw new ApiError('VALIDATION_FAILED', '指定员工考勤读取需要 from/to 或月份。', { status: 422 })
    }
    return normalisePeriod(await resourceGet<unknown>(`/v1/time/attendance/${query.employeeId}`, rangeParams(query)))
  }

  if ((query.month ?? '') === '') {
    throw new ApiError('VALIDATION_FAILED', '请先选择业务月份；页面不会使用浏览器本地月份替代服务端业务日期。', { status: 422 })
  }

  return normalisePeriod(await resourceGet<unknown>('/v1/time/attendance', queryParams(query)))
}

function rangeParams(query: AttendanceDaysQuery): Record<string, unknown> {
  const params: Record<string, unknown> = {}
  const month = query.month !== undefined && query.month !== '' ? query.month : ''
  if (/^\d{4}-\d{2}$/.test(month)) {
    params.from = `${month}-01`
    const year = Number(month.slice(0, 4))
    const monthNumber = Number(month.slice(5, 7))
    const lastDay = new Date(Date.UTC(year, monthNumber, 0)).getUTCDate()
    params.to = `${month}-${String(lastDay).padStart(2, '0')}`
  }
  if (query.orgUnitId !== null && query.orgUnitId !== undefined) params.org_unit_id = query.orgUnitId
  return params
}

/* ------------------------------------------------------------------ */
/* Scoped attendance detail, punch evidence and shift administration   */
/* ------------------------------------------------------------------ */

export type AttendanceRecord = {
  employmentId: number
  employeeNo: string
  employeeName: string
  businessDate: string
  firstIn: string | null
  lastOut: string | null
  source: string | null
  status: string
  lateMinutes: number
  earlyLeaveMinutes: number
  absenceMinutes: number
  exceptionLabel: string | null
}

export type AttendancePunch = {
  id: number
  employmentId: number
  punchedAtUtc: string
  source: string
  deviceId: string | null
}

export type AttendancePage<T> = {
  items: T[]
  page: number
  pageSize: number
  total: number
  asOf: string
  partial: boolean
  partialReason: string | null
}

export type AttendanceRecordsQuery = {
  employmentId?: number | null
  from: string
  to: string
  page?: number
  pageSize?: number
}

export type ShiftTemplate = {
  id: number
  code: string
  name: string
  startLocalTime: string
  endLocalTime: string
  crossesMidnight: boolean
  breakMinutes: number
  lateGraceMinutes: number
  earlyGraceMinutes: number
  standardHoursDecimal: string
  version: number
  validFrom: string
  validTo: string
  supersededAt: string | null
}

export type AttendanceRuleVersion = {
  id: number
  version: string
  effectiveFrom: string
  checksum: string
  config: Record<string, unknown>
  isEffective: boolean
  createdAt: string | null
}

export type AttendanceSchedule = {
  id: number
  employmentId: number
  businessDate: string
  shiftTemplateId: number
  shiftCode: string | null
  shiftName: string | null
  startLocalTime: string | null
  endLocalTime: string | null
  crossesMidnight: boolean | null
}

export type AttendanceClosure = {
  id: number
  month: string
  status: string
  closedBy: number | null
  closedAt: string | null
  reopenedBy: number | null
  reopenedAt: string | null
  reopenReason: string | null
  currentFactVersionId: number | null
  factVersion: number | null
  sourceHash: string | null
  exceptionCount: number | null
}

export type AttendanceDevice = {
  id: number
  code: string
  name: string
  adapter: string
  health: string
  lastSeenAt: string | null
  active: boolean
}

export type AttendanceRuleInput = {
  version: string
  effectiveFrom: string
  config: Record<string, unknown>
}

export type ShiftTemplateInput = {
  code: string
  name: string
  startLocalTime: string
  endLocalTime: string
  crossesMidnight: boolean
  breakMinutes: number
  lateGraceMinutes: number
  earlyGraceMinutes: number
  standardHoursDecimal: string
  validFrom: string
  validTo: string
}

function pageOf<T>(raw: unknown, normalise: (row: RawRecord) => T | null): AttendancePage<T> {
  const payload = isRecord(raw) ? raw : {}
  const rawItems = Array.isArray(payload.items) ? payload.items : []

  return {
    items: rawItems.filter(isRecord).map(normalise).filter((row): row is T => row !== null),
    page: integer(payload.page, 1),
    pageSize: integer(firstValue(payload, ['page_size', 'pageSize']), 20),
    total: integer(payload.total, rawItems.length),
    asOf: text(firstValue(payload, ['as_of', 'asOf'])),
    partial: payload.partial === true,
    partialReason: nullableText(firstValue(payload, ['partial_reason', 'partialReason'])),
  }
}

function normaliseRecord(raw: RawRecord): AttendanceRecord | null {
  const businessDate = text(firstValue(raw, ['business_date', 'businessDate']))
  const employmentId = nullableInteger(firstValue(raw, ['employment_id', 'employmentId']))
  if (businessDate === '' || employmentId === null) return null

  return {
    employmentId,
    employeeNo: text(firstValue(raw, ['employee_no', 'employeeNo'])),
    employeeName: text(firstValue(raw, ['employee_name', 'employeeName'])),
    businessDate,
    firstIn: nullableText(firstValue(raw, ['first_in', 'firstIn'])),
    lastOut: nullableText(firstValue(raw, ['last_out', 'lastOut'])),
    source: nullableText(firstValue(raw, ['source'])),
    status: text(firstValue(raw, ['status']), 'UNKNOWN').toUpperCase(),
    lateMinutes: integer(firstValue(raw, ['late_minutes', 'lateMinutes'])),
    earlyLeaveMinutes: integer(firstValue(raw, ['early_leave_minutes', 'earlyLeaveMinutes'])),
    absenceMinutes: integer(firstValue(raw, ['absence_minutes', 'absenceMinutes'])),
    exceptionLabel: nullableText(firstValue(raw, ['exception_label', 'exceptionLabel'])),
  }
}

function normalisePunch(raw: RawRecord): AttendancePunch | null {
  const id = nullableInteger(raw.id)
  const employmentId = nullableInteger(firstValue(raw, ['employment_id', 'employmentId']))
  if (id === null || employmentId === null) return null

  return {
    id,
    employmentId,
    punchedAtUtc: text(firstValue(raw, ['punched_at_utc', 'punchedAtUtc'])),
    source: text(firstValue(raw, ['source']), 'UNKNOWN').toUpperCase(),
    deviceId: nullableText(firstValue(raw, ['device_id', 'deviceId'])),
  }
}

function normaliseShift(raw: RawRecord): ShiftTemplate | null {
  const id = nullableInteger(raw.id)
  const code = text(raw.code)
  if (id === null || code === '') return null

  return {
    id,
    code,
    name: text(raw.name, code),
    startLocalTime: text(firstValue(raw, ['start_local_time', 'startLocalTime'])),
    endLocalTime: text(firstValue(raw, ['end_local_time', 'endLocalTime'])),
    crossesMidnight: firstValue(raw, ['crosses_midnight', 'crossesMidnight']) === true,
    breakMinutes: integer(firstValue(raw, ['break_minutes', 'breakMinutes'])),
    lateGraceMinutes: integer(firstValue(raw, ['late_grace_minutes', 'lateGraceMinutes'])),
    earlyGraceMinutes: integer(firstValue(raw, ['early_grace_minutes', 'earlyGraceMinutes'])),
    standardHoursDecimal: text(firstValue(raw, ['standard_hours_decimal', 'standardHoursDecimal'])),
    version: integer(raw.version, 1),
    validFrom: text(firstValue(raw, ['valid_from', 'validFrom'])),
    validTo: text(firstValue(raw, ['valid_to', 'validTo'])),
    supersededAt: nullableText(firstValue(raw, ['superseded_at', 'supersededAt'])),
  }
}

function normaliseRule(raw: RawRecord): AttendanceRuleVersion | null {
  const id = nullableInteger(raw.id)
  const version = text(raw.version)
  if (id === null || version === '') return null

  return {
    id,
    version,
    effectiveFrom: text(firstValue(raw, ['effective_from', 'effectiveFrom'])),
    checksum: text(raw.checksum),
    config: isRecord(raw.config) ? raw.config : {},
    isEffective: firstValue(raw, ['is_effective', 'isEffective']) === true,
    createdAt: nullableText(firstValue(raw, ['created_at', 'createdAt'])),
  }
}

function normaliseSchedule(raw: RawRecord): AttendanceSchedule | null {
  const id = nullableInteger(raw.id)
  const employmentId = nullableInteger(firstValue(raw, ['employment_id', 'employmentId']))
  const businessDate = text(firstValue(raw, ['business_date', 'businessDate']))
  if (id === null || employmentId === null || businessDate === '') return null
  const crossValue = firstValue(raw, ['crosses_midnight', 'crossesMidnight'])

  return {
    id,
    employmentId,
    businessDate,
    shiftTemplateId: integer(firstValue(raw, ['shift_template_id', 'shiftTemplateId'])),
    shiftCode: nullableText(firstValue(raw, ['shift_code', 'shiftCode'])),
    shiftName: nullableText(firstValue(raw, ['shift_name', 'shiftName'])),
    startLocalTime: nullableText(firstValue(raw, ['start_local_time', 'startLocalTime'])),
    endLocalTime: nullableText(firstValue(raw, ['end_local_time', 'endLocalTime'])),
    crossesMidnight: crossValue === true ? true : crossValue === false ? false : null,
  }
}

function normaliseClosure(raw: RawRecord): AttendanceClosure | null {
  const id = nullableInteger(raw.id)
  const month = text(raw.month)
  if (id === null || month === '') return null

  return {
    id,
    month,
    status: text(raw.status, 'UNKNOWN').toUpperCase(),
    closedBy: nullableInteger(firstValue(raw, ['closed_by', 'closedBy'])),
    closedAt: nullableText(firstValue(raw, ['closed_at', 'closedAt'])),
    reopenedBy: nullableInteger(firstValue(raw, ['reopened_by', 'reopenedBy'])),
    reopenedAt: nullableText(firstValue(raw, ['reopened_at', 'reopenedAt'])),
    reopenReason: nullableText(firstValue(raw, ['reopen_reason', 'reopenReason'])),
    currentFactVersionId: nullableInteger(firstValue(raw, ['current_fact_version_id', 'currentFactVersionId'])),
    factVersion: nullableInteger(firstValue(raw, ['fact_version', 'factVersion'])),
    sourceHash: nullableText(firstValue(raw, ['source_hash', 'sourceHash'])),
    exceptionCount: nullableInteger(firstValue(raw, ['exception_count', 'exceptionCount'])),
  }
}

function normaliseDevice(raw: RawRecord): AttendanceDevice | null {
  const id = nullableInteger(raw.id)
  const code = text(raw.code)
  if (id === null || code === '') return null

  return {
    id,
    code,
    name: text(raw.name, code),
    adapter: text(raw.adapter),
    health: text(raw.health, 'UNKNOWN').toUpperCase(),
    lastSeenAt: nullableText(firstValue(raw, ['last_seen_at', 'lastSeenAt'])),
    active: raw.active === true,
  }
}

export async function getAttendanceRecords(query: AttendanceRecordsQuery): Promise<AttendancePage<AttendanceRecord>> {
  return pageOf(
    await resourceGet<unknown>('/v1/time/attendance/records', attendanceRangeParams(query)),
    normaliseRecord,
  )
}

export async function getAttendancePunches(query: AttendanceRecordsQuery): Promise<AttendancePage<AttendancePunch>> {
  return pageOf(
    await resourceGet<unknown>('/v1/time/punches', attendanceRangeParams(query)),
    normalisePunch,
  )
}

/**
 * Export is generated by the server from the same scoped query and audited
 * field whitelist as the list. This client only triggers the browser download
 * of the returned CSV bytes; it never assembles export rows from page state.
 */
export async function exportAttendanceRecords(
  query: Omit<AttendanceRecordsQuery, 'page' | 'pageSize'>,
): Promise<{ filename: string; content: string }> {
  const params = attendanceRangeParams(query)
  const response = await rawGet('/v1/time/attendance/records/export', params)

  return {
    filename: `attendance-${query.from}-${query.to}.csv`,
    content: await response.text(),
  }
}

/**
 * Trigger the browser download from CSV bytes returned by the audited export
 * endpoint. The filename is deterministic from the same business-date filter;
 * no row content is rebuilt in the browser.
 */
export function saveAttendanceExport(file: { filename: string; content: string }): void {
  const blob = new Blob([file.content], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = file.filename
  link.rel = 'noopener'
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

async function rawGet(path: string, params: Record<string, unknown>): Promise<Response> {
  const base = import.meta.env.VITE_API_BASE_URL ?? '/v1'
  const baseUrl = new URL(base, window.location.origin)
  const url = new URL(path.startsWith('/v1/') ? path.slice(3) : path, baseUrl)
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value))
  }
  const response = await fetch(url.toString(), {
    credentials: 'include',
    headers: { Accept: 'text/csv' },
  })
  if (!response.ok) {
    throw new ApiError(response.status === 403 ? 'FORBIDDEN' : 'INTERNAL', '考勤明细导出未完成。', {
      status: response.status,
    })
  }
  return response
}

function attendanceRangeParams(query: AttendanceRecordsQuery): Record<string, unknown> {
  const params: Record<string, unknown> = {
    from: query.from,
    to: query.to,
  }
  if (query.employmentId !== undefined && query.employmentId !== null) params.employment_id = query.employmentId
  if (query.page !== undefined) params.page = query.page
  if (query.pageSize !== undefined) params.page_size = query.pageSize
  return params
}

export async function getShiftTemplates(asOf = ''): Promise<ShiftTemplate[]> {
  // GET /v1/time/shifts is the existing read contract. It is intentionally
  // capability-gated and currently returns the full template history; the
  // optional as-of parameter is only sent to the new listing controller when
  // the parent integrates it. Until then the existing endpoint ignores it.
  const raw = await resourceGet<unknown>('/v1/time/shifts', asOf === '' ? {} : { as_of: asOf })
  const payload = isRecord(raw) ? raw : {}
  const items = Array.isArray(payload.items) ? payload.items : []

  return items.filter(isRecord).map(normaliseShift).filter((row): row is ShiftTemplate => row !== null)
}

export async function createShiftTemplate(
  input: ShiftTemplateInput,
  idempotencyKey: string,
): Promise<ShiftTemplate> {
  const raw = await resourcePost<RawRecord>('/v1/time/shifts', shiftBody(input), { idempotencyKey })
  const row = normaliseShift(raw)
  if (row === null) throw new ApiError('INTERNAL', '班次创建响应缺少必要字段。', { status: 500 })
  return row
}

export async function updateShiftTemplate(
  id: number,
  changes: Partial<ShiftTemplateInput>,
  idempotencyKey: string,
): Promise<ShiftTemplate> {
  const raw = await resourcePatch<RawRecord>(`/v1/time/shifts/${id}`, shiftBody(changes), { idempotencyKey })
  const row = normaliseShift(raw)
  if (row === null) throw new ApiError('INTERNAL', '班次更新响应缺少必要字段。', { status: 500 })
  return row
}

export async function disableShiftTemplate(
  id: number,
  reason: string,
  idempotencyKey: string,
): Promise<ShiftTemplate> {
  const raw = await resourcePost<RawRecord>(`/v1/time/shifts/${id}/disable`, { reason }, { idempotencyKey })
  const row = normaliseShift(raw)
  if (row === null) throw new ApiError('INTERNAL', '班次停用响应缺少必要字段。', { status: 500 })
  return row
}

function shiftBody(input: Partial<ShiftTemplateInput>): Record<string, unknown> {
  const body: Record<string, unknown> = {}
  if (input.code !== undefined) body.code = input.code
  if (input.name !== undefined) body.name = input.name
  if (input.startLocalTime !== undefined) body.start_local_time = input.startLocalTime
  if (input.endLocalTime !== undefined) body.end_local_time = input.endLocalTime
  if (input.crossesMidnight !== undefined) body.crosses_midnight = input.crossesMidnight
  if (input.breakMinutes !== undefined) body.break_minutes = input.breakMinutes
  if (input.lateGraceMinutes !== undefined) body.late_grace_minutes = input.lateGraceMinutes
  if (input.earlyGraceMinutes !== undefined) body.early_grace_minutes = input.earlyGraceMinutes
  if (input.standardHoursDecimal !== undefined) body.standard_hours_decimal = input.standardHoursDecimal
  if (input.validFrom !== undefined) body.valid_from = input.validFrom
  if (input.validTo !== undefined) body.valid_to = input.validTo
  return body
}

export async function getAttendanceRules(asOf = ''): Promise<AttendanceRuleVersion[]> {
  const raw = await resourceGet<unknown>('/v1/time/attendance/rules', asOf === '' ? {} : { as_of: asOf })
  const payload = isRecord(raw) ? raw : {}
  const items = Array.isArray(payload.items) ? payload.items : []

  return items.filter(isRecord).map(normaliseRule).filter((row): row is AttendanceRuleVersion => row !== null)
}

export async function publishAttendanceRule(
  input: AttendanceRuleInput,
  idempotencyKey: string,
): Promise<AttendanceRuleVersion> {
  const raw = await resourcePost<RawRecord>(
    '/v1/time/attendance/rules',
    {
      version: input.version,
      effective_from: input.effectiveFrom,
      config: input.config,
    },
    { idempotencyKey },
  )
  const row = normaliseRule(raw)
  if (row === null) throw new ApiError('INTERNAL', '规则发布响应缺少必要字段。', { status: 500 })
  return row
}

export async function getAttendanceSchedules(
  from: string,
  to: string,
  page = 1,
  pageSize = 20,
): Promise<AttendancePage<AttendanceSchedule>> {
  return pageOf(
    await resourceGet<unknown>('/v1/time/shift-schedules', { from, to, page, page_size: pageSize }),
    normaliseSchedule,
  )
}

export async function createAttendanceSchedule(
  input: { employmentId: number; businessDate: string; shiftTemplateId: number },
  idempotencyKey: string,
): Promise<{ scheduleId: number; replayed: boolean }> {
  const raw = await resourcePost<RawRecord>(
    '/v1/time/shift-schedules',
    {
      employment_id: input.employmentId,
      business_date: input.businessDate,
      shift_template_id: input.shiftTemplateId,
    },
    { idempotencyKey },
  )

  return {
    scheduleId: integer(firstValue(raw, ['schedule_id', 'scheduleId'])),
    replayed: raw.replayed === true,
  }
}

export async function getAttendanceClosures(
  month = '',
  page = 1,
  pageSize = 20,
): Promise<AttendancePage<AttendanceClosure>> {
  const params: Record<string, unknown> = { page, page_size: pageSize }
  if (month !== '') params.month = month
  return pageOf(await resourceGet<unknown>('/v1/time/attendance/closings', params), normaliseClosure)
}

export type AttendanceClosureCommandResult = {
  closureId: number
  status: string
  replayed: boolean
  factVersion: number | null
  factVersionId: number | null
  sourceHash: string | null
  exceptionCount: number
}

function closureCommandResult(raw: RawRecord, fallbackStatus: string): AttendanceClosureCommandResult {
  return {
    closureId: integer(firstValue(raw, ['closure_id', 'closureId'])),
    status: text(raw.status, fallbackStatus).toUpperCase(),
    replayed: raw.replayed === true,
    factVersion: nullableInteger(firstValue(raw, ['fact_version', 'factVersion'])),
    factVersionId: nullableInteger(firstValue(raw, ['fact_version_id', 'factVersionId'])),
    sourceHash: nullableText(firstValue(raw, ['source_hash', 'sourceHash'])),
    exceptionCount: nullableInteger(firstValue(raw, ['exception_count', 'exceptionCount'])) ?? 0,
  }
}

export async function closeAttendancePeriod(
  month: string,
  idempotencyKey: string,
): Promise<AttendanceClosureCommandResult> {
  const raw = await resourcePost<RawRecord>('/v1/time/attendance/closings', { month }, { idempotencyKey })
  return closureCommandResult(raw, 'CLOSED')
}

export async function reopenAttendancePeriod(
  closureId: number,
  reason: string,
  idempotencyKey: string,
): Promise<AttendanceClosureCommandResult> {
  const raw = await resourcePost<RawRecord>(
    `/v1/time/attendance/closings/${closureId}/reopen`,
    { reason },
    { idempotencyKey },
  )
  return closureCommandResult(raw, 'REOPENED')
}

export async function recalculateAttendancePeriod(
  month: string,
  employmentId: number | null,
  idempotencyKey: string,
): Promise<void> {
  const body: Record<string, unknown> = { month }
  if (employmentId !== null) body.employment_id = employmentId
  await resourcePost<unknown>('/v1/time/attendance/recalculate', body, { idempotencyKey })
}

export async function getAttendanceDevices(): Promise<AttendanceDevice[]> {
  const raw = await resourceGet<unknown>('/v1/time/attendance/devices', { page: 1, page_size: 100, active: true })
  const payload = isRecord(raw) ? raw : {}
  const items = Array.isArray(payload.items) ? payload.items : []

  return items.filter(isRecord).map(normaliseDevice).filter((row): row is AttendanceDevice => row !== null)
}

const EXCEPTION_STATUSES = new Set(['LATE', 'EARLY', 'EARLY_LEAVE', 'ABSENT', 'INCOMPLETE'])

/**
 * There is no separate exceptions endpoint. Exceptions are exactly the day
 * rows whose calculation shows late/early/absence/incomplete status. Deriving
 * them from the already-authorised month payload avoids a second read of the
 * same data and keeps the calendar and exception list on one snapshot.
 */
export function exceptionsFromPeriod(period: AttendancePeriod): AttendanceExceptionPage {
  const exceptions = period.days
    .filter(
      (day) =>
        EXCEPTION_STATUSES.has(day.status) ||
        day.lateMinutes > 0 ||
        day.earlyLeaveMinutes > 0 ||
        day.absenceMinutes > 0,
    )
    .map((day) => ({
      // Day rows have no separate exception id; the business date identifies the
      // exception within this employee's calendar.
      id: null,
      businessDate: day.businessDate,
      employeeId: day.employeeId,
      employeeName: period.employeeName,
      type:
        day.status === 'PRESENT' && day.lateMinutes > 0
          ? 'LATE'
          : day.status === 'PRESENT' && day.earlyLeaveMinutes > 0
            ? 'EARLY'
            : day.status,
      status: 'OPEN' as const,
      label: day.exceptionLabel,
      note: null,
    }))

  return {
    items: exceptions,
    total: exceptions.length,
    partial: period.partial,
    partialReason: period.partialReason,
  }
}

export async function getAttendanceExceptions(query: AttendanceDaysQuery = {}): Promise<AttendanceExceptionPage> {
  return exceptionsFromPeriod(await getAttendanceDays(query))
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
  businessDate: string,
  note: string,
  idempotencyKey: string,
  employmentId: number | null = null,
): Promise<void> {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(businessDate)) {
    throw new ApiError('VALIDATION_FAILED', '异常记录缺少有效业务日期。', { status: 422 })
  }
  if (note.trim() === '') {
    throw new ApiError('VALIDATION_FAILED', '处理说明不能为空。', { status: 422 })
  }

  // There is no separate exception resource. Resolving an exception is the
  // audited attendance correction command, anchored to the real business date.
  const body: Record<string, unknown> = {
    date: businessDate,
    correctionType: 'WRONG_STATUS',
    reason: note.trim(),
  }
  if (employmentId !== null) body.employment_id = employmentId

  await resourcePost<unknown>('/v1/time/attendance/corrections', body, { idempotencyKey })
}

export function newAttendanceIdempotencyKey(prefix: string): string {
  const suffix =
    typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`
  return `${prefix}-${suffix}`.slice(0, 128)
}
