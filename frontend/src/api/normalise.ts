import type {
  ApplicationStatus,
  CaseSummary,
  EffectStatus,
  PayrollLine,
  PayrollRunDetail,
  PayrollRunState,
  PayrollRunSummary,
  ReconciliationSummary,
} from '@/api/contracts'

/**
 * The backend uses snake_case column names; the views use camelCase view
 * models. Mapping lives here so a field rename breaks exactly one place instead
 * of silently rendering `undefined` behind an `as never` cast.
 */

type Raw = Record<string, unknown>

function num(value: unknown, fallback = 0): number {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : fallback
}

function str(value: unknown, fallback = ''): string {
  return value === null || value === undefined ? fallback : String(value)
}

function payloadOf(raw: Raw): Raw {
  const payload = raw.payload
  return payload !== null && typeof payload === 'object' ? (payload as Raw) : {}
}

/**
 * Applicant display fields are only present when the case payload carries
 * them. The adapter never fabricates a name: an em dash is the honest value
 * for "the workflow payload has no denormalised person label".
 */
export function normaliseCase(raw: Raw): CaseSummary {
  const payload = payloadOf(raw)

  return {
    id: num(raw.id),
    caseNo: str(raw.case_no),
    title: str(raw.title),
    type: str(raw.type),
    applicantName: str(payload.applicant_name, '—'),
    applicantEmploymentNo: str(payload.employee_no, '—'),
    applicantOrgName: str(payload.org_name, '—'),
    appliedAt: str(raw.submitted_at ?? raw.business_date),
    applicationStatus: str(raw.application_status, 'DRAFT') as ApplicationStatus,
    effectStatus: (raw.effect_status ?? null) as EffectStatus | null,
    currentNode: raw.current_node_key === null || raw.current_node_key === undefined ? null : String(raw.current_node_key),
    dueAt: null,
    isMine: Boolean(raw.is_mine),
    isAwaitingMe: Boolean(raw.is_awaiting_me),
  }
}

export function normaliseCasePage(raw: Raw, fallbackPage: number, fallbackPageSize: number) {
  const items = Array.isArray(raw.items) ? raw.items : []

  return {
    items: items.map((row) => normaliseCase(row as Raw)),
    page: num(raw.page, fallbackPage),
    page_size: num(raw.page_size, fallbackPageSize),
    total: num(raw.total),
  }
}

/**
 * `run_no` does not exist yet, so the identifier is composed from the period
 * and primary key. Prefer the server value whenever it appears.
 */
export function normalisePayrollRun(raw: Raw): PayrollRunSummary {
  const period = str(raw.period)
  const id = num(raw.id)

  return {
    id,
    runNo: str(raw.run_no, `RUN-${period}-${id}`),
    period,
    state: str(raw.status, 'DRAFT') as PayrollRunState,
    employeeCount: num(raw.employee_count),
    grossCents: num(raw.gross_cents),
    netCents: num(raw.net_cents),
    payDate: str(raw.pay_date),
    updatedAt: str(raw.created_at),
    isCorrection: str(raw.run_type) === 'CORRECTION',
    freshAt: str(raw.cutoff_at ?? raw.created_at),
  }
}

/**
 * Employee label fields are not part of the payroll projection, so rows show
 * the employment id. Amount keys disappear for readers without field access;
 * they map to 0 rather than `undefined` so the table stays numeric.
 */
export function normalisePayrollLines(raw: unknown): PayrollLine[] {
  if (!Array.isArray(raw)) return []

  return raw.map((row) => {
    const line = row as Raw
    const employmentId = num(line.employment_id)
    const status = str(line.status, 'PENDING')

    return {
      employmentId,
      employeeName: str(line.employee_name, `员工 #${employmentId}`),
      employeeNo: str(line.employee_no, '—'),
      orgName: str(line.org_name, '—'),
      grossCents: num(line.gross_cents),
      deductionsCents: num(line.deductions_cents),
      netCents: num(line.net_cents),
      varianceCents: num(line.variance_cents),
      // Server statuses are PENDING/CALCULATED/EXCLUDED/FAILED; the view only
      // distinguishes verified, needs-review and failed rows.
      status: status === 'CALCULATED' ? 'OK' : status === 'FAILED' ? 'FAILED' : 'REVIEW',
    }
  })
}

export function normaliseReconciliation(raw: unknown): ReconciliationSummary | null {
  if (raw === null || raw === undefined || typeof raw !== 'object') return null

  const data = raw as Raw
  const issues = Array.isArray(data.issues) ? data.issues.length : num(data.blocked_issues)
  const headcount = num(data.headcount, num(data.calculated_headcount))

  return {
    sourceHeadcount: num(data.source_headcount, headcount),
    calculatedHeadcount: num(data.calculated_headcount, headcount),
    grossVarianceCents: num(data.gross_variance_cents),
    netVarianceCents: num(data.net_variance_cents),
    blockedIssues: issues,
    note: str(data.note, issues > 0 ? '存在需要确认的差异。' : '员工结果与工资条一致。'),
  }
}

/**
 * Actions are derived from the run state and the caller's capabilities. The
 * server enforces the same transitions; this only decides what to offer.
 */
export function derivePayrollActions(
  state: PayrollRunState,
  can: (capability: string) => boolean,
): PayrollRunDetail['availableActions'] {
  const actions: PayrollRunDetail['availableActions'] = []

  if (state === 'DRAFT' && can('payroll.run.prepare')) actions.push('LOCK_INPUT')
  if (state === 'INPUT_LOCKED' && can('payroll.run.prepare')) actions.push('CALCULATE')
  if (state === 'CALCULATED' && can('payroll.run.review')) actions.push('REVIEW')
  if (state === 'REVIEWED' && can('payroll.run.approve')) actions.push('APPROVE')
  if (state === 'APPROVED' && can('payroll.run.publish')) actions.push('PUBLISH')
  if (state === 'PUBLISHED' && can('payroll.run.prepare')) actions.push('CREATE_CORRECTION')

  return actions
}
