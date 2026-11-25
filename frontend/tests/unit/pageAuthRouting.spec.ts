import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import PayslipsView from '@/views/PayslipsView.vue'
import { ApiError } from '@/api/errors'

const listPayslips = vi.fn()

vi.mock('@/api', () => ({
  getApi: () => ({
    payroll: {
      listPayslips,
    },
  }),
}))

async function mountWithRouter() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/payroll/my-payslips', name: 'payslips', component: PayslipsView },
      { path: '/login', name: 'login', component: { template: '<div>login</div>' } },
      { path: '/forbidden', name: 'forbidden', component: { template: '<div>forbidden</div>' } },
    ],
  })
  await router.push('/payroll/my-payslips')
  await router.isReady()
  const wrapper = mount(PayslipsView, {
    global: { plugins: [pinia, router, ElementPlus] },
  })
  return { wrapper, router }
}

describe('page-level auth routing', () => {
  beforeEach(() => {
    listPayslips.mockReset()
  })

  it('sends a 401 from a real page to login', async () => {
    listPayslips.mockRejectedValue(new ApiError('UNAUTHENTICATED', 'expired', { status: 401 }))
    const { router } = await mountWithRouter()
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.expired).toBe('1')
  })

  it('sends a 403 from a real page to forbidden with its request id', async () => {
    listPayslips.mockRejectedValue(
      new ApiError('FORBIDDEN', 'denied', { status: 403, requestId: 'req-page-403' }),
    )
    const { router } = await mountWithRouter()
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('forbidden')
    expect(router.currentRoute.value.query.request_id).toBe('req-page-403')
  })
})
