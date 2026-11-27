import { describe, expect, it } from 'vitest'
import { PAGE_REGISTRY } from '@/router/registry'
import { PAGE_COMPONENTS as TALENT_PAGES } from '@/modules/talent/pages'

const TALENT_KEYS = [
  'performance/index',
  'performance/cycles/by-id',
  'recruitment/requests/index',
  'recruitment/candidates/index',
  'recruitment/candidates/by-id',
  'training/index',
]

describe('V2.3 talent module pages', () => {
  it('keeps the six surviving performance/recruitment/training loaders', () => {
    expect(Object.keys(TALENT_PAGES).sort()).toEqual([...TALENT_KEYS].sort())
  })

  it('resolves every loader to a real component module', async () => {
    for (const loader of Object.values(TALENT_PAGES)) {
      expect((await loader()).default).toBeTruthy()
    }
  })

  it('resolves every Wave 4 registry component key exactly once', () => {
    expect(
      PAGE_REGISTRY.filter((page) => page.wave === 'Wave 4').map((page) => page.componentKey).sort(),
    ).toEqual([...TALENT_KEYS].sort())
  })

  it('does not restore folded performance/recruitment/training subpages', () => {
    const keys = Object.keys(TALENT_PAGES)
    for (const retired of [
      'performance/templates', 'performance/indicators', 'performance/reviews',
      'recruitment/requests/approval', 'recruitment/candidates/imports',
      'training/courses', 'training/sessions', 'training/enrollments',
    ]) expect(keys).not.toContain(retired)
  })
})
