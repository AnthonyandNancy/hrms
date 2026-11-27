import { describe, expect, it } from 'vitest'
import { PAGE_REGISTRY } from '@/router/registry'
import { INTERNAL_DETAIL_ROUTES, INTERNAL_ROUTES } from '@/shared/navigation/internalRoutes'

const registeredPaths = new Set(PAGE_REGISTRY.map((page) => page.path))

describe('M01 internal route constants', () => {
  it('keeps every stable workspace target in the page registry', () => {
    for (const path of Object.values(INTERNAL_ROUTES)) {
      expect(registeredPaths.has(path), `${path} must be a registered page`).toBe(true)
    }
  })

  it('builds detail routes that match registered parameterised pages', () => {
    const detailPaths = PAGE_REGISTRY.filter((page) => page.kind === 'detail').map((page) => page.path)
    const examples = [
      INTERNAL_DETAIL_ROUTES.employee(11),
      INTERNAL_DETAIL_ROUTES.payrollRun(12),
      INTERNAL_DETAIL_ROUTES.workflowCase(13),
    ]

    for (const example of examples) {
      expect(
        detailPaths.some((pattern) => new RegExp(`^${pattern.replace(/:[^/]+/g, '[^/]+')}$`).test(example)),
        `${example} must resolve to a registered detail page`,
      ).toBe(true)
    }
  })

  it('never points at the retired self-service or account sub-routes', () => {
    const values = Object.values(INTERNAL_ROUTES)
    for (const retired of ['/account/mfa', '/account/sessions', '/self-service']) {
      expect(values).not.toContain(retired)
      expect(registeredPaths.has(retired), `${retired} must stay deleted`).toBe(false)
    }
  })
})
