import { ApiError, errorCopy } from '@/api/errors'
import { resourceGet, resourcePatch, resourcePost, resourcePut } from '@/api/resourceClient'

/**
 * Platform settings API boundary.
 *
 * Only endpoints that exist in `docs/openapi/openapi.yaml` are called here.
 * Rows are normalised from the real envelope; no setting, file or benefit row
 * is synthesised. Money stays an integer number of cents and rates stay decimal
 * strings from the server. Percentage rendering is string arithmetic only.
 */

type Raw = Record<string, unknown>

export type PageResult<T> = {
  items: T[]
  page: number
  pageSize: number
  total: number
}

function isRecord(value: unknown): value is Raw {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function contractError(field: string): never {
  throw new ApiError('INTERNAL', `服务端返回的数据不符合契约：${field}。`, { status: 502 })
}

function record(value: unknown, field: string): Raw {
  if (isRecord(value)) return value
  contractError(field)
}

function text(value: unknown, field: string): string {
  if (typeof value === 'string' && value !== '') return value
  contractError(field)
}

function nullableText(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

function integer(value: unknown, field: string): number {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  if (typeof value === 'string' && /^-?\d+$/.test(value)) {
    const parsed = Number(value)
    if (Number.isSafeInteger(parsed)) return parsed
  }
  contractError(field)
}

function nullableInteger(value: unknown): number | null {
  if (value === null || value === undefined || value === '') return null
  return integer(value, '数字字段')
}

function bool(value: unknown): boolean {
  return value === true
}

function rowsOf(value: unknown, field: string): Raw[] {
  if (Array.isArray(value)) return value.filter(isRecord)
  if (isRecord(value) && Array.isArray(value.items)) return value.items.filter(isRecord)
  contractError(field)
}

export function pageFrom(value: unknown): PageResult<Raw> {
  if (Array.isArray(value)) {
    return { items: value.filter(isRecord), page: 1, pageSize: Math.max(1, value.length), total: value.length }
  }
  const payload = record(value, '分页响应')
  const items = Array.isArray(payload.items) ? payload.items.filter(isRecord) : []
  const page = typeof payload.page === 'number' && payload.page > 0 ? payload.page : 1
  const pageSize = typeof payload.page_size === 'number' && payload.page_size > 0
    ? payload.page_size
    : Math.max(1, items.length)
  const total = typeof payload.total === 'number' && payload.total >= 0 ? payload.total : items.length
  return { items, page, pageSize, total }
}

/* -------------------------------------------------------------------------
 * Company calendar (single company, no legal-entity or tenant parameter)
 * ---------------------------------------------------------------------- */

export type CalendarHoliday = {
  date: string
  name: string
  type: string
  source: string | null
}

export type CompanyCalendar = {
  year: number
  timezone: string
  workweek: number[]
  standardDayMinutes: number
  version: number
  holidays: CalendarHoliday[]
  partial: boolean
  partialReason: string | null
}

function calendarOf(value: unknown): CompanyCalendar {
  const row = record(value, '工作日历')
  if (!Array.isArray(row.workweek)) contractError('工作日规则')
  if (!Array.isArray(row.holidays)) contractError('节假日列表')
  return {
    year: integer(row.year, '年份'),
    timezone: text(row.timezone, '时区'),
    workweek: row.workweek
      .map((day) => integer(day, '工作日'))
      .filter((day) => day >= 1 && day <= 7),
    standardDayMinutes: integer(row.standard_day_minutes, '标准日分钟'),
    version: integer(row.version, '日历版本'),
    holidays: row.holidays.filter(isRecord).map((holiday) => ({
      date: text(holiday.date, '节假日日期'),
      name: text(holiday.name, '节假日名称'),
      type: text(holiday.type, '节假日类型'),
      source: nullableText(holiday.source),
    })),
    partial: bool(row.partial),
    partialReason: nullableText(row.partial_reason),
  }
}

export async function getCompanyCalendar(year: number): Promise<CompanyCalendar> {
  return calendarOf(await resourceGet<unknown>('/v1/time/calendars', { year }))
}

export type CalendarPublishInput = {
  timezone: string
  workweek: number[]
  standardDayMinutes: number
  holidays: Array<{ date: string; name: string; type: string }>
}

export async function publishCompanyCalendar(
  input: CalendarPublishInput,
  idempotencyKey: string,
): Promise<unknown> {
  return resourcePost<unknown>(
    '/v1/system/calendars/publish',
    {
      timezone: input.timezone,
      workweek: input.workweek,
      standard_day_minutes: input.standardDayMinutes,
      holidays: input.holidays,
    },
    { idempotencyKey },
  )
}

/* -------------------------------------------------------------------------
 * Single-company profile (Organization owns the row; Platform renders it)
 * ---------------------------------------------------------------------- */

export type CompanyProfile = {
  name: string
  countryCode: string
  timezone: string
  currencyCode: string
  calendarPolicy: Record<string, unknown> | null
}

function companyProfileOf(value: unknown): CompanyProfile {
  const row = record(value, '公司资料')
  return {
    name: text(row.name, '公司名称'),
    countryCode: text(row.country_code, '国家代码'),
    timezone: text(row.timezone, '时区'),
    currencyCode: text(row.currency_code, '币种'),
    calendarPolicy: isRecord(row.calendar_policy) ? row.calendar_policy : null,
  }
}

export async function getCompanyProfile(): Promise<CompanyProfile> {
  return companyProfileOf(await resourceGet<unknown>('/v1/system/company-profile'))
}

export type CompanyProfileUpdateInput = {
  name: string
  countryCode: string
  timezone: string
  currencyCode: string
}

export async function updateCompanyProfile(
  input: CompanyProfileUpdateInput,
  idempotencyKey: string,
): Promise<CompanyProfile> {
  return companyProfileOf(await resourcePatch<unknown>(
    '/v1/system/company-profile',
    {
      name: input.name,
      country_code: input.countryCode,
      timezone: input.timezone,
      currency_code: input.currencyCode,
    },
    { idempotencyKey },
  ))
}

/* -------------------------------------------------------------------------
 * Feature flags and typed parameters (the only editable system settings)
 * ---------------------------------------------------------------------- */

export type FeatureFlag = { flag: string; enabled: boolean; risky: boolean }

export async function listFeatureFlags(): Promise<FeatureFlag[]> {
  const payload = record(await resourceGet<unknown>('/v1/platform/features'), '功能开关')
  return rowsOf(payload, '功能开关').map((row) => ({
    flag: text(row.flag, '功能开关'),
    enabled: bool(row.enabled),
    risky: bool(row.risky),
  }))
}

export async function updateFeatureFlag(flag: string, enabled: boolean, reason: string): Promise<FeatureFlag> {
  const payload = record(
    await resourcePut<unknown>(`/v1/platform/features/${encodeURIComponent(flag)}`, { enabled, reason }),
    '功能开关更新结果',
  )
  return {
    flag: text(payload.flag, '功能开关'),
    enabled: bool(payload.enabled),
    risky: false,
  }
}

export type SystemParameter = {
  id: number
  key: string
  scope: string
  value: unknown
  sensitive: boolean
  masked: boolean
}

function parameterOf(value: unknown): SystemParameter {
  const row = record(value, '系统参数')
  return {
    id: integer(row.id, '参数编号'),
    key: text(row.key, '参数名称'),
    scope: text(row.scope, '参数范围'),
    value: row.value,
    sensitive: bool(row.sensitive),
    masked: bool(row.masked),
  }
}

export async function listSystemParameters(): Promise<PageResult<SystemParameter>> {
  const payload = await resourceGet<unknown>('/v1/system/parameters', { page: 1, page_size: 100 })
  const page = pageFrom(payload)
  return { ...page, items: page.items.map(parameterOf) }
}

export type ParameterUpdateInput = {
  key: string
  scope: string
  value: unknown
  sensitive: boolean
}

export async function updateSystemParameter(
  input: ParameterUpdateInput,
  idempotencyKey: string,
): Promise<SystemParameter> {
  return parameterOf(await resourcePut<unknown>(
    '/v1/system/parameters',
    { key: input.key, scope: input.scope, value: input.value, sensitive: input.sensitive },
    { idempotencyKey },
  ))
}

/* -------------------------------------------------------------------------
 * Dictionary read contract (verified, not surfaced by this parameters page)
 * ---------------------------------------------------------------------- */

export type DictionaryEntry = {
  id: number
  type: string
  code: string
  label: string
  sortOrder: number
  active: boolean
}

export async function listDictionaries(type?: string): Promise<PageResult<DictionaryEntry>> {
  const params: Record<string, unknown> = { page: 1, page_size: 100 }
  if (type !== undefined && type !== '') params.type = type
  const payload = await resourceGet<unknown>('/v1/system/dictionaries', params)
  const page = pageFrom(payload)
  return {
    ...page,
    items: page.items.map((value) => {
      const row = record(value, '字典项')
      return {
        id: integer(row.id, '字典项编号'),
        type: text(row.type, '字典类型'),
        code: text(row.code, '字典代码'),
        label: text(row.label, '字典名称'),
        sortOrder: integer(row.sort_order, '字典排序'),
        active: bool(row.active),
      }
    }),
  }
}

export type DictionaryPatch = {
  label?: string
  sortOrder?: number
  active?: boolean
}

export async function updateDictionaryEntry(
  id: number,
  patch: DictionaryPatch,
  idempotencyKey: string,
): Promise<DictionaryEntry> {
  const body: Record<string, unknown> = {}
  if (patch.label !== undefined) body.label = patch.label
  if (patch.sortOrder !== undefined) body.sort_order = patch.sortOrder
  if (patch.active !== undefined) body.active = patch.active
  const row = record(
    await resourcePatch<unknown>(`/v1/system/dictionaries/${id}`, body, { idempotencyKey }),
    '字典项更新结果',
  )
  return {
    id: integer(row.id, '字典项编号'),
    type: text(row.type, '字典类型'),
    code: text(row.code, '字典代码'),
    label: text(row.label, '字典名称'),
    sortOrder: integer(row.sort_order, '字典排序'),
    active: bool(row.active),
  }
}

/* -------------------------------------------------------------------------
 * Benefits: plan versions, enrolment counts and the monthly ledger
 * ---------------------------------------------------------------------- */

export type BenefitPlan = {
  id: number
  code: string
  name: string
  employerRate: string
  employeeRate: string
  effectiveFrom: string
  effectiveTo: string | null
  version: number
}

function benefitPlanOf(value: unknown): BenefitPlan {
  const row = record(value, '福利方案')
  return {
    id: integer(row.id, '方案编号'),
    code: text(row.code, '方案代码'),
    name: text(row.name, '方案名称'),
    employerRate: text(row.employer_rate, '雇主费率'),
    employeeRate: text(row.employee_rate, '个人费率'),
    effectiveFrom: text(row.effective_from, '生效日'),
    effectiveTo: nullableText(row.effective_to),
    version: integer(row.version, '方案版本'),
  }
}

export type BenefitPlanInput = {
  code: string
  name: string
  employerRate: string
  employeeRate: string
  effectiveFrom: string
  effectiveTo: string | null
}

export async function listBenefitPlans(): Promise<PageResult<BenefitPlan>> {
  const page = pageFrom(await resourceGet<unknown>('/v1/benefits/plans', { page: 1, page_size: 100 }))
  return { ...page, items: page.items.map(benefitPlanOf) }
}

export async function createBenefitPlan(
  input: BenefitPlanInput,
  idempotencyKey: string,
): Promise<BenefitPlan> {
  return benefitPlanOf(await resourcePost<unknown>(
    '/v1/benefits/plans',
    {
      code: input.code,
      name: input.name,
      employer_rate: input.employerRate,
      employee_rate: input.employeeRate,
      effective_from: input.effectiveFrom,
      effective_to: input.effectiveTo,
    },
    { idempotencyKey },
  ))
}

export type BenefitEnrollment = {
  id: number
  planId: number
  employmentId: number
  baseAmountCents: number
  employeeRate: string
  employerRate: string
  employeeContributionCents: number
  employerContributionCents: number
  effectiveFrom: string
  effectiveTo: string | null
  version: number
}

function benefitEnrollmentOf(value: unknown): BenefitEnrollment {
  const row = record(value, '参保版本')
  return {
    id: integer(row.id, '参编号'),
    planId: integer(row.plan_id, '方案编号'),
    employmentId: integer(row.employment_id, '任职编号'),
    baseAmountCents: integer(row.base_amount_cents, '参保基数'),
    employeeRate: text(row.employee_rate, '个人费率'),
    employerRate: text(row.employer_rate, '雇主费率'),
    employeeContributionCents: integer(row.employee_contribution_cents, '个人缴费'),
    employerContributionCents: integer(row.employer_contribution_cents, '雇主缴费'),
    effectiveFrom: text(row.effective_from, '生效日'),
    effectiveTo: nullableText(row.effective_to),
    version: integer(row.version, '参保版本号'),
  }
}

export type BenefitEnrollmentQuery = {
  planId?: number
  page?: number
  pageSize?: number
}

export async function listBenefitEnrollments(
  query: BenefitEnrollmentQuery = {},
): Promise<PageResult<BenefitEnrollment>> {
  const params: Record<string, unknown> = {
    page: query.page ?? 1,
    page_size: query.pageSize ?? 100,
  }
  if (query.planId !== undefined) params.plan_id = query.planId
  const page = pageFrom(await resourceGet<unknown>('/v1/benefits/enrollments', params))
  return { ...page, items: page.items.map(benefitEnrollmentOf) }
}

export type BenefitEnrollmentInput = {
  planId: number
  employmentId: number
  baseAmountCents: number
  effectiveFrom: string
  effectiveTo: string | null
}

export async function createBenefitEnrollment(
  input: BenefitEnrollmentInput,
  idempotencyKey: string,
): Promise<BenefitEnrollment> {
  return benefitEnrollmentOf(await resourcePost<unknown>(
    '/v1/benefits/enrollments',
    {
      plan_id: input.planId,
      employment_id: input.employmentId,
      base_amount_cents: input.baseAmountCents,
      effective_from: input.effectiveFrom,
      effective_to: input.effectiveTo,
    },
    { idempotencyKey },
  ))
}

export type BenefitTransaction = {
  id: number
  enrollmentId: number
  employmentId: number
  period: string
  baseAmountCents: number
  employeeAmountCents: number
  employerAmountCents: number
  totalAmountCents: number
  payrollRunId: number | null
  status: string
}

function benefitTransactionOf(value: unknown): BenefitTransaction {
  const row = record(value, '缴费台账')
  return {
    id: integer(row.id, '台账编号'),
    enrollmentId: integer(row.enrollment_id, '参保编号'),
    employmentId: integer(row.employment_id, '任职编号'),
    period: text(row.period, '期间'),
    baseAmountCents: integer(row.base_amount_cents, '缴费基数'),
    employeeAmountCents: integer(row.employee_amount_cents, '个人缴费'),
    employerAmountCents: integer(row.employer_amount_cents, '雇主缴费'),
    totalAmountCents: integer(row.total_amount_cents, '合计缴费'),
    payrollRunId: nullableInteger(row.payroll_run_id),
    status: text(row.status, '台账状态'),
  }
}

export async function listBenefitTransactions(): Promise<PageResult<BenefitTransaction>> {
  const page = pageFrom(await resourceGet<unknown>('/v1/benefits/transactions', { page: 1, page_size: 100 }))
  return { ...page, items: page.items.map(benefitTransactionOf) }
}

export type BenefitTransactionInput = {
  enrollmentId: number
  period: string
  payrollRunId: number | null
}

export async function createBenefitTransaction(
  input: BenefitTransactionInput,
  idempotencyKey: string,
): Promise<BenefitTransaction> {
  return benefitTransactionOf(await resourcePost<unknown>(
    '/v1/benefits/transactions',
    {
      enrollment_id: input.enrollmentId,
      period: input.period,
      payroll_run_id: input.payrollRunId,
    },
    { idempotencyKey },
  ))
}

/**
 * Statutory parameters are the sole source for tax, social-insurance,
 * housing-fund and rounding rates. Benefit plans never carry a copy of these
 * values; this boundary intentionally reuses the existing payroll endpoints.
 */
export type StatutoryParameterSet = 'TAX' | 'SOCIAL_INSURANCE' | 'HOUSING_FUND' | 'ROUNDING'

export type StatutoryParameter = {
  id: number
  parameterSet: StatutoryParameterSet
  code: string
  valueDecimal: string | null
  valueInt: number | null
  effectiveFrom: string
  effectiveTo: string | null
  version: string
  approvedBy: number
  evidenceRef: string
  isDemo: boolean
}

export type StatutoryBracketInput = {
  lowerBoundCents: number
  upperBoundCents: number | null
  rateBps: number
  quickDeductionCents: number
}

const PARAMETER_SETS: readonly StatutoryParameterSet[] = [
  'TAX',
  'SOCIAL_INSURANCE',
  'HOUSING_FUND',
  'ROUNDING',
]

function statutoryParameterSet(value: unknown): StatutoryParameterSet {
  const candidate = text(value, '参数集')
  if (!PARAMETER_SETS.includes(candidate as StatutoryParameterSet)) {
    contractError('参数集')
  }
  return candidate as StatutoryParameterSet
}

function statutoryParameterOf(value: unknown): StatutoryParameter {
  const row = record(value, '法定参数')
  return {
    id: integer(row.id, '参数编号'),
    parameterSet: statutoryParameterSet(row.parameter_set),
    code: text(row.code, '参数代码'),
    valueDecimal: nullableText(row.value_decimal),
    valueInt: nullableInteger(row.value_int),
    effectiveFrom: text(row.effective_from, '生效日'),
    effectiveTo: nullableText(row.effective_to),
    version: text(row.version, '参数版本'),
    approvedBy: integer(row.approved_by, '登记人'),
    evidenceRef: text(row.evidence_ref, '证据引用'),
    isDemo: bool(row.is_demo),
  }
}

export async function listStatutoryParameters(
  parameterSet?: StatutoryParameterSet,
): Promise<PageResult<StatutoryParameter>> {
  const params: Record<string, unknown> = { page: 1, page_size: 100 }
  if (parameterSet !== undefined) params.parameter_set = parameterSet
  const page = pageFrom(await resourceGet<unknown>('/v1/payroll/statutory-parameters', params))
  return { ...page, items: page.items.map(statutoryParameterOf) }
}

export type StatutoryVersionInput = {
  parameterSet: StatutoryParameterSet
  version: string
  effectiveFrom: string
  effectiveTo: string | null
  evidenceRef: string
  isDemo: boolean
  thresholdCents?: number
  minBaseCents?: number
  maxBaseCents?: number
  employeeRate?: string
  employerRate?: string
  mode?: 'HALF_UP' | 'HALF_EVEN' | 'DOWN' | 'UP'
  brackets?: StatutoryBracketInput[]
}

export type StatutoryVersionResult = {
  version: string
  parameterSet: StatutoryParameterSet
  rows: number
  isDemo: boolean
  demoNotice: string | null
}

export async function createStatutoryParameterVersion(
  input: StatutoryVersionInput,
  idempotencyKey: string,
): Promise<StatutoryVersionResult> {
  const body: Record<string, unknown> = {
    parameter_set: input.parameterSet,
    version: input.version,
    effective_from: input.effectiveFrom,
    effective_to: input.effectiveTo,
    evidence_ref: input.evidenceRef,
    is_demo: input.isDemo,
  }

  if (input.parameterSet === 'TAX') {
    body.THRESHOLD_CENTS = input.thresholdCents
    body.brackets = (input.brackets ?? []).map((bracket) => ({
      lower_bound_cents: bracket.lowerBoundCents,
      upper_bound_cents: bracket.upperBoundCents,
      rate_bps: bracket.rateBps,
      quick_deduction_cents: bracket.quickDeductionCents,
    }))
  } else if (input.parameterSet === 'ROUNDING') {
    body.MODE = input.mode
  } else {
    body.MIN_BASE_CENTS = input.minBaseCents
    body.MAX_BASE_CENTS = input.maxBaseCents
    body.EMPLOYEE_RATE = input.employeeRate
    body.EMPLOYER_RATE = input.employerRate
  }

  const result = record(
    await resourcePost<unknown>('/v1/payroll/statutory-parameters', body, { idempotencyKey }),
    '法定参数版本',
  )

  return {
    version: text(result.version, '参数版本'),
    parameterSet: statutoryParameterSet(result.parameter_set),
    rows: integer(result.rows, '参数行数'),
    isDemo: bool(result.is_demo),
    demoNotice: nullableText(result.demo_notice),
  }
}

/* -------------------------------------------------------------------------
 * Decimal-string percentage helpers (no floating point anywhere)
 * ---------------------------------------------------------------------- */

const RATE_PATTERN = /^(0|1)\.(\d{6,18})$/

export function rateIsValid(rate: string): boolean {
  return RATE_PATTERN.test(rate)
}

/**
 * Render a server rate (0.160000) as a percentage string (16%).
 * String slicing only: `Number(rate)` is never called.
 */
export function ratePercent(rate: string): string {
  const match = RATE_PATTERN.exec(rate)
  if (match === null) return '—'
  const whole = match[1] ?? ''
  const fraction = match[2] ?? ''
  const digits = whole + fraction
  const point = whole.length + 2
  const integerPart = (digits.slice(0, point) || '0').replace(/^0+(?=\d)/, '')
  const fractionalPart = digits.slice(point).replace(/0+$/, '')
  return fractionalPart === '' ? `${integerPart}%` : `${integerPart}.${fractionalPart}%`
}

/**
 * Convert a percentage input (16 or 8.5) into the exact decimal string the
 * benefit API requires (0.160000 / 0.085000). Invalid or >100% returns null.
 */
export function percentToRate(percent: string): string | null {
  const value = percent.trim()
  const match = /^(\d{1,3})(?:\.(\d{1,6}))?$/.exec(value)
  if (match === null) return null
  const whole = Number(match[1])
  const fraction = (match[2] ?? '').padEnd(6, '0')
  if (whole > 100 || (whole === 100 && /[1-9]/.test(fraction))) return null
  // Percent -> ratio is a fixed two-place shift. `fraction` carries six input
  // decimal places, so the ratio has eight and is trimmed to its natural
  // precision (never below the API's six-place minimum).
  const rateWhole = String(Math.floor(whole / 100))
  const rateFraction = `${String(whole % 100).padStart(2, '0')}${fraction}`
  const trimmed = rateFraction.replace(/0+$/, '').padEnd(6, '0')
  return `${rateWhole}.${trimmed}`
}

export function platformErrorCopy(error: unknown): string {
  return errorCopy(error)
}

export function isForbidden(error: unknown): boolean {
  return error instanceof ApiError && (error.code === 'FORBIDDEN' || error.status === 403)
}
