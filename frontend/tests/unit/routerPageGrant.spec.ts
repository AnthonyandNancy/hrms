import { describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { RouteLocationNormalized } from 'vue-router'
import { pageAccessGuard } from '@/router'
import { useSessionStore } from '@/stores/session'

vi.mock('@/api', () => ({
  getApi: () => ({ auth: { bootstrap: vi.fn(), permissions: vi.fn() } }),
}))

function route(meta: Record<string, unknown>): RouteLocationNormalized {
  return {
    name: 'hr-employees',
    meta,
    query: {},
    fullPath: '/hr/employees',
  } as unknown as RouteLocationNormalized
}

function authenticate(options: { pages: string[]; capabilities: string[] }) {
  const session = useSessionStore()
  session.$patch({
    status: 'authenticated',
    principal: {
      userId: 7,
      employmentId: 1,
      displayName: 'Synthetic User',
      email: 'synthetic@example.test',
      employmentNo: 'E1001',
      jobTitle: 'Tester',
      orgName: 'Synthetic Org',
      initials: 'SU',
      isPrivileged: false,
      hasMfa: true,
      authVersion: 1,
      tenantTimezone: 'Asia/Shanghai',
    },
    capabilities: options.capabilities,
    pages: options.pages.map((pageCode) => ({ pageCode, path: '' })),
  })
  return session
}

describe('router page and action gates', () => {
  it('forbids a page that has the action capability but no role page grant', async () => {
    setActivePinia(createPinia())
    const session = authenticate({ pages: ['employee-detail'], capabilities: ['people.employee.read'] })

    const result = await pageAccessGuard(
      route({ pageCode: 'hr-employees', capability: 'people.employee.read' }),
      session,
    )

    expect(result).toMatchObject({ name: 'forbidden', query: { reason: 'PAGE_NOT_GRANTED' } })
  })

  it('allows the granted page but still forbids a missing action capability', async () => {
    setActivePinia(createPinia())
    const session = authenticate({ pages: ['hr-employees'], capabilities: [] })

    const result = await pageAccessGuard(
      route({ pageCode: 'hr-employees', capability: 'people.employee.read' }),
      session,
    )

    expect(result).toMatchObject({ name: 'forbidden', query: { reason: 'ACTION_NOT_GRANTED' } })
  })

  it('allows a page when both the page grant and action capability are present', async () => {
    setActivePinia(createPinia())
    const session = authenticate({ pages: ['hr-employees'], capabilities: ['people.employee.read'] })

    const result = await pageAccessGuard(
      route({ pageCode: 'hr-employees', capability: 'people.employee.read' }),
      session,
    )

    expect(result).toBe(true)
  })
})

describe('guard must never redirect a page to itself', () => {
  it('allows /forbidden even when no role grants the forbidden page', async () => {
    setActivePinia(createPinia())
    const session = authenticate({ pages: [], capabilities: [] })

    const result = await pageAccessGuard(route({ pageCode: 'forbidden', public: true }), session)

    expect(result).toBe(true)
  })

  it('allows /not-found even when no role grants the not-found page', async () => {
    setActivePinia(createPinia())
    const session = authenticate({ pages: [], capabilities: [] })

    const result = await pageAccessGuard(route({ pageCode: 'not-found', public: true }), session)

    expect(result).toBe(true)
  })

  it('turns an unauthorized page into PAGE_NOT_GRANTED exactly once', async () => {
    setActivePinia(createPinia())
    const session = authenticate({ pages: ['hr-employees'], capabilities: ['people.employee.read'] })

    const result = await pageAccessGuard(route({ pageCode: 'payroll', capability: 'payroll.run.read' }), session)

    expect(result).toMatchObject({ name: 'forbidden', query: { reason: 'PAGE_NOT_GRANTED' } })
    expect((result as { name: string }).name).not.toBe('payroll')
  })
})
