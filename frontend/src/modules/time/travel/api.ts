import { ApiError } from '@/api/errors'
import { resourceGet, resourcePost } from '@/api/resourceClient'

/**
 * Travel workspace API boundary.
 *
 * Every business decision stays on the server: scopes, filters, workflow
 * cases, attendance links and integer-cent expenses are projected by Time.
 * This module never synthesises a travel row, a status transition or a money
 * value.
 */

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
  change: TravelChangeProjection | null
  attendanceLink: TravelAttendanceLink | null
}

export type TravelChangeProjection = {
  changeId: number
  status: string
  caseId: number | null
  reason: string
}

export type TravelAttendanceLink = {
  travelId: number
  employmentId: number
  fromDate: string
  toDate: string
  sourceCaseId: number
  status: 'ACTIVE' | 'RETIRED' | string
  retiredAt: string | null
  retireReason: string | null
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

export type TravelExpense = {
  id: number
  travelId: number
  employmentId: number
  category: string
  amountCents: number
  currency: string
  receiptFileId: number | null
  status: string
  paymentStatus: string
  note: string | null
  claimedAt: string | null
}

export type TravelReportRow = {
  month: string
  departmentId: number | null
  employmentCount: number
  approvedTrips: number
  pendingTrips: number
  rejectedTrips: number
  cancelledTrips: number
  completedTrips: number
  approvedDays: number
  pendingDays: number
  expenseCents: number
  travelIds: number[]
  detailPath: string
}

export type TravelReportResult = {
  rows: TravelReportRow[]
  total: number
  asOf: string
  sourceHash: string
}

export type TravelRequestsResult = {
  items: TravelRequest[]
  page: number
  pageSize: number
  total: number
  asOf: string
  sourceHash: string
  partial: boolean
  partialReason: string | null
}

export type TravelPoliciesResult = {
  items: TravelPolicy[]
  page: number
  pageSize: number
  total: number
  partial: boolean
  partialReason: string | null
}

export type TravelExpensesResult = {
  items: TravelExpense[]
  page: number
  pageSize: number
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

export type TravelChangeInput = TravelRequestInput & {
  travelId: number
  reason: string
}

export type TravelRequestFilters = {
  view?: 'my' | 'my_approvals' | 'team'
  status?: string
  from?: string
  to?: string
  employmentId?: number | null
  departmentId?: number | null
  page?: number
  pageSize?: number
}

export type TravelExpenseInput = {
  travelId: number
  amountCents: number
  category: 'TRANSPORT' | 'LODGING' | 'MEALS' | 'OTHER'
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
    throw new TypeError(`出差接口记录缺少有效整数 ${label}。`)
  }
  return parsed
}

function optionalInteger(value: unknown): number | null {
  return integer(value)
}

function requiredText(value: unknown, label: string): string {
  const parsed = text(value)
  if (parsed === '') {
    throw new TypeError(`出差接口记录缺少 ${label}。`)
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

function normaliseChange(raw: unknown): TravelChangeProjection | null {
  if (!isRecord(raw)) return null
  const changeId = optionalInteger(firstValue(raw, ['change_id', 'changeId']))
  if (changeId === null || changeId <= 0) return null

  return {
    changeId,
    status: text(raw.status, 'PENDING').toUpperCase(),
    caseId: optionalInteger(firstValue(raw, ['case_id', 'caseId'])),
    reason: text(raw.reason),
  }
}

function normaliseAttendanceLink(raw: unknown): TravelAttendanceLink | null {
  if (!isRecord(raw)) return null
  const travelId = optionalInteger(firstValue(raw, ['travel_id', 'travelId']))
  if (travelId === null) return null

  return {
    travelId,
    employmentId: optionalInteger(firstValue(raw, ['employment_id', 'employmentId'])) ?? 0,
    fromDate: requiredText(firstValue(raw, ['from_date', 'fromDate']), 'attendance_link.from_date'),
    toDate: requiredText(firstValue(raw, ['to_date', 'toDate']), 'attendance_link.to_date'),
    sourceCaseId: optionalInteger(firstValue(raw, ['source_case_id', 'sourceCaseId'])) ?? 0,
    status: text(raw.status, 'ACTIVE').toUpperCase(),
    retiredAt: nullableText(firstValue(raw, ['retired_at', 'retiredAt'])),
    retireReason: nullableText(firstValue(raw, ['retire_reason', 'retireReason'])),
  }
}

export function normaliseTravelRequest(raw: RawRecord): TravelRequest {
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
    change: normaliseChange(firstValue(raw, ['change'])),
    attendanceLink: normaliseAttendanceLink(firstValue(raw, ['attendance_link', 'attendanceLink'])),
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

function normaliseReportRow(raw: RawRecord): TravelReportRow {
  const travelIds = Array.isArray(raw.travel_ids)
    ? raw.travel_ids.map((value) => integer(value)).filter((value): value is number => value !== null)
    : []

  return {
    month: requiredText(firstValue(raw, ['month']), 'month'),
    departmentId: optionalInteger(firstValue(raw, ['department_id', 'departmentId'])),
    employmentCount: optionalInteger(firstValue(raw, ['employment_count', 'employmentCount'])) ?? 0,
    approvedTrips: optionalInteger(firstValue(raw, ['approved_trips', 'approvedTrips'])) ?? 0,
    pendingTrips: optionalInteger(firstValue(raw, ['pending_trips', 'pendingTrips'])) ?? 0,
    rejectedTrips: optionalInteger(firstValue(raw, ['rejected_trips', 'rejectedTrips'])) ?? 0,
    cancelledTrips: optionalInteger(firstValue(raw, ['cancelled_trips', 'cancelledTrips'])) ?? 0,
    completedTrips: optionalInteger(firstValue(raw, ['completed_trips', 'completedTrips'])) ?? 0,
    approvedDays: optionalInteger(firstValue(raw, ['approved_days', 'approvedDays'])) ?? 0,
    pendingDays: optionalInteger(firstValue(raw, ['pending_days', 'pendingDays'])) ?? 0,
    expenseCents: optionalInteger(firstValue(raw, ['expense_cents', 'expenseCents'])) ?? 0,
    travelIds,
    detailPath: text(firstValue(raw, ['detail_path', 'detailPath'])),
  }
}

export const TRAVEL_STATUS_LABELS: Record<string, string> = {
  DRAFT: '草稿',
  PENDING_APPROVAL: '待审批',
  APPROVED: '已批准',
  REJECTED: '已驳回',
  CANCELLED: '已取消',
  COMPLETED: '已完成',
}

export const TRAVEL_CHANGE_STATUS_LABELS: Record<string, string> = {
  PENDING: '变更待审批',
  APPLIED: '变更已生效',
  REJECTED: '变更已驳回',
  CANCELLED: '变更已取消',
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

export const ATTENDANCE_LINK_STATUS_LABELS: Record<string, string> = {
  ACTIVE: '考勤关联生效',
  RETIRED: '考勤关联已失效',
}

export const CITY_TIER_LABELS: Record<string, string> = {
  A: 'A 类城市',
  B: 'B 类城市',
  C: 'C 类城市',
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

export const PAYMENT_STATUS_LABELS: Record<string, string> = {
  CLAIM_ONLY: '仅登记，不付款',
}

export function travelStatusLabel(status: string): string {
  return TRAVEL_STATUS_LABELS[status] ?? '未知状态'
}

export function travelChangeStatusLabel(status: string): string {
  return TRAVEL_CHANGE_STATUS_LABELS[status] ?? '未知变更状态'
}

export function applicationStatusLabel(status: string | null): string {
  if (status === null || status === '') return '—'
  return APPLICATION_STATUS_LABELS[status] ?? '未知状态'
}

export function attendanceLinkStatusLabel(status: string): string {
  return ATTENDANCE_LINK_STATUS_LABELS[status] ?? '未知关联状态'
}

export function cityTierLabel(cityTier: string): string {
  return CITY_TIER_LABELS[cityTier] ?? cityTier
}

export function expenseCategoryLabel(category: string): string {
  return EXPENSE_CATEGORY_LABELS[category] ?? '其他'
}

export function expenseStatusLabel(status: string): string {
  return status === 'CLAIMED' ? '已申报' : status === 'REVERSED' ? '已冲销' : '未知状态'
}

export function travelPolicyStatusLabel(status: string): string {
  return status === 'DRAFT' ? '草稿' : status === 'PUBLISHED' ? '已发布' : status === 'RETIRED' ? '已退役' : '未知状态'
}

export function paymentStatusLabel(status: string): string {
  return PAYMENT_STATUS_LABELS[status] ?? status
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
 * new submission and therefore receives a new key; retrying the same failed
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

export async function getTravelRequests(filters: TravelRequestFilters = {}): Promise<TravelRequestsResult> {
  const params: Record<string, unknown> = {}
  if (filters.view !== undefined) params.view = filters.view
  if (filters.status !== undefined && filters.status !== '') params.status = filters.status
  if (filters.from !== undefined && filters.from !== '') params.from = filters.from
  if (filters.to !== undefined && filters.to !== '') params.to = filters.to
  if (filters.employmentId !== undefined && filters.employmentId !== null) params.employment_id = filters.employmentId
  if (filters.departmentId !== undefined && filters.departmentId !== null) params.department_id = filters.departmentId
  params.page = filters.page ?? 1
  params.page_size = filters.pageSize ?? 20

  const raw = await resourceGet<unknown>('/v1/travel/requests', params)
  const meta = listMeta(raw)
  const payload = isRecord(raw) ? raw : {}

  return {
    items: readRows(raw).map(normaliseTravelRequest),
    page: meta.page,
    pageSize: meta.pageSize,
    total: meta.total,
    asOf: text(firstValue(payload, ['as_of', 'asOf'])),
    sourceHash: text(firstValue(payload, ['source_hash', 'sourceHash'])),
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

export async function submitTravelChange(
  input: TravelChangeInput,
  idempotencyKey: string,
): Promise<{ changeId: number; travelId: number; caseId: number; changeStatus: string; reason: string }> {
  if (!Number.isSafeInteger(input.travelId) || input.travelId < 1) {
    throw new ApiError('VALIDATION_FAILED', '请选择有效的出差申请。', { status: 422 })
  }

  const raw = await resourcePost<RawRecord>(
    `/v1/travel/requests/${input.travelId}/change`,
    {
      from_date: input.fromDate,
      to_date: input.toDate,
      destination: input.destination,
      purpose: input.purpose,
      reason: input.reason,
    },
    { idempotencyKey },
  )

  return {
    changeId: requiredInteger(firstValue(raw, ['change_id', 'changeId']), 'change_id'),
    travelId: requiredInteger(firstValue(raw, ['travel_id', 'travelId']), 'travel_id'),
    caseId: requiredInteger(firstValue(raw, ['case_id', 'caseId']), 'case_id'),
    changeStatus: text(firstValue(raw, ['change_status', 'changeStatus']), 'PENDING').toUpperCase(),
    reason: text(raw.reason),
  }
}

export async function approveTravelRequest(
  travelId: number,
  decision: 'APPROVE' | 'REJECT',
  comment: string,
  idempotencyKey: string,
): Promise<TravelRequest> {
  const raw = await resourcePost<RawRecord>(
    `/v1/travel/requests/${travelId}/approve`,
    { decision, comment },
    { idempotencyKey },
  )
  return normaliseTravelRequest(raw)
}

export async function cancelTravelRequest(
  travelId: number,
  reason: string,
  idempotencyKey: string,
): Promise<TravelRequest> {
  const raw = await resourcePost<RawRecord>(
    `/v1/travel/requests/${travelId}/cancel`,
    { reason },
    { idempotencyKey },
  )
  return normaliseTravelRequest(raw)
}

export async function getTravelPolicies(): Promise<TravelPoliciesResult> {
  const raw = await resourceGet<unknown>('/v1/travel/policies', { page: 1, page_size: 20 })
  const meta = listMeta(raw)
  return {
    items: readRows(raw).map(normaliseTravelPolicy),
    page: meta.page,
    pageSize: meta.pageSize,
    total: meta.total,
    partial: meta.partial,
    partialReason: meta.partialReason,
  }
}

export async function publishTravelPolicy(
  input: {
    cityTier: string
    hotelCapCents: number
    transportCapCents: number
    effectiveFrom: string
  },
  idempotencyKey: string,
): Promise<TravelPolicy> {
  if (!Number.isSafeInteger(input.hotelCapCents) || input.hotelCapCents < 0) {
    throw new ApiError('VALIDATION_FAILED', '住宿上限必须是非负整数分。', { status: 422 })
  }
  if (!Number.isSafeInteger(input.transportCapCents) || input.transportCapCents < 0) {
    throw new ApiError('VALIDATION_FAILED', '交通上限必须是非负整数分。', { status: 422 })
  }

  return normaliseTravelPolicy(
    await resourcePost<RawRecord>(
      '/v1/travel/policies',
      {
        city_tier: input.cityTier,
        hotel_cap_cents: input.hotelCapCents,
        transport_cap_cents: input.transportCapCents,
        effective_from: input.effectiveFrom,
      },
      { idempotencyKey },
    ),
  )
}

export async function getTravelExpenses(travelId: number | null = null): Promise<TravelExpensesResult> {
  const params: Record<string, unknown> = { page: 1, page_size: 100 }
  if (travelId !== null && travelId > 0) params.travel_id = travelId

  const raw = await resourceGet<unknown>('/v1/travel/expenses', params)
  const meta = listMeta(raw)
  return {
    items: readRows(raw).map(normaliseTravelExpense),
    page: meta.page,
    pageSize: meta.pageSize,
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

export async function getTravelReport(from: string, to: string): Promise<TravelReportResult> {
  const raw = await resourceGet<unknown>('/v1/travel/reports/summary', { from, to })
  const payload = isRecord(raw) ? raw : {}
  const rows = Array.isArray(payload.rows) ? payload.rows.filter(isRecord).map(normaliseReportRow) : []

  return {
    rows,
    total: optionalInteger(payload.total) ?? rows.length,
    asOf: requiredText(firstValue(payload, ['as_of', 'asOf']), 'as_of'),
    sourceHash: requiredText(firstValue(payload, ['source_hash', 'sourceHash']), 'source_hash'),
  }
}

/** Render integer minor units without converting the value to a float. */
export function formatMoney(cents: number | null | undefined, currency = 'CNY'): string {
  if (cents === null || cents === undefined || !Number.isSafeInteger(cents)) return '—'
  if (!/^[A-Z]{3}$/.test(currency)) return `${cents} 分`

  const sign = cents < 0 ? '-' : ''
  const digitsText = String(Math.abs(cents))
  const digits = currency === 'JPY' ? 0 : 2
  const padded = digitsText.padStart(digits + 1, '0')
  const whole = digits === 0 ? padded : padded.slice(0, -digits)
  const fraction = digits === 0 ? '' : padded.slice(-digits)
  const grouped = new Intl.NumberFormat('zh-CN', { maximumFractionDigits: 0 }).format(BigInt(whole))
  const symbol = currency === 'CNY' ? '¥' : currency === 'USD' ? '$' : currency === 'EUR' ? '€' : `${currency} `

  return `${sign}${symbol}${grouped}${fraction === '' ? '' : `.${fraction}`}`
}

/** Parse an integer minor-unit input; decimal or signed text is rejected. */
export function parseMinorUnitsInput(value: string): number | null {
  const trimmed = value.trim()
  if (!/^\d+$/.test(trimmed)) return null
  const parsed = Number(trimmed)
  return Number.isSafeInteger(parsed) ? parsed : null
}
