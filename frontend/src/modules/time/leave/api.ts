import { ApiError } from '@/api/errors'
import { resourceGet, resourcePost } from '@/api/resourceClient'

/**
 * Leave workspace API boundary.
 *
 * Every business date, quantity and as-of value comes from the server. The
 * page never derives "today" from the browser clock: empty date fields are
 * intentional, and ledger/transaction dates are rendered exactly as returned.
 */
export type LeaveUnit = 'DAY' | 'HOUR' | string

export type LeaveTypeOption = {
  id: number
  code: string
  name: string
  unit: LeaveUnit
  requiresAttachment: boolean
  allowNegative: boolean
}

export type LeaveBalance = {
  leaveTypeId: number | null
  leaveTypeCode: string
  leaveTypeName: string
  unit: LeaveUnit
  entitled: number
  used: number
  pending: number
  available: number
  asOf: string
  standardDayMinutes: number | null
  expiresOn: string | null
}

export type LeaveRequestStatus =
  | 'DRAFT'
  | 'SUBMITTED'
  | 'IN_REVIEW'
  | 'IN_APPROVAL'
  | 'APPROVED'
  | 'COMPLETED'
  | 'REJECTED'
  | 'WITHDRAWN'
  | 'CANCELLED'
  | string

export type LeaveRequestItem = {
  id: number | null
  caseId: number
  caseNo: string
  applicationStatus: LeaveRequestStatus
  effectStatus: string | null
  leaveTypeId: number | null
  fromDate: string
  toDate: string
  minutes: number
  state: string
}

export type LeavePreviewConflict = {
  code: string
  severity: 'BLOCKING' | 'WARNING' | string
  message: string
}

export type LeavePreview = {
  workingDays: number
  requestedDays: number
  availableBefore: number
  availableAfter: number
  unit: LeaveUnit
  conflicts: LeavePreviewConflict[]
  leaveTypeCode: string
  requestedMinutes: number
  availableBeforeMinutes: number
  availableAfterMinutes: number
  standardDayMinutes: number
}

export type LeaveRequestInput = {
  leaveTypeCode: string
  startDate: string
  endDate: string
  halfDay: boolean
  reason: string
}

export type LeaveRequestResult = {
  caseId: number | null
  caseNo: string
  applicationStatus: LeaveRequestStatus
  effectStatus: string | null
  requestedMinutes: number
}

export type LeaveLedgerEntryType =
  | 'GRANT'
  | 'RESERVE'
  | 'CONSUME'
  | 'RELEASE'
  | 'EXPIRE'
  | 'ADJUST'
  | 'REVERSE'
  | string

export type LeaveLedgerEntry = {
  id: string
  entryType: LeaveLedgerEntryType
  businessDate: string
  quantityMinutes: number
  balanceAfterMinutes: number
  reason: string
  sourceCaseNo: string | null
}

export type LeaveLedger = {
  leaveTypeCode: string
  leaveTypeName: string
  unit: LeaveUnit
  asOf: string
  openingMinutes: number
  closingMinutes: number
  standardDayMinutes: number | null
  entries: LeaveLedgerEntry[]
}

type RawRecord = Record<string, unknown>

function isRecord(value: unknown): value is RawRecord {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function firstValue(row: RawRecord, keys: string[]): unknown {
  for (const key of keys) {
    if (row[key] !== undefined) return row[key]
  }
  return undefined
}

function text(value: unknown, fallback = ''): string {
  if (typeof value === 'string') return value
  if (typeof value === 'number' || typeof value === 'boolean') return String(value)
  return fallback
}

function numberValue(value: unknown, fallback = 0): number {
  if (typeof value === 'number' && Number.isFinite(value)) return value
  if (typeof value === 'string' && /^-?\d+(?:\.\d+)?$/.test(value.trim())) return Number(value)
  return fallback
}

function nullableInteger(value: unknown): number | null {
  const parsed = numberValue(value, Number.NaN)
  return Number.isSafeInteger(parsed) ? parsed : null
}

function nullableText(value: unknown): string | null {
  if (value === null || value === undefined || value === '') return null
  return text(value)
}

function itemsOf(raw: unknown): RawRecord[] {
  if (Array.isArray(raw)) return raw.filter(isRecord)
  if (isRecord(raw) && Array.isArray(raw.items)) return raw.items.filter(isRecord)
  return []
}

function normaliseType(raw: RawRecord): LeaveTypeOption | null {
  const code = text(firstValue(raw, ['code', 'leaveTypeCode', 'leave_type_code']))
  if (code === '') return null

  return {
    id: nullableInteger(firstValue(raw, ['id', 'leaveTypeId', 'leave_type_id'])) ?? 0,
    code,
    name: text(firstValue(raw, ['name', 'leaveTypeName', 'leave_type_name']), code),
    unit: text(firstValue(raw, ['unit']), 'DAY'),
    requiresAttachment: firstValue(raw, ['requires_attachment', 'requiresAttachment']) === true,
    allowNegative: firstValue(raw, ['allow_negative', 'allowNegative']) === true,
  }
}

function normaliseBalance(raw: RawRecord): LeaveBalance | null {
  const code = text(firstValue(raw, ['leaveTypeCode', 'leave_type_code', 'code']))
  if (code === '') return null

  const unit = text(firstValue(raw, ['unit']), 'DAY')
  const standardDayMinutes = nullableInteger(
    firstValue(raw, ['standardDayMinutes', 'standard_day_minutes']),
  )

  /**
   * The balance endpoint exposes both display-day values and canonical
   * minutes. Day-based accounts use the server's already-scaled display value;
   * hourly accounts must use the canonical minutes so a UI label of 小时 can
   * never sit next to a day-scaled number. No value is recalculated.
   */
  function measure(dayKeys: string[], minuteKey: string): number {
    if (unit === 'HOUR') {
      const minutes = firstValue(raw, [minuteKey])
      if (minutes !== undefined) return numberValue(minutes) / 60
    }
    return numberValue(firstValue(raw, dayKeys))
  }

  return {
    leaveTypeId: nullableInteger(firstValue(raw, ['leaveTypeId', 'leave_type_id', 'id'])),
    leaveTypeCode: code,
    leaveTypeName: text(firstValue(raw, ['leaveTypeName', 'leave_type_name', 'name']), code),
    unit,
    // `granted` is the API's server-computed entitlement/credit total. The
    // interface labels it 应享 and never reconstructs it client-side.
    entitled: measure(['entitled', 'granted'], 'grantedMinutes'),
    used: measure(['used'], 'consumedMinutes'),
    pending: measure(['pending', 'reserved'], 'reservedMinutes'),
    available: measure(['available'], 'availableMinutes'),
    asOf: text(firstValue(raw, ['asOf', 'as_of'])),
    standardDayMinutes: standardDayMinutes !== null && standardDayMinutes > 0 ? standardDayMinutes : null,
    expiresOn: nullableText(firstValue(raw, ['expiresOn', 'expires_on'])),
  }
}

function normaliseRequest(raw: RawRecord): LeaveRequestItem | null {
  const caseId = nullableInteger(firstValue(raw, ['caseId', 'case_id', 'id']))
  if (caseId === null || caseId <= 0) return null

  return {
    id: nullableInteger(firstValue(raw, ['id'])),
    caseId,
    caseNo: text(firstValue(raw, ['caseNo', 'case_no']), `CASE-${caseId}`),
    applicationStatus: text(
      firstValue(raw, ['applicationStatus', 'application_status', 'status']),
      'SUBMITTED',
    ).toUpperCase(),
    effectStatus: nullableText(firstValue(raw, ['effectStatus', 'effect_status'])),
    leaveTypeId: nullableInteger(firstValue(raw, ['leaveTypeId', 'leave_type_id'])),
    fromDate: text(firstValue(raw, ['fromDate', 'from_date'])),
    toDate: text(firstValue(raw, ['toDate', 'to_date'])),
    minutes: Math.trunc(numberValue(firstValue(raw, ['minutes']))),
    state: text(firstValue(raw, ['state'])),
  }
}

function normaliseConflict(raw: RawRecord): LeavePreviewConflict {
  return {
    code: text(firstValue(raw, ['code']), 'UNKNOWN'),
    severity: text(firstValue(raw, ['severity']), 'WARNING').toUpperCase(),
    message: text(firstValue(raw, ['message', 'label'])),
  }
}

function normalisePreview(raw: RawRecord): LeavePreview {
  const conflicts = Array.isArray(raw.conflicts) ? raw.conflicts.filter(isRecord).map(normaliseConflict) : []
  const unit = text(firstValue(raw, ['unit']), 'DAY')
  const requestedMinutes = Math.trunc(numberValue(firstValue(raw, ['requestedMinutes', 'requested_minutes'])))
  const availableBeforeMinutes = Math.trunc(
    numberValue(firstValue(raw, ['availableBeforeMinutes', 'available_before_minutes'])),
  )
  const availableAfterMinutes = Math.trunc(
    numberValue(firstValue(raw, ['availableAfterMinutes', 'available_after_minutes'])),
  )

  // The backend's *_days fields are day-scaled for every unit (and
  // requestedDays is 0 for HOUR), so an hourly preview must be displayed from
  // the canonical minute fields. Day previews keep the server's own values.
  const hourly = unit === 'HOUR'

  return {
    workingDays: Math.trunc(numberValue(firstValue(raw, ['workingDays', 'working_days']))),
    requestedDays: hourly
      ? requestedMinutes / 60
      : numberValue(firstValue(raw, ['requestedDays', 'requested_days'])),
    availableBefore: hourly
      ? availableBeforeMinutes / 60
      : numberValue(firstValue(raw, ['availableBefore', 'available_before'])),
    availableAfter: hourly
      ? availableAfterMinutes / 60
      : numberValue(firstValue(raw, ['availableAfter', 'available_after'])),
    unit,
    conflicts,
    leaveTypeCode: text(firstValue(raw, ['leaveTypeCode', 'leave_type_code'])),
    requestedMinutes,
    availableBeforeMinutes,
    availableAfterMinutes,
    standardDayMinutes: Math.trunc(
      numberValue(firstValue(raw, ['standardDayMinutes', 'standard_day_minutes']), 1),
    ),
  }
}

function normaliseLedgerEntry(raw: RawRecord): LeaveLedgerEntry {
  return {
    id: text(firstValue(raw, ['id']), ''),
    entryType: text(firstValue(raw, ['entryType', 'entry_type', 'account']), 'UNKNOWN').toUpperCase(),
    businessDate: text(firstValue(raw, ['businessDate', 'business_date', 'effectiveOn', 'effective_on'])),
    // The backend contract names these `quantity`/`balanceAfter`, but the
    // canonical value is signed integer minutes for every leave type.
    quantityMinutes: Math.trunc(
      numberValue(firstValue(raw, ['quantityMinutes', 'quantity_minutes', 'quantity'])),
    ),
    balanceAfterMinutes: Math.trunc(
      numberValue(firstValue(raw, ['balanceAfterMinutes', 'balance_after_minutes', 'balanceAfter', 'balance_after'])),
    ),
    reason: text(firstValue(raw, ['reason'])),
    sourceCaseNo: nullableText(firstValue(raw, ['sourceCaseNo', 'source_case_no', 'caseNo'])),
  }
}

function normaliseLedger(raw: unknown, standardDayMinutes: number | null): LeaveLedger {
  const payload = isRecord(raw) ? raw : {}
  const rawEntries = firstValue(payload, ['entries'])
  const entries = Array.isArray(rawEntries) ? rawEntries.filter(isRecord).map(normaliseLedgerEntry) : []
  const serverStandardDay = nullableInteger(firstValue(payload, ['standardDayMinutes', 'standard_day_minutes']))

  return {
    leaveTypeCode: text(firstValue(payload, ['leaveTypeCode', 'leave_type_code'])),
    leaveTypeName: text(firstValue(payload, ['leaveTypeName', 'leave_type_name'])),
    unit: text(firstValue(payload, ['unit']), 'DAY'),
    asOf: text(firstValue(payload, ['asOf', 'as_of'])),
    openingMinutes: Math.trunc(numberValue(firstValue(payload, ['openingMinutes', 'opening_minutes', 'opening']))),
    closingMinutes: Math.trunc(numberValue(firstValue(payload, ['closingMinutes', 'closing_minutes', 'closing']))),
    standardDayMinutes:
      serverStandardDay !== null && serverStandardDay > 0 ? serverStandardDay : standardDayMinutes,
    entries,
  }
}

export async function getLeaveTypes(): Promise<LeaveTypeOption[]> {
  const raw = await resourceGet<unknown>('/v1/time/leave/types')
  return itemsOf(raw)
    .map(normaliseType)
    .filter((value): value is LeaveTypeOption => value !== null)
}

export async function getLeaveBalances(): Promise<LeaveBalance[]> {
  const raw = await resourceGet<unknown>('/v1/time/leave/balances')
  return itemsOf(raw)
    .map(normaliseBalance)
    .filter((value): value is LeaveBalance => value !== null)
}

export async function getLeaveRequests(): Promise<LeaveRequestItem[]> {
  const raw = await resourceGet<unknown>('/v1/time/leave/requests')
  return itemsOf(raw)
    .map(normaliseRequest)
    .filter((value): value is LeaveRequestItem => value !== null)
}

export async function previewLeave(input: LeaveRequestInput): Promise<LeavePreview> {
  const raw = await resourcePost<RawRecord>('/v1/time/leave/preview', leaveRequestPayload(input))
  return normalisePreview(raw)
}

export async function submitLeaveRequest(
  input: LeaveRequestInput,
  idempotencyKey: string,
): Promise<LeaveRequestResult> {
  const raw = await resourcePost<RawRecord>(
    '/v1/time/leave/requests',
    leaveRequestPayload(input),
    { idempotencyKey },
  )

  return {
    caseId: nullableInteger(firstValue(raw, ['caseId', 'case_id'])),
    caseNo: text(firstValue(raw, ['caseNo', 'case_no'])),
    applicationStatus: text(
      firstValue(raw, ['applicationStatus', 'application_status']),
      'SUBMITTED',
    ).toUpperCase(),
    effectStatus: nullableText(firstValue(raw, ['effectStatus', 'effect_status'])),
    requestedMinutes: Math.trunc(numberValue(firstValue(raw, ['requestedMinutes', 'requested_minutes']))),
  }
}

export async function cancelLeaveRequest(
  caseId: number,
  reason: string,
  idempotencyKey: string,
): Promise<void> {
  await resourcePost<unknown>(
    `/v1/time/leave/requests/${caseId}/cancel`,
    { reason },
    { idempotencyKey },
  )
}

export async function getLeaveLedger(
  leaveTypeCode: string,
  standardDayMinutes: number | null = null,
): Promise<LeaveLedger> {
  if (leaveTypeCode.trim() === '') {
    throw new ApiError('VALIDATION_FAILED', '请先选择假期类型。', { status: 422 })
  }

  // No as_of is sent: omitting it asks the server to use the company profile's
  // business date, which is the only source for "today" in this workspace.
  const raw = await resourceGet<unknown>('/v1/time/leave/ledger', { leave_type: leaveTypeCode })
  return normaliseLedger(raw, standardDayMinutes)
}

export function leaveRequestPayload(input: LeaveRequestInput): Record<string, unknown> {
  return {
    leaveTypeCode: input.leaveTypeCode,
    startDate: input.startDate,
    endDate: input.endDate,
    halfDay: input.halfDay,
    reason: input.reason.trim(),
  }
}

export function newLeaveIdempotencyKey(prefix: string): string {
  const suffix =
    typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : `${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`
  return `${prefix}-${suffix}`.slice(0, 128)
}

export function leaveUnitLabel(unit: LeaveUnit): string {
  if (unit === 'HOUR') return '小时'
  if (unit === 'DAY') return '天'
  return unit || '单位'
}

export function formatLeaveNumber(value: number): string {
  if (!Number.isFinite(value)) return '—'
  return new Intl.NumberFormat('zh-CN', { maximumFractionDigits: 2 }).format(value)
}

export function formatLeaveQuantity(value: number, unit: LeaveUnit): string {
  return `${formatLeaveNumber(value)} ${leaveUnitLabel(unit)}`
}

export function formatLedgerMinutes(
  minutes: number,
  unit: LeaveUnit,
  standardDayMinutes: number | null,
  signed = false,
): string {
  let converted: { value: number; unit: LeaveUnit }
  if (unit === 'HOUR') {
    converted = { value: minutes / 60, unit: 'HOUR' }
  } else if (unit === 'DAY' && standardDayMinutes !== null && standardDayMinutes > 0) {
    converted = { value: minutes / standardDayMinutes, unit: 'DAY' }
  } else {
    converted = { value: minutes, unit: 'MINUTE' }
  }

  const label = converted.unit === 'MINUTE' ? '分钟' : leaveUnitLabel(converted.unit)
  const prefix = signed && converted.value > 0 ? '+' : ''
  return `${prefix}${formatLeaveNumber(converted.value)} ${label}`
}

export const LEAVE_STATUS_LABEL: Record<string, string> = {
  SUBMITTED: '待审批',
  IN_REVIEW: '审批中',
  IN_APPROVAL: '审批中',
  APPROVED: '已通过',
  REJECTED: '已驳回',
  COMPLETED: '已完成',
  WITHDRAWN: '已撤回',
  CANCELLED: '已撤回',
  RELEASED: '已撤回',
  DRAFT: '草稿',
  REVOKED: '已撤销',
}

export const LEAVE_STATUS_TONE: Record<string, 'pending' | 'approved' | 'rejected' | 'closed'> = {
  DRAFT: 'closed',
  SUBMITTED: 'pending',
  IN_REVIEW: 'pending',
  IN_APPROVAL: 'pending',
  APPROVED: 'approved',
  COMPLETED: 'approved',
  REJECTED: 'rejected',
  WITHDRAWN: 'closed',
  CANCELLED: 'closed',
  RELEASED: 'closed',
}

export const LEDGER_ENTRY_LABEL: Record<string, string> = {
  GRANT: '发放',
  RESERVE: '占用',
  CONSUME: '结算',
  RELEASE: '释放',
  EXPIRE: '到期',
  ADJUST: '调整',
  REVERSE: '冲正',
}

export const PREVIEW_CONFLICT_LABEL: Record<string, string> = {
  INSUFFICIENT_BALANCE: '可用余额不足，无法提交该请假申请。',
  LEAVE_REQUEST_OVERLAP: '所选日期与已有请假申请重叠。',
  ATTENDANCE_PERIOD_CLOSED: '所选日期所在考勤期间已关账。',
  DATE_CONFLICT: '所选日期存在排班冲突。',
}

export function previewConflictText(conflict: LeavePreviewConflict): string {
  return PREVIEW_CONFLICT_LABEL[conflict.code] ?? conflict.message ?? '服务端返回了日期冲突。'
}
