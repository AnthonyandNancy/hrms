import { describe, expect, it } from 'vitest'
import { can, firstAllowedPath, visibleCaseActions, visibleNavigation } from '@/router/navigation'

describe('capability-driven navigation', () => {
  it('hides pages for which the server did not grant the capability', () => {
    const paths = visibleNavigation(['workflow.case.view.mine']).map((item) => item.path)
    expect(paths).toContain('/workspace')
    expect(paths).toContain('/cases')
    expect(paths).not.toContain('/employees')
    expect(paths).not.toContain('/admin')
    expect(paths).not.toContain('/reports')
  })

  it('shows the admin governance entry only with iam.user.view', () => {
    expect(visibleNavigation(['iam.user.view']).some((item) => item.key === 'admin')).toBe(true)
    expect(visibleNavigation(['iam.role.view']).some((item) => item.key === 'admin')).toBe(false)
  })

  it('keeps navigation order stable across capability sets', () => {
    const full = visibleNavigation([
      'workflow.case.view.mine',
      'people.directory.view',
      'organization.view',
      'time.leave.view',
      'time.attendance.view.mine',
      'payroll.run.view',
      'payroll.payslip.view.mine',
      'reporting.dashboard.view',
      'reporting.report.view',
      'engagement.notice.view',
      'iam.user.view',
    ]).map((item) => item.key)
    expect(full[0]).toBe('workspace')
    expect(full.indexOf('directory')).toBeLessThan(full.indexOf('leave'))
    expect(full.indexOf('payroll')).toBeLessThan(full.indexOf('reports'))
  })

  it('filters case action buttons by the matching capability', () => {
    expect(visibleCaseActions([], ['APPROVE', 'RETRY_EFFECT'])).toEqual([])
    expect(visibleCaseActions(['workflow.case.decide'], ['APPROVE', 'REJECT', 'RETRY_EFFECT'])).toEqual([
      'APPROVE',
      'REJECT',
    ])
    expect(visibleCaseActions(['workflow.effect.retry'], ['APPROVE', 'RETRY_EFFECT'])).toEqual(['RETRY_EFFECT'])
  })

  it('provides deterministic fallback paths and capability checks', () => {
    expect(firstAllowedPath([])).toBe('/workspace')
    expect(firstAllowedPath(['people.directory.view'])).toBe('/workspace')
    expect(can([], undefined)).toBe(true)
    expect(can([], 'people.directory.view')).toBe(false)
  })
})
