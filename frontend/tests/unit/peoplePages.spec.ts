import { describe, expect, it } from 'vitest'
import { PAGE_REGISTRY } from '@/router/registry'
import { PAGE_COMPONENTS as PEOPLE_PAGES } from '@/modules/people/pages'

const PEOPLE_KEYS = [
  'hr/employees/index', 'hr/employees/by-id',
  'hr/departments/index', 'hr/positions/index',
  'hr/movements/index', 'hr/movements/by-id',
  'hr/contracts/index', 'hr/contracts/by-id',
]

describe('V2.3 people module pages', () => {
  it('keeps exactly the eight surviving workspace/detail loaders', () => {
    expect(Object.keys(PEOPLE_PAGES).sort()).toEqual([...PEOPLE_KEYS].sort())
  })

  it('resolves every loader to a real component module', async () => {
    for (const loader of Object.values(PEOPLE_PAGES)) {
      expect((await loader()).default).toBeTruthy()
    }
  })

  it('resolves every Wave 2 registry component key exactly once', () => {
    expect(
      PAGE_REGISTRY.filter((page) => page.wave === 'Wave 2').map((page) => page.componentKey).sort(),
    ).toEqual([...PEOPLE_KEYS].sort())
  })

  it('does not restore any folded employee/department/position/movement/contract subpage', () => {
    const keys = Object.keys(PEOPLE_PAGES)
    for (const retired of [
      'hr/employees/imports', 'hr/employees/by-id/files',
      'hr/departments/list', 'hr/departments/members',
      'hr/positions/headcount', 'hr/movements/onboarding',
      'hr/contracts/renewals', 'hr/contracts/templates',
    ]) expect(keys).not.toContain(retired)
  })
})
