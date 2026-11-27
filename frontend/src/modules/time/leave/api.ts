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
  isStatutory: boolean
  isAnnual: boolean
  requiresAttachment: boolean
  allowNegative: boolean
  status: string
  policyVersion: number | null
  policyVersionId: number | null
  entitlementMinutes: number | null
  version: number
}

export type LeavePolicy = {
  id: number
  leaveTypeId: number
  leaveTypeCode: string
  leaveTypeName: string
  unit: LeaveUnit
  version: number
  status: string
  effectiveFrom: string
  effectiveTo: string
  accrualMode: string
  entitlementMinutes: number
  minNoticeDays: number
  maxConsecutiveDays: number | null
  requiresAttachmentOverDays: number | null
  allowHalfDay: boolean
  legalBasis: string | null
  publishedAt: string
  retiredAt: string | null
  retireReason: string | null
}

export type LeavePolicyInput = {
  leave_type_id: number
  effective_from: string
  effective_to: string
  accrual: { mode: string; minutes_per_year: number }
  carryover: Record<string, unknown> | null
  min_notice_days: number
  max_consecutive_days: number | null
  requires_attachment_over_days: number | null
  allow_half_day: boolean
  legal_basis: string | null
  applies_to: { scope: 'COMPANY' }
}

export type LeaveTypeInput = {
  code: string
  name: string
  unit: string
  is_statutory: boolean
  is_annual: boolean
  requires_attachment: boolean
  allow_negative: boolean
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
  | 'REVOKED'
  | string

export type LeaveRequestItem = {
  id: number | null
  caseId: number
  caseNo: string
  applicationStatus: LeaveRequestStatus
  effectStatus: string | null
  employmentId: number | null
  employeeNo: string | null
  departmentName: string | null
  actorUserId: number | null
  orgUnitId: number | null
  leaveTypeId: number | null
  policyVersionId: number | null
  fromDate: string
  toDate: string
  minutes: number
  state: string
}

export type LeaveSummaryRow = {
  leaveTypeId: number
  leaveTypeCode: string
  leaveTypeName: string
  unit: LeaveUnit
  orgUnitId: number | null
  month: string
  employmentId: number | null
  employmentIds: number[]
  requestedMinutes: number
  caseIds: number[]
  consumedMinutes: number
  reservedMinutes: number
  releasedMinutes: number
  reversedMinutes: number
  balanceMinutes: number
}

export type LeaveSummary = {
  rows: LeaveSummaryRow[]
  total: number
  asOf: string
  sourceHash: string
}

export type LeaveCancelResult = {
  caseId: number
  state: string
  releasedMinutes: number
  reversedMinutes: number
  replayed: boolean
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
  policyVersion: number | null
}

export type LeaveRequestInput = {
  leaveTypeCode: string
  startDate: string
  endDate: string
  halfDay: boolean
  reason: string
  attachmentReference?: string | null
}

export type LeaveOnBehalfInput = LeaveRequestInput & { employmentId: number }

export type LeaveRequestResult = {
  caseId: number | null
  caseNo: string
  applicationStatus: LeaveRequestStatus
  effectStatus: string | null
  requestedMinutes: number
  actorUserId: number | null
  subjectEmploymentId: number | null
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
    isStatutory: firstValue(raw, ['is_statutory', 'isStatutory']) === true,
    isAnnual: firstValue(raw, ['is_annual', 'isAnnual']) === true,
    requiresAttachment: firstValue(raw, ['requires_attachment', 'requiresAttachment']) === true,
    allowNegative: firstValue(raw, ['allow_negative', 'allowNegative']) === true,
    status: text(firstValue(raw, ['status']), 'ACTIVE').toUpperCase(),
    policyVersion: nullableInteger(firstValue(raw, ['policy_version', 'policyVersion'])),
    policyVersionId: nullableInteger(firstValue(raw, ['policy_version_id', 'policyVersionId'])),
    entitlementMinutes: nullableInteger(firstValue(raw, ['entitlement_minutes', 'entitlementMinutes'])),
    version: Math.trunc(numberValue(firstValue(raw, ['version']), 1)),
  }
}

function integerList(value: unknown): number[] {
  if (!Array.isArray(value)) return []
  return value
    .map((entry) => nullableInteger(entry))
    .filter((entry): entry is number => entry !== null)
}

function normalisePolicy(raw: RawRecord): LeavePolicy | null {
  const id = nullableInteger(firstValue(raw, ['id']))
  const leaveTypeId = nullableInteger(firstValue(raw, ['leave_type_id', 'leaveTypeId']))
  if (id === null || leaveTypeId === null) return null

  const accrualValue = firstValue(raw, ['accrual', 'accrual_json'])
  const accrual = isRecord(accrualValue) ? accrualValue : {}

  return {
    id,
    leaveTypeId,
    leaveTypeCode: text(firstValue(raw, ['leave_type_code', 'leaveTypeCode'])),
    leaveTypeName: text(firstValue(raw, ['leave_type_name', 'leaveTypeName'])),
    unit: text(firstValue(raw, ['unit']), 'DAY'),
    version: Math.trunc(numberValue(firstValue(raw, ['version']), 1)),
    status: text(firstValue(raw, ['status']), 'PUBLISHED').toUpperCase(),
    effectiveFrom: text(firstValue(raw, ['effective_from', 'effectiveFrom'])),
    effectiveTo: text(firstValue(raw, ['effective_to', 'effectiveTo'])),
    accrualMode: text(firstValue(accrual, ['mode']), 'ANNUAL_FIXED').toUpperCase(),
    entitlementMinutes: Math.trunc(numberValue(firstValue(accrual, ['minutes_per_year']))),
    minNoticeDays: Math.trunc(numberValue(firstValue(raw, ['min_notice_days', 'minNoticeDays']))),
    maxConsecutiveDays: nullableInteger(firstValue(raw, ['max_consecutive_days', 'maxConsecutiveDays'])),
    requiresAttachmentOverDays: nullableInteger(
      firstValue(raw, ['requires_attachment_over_days', 'requiresAttachmentOverDays']),
    ),
    allowHalfDay: firstValue(raw, ['allow_half_day', 'allowHalfDay']) !== false,
    legalBasis: nullableText(firstValue(raw, ['legal_basis', 'legalBasis'])),
    publishedAt: text(firstValue(raw, ['published_at', 'publishedAt'])),
    retiredAt: nullableText(firstValue(raw, ['retired_at', 'retiredAt'])),
    retireReason: nullableText(firstValue(raw, ['retire_reason', 'retireReason'])),
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
    employmentId: nullableInteger(firstValue(raw, ['employment_id', 'employmentId', 'subject_employment_id'])),
    employeeNo: nullableText(firstValue(raw, ['employeeNo', 'employee_no'])),
    departmentName: nullableText(firstValue(raw, ['departmentName', 'department_name'])),
    actorUserId: nullableInteger(firstValue(raw, ['actor_user_id', 'actorUserId'])),
    orgUnitId: nullableInteger(firstValue(raw, ['org_unit_id', 'orgUnitId'])),
    leaveTypeId: nullableInteger(firstValue(raw, ['leaveTypeId', 'leave_type_id'])),
    policyVersionId: nullableInteger(firstValue(raw, ['leave_policy_version_id', 'leavePolicyVersionId'])),
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
    policyVersion: nullableInteger(firstValue(raw, ['policyVersion', 'policy_version'])),
  }
}

function normaliseSummaryRow(raw: RawRecord): LeaveSummaryRow {
  return {
    leaveTypeId: Math.trunc(numberValue(firstValue(raw, ['leave_type_id', 'leaveTypeId']))),
    leaveTypeCode: text(firstValue(raw, ['leave_type_code', 'leaveTypeCode'])),
    leaveTypeName: text(firstValue(raw, ['leave_type_name', 'leaveTypeName'])),
    unit: text(firstValue(raw, ['unit']), 'DAY'),
    orgUnitId: nullableInteger(firstValue(raw, ['org_unit_id', 'orgUnitId'])),
    month: text(firstValue(raw, ['month'])),
    employmentId: nullableInteger(firstValue(raw, ['employment_id', 'employmentId'])),
    employmentIds: integerList(firstValue(raw, ['employment_ids', 'employmentIds'])),
    requestedMinutes: Math.trunc(numberValue(firstValue(raw, ['requested_minutes', 'requestedMinutes']))),
    caseIds: integerList(firstValue(raw, ['case_ids', 'caseIds'])),
    consumedMinutes: Math.trunc(numberValue(firstValue(raw, ['consumed_minutes', 'consumedMinutes']))),
    reservedMinutes: Math.trunc(numberValue(firstValue(raw, ['reserved_minutes', 'reservedMinutes']))),
    releasedMinutes: Math.trunc(numberValue(firstValue(raw, ['released_minutes', 'releasedMinutes']))),
    reversedMinutes: Math.trunc(numberValue(firstValue(raw, ['reversed_minutes', 'reversedMinutes']))),
    balanceMinutes: Math.trunc(numberValue(firstValue(raw, ['balance_minutes', 'balanceMinutes']))),
  }
}

function normaliseSummary(raw: unknown): LeaveSummary {
  const payload = isRecord(raw) ? raw : {}
  const rows = Array.isArray(payload.rows) ? payload.rows.filter(isRecord).map(normaliseSummaryRow) : []

  return {
    rows,
    total: Math.trunc(numberValue(firstValue(payload, ['total']), rows.length)),
    asOf: text(firstValue(payload, ['as_of', 'asOf'])),
    sourceHash: text(firstValue(payload, ['source_hash', 'sourceHash'])),
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

export async function getLeavePolicies(): Promise<LeavePolicy[]> {
  const raw = await resourceGet<unknown>('/v1/time/leave/policies')
  return itemsOf(raw)
    .map(normalisePolicy)
    .filter((value): value is LeavePolicy => value !== null)
}

export async function getLeaveBalances(employmentId?: number | null): Promise<LeaveBalance[]> {
  const raw = await resourceGet<unknown>(
    '/v1/time/leave/balances',
    employmentId ? { employment_id: employmentId } : {},
  )
  return itemsOf(raw)
    .map(normaliseBalance)
    .filter((value): value is LeaveBalance => value !== null)
}

export async function getLeaveRequests(params: Record<string, unknown> = {}): Promise<LeaveRequestItem[]> {
  const raw = await resourceGet<unknown>('/v1/time/leave/requests', params)
  return itemsOf(raw)
    .map(normaliseRequest)
    .filter((value): value is LeaveRequestItem => value !== null)
}

export async function getLeaveSummary(params: Record<string, unknown>): Promise<LeaveSummary> {
  const raw = await resourceGet<unknown>('/v1/time/leave/reports/summary', params)
  return normaliseSummary(raw)
}

export async function createLeaveType(
  input: LeaveTypeInput,
  idempotencyKey: string,
): Promise<LeaveTypeOption | null> {
  const raw = await resourcePost<RawRecord>('/v1/time/leave/types', input, { idempotencyKey })
  return normaliseType(raw)
}

export async function updateLeaveType(
  id: number,
  input: Record<string, unknown>,
  idempotencyKey: string,
): Promise<LeaveTypeOption | null> {
  // Dynamic import keeps the module boundary mockable for the existing leave
  // tests that only stub GET/POST while still using PATCH in production.
  const { resourcePatch } = await import('@/api/resourceClient')
  const raw = await resourcePatch<RawRecord>(`/v1/time/leave/types/${id}`, input, { idempotencyKey })
  return normaliseType(raw)
}

export async function createLeavePolicy(
  input: LeavePolicyInput,
  idempotencyKey: string,
): Promise<LeavePolicy | null> {
  const raw = await resourcePost<RawRecord>('/v1/time/leave/policies', input, { idempotencyKey })
  return normalisePolicy(raw)
}

export async function retireLeavePolicy(
  id: number,
  reason: string,
  idempotencyKey: string,
): Promise<LeavePolicy | null> {
  const raw = await resourcePost<RawRecord>(
    `/v1/time/leave/policies/${id}/retire`,
    { reason },
    { idempotencyKey },
  )
  return normalisePolicy(raw)
}

export async function submitLeaveOnBehalf(
  input: LeaveOnBehalfInput,
  idempotencyKey: string,
): Promise<LeaveRequestResult> {
  const raw = await resourcePost<RawRecord>(
    '/v1/time/leave/requests/on-behalf',
    { employment_id: input.employmentId, ...leaveRequestPayload(input) },
    { idempotencyKey },
  )

  return {
    caseId: nullableInteger(firstValue(raw, ['caseId', 'case_id'])),
    caseNo: text(firstValue(raw, ['caseNo', 'case_no'])),
    applicationStatus: text(firstValue(raw, ['applicationStatus', 'application_status']), 'SUBMITTED').toUpperCase(),
    effectStatus: nullableText(firstValue(raw, ['effectStatus', 'effect_status'])),
    requestedMinutes: Math.trunc(numberValue(firstValue(raw, ['requestedMinutes', 'requested_minutes']))),
    actorUserId: nullableInteger(firstValue(raw, ['actor_user_id', 'actorUserId'])),
    subjectEmploymentId: nullableInteger(firstValue(raw, ['subject_employment_id', 'subjectEmploymentId'])),
  }
}

export async function previewLeave(input: LeaveRequestInput, employmentId?: number | null): Promise<LeavePreview> {
  const raw = employmentId
    ? await resourcePost<RawRecord>('/v1/time/leave/requests/on-behalf/preview', {
        employment_id: employmentId,
        ...leaveRequestPayload(input),
      })
    : await resourcePost<RawRecord>('/v1/time/leave/preview', leaveRequestPayload(input))
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
    actorUserId: nullableInteger(firstValue(raw, ['actor_user_id', 'actorUserId'])),
    subjectEmploymentId: nullableInteger(firstValue(raw, ['subject_employment_id', 'subjectEmploymentId'])),
  }
}

export async function cancelLeaveRequest(
  caseId: number,
  reason: string,
  idempotencyKey: string,
  revoke = false,
): Promise<LeaveCancelResult> {
  const body: Record<string, unknown> = { reason }
  if (revoke) body.revoke = true
  const raw = await resourcePost<RawRecord>(
    `/v1/time/leave/requests/${caseId}/cancel`,
    body,
    { idempotencyKey },
  )

  return {
    caseId: nullableInteger(firstValue(raw, ['caseId', 'case_id'])) ?? caseId,
    state: text(firstValue(raw, ['state']), revoke ? 'REVOKED' : 'CANCELLED'),
    releasedMinutes: Math.trunc(numberValue(firstValue(raw, ['releasedMinutes', 'released_minutes']))),
    reversedMinutes: Math.trunc(numberValue(firstValue(raw, ['reversedMinutes', 'reversed_minutes']))),
    replayed: firstValue(raw, ['replayed']) === true,
  }
}

export async function revokeApprovedLeave(
  caseId: number,
  reason: string,
  idempotencyKey: string,
): Promise<LeaveCancelResult> {
  const raw = await resourcePost<RawRecord>(
    `/v1/time/leave/requests/${caseId}/revoke`,
    { reason },
    { idempotencyKey },
  )

  return {
    caseId: nullableInteger(firstValue(raw, ['caseId', 'case_id'])) ?? caseId,
    state: text(firstValue(raw, ['state']), 'REVOKED'),
    releasedMinutes: Math.trunc(numberValue(firstValue(raw, ['releasedMinutes', 'released_minutes']))),
    reversedMinutes: Math.trunc(numberValue(firstValue(raw, ['reversedMinutes', 'reversed_minutes']))),
    replayed: firstValue(raw, ['replayed']) === true,
  }
}

export async function getLeaveLedger(
  leaveTypeCode: string,
  standardDayMinutes: number | null = null,
  employmentId?: number | null,
): Promise<LeaveLedger> {
  if (leaveTypeCode.trim() === '') {
    throw new ApiError('VALIDATION_FAILED', '请先选择假期类型。', { status: 422 })
  }

  // No as_of is sent: omitting it asks the server to use the company profile's
  // business date, which is the only source for "today" in this workspace.
  const params: Record<string, unknown> = { leave_type: leaveTypeCode }
  if (employmentId) params.employment_id = employmentId
  const raw = await resourceGet<unknown>('/v1/time/leave/ledger', params)
  return normaliseLedger(raw, standardDayMinutes)
}

export function leaveRequestPayload(input: LeaveRequestInput): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    leaveTypeCode: input.leaveTypeCode,
    startDate: input.startDate,
    endDate: input.endDate,
    halfDay: input.halfDay,
    reason: input.reason.trim(),
  }

  if (input.attachmentReference) payload.attachment_reference = input.attachmentReference

  return payload
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
  CANCELLED: '已撤销',
  RELEASED: '已撤销',
  DRAFT: '草稿',
  REVOKED: '已销假',
  CONSUMED: '已结算',
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
  REVOKED: 'closed',
  CONSUMED: 'approved',
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
  LEAVE_MIN_NOTICE_VIOLATION: '未满足该假别要求的最短提前申请天数。',
  LEAVE_MAX_CONSECUTIVE_EXCEEDED: '连续请假天数超过该假别上限。',
  LEAVE_HALF_DAY_NOT_ALLOWED: '该假别不允许半天请假。',
  LEAVE_ATTACHMENT_REQUIRED: '该时长需要上传附件。',
  LEAVE_REASON_TOO_SHORT: '请假事由至少需要 3 个字。',
}

export const POLICY_STATUS_LABEL: Record<string, string> = {
  PUBLISHED: '已发布',
  RETIRED: '已停用',
  DRAFT: '草稿',
}

export const ACCRUAL_MODE_LABEL: Record<string, string> = {
  ANNUAL_FIXED: '按年固定额度',
  MONTHLY_FIXED: '按月固定额度',
  NONE: '不设固定额度',
}

export const LEAVE_UNIT_OPTIONS = [
  { value: 'DAY', label: '天' },
  { value: 'HOUR', label: '小时' },
]

export function previewConflictText(conflict: LeavePreviewConflict): string {
  return PREVIEW_CONFLICT_LABEL[conflict.code] ?? '服务端返回了日期冲突。'
}
