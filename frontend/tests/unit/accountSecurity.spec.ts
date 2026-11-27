import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import AccountSecurityPage from '@/modules/platform/pages/AccountSecurityPage.vue'
import { useSessionStore } from '@/stores/session'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  delete: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
  resourceDelete: resource.delete,
}))

const PROFILE = {
  id: 2,
  company_name: '合成制造有限公司',
  username: 'synthetic.account',
  email: 'account@example.invalid',
  employment_id: 7,
  is_privileged: false,
  has_mfa: false,
  mfa_verified: false,
  auth_version: 1,
  roles: ['EMPLOYEE'],
}

async function mountAccount() {
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
    ],
  })
  await router.push('/account')
  await router.isReady()

  const wrapper = mount(AccountSecurityPage, {
    global: { plugins: [pinia, router, ElementPlus] },
  })
  await flushPromises()
  return { wrapper, router }
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.delete.mockReset()
  resource.get.mockImplementation((path: string) => {
    if (path === '/v1/me') return Promise.resolve(PROFILE)
    if (path === '/v1/auth/sessions') return Promise.resolve({ items: [] })
    if (path === '/v1/auth/mfa/status') {
      return Promise.resolve({ enabled: false, enrolled: false, mfa_verified: false, recovery_codes_remaining: 0 })
    }
    return Promise.reject(new Error(`Unexpected GET ${path}`))
  })
})

describe('single account security workspace', () => {
  it('renders profile, sessions and optional MFA as one route', async () => {
    const { wrapper } = await mountAccount()

    expect(wrapper.find('[data-testid="account-profile"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="account-sessions"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="account-mfa"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('synthetic.account')
    expect(wrapper.text()).toContain('当前部署未启用多因素认证')
  })

  it('keeps every account action on /account rather than a legacy sub-route', async () => {
    const { router } = await mountAccount()

    expect(router.hasRoute('account')).toBe(true)
    expect(router.hasRoute('account-mfa')).toBe(false)
    expect(router.hasRoute('account-sessions')).toBe(false)
  })
})
