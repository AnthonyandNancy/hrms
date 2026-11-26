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
