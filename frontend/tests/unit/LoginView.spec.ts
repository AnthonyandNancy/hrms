import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import LoginView from '@/views/LoginView.vue'
import { useSessionStore } from '@/stores/session'

const auth = vi.hoisted(() => ({
  bootstrap: vi.fn(),
  permissions: vi.fn(),
  login: vi.fn(),
  verifyTotp: vi.fn(),
  logout: vi.fn(),
  enrollMfa: vi.fn(),
  confirmMfa: vi.fn(),
  demoAccounts: vi.fn(),
}))

vi.mock('@/api', () => ({
  getApi: () => ({ auth }),
}))

const authenticatedSession = {
  authenticated: true,
  principal: {
    userId: 3,
    tenantId: 1,
    employmentId: 1,
    displayName: 'Synthetic Operator',
    email: 'operator@example.invalid',
    employmentNo: 'E1003',
    jobTitle: 'HR administrator',
    orgName: 'Synthetic Manufacturing',
    initials: 'SO',
    isPrivileged: true,
    hasMfa: true,
    authVersion: 1,
    tenantTimezone: 'Asia/Shanghai',
    tenantName: 'Synthetic Manufacturing',
    legalEntityName: 'Synthetic Manufacturing',
  },
  capabilities: ['iam.session.manage'],
  csrfToken: 'csrf-token',
  sessionExpiresAt: '2026-11-26T21:00:00Z',
  mfaEnrollmentRequired: false,
}

async function mountLogin(path = '/login') {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', name: 'login', component: LoginView },
      { path: '/workspace', name: 'workspace', component: { template: '<div>workspace</div>' } },
    ],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(LoginView, { global: { plugins: [pinia, router, ElementPlus] } })
  await flushPromises()
  return { wrapper, router }
}

beforeEach(() => {
  auth.bootstrap.mockReset()
  auth.permissions.mockReset()
  auth.login.mockReset()
  auth.verifyTotp.mockReset()
  auth.logout.mockReset()
  auth.enrollMfa.mockReset()
  auth.confirmMfa.mockReset()
  auth.demoAccounts.mockReset()
  auth.bootstrap.mockResolvedValue({ ...authenticatedSession, authenticated: false, principal: null })
  auth.permissions.mockResolvedValue({
    pages: [],
    menus: [],
    actions: [],
    field_capabilities: [],
    scope_labels: [],
    auth_version: 1,
    wildcard: false,
  })
})

describe('password-only login page', () => {
  it('renders exactly username, password and the submit button', async () => {
    const { wrapper } = await mountLogin()

    expect(wrapper.find('#login-username').exists()).toBe(true)
    expect(wrapper.find('#login-password').exists()).toBe(true)
    expect(wrapper.get('form.login-form').findAll('input')).toHaveLength(2)
    expect(wrapper.findAll('button')).toHaveLength(1)
    expect(wrapper.get('button[type="submit"]').text()).toContain('登录')
  })

  it('does not render MFA, CAPTCHA or enrollment controls before a login attempt', async () => {
    const { wrapper } = await mountLogin()

    expect(wrapper.find('#login-totp').exists()).toBe(false)
    expect(wrapper.find('.mfa-destination').exists()).toBe(false)
    expect(wrapper.find('[data-testid="mfa-enroll"]').exists()).toBe(false)
    expect(wrapper.text()).not.toMatch(/动态验证码|验证你的身份|验证码|CAPTCHA|图形码|绑定动态口令/)
  })

  it('submits only username and password to POST /v1/auth/login and navigates on success', async () => {
    auth.login.mockResolvedValue({ status: 'AUTHENTICATED', session: authenticatedSession })
    const { wrapper, router } = await mountLogin('/login?redirect=/workspace')

    await wrapper.get('#login-username').setValue('synthetic.operator')
    await wrapper.get('#login-password').setValue('Synthetic-Pass-2026!')
    await wrapper.get('form.login-form').trigger('submit')
    await flushPromises()

    expect(auth.login).toHaveBeenCalledTimes(1)
    expect(auth.login).toHaveBeenCalledWith({
      username: 'synthetic.operator',
      password: 'Synthetic-Pass-2026!',
    })
    const session = useSessionStore()
    expect(session.status).toBe('authenticated')
    expect(router.currentRoute.value.path).toBe('/workspace')
  })

  it('shows the API error and never asks for a second factor when login is rejected', async () => {
    auth.login.mockRejectedValue(new Error('账号或密码不正确'))
    const { wrapper } = await mountLogin()

    await wrapper.get('#login-username').setValue('synthetic.operator')
    await wrapper.get('#login-password').setValue('Synthetic-Pass-2026!')
    await wrapper.get('form.login-form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('账号或密码不正确')
    expect(wrapper.find('#login-totp').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('动态验证码')
  })
})
