import { describe, expect, it } from 'vitest'
import { derivePayrollActions, normalisePayrollLines, normalisePayrollRun, normaliseReconciliation } from '@/api/normalise'

/** Shape returned by `GET /v1/payroll/runs/{id}` (PayrollRunService::runDetail). */
const backendRun = {
  id: 7,
  legal_entity_id: 1,
  period: '2026-11',
  run_type: 'REGULAR',
  status: 'CALCULATED',
  correction_of_run_id: null,
  pay_date: '2026-11-30',
  created_at: '2026-11-24T02:00:00Z',
  cutoff_at: '2026-11-24T02:00:00Z',
  employee_count: 2,
  employees: [
    { employment_id: 501, status: 'CALCULATED', gross_cents: 1200000, deductions_cents: 180000, net_cents: 1020000 },
    { employment_id: 502, status: 'FAILED', gross_cents: 0, deductions_cents: 0, net_cents: 0, failure_reason: 'MISSING_TAX_PROFILE' },
  ],
}

describe('normalisePayrollRun', () => {
  it('maps the run header fields the view renders', () => {
    const run = normalisePayrollRun(backendRun)

    expect(run.id).toBe(7)
    expect(run.period).toBe('2026-11')
    expect(run.state).toBe('CALCULATED')
    expect(run.employeeCount).toBe(2)
    expect(run.payDate).toBe('2026-11-30')
    expect(run.isCorrection).toBe(false)
    expect(run.runNo).toBe('RUN-2026-11-7')
  })

  it('marks correction runs from run_type', () => {
    expect(normalisePayrollRun({ ...backendRun, run_type: 'CORRECTION' }).isCorrection).toBe(true)
  })
})

describe('normalisePayrollLines', () => {
  it('maps employee rows and falls back to the employment id for labels', () => {
    const lines = normalisePayrollLines(backendRun.employees)

    expect(lines).toHaveLength(2)
    expect(lines[0]).toMatchObject({
      employmentId: 501,
      employeeName: '员工 #501',
      grossCents: 1200000,
      netCents: 1020000,
      status: 'OK',
    })
    expect(lines[1]?.status).toBe('FAILED')
  })

  it('returns an empty list for a missing projection', () => {
    expect(normalisePayrollLines(undefined)).toEqual([])
  })
})

describe('normaliseReconciliation', () => {
  it('returns null when the run has no reconciliation payload', () => {
    expect(normaliseReconciliation(null)).toBeNull()
  })

  it('counts issue rows as blocked issues', () => {
    const summary = normaliseReconciliation({
      source_headcount: 2,
      calculated_headcount: 2,
      gross_variance_cents: 0,
      net_variance_cents: 0,
      issues: [{ code: 'PAYSLIP_WITHOUT_EMPLOYEE' }],
    })

    expect(summary?.blockedIssues).toBe(1)
    expect(summary?.calculatedHeadcount).toBe(2)
  })
})

describe('derivePayrollActions', () => {
  const all = () => true
  const none = () => false

  it('offers only the transition the state machine allows', () => {
    expect(derivePayrollActions('DRAFT', all)).toEqual(['LOCK_INPUT'])
    expect(derivePayrollActions('INPUT_LOCKED', all)).toEqual(['CALCULATE'])
    expect(derivePayrollActions('CALCULATED', all)).toEqual(['REVIEW'])
    expect(derivePayrollActions('REVIEWED', all)).toEqual(['APPROVE'])
    expect(derivePayrollActions('APPROVED', all)).toEqual(['PUBLISH'])
    expect(derivePayrollActions('PUBLISHED', all)).toEqual(['CREATE_CORRECTION'])
  })

  it('offers nothing without the matching capability', () => {
    expect(derivePayrollActions('DRAFT', none)).toEqual([])
    expect(derivePayrollActions('APPROVED', none)).toEqual([])
  })

  it('offers nothing in terminal states', () => {
    expect(derivePayrollActions('CLOSED', all)).toEqual([])
    expect(derivePayrollActions('PAID', all)).toEqual([])
  })
})
