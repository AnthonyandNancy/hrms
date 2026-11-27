import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import { defineComponent } from 'vue'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import SystemCalendarsPage from '@/modules/platform/pages/SystemCalendarsPage.vue'
import SystemSettingsPage from '@/modules/platform/pages/SystemSettingsPage.vue'
import BenefitsPage from '@/modules/platform/pages/BenefitsPage.vue'
import { percentToRate, ratePercent } from '@/modules/platform/api/settings'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  patch: vi.fn(),
  delete: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
  resourcePut: resource.put,
  resourcePatch: resource.patch,
  resourceDelete: resource.delete,
}))

const OverlayStub = defineComponent({
  props: { modelValue: Boolean, title: String },
  emits: ['update:modelValue', 'close'],
  template: `<div v-if="modelValue" class="el-overlay-stub" role="dialog">
    <h2>{{ title }}</h2>
    <slot />
    <slot name="footer" />
  </div>`,
})

const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/benefits', name: 'benefits', component: { template: '<div />' } },
    { path: '/system/calendars', name: 'system-calendars', component: { template: '<div />' } },
    { path: '/system/settings', name: 'system-settings', component: { template: '<div />' } },
    { path: '/hr/employees/:id/files', name: 'hr-employees-by-id-files', component: { template: '<div />' } },
  ],
})

function useCapabilities(capabilities: string[]): void {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({
    status: 'authenticated',
    capabilities,
    principal: {
      userId: 9001,
      employmentId: 1001,
      displayName: 'Synthetic Settings Admin',
      email: 'settings@example.invalid',
      employmentNo: 'E-SETTINGS',
      jobTitle: '系统管理员',
      orgName: 'Synthetic Company',
      initials: 'SA',
      isPrivileged: true,
      hasMfa: true,
      authVersion: 1,
      tenantTimezone: 'Asia/Shanghai',
    },
  })
}

async function mountPage(component: object, capabilities: string[], path = '/benefits') {
  useCapabilities(capabilities)
  await router.push(path)
  await router.isReady()
  return mount(component, {
    global: {
      plugins: [ElementPlus, router],
      stubs: {
        'el-dialog': OverlayStub,
        'el-drawer': OverlayStub,
        // Element Plus pagination's internal select loops under jsdom when
        // many page tables are mounted; the tables only need the rows here.
        'el-pagination': true,
        'el-select': true,
        teleport: true,
      },
    },
  })
}

function listResponse(items: unknown[], overrides: Record<string, unknown> = {}) {
  return { items, page: 1, page_size: 20, total: items.length, ...overrides }
}

const calendar = {
  year: 2027,
  timezone: 'Asia/Shanghai',
  workweek: [1, 2, 3, 4, 5],
  standard_day_minutes: 480,
  version: 4,
  holidays: [
    { date: '2027-10-01', name: '国庆节', type: 'PUBLIC', source: 'ADMIN_PUBLISH' },
    { date: '2027-10-09', name: '调休补班', type: 'MAKEUP', source: 'ADMIN_PUBLISH' },
  ],
}

const plan = {
  id: 10,
  code: 'SOCIAL_INSURANCE',
  name: '社会保险',
  employer_rate: '0.160000',
  employee_rate: '0.080000',
  effective_from: '2026-01-01',
  effective_to: null,
  version: 1,
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.put.mockReset()
  resource.patch.mockReset()
  resource.delete.mockReset()
  resource.patch.mockResolvedValue({})
  resource.put.mockResolvedValue({})
  resource.post.mockResolvedValue({})
})

describe('settings workspace', () => {
  it('renders decimal rates as percentages without converting them to floats', () => {
    expect(ratePercent('0.160000')).toBe('16%')
    expect(ratePercent('0.085000')).toBe('8.5%')
    expect(ratePercent('1.000000')).toBe('100%')
    expect(ratePercent('not-a-rate')).toBe('—')
    expect(percentToRate('16')).toBe('0.160000')
    expect(percentToRate('8.5')).toBe('0.085000')
    expect(percentToRate('100')).toBe('1.000000')
    expect(percentToRate('100.000001')).toBeNull()
    expect(percentToRate('abc')).toBeNull()
  })

  it('lists plans with percentage rates and real enrolment counts, then posts decimal strings', async () => {
    resource.get.mockImplementation((path: string, params?: Record<string, unknown>) => {
      if (path === '/v1/benefits/plans') return Promise.resolve(listResponse([plan]))
      if (path === '/v1/benefits/enrollments') {
        return Promise.resolve(listResponse([{
          id: 20,
          plan_id: 10,
          employment_id: 1001,
          base_amount_cents: 1000000,
          employee_rate: '0.080000',
          employer_rate: '0.160000',
          employee_contribution_cents: 80000,
          employer_contribution_cents: 160000,
          effective_from: '2026-01-01',
          effective_to: null,
          version: 1,
        }], { total: params?.plan_id === 10 ? 1 : 1 }))
      }
      if (path === '/v1/benefits/transactions') {
        return Promise.resolve(listResponse([{
          id: 30,
          enrollment_id: 20,
          employment_id: 1001,
          period: '2026-01',
          base_amount_cents: 1000000,
          employee_amount_cents: 80000,
          employer_amount_cents: 160000,
          total_amount_cents: 240000,
          payroll_run_id: 77,
          status: 'RECORDED',
        }]))
      }
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const wrapper = await mountPage(BenefitsPage, ['benefit.read', 'benefit.manage'])
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/benefits/plans', expect.any(Object))
    expect(resource.get).toHaveBeenCalledWith('/v1/benefits/enrollments', expect.any(Object))
    expect(resource.get).toHaveBeenCalledWith('/v1/benefits/transactions', expect.any(Object))
    expect(wrapper.find('[data-testid="benefits-page"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="benefit-plans-panel"]').text()).toContain('SOCIAL_INSURANCE')
    expect(wrapper.find('[data-testid="benefit-plans-panel"]').text()).toContain('16%')
    expect(wrapper.find('[data-testid="benefit-plans-panel"]').text()).toContain('8%')
    expect(wrapper.find('[data-testid="benefit-plans-panel"]').text()).not.toContain('0.160000')
    expect(wrapper.find('[data-testid="benefit-plans-panel"]').text()).toContain('1 人')
    expect(wrapper.find('[data-testid="benefit-enrollments-panel"]').text()).toContain('¥10,000.00')
    expect(wrapper.find('[data-testid="benefit-transactions-panel"]').text()).toContain('¥2,400.00')

    await wrapper.get('[data-testid="benefit-plan-code"]').setValue('HOUSING_FUND')
    await wrapper.get('[data-testid="benefit-plan-name"]').setValue('住房公积金')
    await wrapper.get('[data-testid="benefit-plan-employer-rate"]').setValue('16')
    await wrapper.get('[data-testid="benefit-plan-employee-rate"]').setValue('8')
    await wrapper.get('[data-testid="benefit-plan-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/benefits/plans',
      expect.objectContaining({
        code: 'HOUSING_FUND',
        employer_rate: '0.160000',
        employee_rate: '0.080000',
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('rejects an invalid percentage before the API is called', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/benefits/plans') return Promise.resolve(listResponse([plan]))
      if (path === '/v1/benefits/enrollments') return Promise.resolve(listResponse([]))
      if (path === '/v1/benefits/transactions') return Promise.resolve(listResponse([]))
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const wrapper = await mountPage(BenefitsPage, ['benefit.read', 'benefit.manage'])
    await flushPromises()
    await wrapper.get('[data-testid="benefit-plan-code"]').setValue('HOUSING_FUND')
    await wrapper.get('[data-testid="benefit-plan-name"]').setValue('住房公积金')
    await wrapper.get('[data-testid="benefit-plan-employer-rate"]').setValue('abc')
    await wrapper.get('[data-testid="benefit-plan-employee-rate"]').setValue('8')
    await wrapper.get('[data-testid="benefit-plan-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="benefit-action-error"]').text()).toContain('费率')
  })

  it('renders benefit loading, empty, forbidden, error and partial states', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/benefits/plans') return Promise.resolve(listResponse([]))
      if (path === '/v1/benefits/enrollments') return Promise.resolve(listResponse([]))
      if (path === '/v1/benefits/transactions') return Promise.resolve(listResponse([]))
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })
    const loading = await mountPage(BenefitsPage, ['benefit.read'])
    expect(loading.find('[data-testid="benefit-loading"]').exists()).toBe(true)
    await flushPromises()
    expect(loading.find('[data-testid="benefit-empty"]').exists()).toBe(true)

    resource.get.mockRejectedValue(new ApiError('FORBIDDEN', 'Forbidden', { status: 403 }))
    const forbidden = await mountPage(BenefitsPage, ['benefit.read'])
    await flushPromises()
    expect(forbidden.find('[data-testid="benefit-forbidden"]').exists()).toBe(true)

    resource.get.mockRejectedValue(new ApiError('INTERNAL', 'Boom', { status: 500, requestId: 'req-benefit-500' }))
    const failed = await mountPage(BenefitsPage, ['benefit.read'])
    await flushPromises()
    expect(failed.find('[data-testid="benefit-error"]').text()).toContain('req-benefit-500')

    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/benefits/plans') return Promise.resolve(listResponse([plan]))
      if (path === '/v1/benefits/enrollments') return Promise.resolve(listResponse([]))
      if (path === '/v1/benefits/transactions') {
        return Promise.reject(new ApiError('INTERNAL', 'Boom', { status: 500 }))
      }
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })
    const partial = await mountPage(BenefitsPage, ['benefit.read'])
    await flushPromises()
    expect(partial.find('[data-testid="benefit-partial"]').exists()).toBe(true)
  })

  it('loads the single-company calendar for a chosen year and publishes after confirmation', async () => {
    resource.get.mockResolvedValue({ ...calendar, partial: true, partial_reason: '仅返回所选年度快照' })
    resource.post.mockResolvedValue({ ...calendar, version: 5 })

    const wrapper = await mountPage(SystemCalendarsPage, ['time.calendar.read', 'time.calendar.manage'], '/system/calendars')
    await flushPromises()
    await wrapper.get('[data-testid="calendar-year-input"] input').setValue('2027')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/time/calendars', { year: 2027 })
    const text = wrapper.text()
    expect(text).toContain('中国标准时间（上海）')
    expect(text).toContain('国庆节')
    expect(text).toContain('第 4 版')
    expect(text).toContain('仅返回所选年度快照')
    expect(text).not.toContain('legal_entity')
    expect(text).not.toContain('tenant')

    await wrapper.get('[data-testid="calendar-publish"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="calendar-publish-confirm"]').exists()).toBe(true)
    await wrapper.get('[data-testid="calendar-publish-confirm-ok"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/system/calendars/publish',
      expect.objectContaining({
        timezone: 'Asia/Shanghai',
        workweek: [1, 2, 3, 4, 5],
        standard_day_minutes: 480,
        holidays: expect.any(Array),
      }),
      expect.objectContaining({ idempotencyKey: expect.stringContaining('calendar-publish') }),
    )
    const body = resource.post.mock.calls[0]?.[1] as Record<string, unknown>
    expect(body).not.toHaveProperty('legal_entity_id')
    expect(body).not.toHaveProperty('tenant_id')
  })

  it('hides calendar publication without manage capability and shows forbidden/error/empty states', async () => {
    resource.get.mockResolvedValue({ ...calendar, holidays: [] })
    const readOnly = await mountPage(SystemCalendarsPage, ['time.calendar.read'], '/system/calendars')
    await flushPromises()
    expect(readOnly.find('[data-testid="calendar-publish"]').exists()).toBe(false)

    resource.get.mockRejectedValue(new ApiError('FORBIDDEN', 'Forbidden', { status: 403 }))
    const forbidden = await mountPage(SystemCalendarsPage, ['time.calendar.read'], '/system/calendars')
    await flushPromises()
    expect(forbidden.find('[data-testid="calendar-forbidden"]').exists()).toBe(true)

    resource.get.mockRejectedValue(new ApiError('INTERNAL', 'Boom', { status: 500, requestId: 'req-calendar-500' }))
    const failed = await mountPage(SystemCalendarsPage, ['time.calendar.read'], '/system/calendars')
    await flushPromises()
    expect(failed.find('[data-testid="calendar-error"]').text()).toContain('req-calendar-500')

    resource.get.mockResolvedValue({ ...calendar, version: 0, holidays: [] })
    const empty = await mountPage(SystemCalendarsPage, ['time.calendar.read'], '/system/calendars')
    await flushPromises()
    expect(empty.find('[data-testid="calendar-empty"]').exists()).toBe(true)
  })

  it('edits only existing dictionary entries and typed parameters, and links employee files without fake rows', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/platform/features') {
        return Promise.resolve({ items: [{ flag: 'payroll_enabled', enabled: false, risky: true }] })
      }
      if (path === '/v1/system/dictionaries') {
        return Promise.resolve(listResponse([
          { id: 2, type: 'EMPLOYEE_STATUS', code: 'PROBATION', label: '试用', sort_order: 1, active: true },
        ]))
      }
      if (path === '/v1/system/parameters') {
        return Promise.resolve(listResponse([
          { id: 3, key: 'pagination.max_page_size', scope: 'COMPANY', value: 50, sensitive: false, masked: false },
          { id: 4, key: 'mail.password', scope: 'COMPANY', value: null, sensitive: true, masked: true },
        ]))
      }
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const wrapper = await mountPage(SystemSettingsPage, [
      'system.settings.manage',
      'platform.feature.manage',
      'system.dictionary.manage',
      'system.parameter.manage',
    ], '/system/settings')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/platform/features')
    expect(resource.get).toHaveBeenCalledWith('/v1/system/dictionaries', expect.any(Object))
    expect(resource.get).toHaveBeenCalledWith('/v1/system/parameters', expect.any(Object))
    expect(wrapper.find('[data-testid="settings-dictionaries"]').text()).toContain('试用')
    expect(wrapper.find('[data-testid="settings-dictionaries"]').text()).toContain('PROBATION')
    expect(wrapper.find('[data-testid="settings-parameters"]').text()).toContain('分页每页上限')
    expect(wrapper.find('[data-testid="settings-parameters"]').text()).not.toContain('mail.password')
    expect(wrapper.find('[data-testid="parameter-key"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="dictionary-type"]').exists()).toBe(false)

    await wrapper.get('[data-testid="settings-dictionary-edit-2"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="dictionary-label"]').setValue('试用期')
    await wrapper.get('[data-testid="dictionary-save"]').trigger('click')
    await flushPromises()

    expect(resource.patch).toHaveBeenCalledWith(
      '/v1/system/dictionaries/2',
      expect.objectContaining({ label: '试用期' }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )

    await wrapper.get('[data-testid="settings-parameter-edit-3"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="parameter-value"]').setValue('80')
    await wrapper.get('[data-testid="parameter-save"]').trigger('click')
    await flushPromises()

    expect(resource.put).toHaveBeenCalledWith(
      '/v1/system/parameters',
      expect.objectContaining({ key: 'pagination.max_page_size', scope: 'COMPANY', value: 80 }),
      expect.objectContaining({ idempotencyKey: expect.stringContaining('parameter-pagination.max_page_size') }),
    )

    expect(wrapper.find('[data-testid="settings-files"]').text()).toContain('员工附件')
    const filesLink = wrapper.findAll('[data-testid="settings-files-link"]')
    expect(filesLink.length).toBeGreaterThan(0)
  })

  it('shows the settings forbidden state without any management capability', async () => {
    resource.get.mockResolvedValue(listResponse([]))
    const wrapper = await mountPage(SystemSettingsPage, [], '/system/settings')
    await flushPromises()

    expect(wrapper.find('[data-testid="settings-forbidden"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="settings-feature-flags"]').exists()).toBe(false)
  })
})
