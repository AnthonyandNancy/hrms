import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import { defineComponent } from 'vue'
import { useSessionStore } from '@/stores/session'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resource.get(...args),
  resourcePost: (...args: unknown[]) => resource.post(...args),
}))

import PayrollWorkspacePage from '@/modules/payroll/pages/PayrollWorkspacePage.vue'
import PayrollRunDetailPage from '@/modules/payroll/pages/PayrollRunDetailPage.vue'

const OverlayStub = defineComponent({
  inheritAttrs: false,
  props: { modelValue: Boolean, title: String },
  emits: ['update:modelValue'],
  template: `<div v-if="modelValue" v-bind="$attrs" class="overlay-stub" role="dialog">
    <h2 v-if="title">{{ title }}</h2>
    <slot />
    <slot name="footer" />
  </div>`,
})

function page(items: unknown[], overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return { items, page: 1, page_size: 20, total: items.length, ...overrides }
}

function itemRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 11,
    item_version_id: 31,
    code: 'DEMO_BONUS',
    version: 2,
    status: 'ACTIVE',
    version_status: 'ACTIVE',
    name: '演示奖金',
    type: 'EARNING',
    taxable: true,
    insurable: true,
    calculation: 'FIXED',
    formula_json: null,
    sort_order: 10,
    effective_from: '2026-12-01',
    effective_to: '9999-12-31',
    retired_at: null,
    retire_reason: null,
    is_current: true,
    is_retired: false,
    ...overrides,
  }
}

function runRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 7,
    period: '2026-11',
    run_type: 'REGULAR',
    status: 'DRAFT',
    correction_of_run_id: null,
    pay_date: '2026-12-05',
    employee_count: 2,
    created_at: '2026-11-20T02:00:00Z',
    gross_cents: 1234567,
    deductions_cents: 234567,
    net_cents: 1000000,
    employer_cost_cents: 1500000,
    ...overrides,
  }
}

const CAPABILITIES = [
  'payroll.run.read',
  'payroll.run.prepare',
  'payroll.run.review',
  'payroll.run.approve',
  'payroll.run.publish',
  'payroll.run.close',
  'payroll.export',
  'payroll.payslip.read.self',
  'payroll.compensation.read',
  'payroll.compensation.manage',
  'payroll.report.read',
]

function detailRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    ...runRaw(),
    scheme_id: 3,
    earning_period_start: '2026-11-01',
    earning_period_end: '2026-11-30',
    posting_period: '2026-11',
    cutoff_at: '2026-11-25T16:00:00Z',
    prepared_by: 11,
    reviewed_by: null,
    approved_by: null,
    published_by: null,
    input_hash: 'abc',
    snapshot_version: 2,
    reopen_count: 0,
    employees: [],
    ...overrides,
  }
}

function piniaWith(capabilities: string[]): ReturnType<typeof createPinia> {
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSessionStore()
  session.capabilities = capabilities
  session.status = 'authenticated'
  return pinia
}

async function mountAt(
  path: string,
  capabilities: string[] = CAPABILITIES,
  component: typeof PayrollWorkspacePage | typeof PayrollRunDetailPage = PayrollWorkspacePage,
): Promise<VueWrapper> {
  const pinia = piniaWith(capabilities)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/payroll', name: 'payroll', component: { template: '<div />' } },
      { path: '/payroll/runs/:id', name: 'payroll-run-detail', component: { template: '<div />' } },
      { path: '/benefits', name: 'benefits', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()

  const wrapper = mount(component, {
    global: {
      plugins: [pinia, router, ElementPlus],
      stubs: { ElDialog: OverlayStub, ElDrawer: OverlayStub },
    },
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.post.mockResolvedValue({})
})

describe('payroll workspace tabs', () => {
  it('renders the four workspace tabs and falls back from an invalid ?tab value', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/payroll/runs') return Promise.resolve(page([runRaw()]))
      if (path === '/v1/payroll/schemes') return Promise.resolve(page([]))
      return Promise.resolve(page([]))
    })

    const wrapper = await mountAt('/payroll?tab=unknown')

    expect(wrapper.find('[data-testid="payroll-tab-batches"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="payroll-tab-components"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="payroll-tab-payslips"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="payroll-tab-reports"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="payroll-tab-batches"]').attributes('aria-selected')).toBe('true')
  })

  it('hides tabs the session capability set cannot view', async () => {
    resource.get.mockResolvedValue(page([]))

    const wrapper = await mountAt('/payroll', ['payroll.run.read'])

    expect(wrapper.find('[data-testid="payroll-tab-payslips"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="payroll-tab-reports"]').exists()).toBe(false)
  })

  it('opens components from ?tab=components and offers create on an empty item catalogue', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/payroll/items') return Promise.resolve(page([]))
      if (path === '/v1/payroll/schemes') return Promise.resolve(page([]))
      return Promise.resolve(page([]))
    })

    const wrapper = await mountAt('/payroll?tab=components')

    expect(wrapper.text()).toContain('还没有薪资项目')
    expect(wrapper.get('[data-testid="payroll-item-create"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-testid="payroll-config-tab-items"]').exists()).toBe(true)
  })

  it('routes the legacy plans and employee-plans query aliases into configuration sections', async () => {
    resource.get.mockImplementation(() => Promise.resolve(page([])))

    const plans = await mountAt('/payroll?tab=plans')
    expect(plans.get('[data-testid="payroll-tab-components"]').attributes('aria-selected')).toBe('true')
    expect(plans.get('[data-testid="payroll-config-tab-plans"]').attributes('aria-selected')).toBe('true')

    const compensation = await mountAt('/payroll?tab=employee-plans')
    expect(compensation.get('[data-testid="payroll-tab-components"]').attributes('aria-selected')).toBe('true')
    expect(compensation.get('[data-testid="payroll-config-tab-compensations"]').attributes('aria-selected')).toBe('true')
  })

  it('links statutory parameters to /benefits?tab=statutory instead of keeping a payroll copy', async () => {
    resource.get.mockImplementation(() => Promise.resolve(page([])))
    const pinia = piniaWith(CAPABILITIES)
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/payroll', name: 'payroll', component: { template: '<div />' } },
        { path: '/benefits', name: 'benefits', component: { template: '<div />' } },
      ],
    })
    await router.push('/payroll?tab=components')
    await router.isReady()

    const wrapper = mount(PayrollWorkspacePage, {
      global: { plugins: [pinia, router, ElementPlus], stubs: { ElDialog: OverlayStub } },
    })
    await flushPromises()

    const link = wrapper.get('[data-testid="payroll-statutory-link"]')
    await link.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/benefits?tab=statutory')
  })

  it('creates a payroll item with an idempotency key and no client amount field', async () => {
    resource.get.mockImplementation(() => Promise.resolve(page([])))

    const wrapper = await mountAt('/payroll?tab=components')
    await wrapper.get('[data-testid="payroll-item-create"]').trigger('click')
    await wrapper.get('[data-testid="payroll-item-code"]').setValue('demo_bonus')
    await wrapper.get('[data-testid="payroll-item-name"]').setValue('演示奖金')
    await wrapper.get('[data-testid="payroll-item-save"]').trigger('click')
    await flushPromises()

    const [path, body, options] = resource.post.mock.calls[0] as [
      string,
      Record<string, unknown>,
      { idempotencyKey?: string },
    ]
    expect(path).toBe('/v1/payroll/items')
    expect(body.code).toBe('DEMO_BONUS')
    expect(body).not.toHaveProperty('amount_cents')
    expect(body).not.toHaveProperty('base_salary_cents')
    expect(options?.idempotencyKey).toMatch(/^payroll-item-/)
  })

  it('appends a new item version through the version command instead of editing in place', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/payroll/items') return Promise.resolve(page([itemRaw()]))
      return Promise.resolve(page([]))
    })

    const wrapper = await mountAt('/payroll?tab=components')
    await wrapper.get('[data-testid="payroll-item-version-11"]').trigger('click')
    await wrapper.get('[data-testid="payroll-item-version-effective-from"]').setValue('2027-01-01')
    await wrapper.get('[data-testid="payroll-item-version-save"]').trigger('click')
    await flushPromises()

    const [path, body] = resource.post.mock.calls[0] as [string, Record<string, unknown>]
    expect(path).toBe('/v1/payroll/items/11/versions')
    expect(body.effective_from).toBe('2027-01-01')
  })

  it('exposes the five run-detail tabs and defaults to input evidence', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/payroll/runs/7') return Promise.resolve(detailRaw())
      return Promise.resolve(page([]))
    })

    const wrapper = await mountAt('/payroll/runs/7', CAPABILITIES, PayrollRunDetailPage)

    expect(wrapper.find('[data-testid="payroll-detail-tab-inputs"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="payroll-detail-tab-review"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="payroll-detail-tab-disbursement"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="payroll-detail-tab-payslips"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="payroll-detail-tab-export"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="payroll-detail-tab-inputs"]').attributes('aria-selected')).toBe('true')
  })

  it('keeps history read-only after retirement', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/payroll/items') {
        return Promise.resolve(page([itemRaw({ is_retired: true, status: 'RETIRED', retired_at: '2026-11-27T00:00:00Z' })]))
      }
      return Promise.resolve(page([]))
    })

    const wrapper = await mountAt('/payroll?tab=components')

    expect(wrapper.text()).toContain('已停用')
    expect(wrapper.text()).toContain('只读历史')
    expect(wrapper.find('[data-testid="payroll-item-version-11"]').exists()).toBe(false)
  })

  it('renders a payroll report summary with integer-cent totals only', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/payroll/reports/summary') {
        return Promise.resolve({
          as_of: '2026-11-27',
          source: 'PUBLISHED_PAYROLL_RUNS',
          group_by: 'period',
          currency: 'CNY',
          items: [
            {
              period: '2026-11',
              department_id: null,
              department_name: null,
              run_count: 1,
              employee_count: 2,
              gross_cents: 100000,
              deductions_cents: 20000,
              net_cents: 80000,
              employer_cost_cents: 120000,
            },
          ],
          trend: [],
          totals: {
            gross_cents: 100000,
            deductions_cents: 20000,
            net_cents: 80000,
            employer_cost_cents: 120000,
          },
        })
      }
      return Promise.resolve(page([]))
    })

    const wrapper = await mountAt('/payroll?tab=reports')

    expect(wrapper.get('[data-testid="payroll-report-totals"]').text()).toContain('¥1,000.00')
    expect(wrapper.get('[data-testid="payroll-report-totals"]').text()).toContain('¥800.00')
    expect(wrapper.text()).toContain('2026-11')
  })
})
