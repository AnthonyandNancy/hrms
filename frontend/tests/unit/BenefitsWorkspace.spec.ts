/* eslint-disable vue/one-component-per-file */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus, { ElMessageBox } from 'element-plus'
import { defineComponent } from 'vue'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import BenefitsPage from '@/modules/platform/pages/BenefitsPage.vue'
import StatutoryParametersPanel from '@/modules/platform/components/StatutoryParametersPanel.vue'

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

const DrawerStub = defineComponent({
  props: { modelValue: Boolean, title: String },
  emits: ['update:modelValue'],
  template: `<div v-if="modelValue" class="el-drawer-stub" role="dialog">
    <h2>{{ title }}</h2>
    <slot />
    <slot name="footer" />
  </div>`,
})



function useCapabilities(capabilities: string[]): void {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({
    status: 'authenticated',
    capabilities,
    principal: {
      userId: 1001,
      employmentId: 2001,
      displayName: 'Synthetic Benefits Admin',
      email: 'benefits@example.invalid',
      employmentNo: 'E-BENEFITS',
      jobTitle: '福利专员',
      orgName: 'Synthetic Company',
      initials: 'BA',
      isPrivileged: true,
      hasMfa: true,
      authVersion: 1,
      tenantTimezone: 'Asia/Shanghai',
    },
  })
}


const SelectStub = defineComponent({
  props: { modelValue: [String, Number] },
  emits: ['update:modelValue'],
  template: `<select :value="modelValue" @change="$emit('update:modelValue', $event.target.value)"><slot /></select>`,
})

const OptionStub = defineComponent({
  props: { value: [String, Number], label: String },
  template: `<option :value="value">{{ label }}</option>`,
})

async function mountBenefits(path: string, capabilities: string[], component: object = BenefitsPage) {
  useCapabilities(capabilities)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/benefits', name: 'benefits', component: { template: '<div />' } }],
  })
  await router.push(path)
  await router.isReady()
  return mount(component, {
    global: {
      plugins: [ElementPlus, router],
      stubs: {
        'el-drawer': DrawerStub,
        'el-select': SelectStub,
        'el-option': OptionStub,
        'el-pagination': true,
        teleport: true,
      },
    },
  })
}

function listResponse(items: unknown[], overrides: Record<string, unknown> = {}) {
  return { items, page: 1, page_size: 100, total: items.length, ...overrides }
}

const plan = {
  id: 10,
  code: 'SUPPLEMENTARY_MEDICAL',
  name: '补充医疗',
  employer_rate: '0.160000',
  employee_rate: '0.080000',
  effective_from: '2026-01-01',
  effective_to: null,
  version: 1,
}

const enrollment = {
  id: 20,
  plan_id: 10,
  employment_id: 1001,
  base_amount_cents: 1_000_000,
  employee_rate: '0.080000',
  employer_rate: '0.160000',
  employee_contribution_cents: 80_000,
  employer_contribution_cents: 160_000,
  effective_from: '2026-01-01',
  effective_to: null,
  version: 1,
}

const transaction = {
  id: 30,
  enrollment_id: 20,
  employment_id: 1001,
  period: '2026-01',
  base_amount_cents: 1_000_000,
  employee_amount_cents: 80_000,
  employer_amount_cents: 160_000,
  total_amount_cents: 240_000,
  payroll_run_id: 77,
  entry_type: 'RECORD',
  reversal_of_id: null,
  reversed_by_id: null,
  reason: null,
  source_hash: 'a'.repeat(64),
  status: 'RECORDED',
}

const statutoryRows = [
  {
    id: 1,
    parameter_set: 'SOCIAL_INSURANCE',
    code: 'MIN_BASE_CENTS',
    value_decimal: null,
    value_int: 400_000,
    effective_from: '2026-07-01',
    effective_to: '9999-12-31',
    version: 'SI-2026.2',
    approved_by: 9,
    evidence_ref: '董事会 2026-06 决议',
    is_demo: false,
  },
  {
    id: 2,
    parameter_set: 'SOCIAL_INSURANCE',
    code: 'MAX_BASE_CENTS',
    value_decimal: null,
    value_int: 3_000_000,
    effective_from: '2026-07-01',
    effective_to: '9999-12-31',
    version: 'SI-2026.2',
    approved_by: 9,
    evidence_ref: '董事会 2026-06 决议',
    is_demo: false,
  },
  {
    id: 3,
    parameter_set: 'SOCIAL_INSURANCE',
    code: 'EMPLOYEE_RATE',
    value_decimal: '8.000000',
    value_int: null,
    effective_from: '2026-07-01',
    effective_to: '9999-12-31',
    version: 'SI-2026.2',
    approved_by: 9,
    evidence_ref: '董事会 2026-06 决议',
    is_demo: false,
  },
  {
    id: 4,
    parameter_set: 'SOCIAL_INSURANCE',
    code: 'EMPLOYER_RATE',
    value_decimal: '16.000000',
    value_int: null,
    effective_from: '2026-07-01',
    effective_to: '9999-12-31',
    version: 'SI-2026.2',
    approved_by: 9,
    evidence_ref: '董事会 2026-06 决议',
    is_demo: false,
  },
]

function mockBenefitsAndStatutory() {
  resource.get.mockImplementation((path: string) => {
    if (path === '/v1/benefits/plans') return Promise.resolve(listResponse([plan]))
    if (path === '/v1/benefits/enrollments') return Promise.resolve(listResponse([enrollment]))
    if (path === '/v1/benefits/transactions') return Promise.resolve(listResponse([transaction]))
    if (path === '/v1/payroll/statutory-parameters') return Promise.resolve(listResponse(statutoryRows))
    return Promise.reject(new Error(`Unexpected path ${path}`))
  })
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.put.mockReset()
  resource.patch.mockReset()
  resource.delete.mockReset()
  resource.get.mockResolvedValue(listResponse([]))
  resource.post.mockResolvedValue({})
})

describe('benefits workspace', () => {
  it('renders four tabs and hides statutory write without parameters manage', async () => {
    mockBenefitsAndStatutory()
    const wrapper = await mountBenefits('/benefits?tab=statutory', ['benefit.read', 'payroll.parameters.read'])
    await flushPromises()

    expect(wrapper.findAll('[data-testid^="benefits-tab-"]')).toHaveLength(4)
    expect(wrapper.find('[data-testid="statutory-parameters-panel"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="statutory-parameters-panel"]').text()).toContain('社会保险')
    expect(wrapper.find('[data-testid="statutory-parameters-panel"]').text()).toContain('全公司')
    expect(wrapper.find('[data-testid="statutory-parameters-panel"]').text()).toContain('长期有效')
    expect(wrapper.find('[data-testid="statutory-parameters-panel"]').text()).toContain('¥4,000.00')
    expect(wrapper.find('[data-testid="statutory-parameters-panel"]').text()).toContain('8%')
    expect(wrapper.find('[data-testid="statutory-parameters-panel"]').text()).toContain('16%')
    expect(wrapper.find('[data-testid="statutory-parameters-panel"]').text()).not.toContain('8.000000')
    expect(wrapper.find('[data-testid="statutory-parameters-panel"]').text()).not.toContain('SOCIAL_INSURANCE')
    expect(wrapper.find('[data-testid="statutory-version-submit"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="statutory-version-open"]').exists()).toBe(false)
  })

  it('shows the statutory tab only with payroll parameter read and falls back to plans', async () => {
    mockBenefitsAndStatutory()
    const wrapper = await mountBenefits('/benefits?tab=statutory', ['benefit.read'])
    await flushPromises()

    expect(wrapper.findAll('[data-testid^="benefits-tab-"]')).toHaveLength(3)
    expect(wrapper.find('[data-testid="statutory-parameters-panel"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="benefit-plans-panel"]').exists()).toBe(true)
  })

  it('creates a statutory insurance version with integer cents and decimal rate strings', async () => {
    mockBenefitsAndStatutory()
    resource.post.mockResolvedValue({
      version: 'SI-2027.1',
      parameter_set: 'SOCIAL_INSURANCE',
      rows: 4,
      is_demo: false,
      demo_notice: null,
    })

    const wrapper = await mountBenefits('/benefits?tab=statutory', [
      'benefit.read',
      'payroll.parameters.read',
      'payroll.parameters.manage',
    ])
    await flushPromises()
    await wrapper.get('[data-testid="statutory-version-open"]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-testid="statutory-version"]').setValue('SI-2027.1')
    await wrapper.get('[data-testid="statutory-evidence-ref"]').setValue('薪酬委员会 2026-12 决议')
    await wrapper.get('[data-testid="statutory-min-base"]').setValue('4000')
    await wrapper.get('[data-testid="statutory-max-base"]').setValue('30000')
    await wrapper.get('[data-testid="statutory-employee-rate"]').setValue('8')
    await wrapper.get('[data-testid="statutory-employer-rate"]').setValue('16')
    await wrapper.get('[data-testid="statutory-version-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/payroll/statutory-parameters',
      expect.objectContaining({
        parameter_set: 'SOCIAL_INSURANCE',
        version: 'SI-2027.1',
        MIN_BASE_CENTS: 400_000,
        MAX_BASE_CENTS: 3_000_000,
        EMPLOYEE_RATE: '8.000000',
        EMPLOYER_RATE: '16.000000',
        evidence_ref: '薪酬委员会 2026-12 决议',
      }),
      expect.objectContaining({ idempotencyKey: expect.stringContaining('statutory') }),
    )
  })

  it('keeps tax brackets as integer cents and basis points', async () => {
    mockBenefitsAndStatutory()
    resource.post.mockResolvedValue({
      version: 'TAX-2027.1',
      parameter_set: 'TAX',
      rows: 1,
      is_demo: false,
      demo_notice: null,
    })

    const wrapper = await mountBenefits('/benefits?tab=statutory', [
      'benefit.read',
      'payroll.parameters.read',
      'payroll.parameters.manage',
    ])
    await flushPromises()
    await wrapper.get('[data-testid="statutory-version-open"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="statutory-parameter-set"]').setValue('TAX')
    await wrapper.get('[data-testid="statutory-version"]').setValue('TAX-2027.1')
    await wrapper.get('[data-testid="statutory-evidence-ref"]').setValue('税务顾问 2026-12 书面意见')
    await wrapper.get('[data-testid="statutory-threshold"]').setValue('5000')
    await wrapper.get('[data-testid="statutory-bracket-lower"]').setValue('0')
    await wrapper.get('[data-testid="statutory-bracket-upper"]').setValue('3000')
    await wrapper.get('[data-testid="statutory-bracket-rate"]').setValue('3')
    await wrapper.get('[data-testid="statutory-bracket-quick-deduction"]').setValue('0')
    await wrapper.get('[data-testid="statutory-bracket-add"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="statutory-bracket-table"]').text()).toContain('3%')

    await wrapper.get('[data-testid="statutory-version-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/payroll/statutory-parameters',
      expect.objectContaining({
        parameter_set: 'TAX',
        THRESHOLD_CENTS: 500_000,
        brackets: [{
          lower_bound_cents: 0,
          upper_bound_cents: 300_000,
          rate_bps: 300,
          quick_deduction_cents: 0,
        }],
      }),
      expect.objectContaining({ idempotencyKey: expect.stringContaining('statutory') }),
    )
  })

  it('renders statutory loading, empty, forbidden and error states', async () => {
    resource.get.mockResolvedValue(listResponse([]))
    const empty = await mountBenefits('/benefits?tab=statutory', ['benefit.read', 'payroll.parameters.read'])
    expect(empty.find('[data-testid="statutory-parameters-panel"]').exists()).toBe(true)
    expect(empty.find('[data-testid="statutory-parameters-panel"]').text()).toContain('正在加载法定参数版本')
    await flushPromises()
    expect(empty.find('[data-testid="statutory-parameters-empty"]').exists()).toBe(true)

    resource.get.mockRejectedValue(new ApiError('FORBIDDEN', 'Forbidden', { status: 403 }))
    const forbidden = await mountBenefits('/benefits?tab=statutory', ['benefit.read', 'payroll.parameters.read'])
    await flushPromises()
    expect(forbidden.find('[data-testid="statutory-parameters-forbidden"]').exists()).toBe(true)

    resource.get.mockRejectedValue(new ApiError('INTERNAL', 'Boom', { status: 500, requestId: 'req-statutory-500' }))
    const failed = await mountBenefits('/benefits?tab=statutory', ['benefit.read', 'payroll.parameters.read'])
    await flushPromises()
    expect(failed.find('[data-testid="statutory-parameters-error"]').text()).toContain('req-statutory-500')
  })

  it('loads the statutory tab from only the statutory API and plans tab from plans plus enrolment counts', async () => {
    mockBenefitsAndStatutory()
    const statutory = await mountBenefits('/benefits?tab=statutory', ['benefit.read', 'payroll.parameters.read'])
    await flushPromises()

    const statutoryPaths = resource.get.mock.calls.map((call) => call[0] as string)
    expect(statutoryPaths).toContain('/v1/payroll/statutory-parameters')
    expect(statutoryPaths).not.toContain('/v1/payroll/items')
    expect(statutory.find('[data-testid="statutory-parameters-panel"]').exists()).toBe(true)

    resource.get.mockClear()
    const plans = await mountBenefits('/benefits?tab=plans', ['benefit.read'])
    await flushPromises()

    const paths = resource.get.mock.calls.map((call) => call[0] as string)
    expect(paths).toContain('/v1/benefits/plans')
    expect(paths).toContain('/v1/benefits/enrollments')
    expect(paths).not.toContain('/v1/payroll/items')
    expect(plans.find('[data-testid="benefit-plans-panel"]').text()).toContain('16%')
    expect(plans.find('[data-testid="benefit-plans-panel"]').text()).not.toContain('0.160000')
  })

  it('renders benefit plan, enrollment and transaction formats on their own tabs', async () => {
    mockBenefitsAndStatutory()

    const plans = await mountBenefits('/benefits?tab=plans', ['benefit.read'])
    await flushPromises()
    expect(plans.find('[data-testid="benefit-plans-panel"]').text()).toContain('补充医疗')
    expect(plans.find('[data-testid="benefit-plans-panel"]').text()).toContain('1 人')

    const enrollments = await mountBenefits('/benefits?tab=enrollments', ['benefit.read'])
    await flushPromises()
    expect(enrollments.find('[data-testid="benefit-enrollments-panel"]').text()).toContain('¥10,000.00')
    expect(enrollments.find('[data-testid="benefit-enrollments-panel"]').text()).toContain('8%')
    expect(enrollments.find('[data-testid="benefit-enrollments-panel"]').text()).not.toContain('0.080000')

    const transactions = await mountBenefits('/benefits?tab=transactions', ['benefit.read'])
    await flushPromises()
    expect(transactions.find('[data-testid="benefit-transactions-panel"]').text()).toContain('¥2,400.00')
    expect(transactions.find('[data-testid="benefit-transactions-panel"]').text()).toContain('工资批次 #77')
  })

  it('renders P3-masked benefit amounts as restricted instead of zero or crashing', async () => {
    const maskedEnrollment = {
      id: 20,
      plan_id: 10,
      employment_id: 1001,
      employee_rate: '0.080000',
      employer_rate: '0.160000',
      effective_from: '2026-01-01',
      effective_to: null,
      version: 1,
    }
    const maskedTransaction = {
      id: 30,
      enrollment_id: 20,
      employment_id: 1001,
      period: '2026-01',
      payroll_run_id: null,
      entry_type: 'RECORD',
      reversal_of_id: null,
      reversed_by_id: null,
      reason: null,
      source_hash: 'a'.repeat(64),
      status: 'RECORDED',
    }
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/benefits/plans') return Promise.resolve(listResponse([plan]))
      if (path === '/v1/benefits/enrollments') return Promise.resolve(listResponse([maskedEnrollment]))
      if (path === '/v1/benefits/transactions') return Promise.resolve(listResponse([maskedTransaction]))
      return Promise.resolve(listResponse([]))
    })

    const enrollments = await mountBenefits('/benefits?tab=enrollments', ['benefit.read'])
    await flushPromises()
    expect(enrollments.find('[data-testid="benefit-enrollments-panel"]').text()).toContain('金额受限')
    expect(enrollments.find('[data-testid="benefit-enrollments-panel"]').text()).not.toContain('¥0.00')

    const transactions = await mountBenefits('/benefits?tab=transactions', ['benefit.read'])
    await flushPromises()
    expect(transactions.find('[data-testid="benefit-transactions-panel"]').text()).toContain('金额受限')
    expect(transactions.find('[data-testid="benefit-transactions-panel"]').text()).not.toContain('¥0.00')
  })

  it('marks enrollment counts truncated when the single read does not cover every enrolment', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/benefits/plans') return Promise.resolve(listResponse([plan]))
      if (path === '/v1/benefits/enrollments') {
        return Promise.resolve(listResponse([enrollment], { total: 150 }))
      }
      if (path === '/v1/benefits/transactions') return Promise.resolve(listResponse([]))
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const wrapper = await mountBenefits('/benefits?tab=plans', ['benefit.read'])
    await flushPromises()

    expect(wrapper.find('[data-testid="benefit-enrollment-count-truncated"]').exists()).toBe(true)
    expect(resource.get.mock.calls.filter((call) => call[0] === '/v1/benefits/enrollments')).toHaveLength(1)
  })

  it('posts a benefit plan with decimal rates and an enrollment in integer cents', async () => {
    mockBenefitsAndStatutory()
    resource.post.mockImplementation((path: string) => {
      if (path === '/v1/benefits/plans') return Promise.resolve(plan)
      if (path === '/v1/benefits/enrollments') return Promise.resolve(enrollment)
      return Promise.resolve({})
    })

    const wrapper = await mountBenefits('/benefits?tab=plans', ['benefit.read', 'benefit.manage'])
    await flushPromises()
    await wrapper.get('[data-testid="benefit-plan-code"]').setValue('SUPPLEMENTARY_MEDICAL')
    await wrapper.get('[data-testid="benefit-plan-name"]').setValue('补充医疗')
    await wrapper.get('[data-testid="benefit-plan-employer-rate"]').setValue('16')
    await wrapper.get('[data-testid="benefit-plan-employee-rate"]').setValue('8')
    await wrapper.get('[data-testid="benefit-plan-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/benefits/plans',
      expect.objectContaining({
        employer_rate: '0.160000',
        employee_rate: '0.080000',
        effective_to: null,
      }),
      expect.objectContaining({ idempotencyKey: expect.stringContaining('benefit-plan') }),
    )

    resource.post.mockClear()
    const enrollmentWrapper = await mountBenefits('/benefits?tab=enrollments', ['benefit.read', 'benefit.manage'])
    await flushPromises()
    await vi.waitFor(() => {
      expect(enrollmentWrapper.find('[data-testid="benefit-enrollment-employment"]').exists()).toBe(true)
    })
    await enrollmentWrapper.get('[data-testid="benefit-enrollment-employment"]').setValue('1001')
    await enrollmentWrapper.get('[data-testid="benefit-enrollment-base"]').setValue('10000')
    await enrollmentWrapper.get('[data-testid="benefit-enrollment-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/benefits/enrollments',
      expect.objectContaining({
        plan_id: 10,
        employment_id: 1001,
        base_amount_cents: 1_000_000,
        effective_to: null,
      }),
      expect.objectContaining({ idempotencyKey: expect.stringContaining('benefit-enrollment') }),
    )
  })

  it('reverses a recorded contribution with a reason and the real command endpoint', async () => {
    mockBenefitsAndStatutory()
    const prompt = vi.spyOn(ElMessageBox, 'prompt').mockResolvedValue({
      value: 'Synthetic duplicate contribution reversal.',
    } as never)
    resource.post.mockResolvedValue({
      ...transaction,
      id: 31,
      entry_type: 'REVERSAL',
      reversal_of_id: 30,
      status: 'REVERSAL',
    })

    const wrapper = await mountBenefits('/benefits?tab=transactions', ['benefit.read', 'benefit.manage'])
    await flushPromises()
    await wrapper.get('[data-testid="benefit-transaction-reverse-30"]').trigger('click')
    await flushPromises()

    expect(prompt).toHaveBeenCalled()
    expect(resource.post).toHaveBeenCalledWith(
      '/v1/benefits/transactions/30/reverse',
      { reason: 'Synthetic duplicate contribution reversal.' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.find('[data-testid="benefit-transaction-message"]').text()).toContain('冲销')
    prompt.mockRestore()
  })

  it('translates a frozen payroll run conflict without exposing the server message', async () => {
    mockBenefitsAndStatutory()
    resource.post.mockRejectedValue(new ApiError('CONFLICT', 'Frozen payroll run', {
      status: 409,
      details: { reason: 'BENEFIT_PAYROLL_RUN_FROZEN' },
    }))

    const wrapper = await mountBenefits('/benefits?tab=transactions', ['benefit.read', 'benefit.manage'])
    await flushPromises()
    await wrapper.get('[data-testid="benefit-transaction-enrollment"]').setValue('20')
    await wrapper.get('[data-testid="benefit-transaction-payroll-run"]').setValue('77')
    await wrapper.get('[data-testid="benefit-transaction-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="benefit-transaction-error"]').text()).toContain('已发布')
    expect(wrapper.text()).not.toContain('Frozen payroll run')
  })

  it('renders benefit forbidden, empty and partial states without inventing rows', async () => {
    resource.get.mockRejectedValue(new ApiError('FORBIDDEN', 'Forbidden', { status: 403 }))
    const forbidden = await mountBenefits('/benefits?tab=plans', ['benefit.read'])
    await flushPromises()
    expect(forbidden.find('[data-testid="benefit-forbidden"]').exists()).toBe(true)

    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/benefits/plans') return Promise.resolve(listResponse([]))
      if (path === '/v1/benefits/enrollments') return Promise.resolve(listResponse([]))
      if (path === '/v1/benefits/transactions') return Promise.resolve(listResponse([]))
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })
    const empty = await mountBenefits('/benefits?tab=plans', ['benefit.read'])
    await flushPromises()
    expect(empty.find('[data-testid="benefit-empty"]').exists()).toBe(true)

    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/benefits/plans') return Promise.resolve(listResponse([plan]))
      if (path === '/v1/benefits/enrollments') return Promise.reject(new ApiError('INTERNAL', 'Boom', { status: 500 }))
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })
    const partial = await mountBenefits('/benefits?tab=plans', ['benefit.read'])
    await flushPromises()
    expect(partial.find('[data-testid="benefit-partial"]').exists()).toBe(true)
  })

  it('rejects an invalid benefit percentage before the API is called', async () => {
    mockBenefitsAndStatutory()
    const wrapper = await mountBenefits('/benefits?tab=plans', ['benefit.read', 'benefit.manage'])
    await flushPromises()
    await wrapper.get('[data-testid="benefit-plan-code"]').setValue('SUPPLEMENTARY_MEDICAL')
    await wrapper.get('[data-testid="benefit-plan-name"]').setValue('补充医疗')
    await wrapper.get('[data-testid="benefit-plan-employer-rate"]').setValue('abc')
    await wrapper.get('[data-testid="benefit-plan-employee-rate"]').setValue('8')
    await wrapper.get('[data-testid="benefit-plan-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="benefit-action-error"]').text()).toContain('比例')
  })

  it('mounts the statutory panel standalone with read-only access', async () => {
    mockBenefitsAndStatutory()
    const wrapper = await mountBenefits('/benefits', ['payroll.parameters.read'], StatutoryParametersPanel)
    await flushPromises()

    expect(wrapper.find('[data-testid="statutory-parameters-panel"]').text()).toContain('社会保险')
    expect(wrapper.find('[data-testid="statutory-version-open"]').exists()).toBe(false)
  })
})
