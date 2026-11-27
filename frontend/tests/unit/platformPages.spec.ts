import { describe, expect, it } from 'vitest'
import { PAGE_REGISTRY } from '@/router/registry'
import { PAGE_COMPONENTS as PLATFORM_PAGES } from '@/modules/platform/pages'

const PLATFORM_KEYS = [
  'account', 'system/system-users', 'system/system-roles', 'system/system-pages',
  'workflow', 'workflow/cases/by-id', 'benefits', 'system/system-settings',
  'notifications', 'system/system-audit',
]

describe('V2.3 platform module pages', () => {
  it('keeps exactly the ten consolidated governance loaders', () => {
    expect(Object.keys(PLATFORM_PAGES).sort()).toEqual([...PLATFORM_KEYS].sort())
  })

  it('resolves every loader to a real component module', async () => {
    for (const loader of Object.values(PLATFORM_PAGES)) {
      expect((await loader()).default).toBeTruthy()
    }
  })

  it('resolves every platform-layer registry component key exactly once', () => {
    expect(
      PAGE_REGISTRY.filter((page) => page.layer === 'platform').map((page) => page.componentKey).sort(),
    ).toEqual([...PLATFORM_KEYS].sort())
  })

  it('does not restore the retired IAM/notice/settings subpages', () => {
    const keys = Object.keys(PLATFORM_PAGES)
    for (const retired of [
      'system/system-menus', 'system/system-permissions', 'system/system-data-scopes',
      'system/system-field-policies', 'system/system-calendars',
      'notices/approval', 'notices/audience', 'notices/reads', 'notices/templates',
    ]) expect(keys).not.toContain(retired)
  })
})
