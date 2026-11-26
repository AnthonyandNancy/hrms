import { describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import router from '@/router'
import { useSessionStore } from '@/stores/session'

vi.mock('@/api', () => ({
  getApi: () => ({ auth: { bootstrap: vi.fn(), permissions: vi.fn() } }),
}))

function authenticate(session: ReturnType<typeof useSessionStore>, options: { pages: string[]; capabilities: string[] }) {
  session.$patch({
    status: 'authenticated',
    principal: {
      userId: 7,
      tenantId: 1,
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
}

describe('router page and action gates', () => {
  it('forbids a page that has the action capability but no role page grant', async () => {
    setActivePinia(createPinia())
    const session = useSessionStore()
    authenticate(session, { pages: ['employee-detail'], capabilities: ['people.employee.read'] })

    await router.push('/hr/employees')

    expect(router.currentRoute.value.name).toBe('forbidden')
    expect(router.currentRoute.value.query.reason).toBe('PAGE_NOT_GRANTED')
  })

  it('allows the granted page but still forbids a missing action capability', async () => {
    setActivePinia(createPinia())
    const session = useSessionStore()
    authenticate(session, { pages: ['hr-employees'], capabilities: [] })

    await router.push('/hr/employees')

    expect(router.currentRoute.value.name).toBe('forbidden')
    expect(router.currentRoute.value.query.reason).toBe('ACTION_NOT_GRANTED')
  })

  it('allows a page when both the page grant and action capability are present', async () => {
    setActivePinia(createPinia())
    const session = useSessionStore()
    authenticate(session, { pages: ['hr-employees'], capabilities: ['people.employee.read'] })

    await router.push('/hr/employees')

    expect(router.currentRoute.value.name).toBe('hr-employees')
  })
})
