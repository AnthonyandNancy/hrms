import { describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'

const auth = vi.hoisted(() => ({
  bootstrap: vi.fn(),
  permissions: vi.fn(),
  login: vi.fn(),
  verifyTotp: vi.fn(),
}))

vi.mock('@/api', () => ({
  getApi: () => ({ auth }),
}))

const authenticated = {
  authenticated: true,
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
  capabilities: ['people.employee.read'],
  csrfToken: 'csrf',
  sessionExpiresAt: null,
  mfaEnrollmentRequired: false,
}

function permissions(overrides: Record<string, unknown> = {}) {
  return {
    pages: [{ page_code: 'hr-employees', path: '/hr/employees' }],
    menus: [],
    actions: ['people.employee.read'],
    field_capabilities: [],
    scope_labels: [],
    auth_version: 1,
    wildcard: false,
    ...overrides,
  }
}

describe('session page grant projection', () => {
  it('loads pages from /me/permissions and allows only granted page codes', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    auth.bootstrap.mockResolvedValue(authenticated)
    auth.permissions.mockResolvedValue(permissions())

    const session = useSessionStore()
    await session.bootstrap()

    expect(auth.permissions).toHaveBeenCalledTimes(1)
    expect(session.pages).toEqual([{ pageCode: 'hr-employees', path: '/hr/employees' }])
    expect(session.canPage('hr-employees')).toBe(true)
    expect(session.canPage('hr-employees-create')).toBe(false)
    expect(session.permissionsError).toBeNull()
  })

  it('propagates a permissions load failure instead of degrading to an empty menu', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    auth.bootstrap.mockResolvedValue(authenticated)
    auth.permissions.mockRejectedValue(new ApiError('INTERNAL', 'permissions unavailable', { status: 500, requestId: 'req-perm-500' }))

    const session = useSessionStore()
    await expect(session.bootstrap()).rejects.toThrow('permissions unavailable')
    expect(session.status).toBe('authenticated')
    expect(session.permissionsError).toContain('permissions unavailable')
    expect(session.pages).toEqual([])
  })
})

describe('login must load the page projection', () => {
  it('loads /me/permissions immediately after a password login', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    auth.bootstrap.mockResolvedValue({ ...authenticated, authenticated: false, principal: null })
    auth.login.mockResolvedValue({ status: 'AUTHENTICATED', session: authenticated })
    auth.permissions.mockResolvedValue(permissions())

    const session = useSessionStore()
    await session.login({ username: 'demo.hr', password: 'Demo!2026Synthetic' })

    expect(auth.permissions).toHaveBeenCalledTimes(1)
    expect(session.canPage('hr-employees')).toBe(true)
  })

  it('loads /me/permissions after an MFA challenge is verified', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    auth.bootstrap.mockResolvedValue({ ...authenticated, authenticated: false, principal: null })
    auth.login.mockResolvedValue({ status: 'MFA_REQUIRED', challengeId: 'c1', maskedDestination: 'app' })
    auth.verifyTotp.mockResolvedValue(authenticated)
    auth.permissions.mockResolvedValue(permissions())

    const session = useSessionStore()
    await session.login({ username: 'demo.hr', password: 'Demo!2026Synthetic' })
    await session.verifyTotp('123456')

    expect(auth.permissions).toHaveBeenCalledTimes(1)
    expect(session.canPage('hr-employees')).toBe(true)
  })
})
