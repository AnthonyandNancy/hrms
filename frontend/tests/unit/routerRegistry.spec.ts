import { describe, expect, it } from 'vitest'
import router from '@/router'
import { PAGE_REGISTRY } from '@/router/registry'

describe('registry-driven router', () => {
  it('resolves every registered page path', () => {
    const unresolved = PAGE_REGISTRY.filter((page) => {
      const resolved = router.resolve(page.path.replace(/:id/g, '1'))
      return resolved.matched.length === 0 || resolved.name === 'not-found-catchall'
    }).map((page) => page.path)

    expect(unresolved).toEqual([])
  })

  it('has no duplicate route names', () => {
    const names = router.getRoutes().map((route) => route.name).filter((name) => typeof name === 'string')
    expect(new Set(names).size).toBe(names.length)
  })

  it('keeps base pages public or capability guarded exactly as declared', () => {
    for (const page of PAGE_REGISTRY.filter((entry) => entry.layer === 'base')) {
      const resolved = router.resolve(page.path)
      if (page.capability === 'public') {
        expect(resolved.meta.public, page.path).toBe(true)
      } else {
        expect(resolved.meta.capability, page.path).toBe(page.capability)
      }
    }
  })
})
