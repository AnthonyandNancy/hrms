import { describe, expect, it } from 'vitest'
import { PAGE_REGISTRY } from '@/router/registry'
import { PAGE_COMPONENTS as TIME_PAGES } from '@/modules/time/workspaces'
import { PAGE_COMPONENTS as PAYROLL_PAGES } from '@/modules/payroll/pages'

const TIME_KEYS = ['attendance/index', 'leave/index', 'overtime/index', 'travel/index']
const PAYROLL_KEYS = ['payroll/workspace', 'payroll/runs/by-id']

describe('V2.3 time and payroll module pages', () => {
  it('keeps one workspace loader per time module and two payroll loaders', () => {
    expect(Object.keys(TIME_PAGES).sort()).toEqual([...TIME_KEYS].sort())
    expect(Object.keys(PAYROLL_PAGES).sort()).toEqual([...PAYROLL_KEYS].sort())
  })

  it('resolves every loader to a real component module', async () => {
    for (const loader of [...Object.values(TIME_PAGES), ...Object.values(PAYROLL_PAGES)]) {
      const module = await loader()
      expect(module.default).toBeTruthy()
    }
  })

  it('resolves every Wave 3 registry component key exactly once', () => {
    const registryKeys = PAGE_REGISTRY
      .filter((page) => page.wave === 'Wave 3')
      .map((page) => page.componentKey)
      .sort()
    expect(registryKeys).toEqual([...TIME_KEYS, ...PAYROLL_KEYS].sort())
  })

  it('does not restore any folded subpage loader', () => {
    const all = [...Object.keys(TIME_PAGES), ...Object.keys(PAYROLL_PAGES)]
    for (const retired of [
      'attendance/punches', 'attendance/rules', 'leave/requests', 'leave/policies',
      'overtime/policies', 'travel/requests', 'payroll/components', 'payroll/statutory',
    ]) {
      expect(all).not.toContain(retired)
    }
  })
})
