import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import AccountSecurityView from '@/views/AccountSecurityView.vue'
import { ApiError } from '@/api/errors'

const get = vi.fn()
const post = vi.fn()

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => get(...args),
  resourcePost: (...args: unknown[]) => post(...args),
}))

async function mountAt(path: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/account/mfa', name: 'mfa', component: AccountSecurityView },
      { path: '/account/sessions', name: 'sessions', component: AccountSecurityView },
      { path: '/login', name: 'login', component: { template: '<div>login</div>' } },
    ],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(AccountSecurityView, { global: { plugins: [pinia, router, ElementPlus] } })
  return { wrapper, router }
}

describe('account security pages', () => {
  beforeEach(() => {
    get.mockReset()
    post.mockReset()
  })

  it('loads the real account projection on the MFA page', async () => {
    get.mockResolvedValue({ id: 2, username: 'demo.hr', has_mfa: true, mfa_verified: false, auth_version: 1 })
    const { wrapper } = await mountAt('/account/mfa')
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/v1/me')
    expect(wrapper.text()).toContain('demo.hr')
    expect(wrapper.text()).toContain('多因素认证')
  })

  it('enrolls TOTP with an idempotency key and shows the one-time secret', async () => {
    get.mockResolvedValue({ id: 2, username: 'demo.hr', has_mfa: false, mfa_verified: false, auth_version: 1 })
    post.mockResolvedValue({ secret: 'JBSWY3DPEHPK3PXP', otpauth_uri: 'otpauth://totp/x', recovery_codes: [] })
    const { wrapper } = await mountAt('/account/mfa')
    await flushPromises()

    await wrapper.get('button.el-button--primary').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/v1/auth/mfa/totp/enroll', {}, expect.objectContaining({ idempotencyKey: expect.any(String) }))
    expect(wrapper.text()).toContain('JBSWY3DPEHPK3PXP')
  })

  it('shows the session page and keeps the 5xx request id', async () => {
    get.mockRejectedValue(new ApiError('INTERNAL', 'boom', { status: 500, requestId: 'req-account-500' }))
    const { wrapper } = await mountAt('/account/sessions')
    await flushPromises()

    expect(wrapper.text()).toContain('req-account-500')
  })
})
