import { ApiError, errorCopy } from '@/api/errors'
import { resourceGet, resourcePost } from '@/api/resourceClient'

/**
 * Payroll workspace API boundary.
 *
 * The backend owns authorization, run membership, the lifecycle state machine,
 * grouping and every monetary value. This module normalises transport casing
 * only: amounts stay integer minor units, and a missing amount key stays
 * missing so the UI can render "field permission" instead of inventing zero.
 */

export type PayrollRunStatus =
  | 'DRAFT'
  | 'INPUT_LOCKED'
  | 'CALCULATED'
  | 'REVIEWED'
  | 'APPROVED'
  | 'PUBLISHED'
  | 'PAID'
  | 'CLOSED'
  | 'CANCELLED'

export type PayrollRunType = 'REGULAR' | 'CORRECTION' | 'OFF_CYCLE' | 'BONUS'

export type PayrollEmployeeStatus = 'PENDING' | 'CALCULATED' | 'EXCLUDED' | 'FAILED'

export type PayrollRunSummary = {
  id: number
  period: string
  runType: PayrollRunType
  status: PayrollRunStatus
  correctionOfRunId: number | null
  payDate: string
  employeeCount: number
  createdAt: string
  /** Absent when the caller does not hold the payslip field profile. */
  grossCents?: number
  deductionsCents?: number
  netCents?: number
  employerCostCents?: number
}

export type PayrollRunPage = {
  items: PayrollRunSummary[]
  page: number
  pageSize: number
  total: number
}

export type PayrollEmployee = {
  employmentId: number
  status: PayrollEmployeeStatus
  failureReason: string | null
  grossCents?: number
  taxableCents?: number
  deductionsCents?: number
  netCents?: number
  employerCostCents?: number
  employeeSiCents?: number
  employeeHfCents?: number
  taxCents?: number
}

export type PayrollEmployeePage = {
  items: PayrollEmployee[]
  page: number
  pageSize: number
  total: number
}

export type PayrollRunDetail = PayrollRunSummary & {
  schemeId: number
  earningPeriodStart: string
  earningPeriodEnd: string
  postingPeriod: string
  cutoffAt: string
  preparedBy: number
  reviewedBy: number | null
  approvedBy: number | null
  publishedBy: number | null
  inputHash: string | null
  snapshotVersion: number
  reopenCount: number
  employees: PayrollEmployee[]
}

export type PayrollInputSnapshot = {
  id: number
  employmentId: number
  snapshotType: string
  snapshotVersion: number
  sourceHash: string
  payload: Record<string, unknown>
  createdAt: string | null
}

export type PayrollInputPage = {
  items: PayrollInputSnapshot[]
  page: number
  pageSize: number
  total: number
}

export type PayrollReconciliation = {
  runId: number
  period: string
  status: string
  employeeCount: number
  payslipCount: number
  grossCents: number
  deductionsCents: number
  netCents: number
  employerCostCents: number
  differences: Array<Record<string, unknown>>
  passed: boolean
}

export type PayrollExport = {
  filename: string
  content: string
  sha256: string
}

/** Only operations that exist on the real backend. */
export type PayrollAction =
  | 'lock-inputs'
  | 'calculate'
  | 'review'
  | 'approve'
  | 'publish'
  | 'mark-paid'
  | 'close'
  | 'cancel'

export type PayrollExportKind = 'bank' | 'tax'

export type PayrollRunFilters = {
  period?: string
  status?: string
}

type Raw = Record<string, unknown>

const RUN_STATUSES: readonly PayrollRunStatus[] = [
  'DRAFT',
  'INPUT_LOCKED',
  'CALCULATED',
  'REVIEWED',
  'APPROVED',
  'PUBLISHED',
  'PAID',
  'CLOSED',
  'CANCELLED',
]

const RUN_TYPES: readonly PayrollRunType[] = ['REGULAR', 'CORRECTION', 'OFF_CYCLE', 'BONUS']

function isRecord(value: unknown): value is Raw {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function contractError(field: string): never {
  throw new ApiError('INTERNAL', `服务端返回的薪资数据不符合契约：${field}。`, { status: 502 })
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

function requiredInteger(value: unknown, field: string): number {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  contractError(field)
}

function nullableInteger(value: unknown, field: string): number | null {
  if (value === null || value === undefined) return null
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  contractError(field)
}

/** Missing money keys are preserved as absent, never coerced to zero. */
function money(value: unknown, field: string): number | undefined {
  if (value === undefined) return undefined
  return requiredInteger(value, field)
}

function record(value: unknown, field: string): Raw {
  if (isRecord(value)) return value
  contractError(field)
}

function listOf(value: unknown, field: string): unknown[] {
  if (Array.isArray(value)) return value
  contractError(field)
}

export function normalisePayrollRun(raw: unknown): PayrollRunSummary {
  const row = record(raw, 'run')
  const status = text(row.status, 'status').toUpperCase() as PayrollRunStatus
  const runType = text(row.run_type, 'run_type').toUpperCase() as PayrollRunType

  if (!RUN_STATUSES.includes(status)) contractError('status')
  if (!RUN_TYPES.includes(runType)) contractError('run_type')

  return {
    id: requiredInteger(row.id, 'id'),
    period: text(row.period, 'period'),
    runType,
    status,
    correctionOfRunId: nullableInteger(row.correction_of_run_id, 'correction_of_run_id'),
    payDate: text(row.pay_date, 'pay_date'),
    employeeCount: requiredInteger(row.employee_count, 'employee_count'),
    createdAt: text(row.created_at, 'created_at'),
    grossCents: money(row.gross_cents, 'gross_cents'),
    deductionsCents: money(row.deductions_cents, 'deductions_cents'),
    netCents: money(row.net_cents, 'net_cents'),
    employerCostCents: money(row.employer_cost_cents, 'employer_cost_cents'),
  }
}

export function normalisePayrollEmployee(raw: unknown): PayrollEmployee {
  const row = record(raw, 'employee')
  const status = text(row.status, 'employee.status').toUpperCase() as PayrollEmployeeStatus

  if (!['PENDING', 'CALCULATED', 'EXCLUDED', 'FAILED'].includes(status)) {
    contractError('employee.status')
  }

  return {
    employmentId: requiredInteger(row.employment_id, 'employment_id'),
    status,
    failureReason: nullableText(row.failure_reason, 'failure_reason'),
    grossCents: money(row.gross_cents, 'gross_cents'),
    taxableCents: money(row.taxable_cents, 'taxable_cents'),
    deductionsCents: money(row.deductions_cents, 'deductions_cents'),
    netCents: money(row.net_cents, 'net_cents'),
    employerCostCents: money(row.employer_cost_cents, 'employer_cost_cents'),
    employeeSiCents: money(row.employee_si_cents, 'employee_si_cents'),
    employeeHfCents: money(row.employee_hf_cents, 'employee_hf_cents'),
    taxCents: money(row.tax_cents, 'tax_cents'),
  }
}

export function normalisePayrollRunDetail(raw: unknown): PayrollRunDetail {
  const row = record(raw, 'run_detail')

  return {
    ...normalisePayrollRun(row),
    schemeId: requiredInteger(row.scheme_id, 'scheme_id'),
    earningPeriodStart: text(row.earning_period_start, 'earning_period_start'),
    earningPeriodEnd: text(row.earning_period_end, 'earning_period_end'),
    postingPeriod: text(row.posting_period, 'posting_period'),
    cutoffAt: text(row.cutoff_at, 'cutoff_at'),
    preparedBy: requiredInteger(row.prepared_by, 'prepared_by'),
    reviewedBy: nullableInteger(row.reviewed_by, 'reviewed_by'),
    approvedBy: nullableInteger(row.approved_by, 'approved_by'),
    publishedBy: nullableInteger(row.published_by, 'published_by'),
    inputHash: nullableText(row.input_hash, 'input_hash'),
    snapshotVersion: requiredInteger(row.snapshot_version, 'snapshot_version'),
    reopenCount: requiredInteger(row.reopen_count, 'reopen_count'),
    employees: listOf(row.employees, 'employees').map(normalisePayrollEmployee),
  }
}

export function normalisePayrollInput(raw: unknown): PayrollInputSnapshot {
  const row = record(raw, 'input_snapshot')

  return {
    id: requiredInteger(row.id, 'id'),
    employmentId: requiredInteger(row.employment_id, 'employment_id'),
    snapshotType: text(row.snapshot_type, 'snapshot_type'),
    snapshotVersion: requiredInteger(row.snapshot_version, 'snapshot_version'),
    sourceHash: text(row.source_hash, 'source_hash'),
    payload: record(row.payload, 'payload'),
    createdAt: nullableText(row.created_at, 'created_at'),
  }
}

function page<T>(
  raw: unknown,
  field: string,
  normalise: (row: unknown) => T,
): { items: T[]; page: number; pageSize: number; total: number } {
  const row = record(raw, field)
  return {
    items: listOf(row.items, `${field}.items`).map(normalise),
    page: requiredInteger(row.page, `${field}.page`),
    pageSize: requiredInteger(row.page_size, `${field}.page_size`),
    total: requiredInteger(row.total, `${field}.total`),
  }
}

export async function listPayrollRuns(
  filters: PayrollRunFilters,
  pageNumber = 1,
  pageSize = 20,
): Promise<PayrollRunPage> {
  const params: Record<string, unknown> = {
    page: Math.max(1, Math.trunc(pageNumber)),
    page_size: Math.min(100, Math.max(1, Math.trunc(pageSize))),
  }
  if (filters.period !== undefined && filters.period !== '') params.period = filters.period
  if (filters.status !== undefined && filters.status !== '') params.status = filters.status

  return page(await resourceGet<unknown>('/v1/payroll/runs', params), 'run_page', normalisePayrollRun)
}

export async function getPayrollRun(id: number): Promise<PayrollRunDetail> {
  return normalisePayrollRunDetail(await resourceGet<unknown>(`/v1/payroll/runs/${id}`))
}

export async function getPayrollEmployees(id: number): Promise<PayrollEmployeePage> {
  return page(
    await resourceGet<unknown>(`/v1/payroll/runs/${id}/employees`),
    'employee_page',
    normalisePayrollEmployee,
  )
}

export async function getPayrollInputs(id: number): Promise<PayrollInputPage> {
  return page(
    await resourceGet<unknown>(`/v1/payroll/runs/${id}/inputs`),
    'input_page',
    normalisePayrollInput,
  )
}

export function normalisePayrollReconciliation(raw: unknown): PayrollReconciliation {
  const row = record(raw, 'reconciliation')
  const differences = listOf(row.differences, 'differences')

  if (!differences.every(isRecord)) contractError('differences')

  return {
    runId: requiredInteger(row.run_id, 'run_id'),
    period: text(row.period, 'period'),
    status: text(row.status, 'status'),
    employeeCount: requiredInteger(row.employee_count, 'employee_count'),
    payslipCount: requiredInteger(row.payslip_count, 'payslip_count'),
    grossCents: requiredInteger(row.gross_cents, 'gross_cents'),
    deductionsCents: requiredInteger(row.deductions_cents, 'deductions_cents'),
    netCents: requiredInteger(row.net_cents, 'net_cents'),
    employerCostCents: requiredInteger(row.employer_cost_cents, 'employer_cost_cents'),
    differences: differences as Array<Record<string, unknown>>,
    passed: row.passed === true,
  }
}

export async function getPayrollReconciliation(id: number): Promise<PayrollReconciliation> {
  return normalisePayrollReconciliation(
    await resourceGet<unknown>(`/v1/payroll/runs/${id}/reconciliation`),
  )
}

export function normalisePayrollExport(raw: unknown): PayrollExport {
  const row = record(raw, 'export')
  return {
    filename: text(row.filename, 'filename'),
    content: text(row.content, 'content'),
    sha256: text(row.sha256, 'sha256'),
  }
}

export async function exportPayrollRun(id: number, kind: PayrollExportKind): Promise<PayrollExport> {
  return normalisePayrollExport(
    await resourceGet<unknown>(`/v1/payroll/runs/${id}/exports/${kind}`),
  )
}

/**
 * Trigger the browser download from the bytes the API just returned. The URL
 * is a local blob for the response body; the network call above used the only
 * real export endpoint, and the filename comes from the server.
 */
export function savePayrollExport(file: PayrollExport): void {
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

export function newPayrollIdempotencyKey(prefix: string): string {
  const suffix =
    typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`
  return `payroll-${prefix}-${suffix}`.slice(0, 128)
}

export async function runPayrollAction(
  id: number,
  action: PayrollAction,
  body: Record<string, unknown> = {},
  idempotencyKey?: string,
): Promise<unknown> {
  return resourcePost<unknown>(`/v1/payroll/runs/${id}/${action}`, body, {
    idempotencyKey: idempotencyKey ?? newPayrollIdempotencyKey(action),
  })
}

/* ------------------------------------------------------------------ */
/* Versioned payroll catalogue and workspace tabs.                     */
/* ------------------------------------------------------------------ */

export type PayrollItemType = 'EARNING' | 'DEDUCTION' | 'EMPLOYER_COST' | 'INFO'
export type PayrollCalculation = 'FIXED' | 'ATTENDANCE_PRORATE' | 'PERCENT' | 'FORMULA_LIMITED'

export type PayrollItemRecord = {
  id: number
  itemVersionId: number
  code: string
  version: number
  status: string
  versionStatus: string
  name: string
  type: PayrollItemType
  taxable: boolean
  insurable: boolean
  calculation: PayrollCalculation
  formulaJson: Record<string, unknown> | null
  sortOrder: number
  effectiveFrom: string
  effectiveTo: string
  retiredAt: string | null
  retireReason: string | null
  isCurrent: boolean
  isRetired: boolean
}

export type PayrollSchemeRecord = {
  id: number
  code: string
  name: string
  currency: string
  version: number
  status: string
  prorationDivisor: string
  roundingMode: string
  overtimeMultiplier: string
  baseSalaryCents: number
  items: Array<{
    itemCode: string
    label: string
    category: string
    amountCents: number
    sortOrder: number
    taxable: boolean
  }>
}

export type EmployeeCompensationRecord = {
  id: number
  employmentId: number
  effectiveFrom: string
  currency: string
  salarySchemeCode: string | null
  createdBy: number
  createdAt: string | null
  baseSalaryCents?: number
}

export type SalaryAdjustmentRecord = EmployeeCompensationRecord & {
  caseId: number
  compensationId: number
  reason: string
}

export type MyPayslip = {
  id: number
  runId: number
  employmentId: number
  version: number
  publishedAt: string | null
  grossCents?: number
  deductionsCents?: number
  netCents?: number
  employerCostCents?: number
}

export type PayrollReportGroupRow = {
  period: string | null
  departmentId: number | null
  departmentName: string | null
  runCount: number
  employeeCount: number
  grossCents: number
  deductionsCents: number
  netCents: number
  employerCostCents: number
}

export type PayrollReportSummary = {
  asOf: string
  source: string
  groupBy: 'period' | 'department'
  currency: string
  items: PayrollReportGroupRow[]
  trend: PayrollReportGroupRow[]
  totals: {
    grossCents: number
    deductionsCents: number
    netCents: number
    employerCostCents: number
  }
}

function optionalMoney(row: Raw, key: string, field: string): number | undefined {
  return row[key] === undefined ? undefined : requiredInteger(row[key], field)
}

export function normalisePayrollItem(raw: unknown): PayrollItemRecord {
  const row = record(raw, 'payroll_item')
  const type = text(row.type, 'item.type') as PayrollItemType
  const calculation = text(row.calculation, 'item.calculation') as PayrollCalculation

  if (!['EARNING', 'DEDUCTION', 'EMPLOYER_COST', 'INFO'].includes(type)) contractError('item.type')
  if (!['FIXED', 'ATTENDANCE_PRORATE', 'PERCENT', 'FORMULA_LIMITED'].includes(calculation)) {
    contractError('item.calculation')
  }

  return {
    id: requiredInteger(row.id, 'item.id'),
    itemVersionId: requiredInteger(row.item_version_id, 'item.item_version_id'),
    code: text(row.code, 'item.code'),
    version: requiredInteger(row.version, 'item.version'),
    status: text(row.status, 'item.status'),
    versionStatus: text(row.version_status ?? row.status, 'item.version_status'),
    name: text(row.name, 'item.name'),
    type,
    taxable: row.taxable !== false,
    insurable: row.insurable !== false,
    calculation,
    formulaJson: isRecord(row.formula_json) ? row.formula_json : null,
    sortOrder: nullableInteger(row.sort_order, 'item.sort_order') ?? 0,
    effectiveFrom: text(row.effective_from, 'item.effective_from'),
    effectiveTo: nullableText(row.effective_to, 'item.effective_to') ?? '9999-12-31',
    retiredAt: nullableText(row.retired_at, 'item.retired_at'),
    retireReason: nullableText(row.retire_reason, 'item.retire_reason'),
    // Write responses return a single version without the list-only flags;
    // derive them from status so a fresh create/append remains renderable.
    isCurrent: row.is_current === true || row.is_current === undefined,
    isRetired: row.is_retired === true || row.status === 'RETIRED',
  }
}

export async function listPayrollItems(): Promise<PayrollItemRecord[]> {
  const payload = record(await resourceGet<unknown>('/v1/payroll/items'), 'payroll_items')
  return listOf(payload.items, 'payroll_items.items').map(normalisePayrollItem)
}

export async function createPayrollItem(
  input: Record<string, unknown>,
  idempotencyKey = newPayrollIdempotencyKey('item'),
): Promise<PayrollItemRecord> {
  return normalisePayrollItem(
    await resourcePost<unknown>('/v1/payroll/items', input, { idempotencyKey }),
  )
}

export async function createPayrollItemVersion(
  itemId: number,
  input: Record<string, unknown>,
  idempotencyKey = newPayrollIdempotencyKey('item-version'),
): Promise<PayrollItemRecord> {
  return normalisePayrollItem(
    await resourcePost<unknown>(`/v1/payroll/items/${itemId}/versions`, input, { idempotencyKey }),
  )
}

export async function retirePayrollItem(
  itemId: number,
  reason: string,
  idempotencyKey = newPayrollIdempotencyKey('item-retire'),
): Promise<PayrollItemRecord> {
  return normalisePayrollItem(
    await resourcePost<unknown>(`/v1/payroll/items/${itemId}/retire`, { reason }, { idempotencyKey }),
  )
}

export function normalisePayrollScheme(raw: unknown): PayrollSchemeRecord {
  const row = record(raw, 'scheme')
  const items = listOf(row.items ?? [], 'scheme.items')

  return {
    id: requiredInteger(row.id, 'scheme.id'),
    code: text(row.code, 'scheme.code'),
    name: text(row.name, 'scheme.name'),
    currency: text(row.currency, 'scheme.currency'),
    version: requiredInteger(row.version, 'scheme.version'),
    status: text(row.status, 'scheme.status'),
    prorationDivisor: text(row.proration_divisor, 'scheme.proration_divisor'),
    roundingMode: text(row.rounding_mode, 'scheme.rounding_mode'),
    overtimeMultiplier: text(row.overtime_multiplier, 'scheme.overtime_multiplier'),
    baseSalaryCents: requiredInteger(row.base_salary_cents, 'scheme.base_salary_cents'),
    items: items.map((candidate) => {
      const item = record(candidate, 'scheme.item')
      return {
        itemCode: text(item.item_code, 'scheme.item.item_code'),
        label: text(item.label, 'scheme.item.label'),
        category: text(item.category, 'scheme.item.category'),
        amountCents: requiredInteger(item.amount_cents, 'scheme.item.amount_cents'),
        sortOrder: nullableInteger(item.sort_order, 'scheme.item.sort_order') ?? 0,
        taxable: item.taxable !== false,
      }
    }),
  }
}

export async function listPayrollSchemes(): Promise<PayrollSchemeRecord[]> {
  const payload = record(await resourceGet<unknown>('/v1/payroll/schemes'), 'payroll_schemes')
  return listOf(payload.items, 'payroll_schemes.items').map(normalisePayrollScheme)
}

export async function createPayrollScheme(
  input: Record<string, unknown>,
  idempotencyKey = newPayrollIdempotencyKey('scheme'),
): Promise<PayrollSchemeRecord> {
  const payload = await resourcePost<Record<string, unknown>>('/v1/payroll/schemes', input, { idempotencyKey })
  return normalisePayrollScheme({
    ...payload,
    items: [],
    proration_divisor: payload.proration_divisor ?? 'ANNUAL_DAYS_365',
    rounding_mode: payload.rounding_mode ?? 'HALF_UP',
    overtime_multiplier: payload.overtime_multiplier ?? '1.500000',
    base_salary_cents: payload.base_salary_cents ?? 0,
    status: payload.status ?? 'ACTIVE',
  })
}

export function normaliseCompensation(raw: unknown): EmployeeCompensationRecord {
  const row = record(raw, 'employee_compensation')
  return {
    id: requiredInteger(row.id, 'compensation.id'),
    employmentId: requiredInteger(row.employment_id, 'compensation.employment_id'),
    effectiveFrom: text(row.effective_from, 'compensation.effective_from'),
    currency: text(row.currency, 'compensation.currency'),
    salarySchemeCode: nullableText(row.salary_scheme_code, 'compensation.salary_scheme_code'),
    createdBy: requiredInteger(row.created_by, 'compensation.created_by'),
    createdAt: nullableText(row.created_at, 'compensation.created_at'),
    baseSalaryCents: optionalMoney(row, 'base_salary_cents', 'compensation.base_salary_cents'),
  }
}

export function normaliseSalaryAdjustment(raw: unknown): SalaryAdjustmentRecord {
  const row = record(raw, 'salary_adjustment')
  return {
    ...normaliseCompensation(row),
    caseId: requiredInteger(row.case_id, 'adjustment.case_id'),
    compensationId: requiredInteger(row.compensation_id, 'adjustment.compensation_id'),
    reason: text(row.reason, 'adjustment.reason'),
  }
}

export async function listEmployeeCompensations(employmentId?: number): Promise<EmployeeCompensationRecord[]> {
  const payload = record(
    await resourceGet<unknown>('/v1/payroll/employee-plans', {
      page: 1,
      page_size: 100,
      ...(employmentId !== undefined ? { employment_id: employmentId } : {}),
    }),
    'employee_compensations',
  )
  return listOf(payload.items, 'employee_compensations.items').map(normaliseCompensation)
}

export async function listSalaryAdjustments(employmentId?: number): Promise<SalaryAdjustmentRecord[]> {
  const payload = record(
    await resourceGet<unknown>('/v1/payroll/adjustments', {
      page: 1,
      page_size: 100,
      ...(employmentId !== undefined ? { employment_id: employmentId } : {}),
    }),
    'salary_adjustments',
  )
  return listOf(payload.items, 'salary_adjustments.items').map(normaliseSalaryAdjustment)
}

/**
 * CompensationController currently returns its write body as a nested
 * envelope. Unwrap exactly one `data` layer at the API boundary so the page
 * never has to know about the legacy transport quirk.
 */
export async function createEmployeeCompensation(
  input: Record<string, unknown>,
  idempotencyKey = newPayrollIdempotencyKey('compensation'),
): Promise<EmployeeCompensationRecord> {
  const response = record(
    await resourcePost<unknown>('/v1/payroll/employee-plans', input, { idempotencyKey }),
    'employee_compensation_write',
  )
  const row = isRecord(response.data) ? response.data : response
  return normaliseCompensation(row)
}

export function normaliseMyPayslip(raw: unknown): MyPayslip {
  const row = record(raw, 'payslip')
  return {
    id: requiredInteger(row.id, 'payslip.id'),
    runId: requiredInteger(row.run_id, 'payslip.run_id'),
    employmentId: requiredInteger(row.employment_id, 'payslip.employment_id'),
    version: requiredInteger(row.version, 'payslip.version'),
    publishedAt: nullableText(row.published_at, 'payslip.published_at'),
    grossCents: optionalMoney(row, 'gross_cents', 'payslip.gross_cents'),
    deductionsCents: optionalMoney(row, 'deductions_cents', 'payslip.deductions_cents'),
    netCents: optionalMoney(row, 'net_cents', 'payslip.net_cents'),
    employerCostCents: optionalMoney(row, 'employer_cost_cents', 'payslip.employer_cost_cents'),
  }
}

export async function listMyPayslips(period?: string): Promise<MyPayslip[]> {
  const payload = record(
    await resourceGet<unknown>('/v1/payroll/payslips', period ? { period } : undefined),
    'my_payslips',
  )
  return listOf(payload.items, 'my_payslips.items').map(normaliseMyPayslip)
}

export function normalisePayrollReportSummary(raw: unknown): PayrollReportSummary {
  const payload = record(raw, 'payroll_report')
  const rows = (candidate: unknown): PayrollReportGroupRow[] =>
    listOf(candidate, 'payroll_report.items').map((entry) => {
      const row = record(entry, 'payroll_report.row')
      return {
        period: nullableText(row.period, 'report.period'),
        departmentId: nullableInteger(row.department_id, 'report.department_id'),
        departmentName: nullableText(row.department_name, 'report.department_name'),
        runCount: requiredInteger(row.run_count, 'report.run_count'),
        employeeCount: requiredInteger(row.employee_count, 'report.employee_count'),
        grossCents: requiredInteger(row.gross_cents, 'report.gross_cents'),
        deductionsCents: requiredInteger(row.deductions_cents, 'report.deductions_cents'),
        netCents: requiredInteger(row.net_cents, 'report.net_cents'),
        employerCostCents: requiredInteger(row.employer_cost_cents, 'report.employer_cost_cents'),
      }
    })
  const totals = record(payload.totals, 'payroll_report.totals')

  return {
    asOf: text(payload.as_of, 'report.as_of'),
    source: text(payload.source, 'report.source'),
    groupBy: payload.group_by === 'department' ? 'department' : 'period',
    currency: text(payload.currency, 'report.currency'),
    items: rows(payload.items),
    trend: rows(payload.trend ?? []),
    totals: {
      grossCents: requiredInteger(totals.gross_cents, 'report.totals.gross_cents'),
      deductionsCents: requiredInteger(totals.deductions_cents, 'report.totals.deductions_cents'),
      netCents: requiredInteger(totals.net_cents, 'report.totals.net_cents'),
      employerCostCents: requiredInteger(totals.employer_cost_cents, 'report.totals.employer_cost_cents'),
    },
  }
}

export async function getPayrollReportSummary(params: {
  period?: string
  from?: string
  to?: string
  groupBy?: 'period' | 'department'
}): Promise<PayrollReportSummary> {
  const query: Record<string, unknown> = {}
  if (params.period) query.period = params.period
  if (params.from) query.from = params.from
  if (params.to) query.to = params.to
  if (params.groupBy) query.group_by = params.groupBy
  return normalisePayrollReportSummary(await resourceGet<unknown>('/v1/payroll/reports/summary', query))
}

/* ------------------------------------------------------------------ */
/* Render-only Chinese copy. Values never feed back into a money model. */
/* ------------------------------------------------------------------ */

export const PAYROLL_STATUS_LABELS: Record<PayrollRunStatus, string> = {
  DRAFT: '草稿',
  INPUT_LOCKED: '输入已锁定',
  CALCULATED: '已试算',
  REVIEWED: '已复核',
  APPROVED: '已批准',
  PUBLISHED: '已发布',
  PAID: '已发放',
  CLOSED: '已关闭',
  CANCELLED: '已取消',
}

/** The four workspace-level phases required by the product brief. */
export type PayrollStage = '草稿' | '已提交' | '已批准' | '已发布' | '已取消'

export function payrollStage(status: PayrollRunStatus): PayrollStage {
  switch (status) {
    case 'DRAFT':
      return '草稿'
    case 'INPUT_LOCKED':
    case 'CALCULATED':
    case 'REVIEWED':
      return '已提交'
    case 'APPROVED':
      return '已批准'
    case 'PUBLISHED':
    case 'PAID':
    case 'CLOSED':
      return '已发布'
    case 'CANCELLED':
      return '已取消'
  }
}

export function payrollStageTone(stage: PayrollStage): 'draft' | 'submitted' | 'approved' | 'published' | 'cancelled' {
  switch (stage) {
    case '草稿':
      return 'draft'
    case '已提交':
      return 'submitted'
    case '已批准':
      return 'approved'
    case '已发布':
      return 'published'
    case '已取消':
      return 'cancelled'
  }
}

export function payrollRunTypeLabel(type: PayrollRunType): string {
  return {
    REGULAR: '常规批次',
    CORRECTION: '更正批次',
    OFF_CYCLE: '离周期批次',
    BONUS: '奖金批次',
  }[type]
}

export function payrollEmployeeStatusLabel(status: PayrollEmployeeStatus): string {
  return {
    PENDING: '待计算',
    CALCULATED: '已计算',
    EXCLUDED: '已排除',
    FAILED: '计算失败',
  }[status]
}

export function payrollActionLabel(action: PayrollAction): string {
  return {
    'lock-inputs': '准备（锁定输入）',
    calculate: '试算',
    review: '提交复核',
    approve: '批准',
    publish: '发布',
    'mark-paid': '标记发放',
    close: '关闭批次',
    cancel: '取消批次',
  }[action]
}

export function payrollExportLabel(kind: PayrollExportKind): string {
  return kind === 'bank' ? '银行代发导出' : '税务申报导出'
}

export function payrollPeriodLabel(period: string): string {
  const match = /^(\d{4})-(\d{2})$/.exec(period)
  if (match === null) return period
  return `${match[1]} 年 ${Number(match[2])} 月`
}

export function isRunReadOnly(status: PayrollRunStatus): boolean {
  return status === 'PUBLISHED' || status === 'PAID' || status === 'CLOSED'
}

export function canExportStatus(status: PayrollRunStatus): boolean {
  return ['APPROVED', 'PUBLISHED', 'PAID', 'CLOSED'].includes(status)
}

/**
 * Stable Chinese explanation for a failed command. A raw provider reason is
 * never shown to the operator; unknown failures fall back to the shared copy.
 */
export function payrollActionErrorCopy(error: unknown): string {
  if (error instanceof ApiError) {
    switch (error.serverReason) {
      case 'INVALID_RUN_TRANSITION':
        return '当前状态不允许该操作。请刷新后按提示重新选择。'
      case 'RUN_NOT_LOCKABLE':
        return '仅草稿批次可以锁定输入。'
      case 'RUN_NOT_CALCULABLE':
        return '仅输入已锁定的批次可以试算。'
      case 'RUN_NOT_APPROVABLE':
        return '仅已复核的批次可以批准。'
      case 'RUN_SEALED':
        return '已发布批次为只读，不能取消或修改；如需更正请新建更正批次。'
      case 'RUN_NOT_EXPORTABLE':
        return '仅已批准或已发布的批次可以导出。'
      case 'RUN_NOT_PAYABLE':
        return '仅已发布的批次可以标记发放。'
      case 'SOD_CONFLICT':
        return '准备、复核、批准与发布必须由不同人员完成。'
      case 'MFA_STEP_UP_REQUIRED':
        return '此操作需要重新完成多因素认证后再试。'
      case 'NO_PAYSLIPS':
        return '该批次没有可导出的工资条。'
      case 'NO_EMPLOYEES':
        return '该批次没有可导出的员工明细。'
      case 'CAPABILITY_DENIED':
        return '当前账号没有执行该操作的权限。'
    }
  }

  return errorCopy(error)
}
