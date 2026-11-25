import { describe, expect, it } from 'vitest'
import { can, firstAllowedPath, visibleCaseActions, visibleNavigation } from '@/router/navigation'

describe('capability-driven navigation', () => {
  it('hides child pages for which the server did not grant the capability', () => {
    const navigation = visibleNavigation(['workflow.case.read'])
    const paths = navigation.flatMap((item) => item.children.map((child) => child.path))
    expect(navigation[0]?.key).toBe('workspace')
    expect(paths).not.toContain('/hr/employees')
    expect(paths).not.toContain('/reports')
  })

  it('shows the system governance group only with a granted system capability', () => {
    const system = visibleNavigation(['iam.user.read']).find((item) => item.group === 'system')
    expect(system?.children.some((child) => child.path === '/system/users')).toBe(true)

    const roleManager = visibleNavigation(['iam.role.manage']).find((item) => item.group === 'system')
    expect(roleManager?.children.some((child) => child.path === '/system/roles')).toBe(true)
    expect(visibleNavigation(['random.capability']).find((item) => item.group === 'system')).toBeUndefined()
  })

  it('keeps the 17-group order stable across capability sets', () => {
    const full = visibleNavigation([
      'workflow.case.read',
      'people.employee.read',
      'org.unit.read',
      'time.leave.read',
      'time.attendance.read',
      'payroll.run.read',
      'payroll.payslip.read.self',
      'reporting.dashboard.read',
      'reporting.export',
      'engagement.notice.read',
      'iam.user.read',
    ]).map((item) => item.key)
    expect(full[0]).toBe('workspace')
    expect(full.indexOf('people')).toBeLessThan(full.indexOf('leave'))
    expect(full.indexOf('reports')).toBeLessThan(full.indexOf('people'))
    expect(full.indexOf('payroll')).toBeLessThan(full.indexOf('engagement'))
  })

  it('filters case action buttons by the matching capability', () => {
    expect(visibleCaseActions([], ['APPROVE', 'RETRY_EFFECT'])).toEqual([])
    expect(visibleCaseActions(['workflow.case.decide'], ['APPROVE', 'REJECT', 'RETRY_EFFECT'])).toEqual([
      'APPROVE',
      'REJECT',
    ])
    expect(visibleCaseActions(['workflow.case.admin'], ['APPROVE', 'RETRY_EFFECT'])).toEqual(['RETRY_EFFECT'])
  })

  it('provides deterministic fallback paths and capability checks', () => {
    expect(firstAllowedPath([])).toBe('/workspace')
    expect(firstAllowedPath(['people.employee.read'])).toBe('/workspace')
    expect(can([], undefined)).toBe(true)
    expect(can([], 'people.employee.read')).toBe(false)
  })
})
