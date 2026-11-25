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

async function mountPayslips() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/payroll/my-payslips', name: 'payslips', component: PayslipsView },
      { path: '/login', name: 'login', component: { template: '<div>login</div>' } },
      { path: '/forbidden', name: 'forbidden', component: { template: '<div>forbidden</div>' } },
      { path: '/system/users', name: 'system-users', component: { template: '<div>users</div>' } },
      { path: '/hr/employees', name: 'hr-employees', component: { template: '<div>employees</div>' } },
    ],
  })
  await router.push('/payroll/my-payslips')
  await router.isReady()

  return mount(PayslipsView, {
    global: { plugins: [pinia, router, ElementPlus] },
  })
}

describe('payslips page error isolation', () => {
  beforeEach(() => {
    listPayslips.mockReset()
  })

  it('renders the dedicated NO_ACTIVE_EMPLOYMENT state with safe links instead of a generic error', async () => {
    listPayslips.mockRejectedValue(
      new ApiError('NO_ACTIVE_EMPLOYMENT', 'The current account is not linked to an active employment.', {
        status: 409,
        requestId: 'req-no-employment',
      }),
    )

    const wrapper = await mountPayslips()
    await flushPromises()

    expect(wrapper.text()).toContain('当前账号未绑定员工')
    expect(wrapper.text()).toContain('请求编号')
    expect(wrapper.text()).toContain('req-no-employment')
    expect(wrapper.text()).toContain('前往系统管理')
    expect(wrapper.text()).toContain('前往员工档案')
    expect(wrapper.text()).toContain('员工自助功能不可用')
  })

  it('shows a page-level 5xx error with its request id', async () => {
    listPayslips.mockRejectedValue(
      new ApiError('INTERNAL', 'Internal error', { status: 500, requestId: 'req-500' }),
    )

    const wrapper = await mountPayslips()
    await flushPromises()

    expect(wrapper.text()).toContain('请求编号')
    expect(wrapper.text()).toContain('req-500')
    expect(wrapper.text()).not.toContain('当前账号未绑定员工')
  })
})
