import { ApiError } from '@/api/errors'
import { resourceGet, resourcePost } from '@/api/resourceClient'

/**
 * Overtime and business-travel API boundary.
 *
 * The backend owns authorization, scoping, policy versions, status transitions
 * and persistence. This module only normalises transport casing and never
 * synthesises a row. Monetary values stay integer minor units end to end;
 * formatting is a render-only concern and never feeds back into a model.
 */

/**
 * One company overtime policy version. The business date fields are server
 * dates; multipliers remain exact decimal strings end to end and are never
 * parsed into JavaScript numbers.
 */
export type OvertimePolicy = {
  id: number | null
  version: number | null
  status: string
  effectiveFrom: string | null
  effectiveTo: string | null
  timeUnitMinutes: number | null
  multiplier: string
  allowPay: boolean
  allowTimeoff: boolean
  statutoryHolidayMultiplier: string
  allocation: string
  rounding: string
  legalBasis: string | null
  source: string
  note: string
  publishedAt: string | null
  retiredAt: string | null
  retireReason: string | null
}

export type OvertimePoliciesResult = {
  items: OvertimePolicy[]
  total: number
  asOf: string
  source: string
  current: OvertimePolicy | null
}

export type OvertimePolicyInput = {
  effectiveFrom: string
  effectiveTo: string
  timeUnitMinutes: number
  multiplier: string
  statutoryHolidayMultiplier: string
  allowPay: boolean
  allowTimeoff: boolean
  allocation: string
  rounding: string
  legalBasis?: string
}

export type OvertimeRequestStatus = 'SUBMITTED' | 'APPROVED' | 'CONFIRMED' | 'REJECTED' | 'CANCELLED' | string

export type OvertimeRequest = {
  id: number
  employmentId: number
  businessDate: string
  declaredMinutes: number
  confirmedMinutes: number | null
  status: OvertimeRequestStatus
  reason: string
  caseId: number | null
  overtimeRecordId: number | null
  policyVersionId: number | null
  policySnapshot: Record<string, unknown> | null
  employeeNo: string | null
  employeeName: string | null
  settlementMethod: 'PAY' | 'TIMEOFF' | null
  settlementId: number | null
  attendanceStatus: string | null
  attendanceWorkedMinutes: number | null
  attendanceOvertimeMinutes: number | null
  payrollPeriodFrozen: boolean | null
  recordedAt: string | null
  allowedActions?: string[]
}

export type OvertimeRequestProjection = {
  items: OvertimeRequest[]
  page: number
  pageSize: number
  total: number
  asOf: string
  freshness: string
  sourceHash: string
  partial: boolean
  partialReason: string | null
  view?: 'self' | 'team'
}

export type OvertimeRequestQuery = {
  view?: 'self' | 'team'
  employmentId?: number | null
  status?: string | null
  from?: string | null
  to?: string | null
  page?: number
  pageSize?: number
  asOf?: string | null
}

export type OvertimeRecord = {
  id: number
  employmentId: number
  businessDate: string
  minutes: number
  status: string
  caseId: number | null
  settlementMethod: 'PAY' | 'TIMEOFF' | null
  settlementId: number | null
}

export type OvertimeRecordsResult = {
  items: OvertimeRecord[]
  total: number
  partial: boolean
  partialReason: string | null
}

export type TimeoffBalanceItem = {
  settlementId: number
  overtimeRecordId: number
  employmentId: number
  businessDate: string | null
  method: 'TIMEOFF'
  minutes: number
  reason: string
  recordedAt: string | null
}

export type TimeoffBalancesResult = {
  items: TimeoffBalanceItem[]
  availableMinutes: number
  settlementCount: number
  total: number
  asOf: string
  partial: boolean
  partialReason: string | null
}

export type OvertimeSettlement = {
  settlementId: number
  overtimeRecordId: number
  employmentId: number
  method: 'PAY' | 'TIMEOFF'
  minutes: number
  replayed: boolean
}

export type OvertimeSettlementRow = {
  overtimeRecordId: number
  employmentId: number
  businessDate: string
  minutes: number
  status: string
  settlementId: number
  method: 'PAY' | 'TIMEOFF'
  reason: string | null
  recordedAt: string | null
  payrollPeriodFrozen: boolean | null
}

export type OvertimeSettlementsResult = {
  items: OvertimeSettlementRow[]
  total: number
  asOf: string
}

export type OvertimeRequestInput = {
  businessDate: string
  declaredMinutes: number
  reason: string
}

export type OvertimeConfirmationInput = {
  requestId: number
  confirmedMinutes: number
}

export type OvertimeSettlementInput = {
  recordId: number
  method: 'PAY' | 'TIMEOFF'
  reason: string
}

export type TravelRequest = {
  travelId: number
  employmentId: number
  fromDate: string
  toDate: string
  destination: string | null
  purpose: string
  status: string
  caseId: number | null
  caseNo: string | null
  applicationStatus: string | null
}

export type TravelRequestsResult = {
  items: TravelRequest[]
  total: number
  partial: boolean
  partialReason: string | null
}

export type TravelPolicy = {
  id: number
  cityTier: string
  hotelCapCents: number
  transportCapCents: number
  effectiveFrom: string
  version: number
  status: string
}

export type TravelPoliciesResult = {
  items: TravelPolicy[]
  total: number
  partial: boolean
  partialReason: string | null
}

export type TravelExpenseCategory = 'TRANSPORT' | 'LODGING' | 'MEALS' | 'OTHER'

export type TravelExpense = {
  id: number
  travelId: number
  employmentId: number
  category: TravelExpenseCategory | string
  amountCents: number
  currency: string
  receiptFileId: number | null
  status: string
  paymentStatus: string
  note: string | null
  claimedAt: string | null
}

export type TravelExpensesResult = {
  items: TravelExpense[]
  total: number
  partial: boolean
  partialReason: string | null
}

export type TravelRequestInput = {
  fromDate: string
  toDate: string
  destination: string
  purpose: string
}

export type TravelExpenseInput = {
  travelId: number
  amountCents: number
  category: TravelExpenseCategory
  note?: string
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
  if (value === null || value === undefined || value === '') return null
  return text(value)
}

function integer(value: unknown): number | null {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  if (typeof value === 'string' && /^-?\d+$/.test(value.trim())) {
    const parsed = Number(value.trim())
    return Number.isSafeInteger(parsed) ? parsed : null
  }
  return null
}

function requiredInteger(value: unknown, label: string): number {
  const parsed = integer(value)
  if (parsed === null) {
    throw new TypeError(`加班或出差接口记录缺少有效整数 ${label}。`)
  }
  return parsed
}

function optionalInteger(value: unknown): number | null {
  return integer(value)
}

function requiredText(value: unknown, label: string): string {
  const parsed = text(value)
  if (parsed === '') {
    throw new TypeError(`加班或出差接口记录缺少 ${label}。`)
  }
  return parsed
}

function firstValue(row: RawRecord, keys: string[]): unknown {
  for (const key of keys) {
    if (row[key] !== undefined) return row[key]
  }
  return undefined
}

function readRows(raw: unknown): RawRecord[] {
  if (Array.isArray(raw)) return raw.filter(isRecord)
  if (isRecord(raw) && Array.isArray(raw.items)) return raw.items.filter(isRecord)
  return []
}

function listMeta(raw: unknown): {
  page: number
  pageSize: number
  total: number
  partial: boolean
  partialReason: string | null
} {
  const payload = isRecord(raw) ? raw : {}
  const items = readRows(raw)
  return {
    page: optionalInteger(payload.page) ?? 1,
    pageSize: optionalInteger(payload.page_size ?? payload.pageSize) ?? 20,
    total: optionalInteger(payload.total) ?? items.length,
    partial: payload.partial === true,
    partialReason: nullableText(payload.partial_reason ?? payload.partialReason),
  }
}

function normaliseOvertimePolicy(raw: unknown): OvertimePolicy {
  const payload = isRecord(raw) ? raw : {}
  return {
    id: optionalInteger(payload.id),
    version: optionalInteger(payload.version),
    status: text(payload.status),
    effectiveFrom: nullableText(firstValue(payload, ['effective_from', 'effectiveFrom'])),
    effectiveTo: nullableText(firstValue(payload, ['effective_to', 'effectiveTo'])),
    timeUnitMinutes: optionalInteger(firstValue(payload, ['time_unit_minutes', 'timeUnitMinutes'])),
    multiplier: text(payload.multiplier),
    allowPay: payload.allow_pay !== false,
    allowTimeoff: payload.allow_timeoff !== false,
    statutoryHolidayMultiplier: text(firstValue(payload, ['statutory_holiday_multiplier', 'statutoryHolidayMultiplier'])),
    allocation: text(payload.allocation),
    rounding: text(payload.rounding),
    legalBasis: nullableText(firstValue(payload, ['legal_basis', 'legalBasis'])),
    source: text(payload.source),
    note: text(payload.note),
    publishedAt: nullableText(firstValue(payload, ['published_at', 'publishedAt'])),
    retiredAt: nullableText(firstValue(payload, ['retired_at', 'retiredAt'])),
    retireReason: nullableText(firstValue(payload, ['retire_reason', 'retireReason'])),
  }
}

function normaliseOvertimeRequest(raw: RawRecord): OvertimeRequest {
  return {
    id: requiredInteger(firstValue(raw, ['id', 'overtime_request_id', 'overtimeRequestId']), 'id'),
    employmentId: requiredInteger(firstValue(raw, ['employment_id', 'employmentId']), 'employment_id'),
    businessDate: requiredText(firstValue(raw, ['business_date', 'businessDate']), 'business_date'),
    declaredMinutes: requiredInteger(firstValue(raw, ['declared_minutes', 'declaredMinutes']), 'declared_minutes'),
    confirmedMinutes: optionalInteger(firstValue(raw, ['confirmed_minutes', 'confirmedMinutes'])),
    status: text(raw.status, 'SUBMITTED').toUpperCase(),
    reason: text(raw.reason),
    caseId: optionalInteger(firstValue(raw, ['case_id', 'caseId'])),
    overtimeRecordId: optionalInteger(firstValue(raw, ['overtime_record_id', 'overtimeRecordId'])),
    policyVersionId: optionalInteger(firstValue(raw, ['policy_version_id', 'policyVersionId'])),
    policySnapshot: isRecord(raw.policy_snapshot ?? raw.policySnapshot)
      ? (raw.policy_snapshot ?? raw.policySnapshot) as Record<string, unknown>
      : null,
    employeeNo: nullableText(firstValue(raw, ['employee_no', 'employeeNo'])),
    employeeName: nullableText(firstValue(raw, ['employee_name', 'employeeName'])),
    settlementMethod: (() => {
      const method = text(firstValue(raw, ['settlement_method', 'settlementMethod'])).toUpperCase()
      return method === 'PAY' || method === 'TIMEOFF' ? method : null
    })(),
    settlementId: optionalInteger(firstValue(raw, ['settlement_id', 'settlementId'])),
    attendanceStatus: nullableText(firstValue(raw, ['attendance_status', 'attendanceStatus'])),
    attendanceWorkedMinutes: optionalInteger(firstValue(raw, ['attendance_worked_minutes', 'attendanceWorkedMinutes'])),
    attendanceOvertimeMinutes: optionalInteger(firstValue(raw, ['attendance_overtime_minutes', 'attendanceOvertimeMinutes'])),
    payrollPeriodFrozen: typeof raw.payroll_period_frozen === 'boolean'
      ? raw.payroll_period_frozen
      : typeof raw.payrollPeriodFrozen === 'boolean'
        ? raw.payrollPeriodFrozen
        : null,
    recordedAt: nullableText(firstValue(raw, ['recorded_at', 'recordedAt'])),
    allowedActions: Array.isArray(raw.allowed_actions)
      ? raw.allowed_actions.filter((value): value is string => typeof value === 'string')
      : [],
  }
}

function normaliseOvertimeRecord(raw: RawRecord): OvertimeRecord {
  const method = text(firstValue(raw, ['settlement_method', 'settlementMethod'])).toUpperCase()
  return {
    id: requiredInteger(raw.id, 'id'),
    employmentId: requiredInteger(firstValue(raw, ['employment_id', 'employmentId']), 'employment_id'),
    businessDate: requiredText(firstValue(raw, ['business_date', 'businessDate']), 'business_date'),
    minutes: optionalInteger(raw.minutes) ?? 0,
    status: text(raw.status, 'APPROVED').toUpperCase(),
    caseId: optionalInteger(firstValue(raw, ['case_id', 'caseId'])),
    settlementMethod: method === 'PAY' || method === 'TIMEOFF' ? method : null,
    settlementId: optionalInteger(firstValue(raw, ['settlement_id', 'settlementId'])),
  }
}

function normaliseTimeoffBalance(raw: RawRecord): TimeoffBalanceItem {
  return {
    settlementId: requiredInteger(firstValue(raw, ['settlement_id', 'settlementId']), 'settlement_id'),
    overtimeRecordId: requiredInteger(firstValue(raw, ['overtime_record_id', 'overtimeRecordId']), 'overtime_record_id'),
    employmentId: requiredInteger(firstValue(raw, ['employment_id', 'employmentId']), 'employment_id'),
    businessDate: nullableText(firstValue(raw, ['business_date', 'businessDate'])),
    method: 'TIMEOFF',
    minutes: optionalInteger(raw.minutes) ?? 0,
    reason: text(raw.reason),
    recordedAt: nullableText(firstValue(raw, ['recorded_at', 'recordedAt'])),
  }
}

function normaliseSettlement(raw: unknown): OvertimeSettlement {
  const payload = isRecord(raw) ? raw : {}
  const method = text(payload.method).toUpperCase()
  if (method !== 'PAY' && method !== 'TIMEOFF') {
    throw new TypeError('加班结算接口返回了未知的结算方式。')
  }
  return {
    settlementId: requiredInteger(firstValue(payload, ['settlement_id', 'settlementId']), 'settlement_id'),
    overtimeRecordId: requiredInteger(firstValue(payload, ['overtime_record_id', 'overtimeRecordId']), 'overtime_record_id'),
    employmentId: requiredInteger(firstValue(payload, ['employment_id', 'employmentId']), 'employment_id'),
    method,
    minutes: optionalInteger(payload.minutes) ?? 0,
    replayed: payload.replayed === true,
  }
}

function normaliseTravelRequest(raw: RawRecord): TravelRequest {
  return {
    travelId: requiredInteger(firstValue(raw, ['travel_id', 'travelId']), 'travel_id'),
    employmentId: requiredInteger(firstValue(raw, ['employment_id', 'employmentId']), 'employment_id'),
    fromDate: requiredText(firstValue(raw, ['from_date', 'fromDate']), 'from_date'),
    toDate: requiredText(firstValue(raw, ['to_date', 'toDate']), 'to_date'),
    destination: nullableText(firstValue(raw, ['destination'])),
    purpose: text(raw.purpose),
    status: text(raw.status, 'PENDING_APPROVAL').toUpperCase(),
    caseId: optionalInteger(firstValue(raw, ['case_id', 'caseId'])),
    caseNo: nullableText(firstValue(raw, ['case_no', 'caseNo'])),
    applicationStatus: nullableText(firstValue(raw, ['application_status', 'applicationStatus'])),
  }
}

function normaliseTravelPolicy(raw: RawRecord): TravelPolicy {
  return {
    id: requiredInteger(raw.id, 'id'),
    cityTier: requiredText(firstValue(raw, ['city_tier', 'cityTier']), 'city_tier'),
    hotelCapCents: requiredInteger(firstValue(raw, ['hotel_cap_cents', 'hotelCapCents']), 'hotel_cap_cents'),
    transportCapCents: optionalInteger(firstValue(raw, ['transport_cap_cents', 'transportCapCents'])) ?? 0,
    effectiveFrom: requiredText(firstValue(raw, ['effective_from', 'effectiveFrom']), 'effective_from'),
    version: optionalInteger(raw.version) ?? 1,
    status: text(raw.status, 'PUBLISHED').toUpperCase(),
  }
}

function normaliseTravelExpense(raw: RawRecord): TravelExpense {
  const currency = requiredText(firstValue(raw, ['currency']), 'currency').toUpperCase()
  if (!/^[A-Z]{3}$/.test(currency)) {
    throw new TypeError('出差费用接口返回了无效的币种代码。')
  }
  return {
    id: requiredInteger(raw.id, 'id'),
    travelId: requiredInteger(firstValue(raw, ['travel_id', 'travelId']), 'travel_id'),
    employmentId: requiredInteger(firstValue(raw, ['employment_id', 'employmentId']), 'employment_id'),
    category: text(raw.category, 'OTHER').toUpperCase(),
    amountCents: requiredInteger(firstValue(raw, ['amount_cents', 'amountCents']), 'amount_cents'),
    currency,
    receiptFileId: optionalInteger(firstValue(raw, ['receipt_file_id', 'receiptFileId'])),
    status: text(raw.status, 'CLAIMED').toUpperCase(),
    paymentStatus: text(firstValue(raw, ['payment_status', 'paymentStatus']), 'CLAIM_ONLY').toUpperCase(),
    note: nullableText(raw.note),
    claimedAt: nullableText(firstValue(raw, ['claimed_at', 'claimedAt'])),
  }
}

export const OVERTIME_REQUEST_STATUS_LABELS: Record<string, string> = {
  SUBMITTED: '已提交',
  APPROVED: '已批准',
  CONFIRMED: '已确认',
  REJECTED: '已驳回',
  CANCELLED: '已取消',
}

export const TRAVEL_STATUS_LABELS: Record<string, string> = {
  DRAFT: '草稿',
  PENDING_APPROVAL: '待审批',
  APPROVED: '已批准',
  REJECTED: '已驳回',
  CANCELLED: '已取消',
  COMPLETED: '已完成',
}

export const APPLICATION_STATUS_LABELS: Record<string, string> = {
  DRAFT: '草稿',
  SUBMITTED: '已提交',
  IN_APPROVAL: '审批中',
  APPROVED: '已通过',
  REJECTED: '已驳回',
  WITHDRAWN: '已撤回',
  CANCELLED: '已取消',
}

export const PAYMENT_STATUS_LABELS: Record<string, string> = {
  CLAIM_ONLY: '仅登记',
  CREATED: '已创建',
  PAID: '已支付',
}

export const CITY_TIER_LABELS: Record<string, string> = {
  TIER_1: '一线城市',
  TIER_2: '二线城市',
  TIER_3: '三线城市',
  OVERSEAS: '境外城市',
}

export const EXPENSE_CATEGORY_LABELS: Record<string, string> = {
  TRANSPORT: '交通',
  LODGING: '住宿',
  MEALS: '餐饮',
  OTHER: '其他',
}

export const SETTLEMENT_METHOD_LABELS: Record<'PAY' | 'TIMEOFF', string> = {
  PAY: '折算加班费',
  TIMEOFF: '调休',
}

export const OVERTIME_POLICY_STATUS_LABELS: Record<string, string> = {
  PUBLISHED: '已发布',
  RETIRED: '已停用',
}

export const OVERTIME_ALLOCATION_LABELS: Record<string, string> = {
  EMPLOYEE_CHOICE: '员工选择',
  PAY: '仅折算加班费',
  TIMEOFF: '仅调休',
  EMPLOYER_DECIDES: '公司决定',
}

export const OVERTIME_ROUNDING_LABELS: Record<string, string> = {
  HALF_UP: '四舍五入',
  HALF_EVEN: '银行家舍入',
  DOWN: '向下取整',
  UP: '向上取整',
}

export function overtimePolicyStatusLabel(status: string): string {
  return OVERTIME_POLICY_STATUS_LABELS[status] ?? '未知状态'
}

export function overtimeAllocationLabel(allocation: string): string {
  return OVERTIME_ALLOCATION_LABELS[allocation] ?? '未配置'
}

export function overtimeRoundingLabel(rounding: string): string {
  return OVERTIME_ROUNDING_LABELS[rounding] ?? '未配置'
}

/** Format integer minutes without ever rendering a monetary amount. */
export function formatMinutes(minutes: number | null | undefined): string {
  if (minutes === null || minutes === undefined || !Number.isSafeInteger(minutes)) return '—'
  const sign = minutes < 0 ? '-' : ''
  const absolute = Math.abs(minutes)
  const hours = Math.floor(absolute / 60)
  const rest = absolute % 60
  if (hours === 0) return `${sign}${rest} 分钟`
  if (rest === 0) return `${sign}${hours} 小时`
  return `${sign}${hours} 小时 ${rest} 分钟`
}

export const COMPENSATION_PREFERENCE_LABELS: Record<'PAY' | 'TIMEOFF', string> = {
  PAY: '加班费',
  TIMEOFF: '调休',
}

export function overtimeStatusLabel(status: string): string {
  return OVERTIME_REQUEST_STATUS_LABELS[status] ?? '未知状态'
}

export function travelStatusLabel(status: string): string {
  return TRAVEL_STATUS_LABELS[status] ?? '未知状态'
}

export function applicationStatusLabel(status: string | null): string {
  if (status === null || status === '') return '—'
  return APPLICATION_STATUS_LABELS[status] ?? '未知状态'
}

export function paymentStatusLabel(status: string): string {
  return PAYMENT_STATUS_LABELS[status] ?? '未知状态'
}

export function expenseCategoryLabel(category: string): string {
  return EXPENSE_CATEGORY_LABELS[category] ?? '其他'
}

export function cityTierLabel(cityTier: string): string {
  const label = CITY_TIER_LABELS[cityTier]
  return label === undefined ? '未知城市档位' : `${label}（${cityTier}）`
}

export function overtimeNextStep(status: string): string {
  if (status === 'APPROVED') return '待确认实际分钟'
  if (status === 'CONFIRMED') return '待结算补偿'
  return ''
}

export function recordNextStep(record: OvertimeRecord): string {
  if (record.settlementMethod !== null) return '已结算'
  return '待结算'
}

export function newIdempotencyKey(prefix: string): string {
  const suffix =
    typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`
  return `${prefix}-${suffix}`.slice(0, 128)
}

/**
 * Keep the key stable while the payload is unchanged. A changed payload is a
 * new submission and therefore receives a new key; a retry of the same failed
 * payload reuses the original key.
 */
export function stableSubmissionKey(
  previous: StableSubmissionKey | null,
  prefix: string,
  payload: Record<string, unknown>,
): StableSubmissionKey {
  const fingerprint = JSON.stringify(payload)
  if (previous !== null && previous.fingerprint === fingerprint) return previous
  return { fingerprint, key: newIdempotencyKey(prefix) }
}

function currencyDigits(currency: string): number {
  const formatter = new Intl.NumberFormat('zh-CN', { style: 'currency', currency })
  return Math.max(0, Math.min(3, formatter.resolvedOptions().maximumFractionDigits ?? 2))
}

/**
 * Render integer minor units without converting the amount to a float.
 *
 * Currency and its exponent come from the API payload / company profile; the
 * locale rules only decide grouping, symbol placement and the decimal mark.
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
    // Unsupported ICU data must not turn the amount into a rounded float or
    // silently assume a two-decimal currency.
    return `${sign}${digitsText} ${currency}（最小货币单位）`
  }
}

/** Parse an integer minor-unit input; decimal or signed text is rejected. */
export function parseMinorUnitsInput(value: string): number | null {
  const trimmed = value.trim()
  if (!/^\d+$/.test(trimmed)) return null
  const parsed = Number(trimmed)
  return Number.isSafeInteger(parsed) ? parsed : null
}

function normaliseOvertimeSettlementRow(raw: RawRecord): OvertimeSettlementRow {
  const method = text(firstValue(raw, ['method', 'settlement_method', 'settlementMethod'])).toUpperCase()
  if (method !== 'PAY' && method !== 'TIMEOFF') {
    throw new TypeError('加班结算记录缺少有效的结算方式。')
  }
  const frozen = firstValue(raw, ['payroll_period_frozen', 'payrollPeriodFrozen'])
  return {
    overtimeRecordId: requiredInteger(firstValue(raw, ['overtime_record_id', 'overtimeRecordId']), 'overtime_record_id'),
    employmentId: requiredInteger(firstValue(raw, ['employment_id', 'employmentId']), 'employment_id'),
    businessDate: requiredText(firstValue(raw, ['business_date', 'businessDate']), 'business_date'),
    minutes: optionalInteger(raw.minutes) ?? 0,
    status: text(raw.status),
    settlementId: requiredInteger(firstValue(raw, ['settlement_id', 'settlementId']), 'settlement_id'),
    method,
    reason: nullableText(raw.reason),
    recordedAt: nullableText(firstValue(raw, ['recorded_at', 'recordedAt'])),
    payrollPeriodFrozen: typeof frozen === 'boolean' ? frozen : null,
  }
}

function overtimeQueryParams(query: OvertimeRequestQuery = {}): Record<string, unknown> {
  const params: Record<string, unknown> = {}
  if (query.view !== undefined) params.view = query.view
  if (query.employmentId !== null && query.employmentId !== undefined) params.employment_id = query.employmentId
  if (query.status !== null && query.status !== undefined && query.status !== '') params.status = query.status
  if (query.from !== null && query.from !== undefined && query.from !== '') params.from = query.from
  if (query.to !== null && query.to !== undefined && query.to !== '') params.to = query.to
  if (query.page !== undefined) params.page = query.page
  if (query.pageSize !== undefined) params.page_size = query.pageSize
  if (query.asOf !== null && query.asOf !== undefined && query.asOf !== '') params.as_of = query.asOf
  return params
}

export async function getOvertimePolicies(asOf: string | null = null): Promise<OvertimePoliciesResult> {
  const raw = await resourceGet<unknown>(
    '/v1/time/overtime/policies',
    asOf !== null && asOf !== '' ? { as_of: asOf } : {},
  )
  const payload = isRecord(raw) ? raw : {}
  const current = payload.current
  return {
    items: readRows(raw).map(normaliseOvertimePolicy),
    total: optionalInteger(payload.total) ?? readRows(raw).length,
    asOf: text(firstValue(payload, ['as_of', 'asOf'])),
    source: text(payload.source, 'COMPANY_VERSION'),
    current: isRecord(current) ? normaliseOvertimePolicy(current) : null,
  }
}

/** Backward-compatible single-policy helper; never invents a default. */
export async function getOvertimePolicy(asOf: string | null = null): Promise<OvertimePolicy | null> {
  return (await getOvertimePolicies(asOf)).current
}

export async function createOvertimePolicy(
  input: OvertimePolicyInput,
  idempotencyKey: string,
): Promise<OvertimePolicy> {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(input.effectiveFrom) || !/^\d{4}-\d{2}-\d{2}$/.test(input.effectiveTo)) {
    throw new ApiError('VALIDATION_FAILED', '加班规则生效日期格式必须为 YYYY-MM-DD。', { status: 422 })
  }
  if (input.effectiveTo < input.effectiveFrom) {
    throw new ApiError('VALIDATION_FAILED', '加班规则结束日期不得早于开始日期。', { status: 422 })
  }
  if (![1, 5, 10, 15, 30, 60].includes(input.timeUnitMinutes)) {
    throw new ApiError('VALIDATION_FAILED', '加班时间单位必须为 1/5/10/15/30/60 分钟。', { status: 422 })
  }
  // The service validates too; this client check keeps decimals as strings and
  // prevents an accidental Number() conversion at the transport edge.
  if (!/^\d{1,6}\.\d{6,18}$/.test(input.multiplier) || !/^\d{1,6}\.\d{6,18}$/.test(input.statutoryHolidayMultiplier)) {
    throw new ApiError('VALIDATION_FAILED', '加班倍率必须是至少 6 位小数的定点字符串。', { status: 422 })
  }
  return normaliseOvertimePolicy(await resourcePost<RawRecord>(
    '/v1/time/overtime/policies',
    {
      effective_from: input.effectiveFrom,
      effective_to: input.effectiveTo,
      time_unit_minutes: input.timeUnitMinutes,
      multiplier: input.multiplier,
      statutory_holiday_multiplier: input.statutoryHolidayMultiplier,
      allow_pay: input.allowPay,
      allow_timeoff: input.allowTimeoff,
      allocation: input.allocation,
      rounding: input.rounding,
      ...(input.legalBasis !== undefined && input.legalBasis.trim() !== ''
        ? { legal_basis: input.legalBasis.trim() }
        : {}),
    },
    { idempotencyKey },
  ))
}

export async function retireOvertimePolicy(
  policyId: number,
  reason: string,
  idempotencyKey: string,
): Promise<OvertimePolicy> {
  if (!Number.isSafeInteger(policyId) || policyId < 1) {
    throw new ApiError('VALIDATION_FAILED', '请选择有效的加班规则版本。', { status: 422 })
  }
  if (reason.trim().length < 3) {
    throw new ApiError('VALIDATION_FAILED', '停用原因至少需要 3 个字符。', { status: 422 })
  }
  return normaliseOvertimePolicy(await resourcePost<RawRecord>(
    `/v1/time/overtime/policies/${policyId}/retire`,
    { reason: reason.trim() },
    { idempotencyKey },
  ))
}

export async function getOvertimeRecords(): Promise<OvertimeRecordsResult> {
  const raw = await resourceGet<unknown>('/v1/time/overtime/records')
  return {
    items: readRows(raw).map(normaliseOvertimeRecord),
    total: listMeta(raw).total,
    partial: listMeta(raw).partial,
    partialReason: listMeta(raw).partialReason,
  }
}

export async function getOvertimeRequests(query: OvertimeRequestQuery = {}): Promise<OvertimeRequestProjection> {
  const raw = await resourceGet<unknown>('/v1/time/overtime/requests', overtimeQueryParams(query))
  const payload = isRecord(raw) ? raw : {}
  const meta = listMeta(raw)
  const view = text(payload.view).toLowerCase()
  return {
    items: readRows(raw).map(normaliseOvertimeRequest),
    page: meta.page,
    pageSize: meta.pageSize,
    total: meta.total,
    asOf: text(firstValue(payload, ['as_of', 'asOf'])),
    freshness: text(payload.freshness, 'LIVE'),
    sourceHash: text(firstValue(payload, ['source_hash', 'sourceHash'])),
    partial: meta.partial,
    partialReason: meta.partialReason,
    view: view === 'team' || view === 'self' ? view : undefined,
  }
}

export async function getOvertimeSettlements(
  employmentId: number | null = null,
  asOf: string | null = null,
): Promise<OvertimeSettlementsResult> {
  const params: Record<string, unknown> = {}
  if (employmentId !== null && employmentId > 0) params.employment_id = employmentId
  if (asOf !== null && asOf !== '') params.as_of = asOf
  const raw = await resourceGet<unknown>('/v1/time/overtime/settlements', params)
  const payload = isRecord(raw) ? raw : {}
  return {
    items: readRows(raw).map(normaliseOvertimeSettlementRow),
    total: optionalInteger(payload.total) ?? readRows(raw).length,
    asOf: text(firstValue(payload, ['as_of', 'asOf'])),
  }
}

export async function getTimeoffBalances(): Promise<TimeoffBalancesResult> {
  const raw = await resourceGet<unknown>('/v1/time/overtime/timeoff-balances')
  const payload = isRecord(raw) ? raw : {}
  const meta = listMeta(raw)
  return {
    items: readRows(raw).map(normaliseTimeoffBalance),
    availableMinutes: optionalInteger(firstValue(payload, ['available_minutes', 'availableMinutes'])) ?? 0,
    settlementCount: optionalInteger(firstValue(payload, ['settlement_count', 'settlementCount'])) ?? 0,
    total: meta.total,
    asOf: text(firstValue(payload, ['as_of', 'asOf'])),
    partial: meta.partial,
    partialReason: meta.partialReason,
  }
}

export async function submitOvertimeRequest(
  input: OvertimeRequestInput,
  idempotencyKey: string,
): Promise<OvertimeRequest> {
  if (!Number.isSafeInteger(input.declaredMinutes) || input.declaredMinutes < 1) {
    throw new ApiError('VALIDATION_FAILED', '申报加班分钟数必须是正整数。', { status: 422 })
  }
  const raw = await resourcePost<RawRecord>(
    '/v1/time/overtime/requests',
    {
      business_date: input.businessDate,
      declared_minutes: input.declaredMinutes,
      reason: input.reason,
    },
    { idempotencyKey },
  )
  return normaliseOvertimeRequest(raw)
}

export async function approveOvertimeRequest(
  requestId: number,
  decision: 'APPROVE' | 'REJECT',
  comment: string,
  idempotencyKey: string,
): Promise<OvertimeRequest> {
  const raw = await resourcePost<RawRecord>(
    `/v1/time/overtime/requests/${requestId}/approve`,
    { decision, comment },
    { idempotencyKey },
  )
  return normaliseOvertimeRequest(raw)
}

export async function confirmOvertimeRequest(
  input: OvertimeConfirmationInput,
  idempotencyKey: string,
): Promise<OvertimeRequest> {
  if (!Number.isSafeInteger(input.confirmedMinutes) || input.confirmedMinutes < 1) {
    throw new ApiError('VALIDATION_FAILED', '确认加班分钟数必须是正整数。', { status: 422 })
  }
  const raw = await resourcePost<RawRecord>(
    `/v1/time/overtime/requests/${input.requestId}/confirm`,
    { confirmed_minutes: input.confirmedMinutes },
    { idempotencyKey },
  )
  return normaliseOvertimeRequest(raw)
}

export async function settleOvertimeRecord(
  input: OvertimeSettlementInput,
  idempotencyKey: string,
): Promise<OvertimeSettlement> {
  if (input.method !== 'PAY' && input.method !== 'TIMEOFF') {
    throw new ApiError('VALIDATION_FAILED', '请选择调休或折算加班费。', { status: 422 })
  }
  return normaliseSettlement(
    await resourcePost<unknown>(
      `/v1/overtime/records/${input.recordId}/settlements`,
      { method: input.method, reason: input.reason },
      { idempotencyKey },
    ),
  )
}

export async function getTravelRequests(): Promise<TravelRequestsResult> {
  const raw = await resourceGet<unknown>('/v1/travel/requests', {})
  const meta = listMeta(raw)
  return {
    items: readRows(raw).map(normaliseTravelRequest),
    total: meta.total,
    partial: meta.partial,
    partialReason: meta.partialReason,
  }
}

export async function submitTravelRequest(
  input: TravelRequestInput,
  idempotencyKey: string,
): Promise<TravelRequest> {
  const raw = await resourcePost<RawRecord>(
    '/v1/travel/requests',
    {
      from_date: input.fromDate,
      to_date: input.toDate,
      destination: input.destination,
      purpose: input.purpose,
    },
    { idempotencyKey },
  )
  return normaliseTravelRequest(raw)
}

export async function getTravelExpenses(travelId: number | null = null): Promise<TravelExpensesResult> {
  const raw = await resourceGet<unknown>(
    '/v1/travel/expenses',
    travelId !== null && travelId > 0 ? { travel_id: travelId } : {},
  )
  const meta = listMeta(raw)
  return {
    items: readRows(raw).map(normaliseTravelExpense),
    total: meta.total,
    partial: meta.partial,
    partialReason: meta.partialReason,
  }
}

export async function submitTravelExpense(
  input: TravelExpenseInput,
  idempotencyKey: string,
): Promise<TravelExpense> {
  if (!Number.isSafeInteger(input.amountCents) || input.amountCents < 0) {
    throw new ApiError('VALIDATION_FAILED', '费用金额必须是非负整数最小货币单位。', { status: 422 })
  }
  if (!Number.isSafeInteger(input.travelId) || input.travelId < 1) {
    throw new ApiError('VALIDATION_FAILED', '请选择有效的出差申请。', { status: 422 })
  }

  const body: Record<string, unknown> = {
    travel_id: input.travelId,
    amount_cents: input.amountCents,
    category: input.category,
  }
  if (input.note !== undefined && input.note.trim() !== '') body.note = input.note.trim()

  return normaliseTravelExpense(
    await resourcePost<RawRecord>('/v1/travel/expenses', body, { idempotencyKey }),
  )
}

export async function getTravelPolicies(): Promise<TravelPoliciesResult> {
  const raw = await resourceGet<unknown>('/v1/travel/policies', {})
  const meta = listMeta(raw)
  return {
    items: readRows(raw).map(normaliseTravelPolicy),
    total: meta.total,
    partial: meta.partial,
    partialReason: meta.partialReason,
  }
}
