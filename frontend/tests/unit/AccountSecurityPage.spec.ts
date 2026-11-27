import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  patch: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
}))

const dialogs = vi.hoisted(() => ({
  confirm: vi.fn(),
}))

const messages = vi.hoisted(() => ({
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
  resourcePatch: resource.patch,
  resourcePut: resource.put,
  resourceDelete: resource.delete,
}))

vi.mock('element-plus', async (importOriginal) => {
  const actual = await importOriginal<typeof import('element-plus')>()
  return {
    ...actual,
    ElMessage: { success: messages.success, error: messages.error },
    ElMessageBox: { ...actual.ElMessageBox, confirm: dialogs.confirm },
  }
})

import AccountSecurityPage from '@/modules/platform/pages/AccountSecurityPage.vue'

const PROFILE = {
  id: 1,
  company_name: '合成制造有限公司',
  username: 'synthetic.operator',
  email: 'operator@example.invalid',
  employment_id: 1,
  is_privileged: false,
  has_mfa: false,
  mfa_verified: false,
  auth_version: 4,
  roles: ['HR_ADMIN'],
}

const CURRENT_SESSION_ID = '01J000000000000000000000A1'
const OTHER_SESSION_ID = '01J000000000000000000000B2'

const SESSIONS = [
  {
    id: CURRENT_SESSION_ID,
    device: 'Windows · Chrome',
    ip: '127.0.0.1',
    current: true,
    mfa_verified: false,
    last_seen_at: '2026-11-27T01:00:00Z',
    created_at: '2026-11-27T00:00:00Z',
    absolute_expires_at: '2026-11-27T12:00:00Z',
  },
  {
    id: OTHER_SESSION_ID,
    device: 'macOS · Safari',
    ip: '10.0.0.8',
    current: false,
    mfa_verified: true,
    last_seen_at: '2026-11-26T20:00:00Z',
    created_at: '2026-11-26T18:00:00Z',
    absolute_expires_at: '2026-11-27T06:00:00Z',
  },
]

function mfaStatus(overrides: Record<string, unknown> = {}) {
  return { enabled: false, enrolled: false, mfa_verified: false, recovery_codes_remaining: 0, ...overrides }
}

function mockHappyPath(options: { mfa?: Record<string, unknown>; sessions?: unknown[]; sessionsError?: unknown } = {}) {
  resource.get.mockImplementation((path: string) => {
    if (path === '/v1/me') return Promise.resolve(PROFILE)
    if (path === '/v1/auth/sessions') {
      if (options.sessionsError !== undefined) return Promise.reject(options.sessionsError)
      return Promise.resolve({ items: options.sessions ?? SESSIONS })
    }
    if (path === '/v1/auth/mfa/status') return Promise.resolve(mfaStatus(options.mfa))
    return Promise.reject(new Error(`Unexpected GET ${path}`))
  })
}

async function mountPage() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSessionStore()
  session.capabilities = ['iam.session.manage']
  session.csrfToken = 'synthetic-csrf'

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/account', name: 'account', component: { template: '<div />' } },
      { path: '/login', name: 'login', component: { template: '<div>login</div>' } },
      { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
    ],
  })
  await router.push('/account')
  await router.isReady()

  const wrapper = mount(AccountSecurityPage, {
    global: { plugins: [pinia, router, ElementPlus] },
    attachTo: document.body,
  })
  await flushPromises()
  return { wrapper, router, session }
}

function setInput(wrapper: Awaited<ReturnType<typeof mountPage>>['wrapper'], testId: string, value: string) {
  const direct = wrapper.find(`input[data-testid="${testId}"]`)
  const nested = wrapper.find(`[data-testid="${testId}"] input`)
  const input = direct.exists() ? direct : nested
  if (!input.exists()) throw new Error(`Input ${testId} not found`)
  return input.setValue(value)
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.delete.mockReset()
  dialogs.confirm.mockReset()
  messages.success.mockReset()
  messages.error.mockReset()
  document.body.innerHTML = ''
})

describe('account security workspace', () => {
  it('validates password length, difference and confirmation before calling the API', async () => {
    mockHappyPath()
    const { wrapper } = await mountPage()

    await wrapper.get('[data-testid="open-change-password"]').trigger('click')
    await flushPromises()

    await setInput(wrapper, 'current-password', 'Synthetic-Current-1!')
    await setInput(wrapper, 'new-password', 'short')
    await setInput(wrapper, 'password-confirmation', 'different-value')
    await wrapper.get('[data-testid="password-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="new-password-error"]').text()).toContain('至少')
    expect(wrapper.get('[data-testid="password-confirmation-error"]').text()).toContain('不一致')
    expect(resource.post).not.toHaveBeenCalledWith('/v1/auth/password', expect.anything(), expect.anything())

    await setInput(wrapper, 'new-password', 'Synthetic-Current-1!')
    await setInput(wrapper, 'password-confirmation', 'Synthetic-Current-1!')
    await wrapper.get('[data-testid="password-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="new-password-error"]').text()).toContain('不能与当前密码相同')
    expect(resource.post).not.toHaveBeenCalledWith('/v1/auth/password', expect.anything(), expect.anything())
  })

  it('maps a server 422 current-password error inline in Chinese', async () => {
    mockHappyPath()
    resource.post.mockRejectedValue(
      new ApiError('VALIDATION_FAILED', 'The given data was invalid.', {
        status: 422,
        fieldErrors: { current_password: ['The current password is incorrect.'] },
        details: { reason: 'BAD_CURRENT_PASSWORD' },
      }),
    )

    const { wrapper } = await mountPage()
    await wrapper.get('[data-testid="open-change-password"]').trigger('click')
    await flushPromises()

    await setInput(wrapper, 'current-password', 'Wrong-Current-Password-1!')
    await setInput(wrapper, 'new-password', 'Synthetic-New-Password-2!')
    await setInput(wrapper, 'password-confirmation', 'Synthetic-New-Password-2!')
    await wrapper.get('[data-testid="password-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="current-password-error"]').text()).toContain('当前密码不正确')
    expect(resource.post).toHaveBeenCalledWith(
      '/v1/auth/password',
      {
        current_password: 'Wrong-Current-Password-1!',
        new_password: 'Synthetic-New-Password-2!',
        new_password_confirmation: 'Synthetic-New-Password-2!',
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('tells the user other sessions were invalidated and offers re-authentication after a password change', async () => {
    mockHappyPath()
    resource.post.mockResolvedValue({ password_changed: true, reauth_required: true, sessions_revoked: true })

    const { wrapper, router } = await mountPage()
    await wrapper.get('[data-testid="open-change-password"]').trigger('click')
    await flushPromises()

    await setInput(wrapper, 'current-password', 'Synthetic-Current-1!')
    await setInput(wrapper, 'new-password', 'Synthetic-New-Password-2!')
    await setInput(wrapper, 'password-confirmation', 'Synthetic-New-Password-2!')
    await wrapper.get('[data-testid="password-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="password-change-done"]').text()).toContain('所有会话已全部失效')

    await wrapper.get('[data-testid="password-reauth"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.expired).toBe('1')
  })

  it('hides the MFA section entirely when the API says the deployment has it disabled', async () => {
    mockHappyPath({ mfa: { enabled: false } })
    const { wrapper } = await mountPage()

    expect(wrapper.text()).toContain('当前部署未启用多因素认证')
    expect(wrapper.find('[data-testid="mfa-section"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="mfa-enroll"]').exists()).toBe(false)
  })

  it('shows enrollment, recovery codes and disable controls only when MFA is enabled', async () => {
    mockHappyPath({ mfa: { enabled: true, enrolled: false } })
    resource.post.mockImplementation((path: string) => {
      if (path === '/v1/auth/mfa/totp/enroll') {
        return Promise.resolve({ factor_id: 21, secret: 'SYNTHETIC-SECRET', otpauth_uri: 'otpauth://totp/x' })
      }
      if (path === '/v1/auth/mfa/totp/confirm') {
        return Promise.resolve({ confirmed: true, recovery_codes: ['SYNTHETIC-RECOVERY-1'] })
      }
      return Promise.reject(new Error(`Unexpected POST ${path}`))
    })

    const { wrapper } = await mountPage()
    expect(wrapper.find('[data-testid="mfa-section"]').exists()).toBe(true)

    await wrapper.get('[data-testid="mfa-enroll"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="mfa-secret"]').text()).toBe('SYNTHETIC-SECRET')

    await wrapper.get('input[data-testid="mfa-code"]').setValue('123456')
    await wrapper.get('[data-testid="mfa-confirm"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="recovery-codes"]').text()).toContain('SYNTHETIC-RECOVERY-1')
    expect(resource.post).toHaveBeenCalledWith(
      '/v1/auth/mfa/totp/confirm',
      { factor_id: 21, code: '123456' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('confirms before revoking a single non-current session and refreshes the list', async () => {
    mockHappyPath()
    dialogs.confirm.mockResolvedValue('confirm')
    resource.delete.mockResolvedValue({ revoked: true, already_revoked: false, current_session: false })

    const { wrapper } = await mountPage()
    await wrapper.get(`[data-testid="revoke-session-${OTHER_SESSION_ID}"]`).trigger('click')
    await flushPromises()

    expect(dialogs.confirm).toHaveBeenCalledTimes(1)
    expect(resource.delete).toHaveBeenCalledWith(
      `/v1/auth/sessions/${OTHER_SESSION_ID}`,
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(messages.success).toHaveBeenCalled()
    expect(resource.get.mock.calls.filter(([path]) => path === '/v1/auth/sessions')).toHaveLength(2)
  })

  it('does not revoke when the confirmation is cancelled', async () => {
    mockHappyPath()
    dialogs.confirm.mockRejectedValue(new Error('cancel'))

    const { wrapper } = await mountPage()
    await wrapper.get(`[data-testid="revoke-session-${OTHER_SESSION_ID}"]`).trigger('click')
    await flushPromises()

    expect(resource.delete).not.toHaveBeenCalled()
  })

  it('logs out to the login page when the current session itself is revoked', async () => {
    mockHappyPath()
    dialogs.confirm.mockResolvedValue('confirm')
    resource.delete.mockResolvedValue({ revoked: true, already_revoked: false, current_session: true })

    const { wrapper, router, session } = await mountPage()
    await wrapper.get(`[data-testid="revoke-session-${CURRENT_SESSION_ID}"]`).trigger('click')
    await flushPromises()

    expect(resource.delete).toHaveBeenCalledWith(
      `/v1/auth/sessions/${CURRENT_SESSION_ID}`,
      expect.anything(),
    )
    expect(router.currentRoute.value.name).toBe('login')
    expect(session.status).toBe('expired')
  })

  it('marks the current session readably and never renders a raw user-agent string', async () => {
    mockHappyPath({
      sessions: [
        {
          ...SESSIONS[0],
          // Even if a server regression sent a raw UA as the label, the page
          // still needs its current-session marker and a readable cell.
          device: 'Windows · Chrome',
        },
        {
          ...SESSIONS[1],
          device: '未知设备',
        },
      ],
    })

    const { wrapper } = await mountPage()
    expect(wrapper.get('[data-testid="current-session-marker"]').text()).toBe('当前会话')
    expect(wrapper.text()).toContain('Windows · Chrome')
    expect(wrapper.text()).toContain('未知设备')
    expect(wrapper.text()).toContain('IP：10.0.0.8')
    expect(wrapper.text()).not.toContain('Mozilla/5.0')
  })

  it('renders the five states: loading, empty, error, forbidden and partial', async () => {
    // Error state for sessions plus the other panels loaded -> partial banner.
    mockHappyPath({ sessionsError: new ApiError('INTERNAL', 'session store unavailable', { status: 500, requestId: 'req-account-500' }) })
    const { wrapper: errorWrapper } = await mountPage()
    expect(errorWrapper.find('[data-testid="account-partial"]').exists()).toBe(true)
    expect(errorWrapper.get('[data-testid="sessions-error"]').text()).toContain('系统暂时无法完成该操作')
    expect(errorWrapper.find('[data-testid="account-profile"]').exists()).toBe(true)

    document.body.innerHTML = ''
    resource.get.mockReset()
    mockHappyPath({ sessions: [] })
    const { wrapper: emptyWrapper } = await mountPage()
    expect(emptyWrapper.find('[data-testid="sessions-empty"]').exists()).toBe(true)

    document.body.innerHTML = ''
    resource.get.mockReset()
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/me') return Promise.resolve(PROFILE)
      if (path === '/v1/auth/sessions') return Promise.resolve({ items: SESSIONS })
      if (path === '/v1/auth/mfa/status') return Promise.resolve(mfaStatus())
      return Promise.reject(new Error(`Unexpected GET ${path}`))
    })
    const { wrapper: deniedWrapper } = await mountPage()
    // Removing the page capability at runtime must surface an explicit
    // forbidden state instead of leaving an empty shell with live buttons.
    const deniedSession = useSessionStore()
    deniedSession.capabilities = []
    await deniedWrapper.vm.$nextTick()
    expect(deniedWrapper.find('[data-testid="account-forbidden"]').exists()).toBe(true)
    expect(deniedWrapper.find('[data-testid="revoke-session-' + OTHER_SESSION_ID + '"]').exists()).toBe(false)
    expect(deniedWrapper.text()).toContain('无权访问账号安全')
  })
})
