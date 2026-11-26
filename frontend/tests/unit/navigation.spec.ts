import { describe, expect, it } from 'vitest'
import router from '@/router'
import { PAGE_REGISTRY } from '@/router/registry'
import { NAVIGATION, can, firstAllowedPath, visibleCaseActions, visibleNavigation } from '@/router/navigation'

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


describe('expandable navigation affordance', () => {
  it('marks every child-bearing menu group expandable while leaving the workspace entry plain', () => {
    const parents = NAVIGATION.filter((item) => item.children.length > 0 && item.key !== 'workspace')
    expect(parents.length).toBeGreaterThan(0)
    expect(parents.every((item) => item.expandable === true)).toBe(true)
    expect(NAVIGATION.find((item) => item.key === 'workspace')?.expandable).toBe(false)
  })

  it('gives every child an explicit icon inherited from its registry page', () => {
    const children = new Map(NAVIGATION.flatMap((item) => item.children).map((child) => [child.key, child]))

    for (const page of PAGE_REGISTRY) {
      if (page.menu === null) continue
      const child = children.get(page.name)
      expect(child, page.name).toBeDefined()
      expect(child?.icon, page.name).toBe(page.menu.icon)
      expect(child?.icon, page.name).not.toBe('')
    }
  })

  it('uses a distinct semantic icon for every registry menu group', () => {
    const iconByGroup = new Map<string, string>()

    for (const page of PAGE_REGISTRY) {
      if (page.menu === null) continue
      const existing = iconByGroup.get(page.menu.group)
      if (existing !== undefined) {
        expect(page.menu.icon, page.menu.group).toBe(existing)
      } else {
        iconByGroup.set(page.menu.group, page.menu.icon)
      }
    }

    expect(iconByGroup.size).toBe(18)
    expect([...iconByGroup.values()].every((icon) => icon !== 'Menu')).toBe(true)
    expect(new Set(iconByGroup.values()).size).toBe(iconByGroup.size)
  })

  it('stamps meta.navKey on every registry-backed route', () => {
    for (const page of PAGE_REGISTRY) {
      const resolved = router.resolve(page.path.replace(/:id/g, '1'))
      expect(resolved.meta.navKey, page.path).toBe(page.name)
    }
  })
})
