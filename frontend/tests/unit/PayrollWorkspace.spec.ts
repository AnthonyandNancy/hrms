import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import { defineComponent } from 'vue'
import { ApiError } from '@/api/errors'
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
import PayrollActionBar from '@/modules/payroll/components/PayrollActionBar.vue'
import PayslipDrawer from '@/modules/payroll/components/PayslipDrawer.vue'

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
    allowed_actions: [
      'payroll.run.detail.lock-inputs',
      'payroll.run.detail.calculate',
      'payroll.run.detail.review',
      'payroll.run.detail.approve',
      'payroll.run.detail.publish',
      'payroll.run.detail.mark-paid',
      'payroll.run.detail.close',
    ],
    employees: [
      {
        employment_id: 21,
        status: 'CALCULATED',
        failure_reason: null,
        gross_cents: 800000,
        taxable_cents: 800000,
        deductions_cents: 200000,
        net_cents: 600000,
        employer_cost_cents: 1000000,
        employee_si_cents: 64000,
        employee_hf_cents: 96000,
        tax_cents: 40000,
      },
      {
        employment_id: 22,
        status: 'FAILED',
        failure_reason: '缺少计薪输入快照',
      },
    ],
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
  'payroll.payslip.read',
]

function piniaWith(capabilities: string[]): ReturnType<typeof createPinia> {
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSessionStore()
  session.capabilities = capabilities
  session.status = 'authenticated'
  return pinia
}

async function mountAt(
  component: typeof PayrollWorkspacePage | typeof PayrollRunDetailPage,
  path: string,
  capabilities: string[] = CAPABILITIES,
): Promise<VueWrapper> {
  const pinia = piniaWith(capabilities)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/payroll', name: 'payroll', component: { template: '<div />' } },
      { path: '/payroll/runs/:id', name: 'payroll-run-detail', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()

  const wrapper = mount(component, {
    global: {
      plugins: [pinia, router, ElementPlus],
      stubs: {
        ElDrawer: OverlayStub,
        ElDialog: OverlayStub,
      },
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

describe('payroll workspace list', () => {
  it('renders real run rows with Chinese stage labels, headcount and integer-cent money', async () => {
    resource.get.mockResolvedValue(
      page([
        runRaw(),
        runRaw({
          id: 8,
          period: '2026-10',
          run_type: 'CORRECTION',
          status: 'REVIEWED',
          employee_count: 5,
          gross_cents: 900,
          deductions_cents: 100,
          net_cents: 800,
        }),
      ]),
    )

    const wrapper = await mountAt(PayrollWorkspacePage, '/payroll')

    expect(resource.get).toHaveBeenCalledWith(
      '/v1/payroll/runs',
      expect.objectContaining({ page: 1, page_size: 20 }),
    )
    const text = wrapper.text()
    expect(text).toContain('薪资工作台')
    expect(text).toContain('2026-11')
    expect(text).toContain('草稿')
    expect(text).toContain('已提交')
    expect(text).toContain('常规批次')
    expect(text).toContain('更正批次')
    expect(text).toContain('¥12,345.67')
    expect(text).toContain('¥10,000.00')
    expect(text).toContain('¥9.00')
    expect(text).toContain('¥8.00')
    expect(text).toContain('第 1 / 1 页')
  })

  it('sends the period and status filters to the API and keeps pagination server-driven', async () => {
    resource.get.mockResolvedValue(page([runRaw()], { page: 2, total: 21 }))

    const wrapper = await mountAt(PayrollWorkspacePage, '/payroll')

    await wrapper.get('[data-testid="payroll-period-filter"]').setValue('2026-11')
    await flushPromises()
    const statusFilter = wrapper.getComponent('[data-testid="payroll-status-filter"]') as VueWrapper
    await statusFilter.setValue('REVIEWED')
    statusFilter.vm.$emit('change', 'REVIEWED')
    await flushPromises()

    expect(resource.get).toHaveBeenLastCalledWith(
      '/v1/payroll/runs',
      expect.objectContaining({ period: '2026-11', status: 'REVIEWED', page: 1, page_size: 20 }),
    )
    expect(wrapper.text()).toContain('第 2 / 2 页')
  })

  it('renders a real empty state without inventing rows and a forbidden state without fetching', async () => {
    resource.get.mockResolvedValue(page([], { total: 0 }))
    const empty = await mountAt(PayrollWorkspacePage, '/payroll')
    expect(empty.text()).toContain('还没有工资批次')
    expect(empty.findAll('tbody tr')).toHaveLength(0)

    resource.get.mockClear()
    const forbidden = await mountAt(PayrollWorkspacePage, '/payroll', [])
    expect(forbidden.get('[data-testid="payroll-forbidden"]').text()).toContain('payroll.run.read')
    expect(resource.get).not.toHaveBeenCalled()
  })

  it('renders the shared error state with the server message and retries', async () => {
    resource.get
      .mockRejectedValueOnce(new ApiError('INTERNAL', '服务端暂时不可用', { status: 500 }))
      .mockResolvedValueOnce(page([runRaw()]))

    const wrapper = await mountAt(PayrollWorkspacePage, '/payroll')
    expect(wrapper.text()).toContain('服务端暂时不可用')

    const retry = wrapper.findAll('button').find((button) => button.text() === '重试')
    expect(retry).toBeDefined()
    await retry?.trigger('click')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('2026-11')
  })

  it('marks amount columns as permission-hidden when the API omits money keys', async () => {
    const masked = runRaw()
    delete masked.gross_cents
    delete masked.deductions_cents
    delete masked.net_cents
    resource.get.mockResolvedValue(page([masked]))

    const wrapper = await mountAt(PayrollWorkspacePage, '/payroll')

    expect(wrapper.get('[data-testid="payroll-partial"]').text()).toContain('字段策略隐藏')
    expect(wrapper.text()).toContain('已按字段权限隐藏')
    expect(wrapper.text()).not.toContain('¥0.00')
  })
})

describe('payroll run detail and action bar', () => {
  it('renders employee gross, deduction and net integer minors plus failure reasons', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/payroll/runs/7/employees') {
        return Promise.resolve(page([
          { employment_id: 21, status: 'CALCULATED', failure_reason: null, gross_cents: 800000, deductions_cents: 200000, net_cents: 600000 },
          { employment_id: 22, status: 'FAILED', failure_reason: '缺少计薪输入快照' },
        ]))
      }
      if (path === '/v1/payroll/runs/7/inputs') {
        return Promise.resolve(page([
          { id: 1, employment_id: 21, snapshot_type: 'INPUT', snapshot_version: 2, source_hash: 'b'.repeat(64), payload: { compensation_segments: [{ compensation_id: 41, segment_from: '2026-11-01', segment_to: '2026-11-15', base_salary_cents: 800000 }, { compensation_id: 42, segment_from: '2026-11-16', segment_to: '2026-11-30', base_salary_cents: 900000 }] }, created_at: '2026-11-25T16:00:00Z' },
        ]))
      }
      if (path === '/v1/payroll/runs/7/reconciliation') {
        return Promise.resolve({
          run_id: 7, period: '2026-11', status: 'DRAFT', employee_count: 2, payslip_count: 1,
          gross_cents: 800000, deductions_cents: 200000, net_cents: 600000, employer_cost_cents: 1000000,
          differences: [], chain: { ytd_ledger: [{ source_run_id: 7, source_type: 'PAYROLL', gross_cents: 800000, net_cents: -120000, carry_forward_cents: -120000, source_hash: 'c'.repeat(64) }] }, passed: true,
        })
      }
      return Promise.resolve(detailRaw({
        run_type: 'CORRECTION',
        correction_of_run_id: 3,
        correction_sequence: 2,
        correction_baseline_run_id: 4,
        prior_correction_ids: [4],
        correction_target_net_cents: 835000,
        correction_baseline_net_cents: 830000,
        correction_delta_net_cents: 5000,
        correction_cumulative_net_cents: 835000,
      }))
    })

    const wrapper = await mountAt(PayrollRunDetailPage, '/payroll/runs/7?tab=inputs')

    expect(resource.get).toHaveBeenCalledWith('/v1/payroll/runs/7')
    expect(resource.get).toHaveBeenCalledWith('/v1/payroll/runs/7/employees')
    expect(resource.get).toHaveBeenCalledWith('/v1/payroll/runs/7/inputs')
    expect(resource.get).toHaveBeenCalledWith('/v1/payroll/runs/7/reconciliation')
    expect(wrapper.get('[data-testid="payroll-input-table"]').text()).toContain('员工 #21')
    expect(wrapper.get('[data-testid="payroll-compensation-segments"]').text()).toContain('2026年11月1日')
    expect(wrapper.get('[data-testid="payroll-compensation-segments"]').text()).toContain('8,000.00')
    expect(wrapper.get('[data-testid="payroll-ytd-ledger"]').text()).toContain('结转')
    const correction = wrapper.get('[data-testid="payroll-correction-chain"]').text()
    expect(correction).toContain('第 2 次更正')
    expect(correction).toContain('8,350.00')
    expect(correction).toContain('50.00')
    expect(wrapper.get('[data-testid="payroll-reconciliation-status"]').text()).toContain('对账通过')
    expect(wrapper.text()).toContain('2026 年 11 月')
  })

  it('disables actions with Chinese reasons when the transition or capability is missing', async () => {
    resource.get.mockResolvedValue(detailRaw({ allowed_actions: [] }))

    const wrapper = await mountAt(PayrollRunDetailPage, '/payroll/runs/7?tab=export', ['payroll.run.read'])

    const lock = wrapper.get('[data-testid="payroll-action-lock-inputs"]')
    expect(lock.attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="payroll-action-reason-lock-inputs"]').text()).toContain('当前状态或授权不允许')
    expect(wrapper.get('[data-testid="payroll-action-reason-approve"]').text()).toContain('当前状态或授权不允许')
  })

  it('explains illegal transitions for an allowed capability without issuing a request', async () => {
    resource.get.mockResolvedValue(detailRaw({ status: 'PUBLISHED' }))

    const wrapper = await mountAt(PayrollRunDetailPage, '/payroll/runs/7?tab=payslips')
    const calculate = wrapper.get('[data-testid="payroll-action-calculate"]')

    expect(calculate.attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="payroll-readonly"]').text()).toContain('只读')
    await calculate.trigger('click')
    await flushPromises()
    expect(resource.post).not.toHaveBeenCalled()
  })

  it('names the period in the confirmation and posts only the real lifecycle endpoint', async () => {
    resource.get.mockResolvedValue(detailRaw())

    const wrapper = await mountAt(PayrollRunDetailPage, '/payroll/runs/7?tab=payslips')
    await wrapper.get('[data-testid="payroll-action-lock-inputs"]').trigger('click')

    const dialog = wrapper.get('[data-testid="payroll-action-confirm"]')
    expect(dialog.text()).toContain('锁定计薪输入')
    expect(dialog.text()).toContain('2026-11')

    await wrapper.get('[data-testid="payroll-action-confirm-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledTimes(1)
    const [path, body, options] = resource.post.mock.calls[0] as [string, Record<string, unknown>, { idempotencyKey?: string }]
    expect(path).toBe('/v1/payroll/runs/7/lock-inputs')
    expect(body).toEqual({})
    expect(options?.idempotencyKey).toMatch(/^payroll-lock-inputs-/)
  })

  it('reports a 409 transition conflict in Chinese instead of a raw server message', async () => {
    resource.get.mockResolvedValue(detailRaw())
    resource.post.mockRejectedValue(
      new ApiError('CONFLICT', 'Invalid transition.', {
        status: 409,
        details: { reason: 'RUN_NOT_LOCKABLE' },
      }),
    )

    const wrapper = await mountAt(PayrollRunDetailPage, '/payroll/runs/7?tab=payslips')
    await wrapper.get('[data-testid="payroll-action-lock-inputs"]').trigger('click')
    await wrapper.get('[data-testid="payroll-action-confirm-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('仅草稿批次可以锁定输入。')
    expect(wrapper.text()).not.toContain('Invalid transition.')
  })

  it('posts cancellation and correction only when the server action is present', async () => {
    resource.get.mockResolvedValue(detailRaw({ allowed_actions: ['payroll.run.detail.cancel'] }))
    const wrapper = await mountAt(PayrollRunDetailPage, '/payroll/runs/7?tab=export')
    expect(wrapper.find('[data-testid="payroll-more-cancel"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="payroll-more-correction"]').exists()).toBe(false)

    await wrapper.get('[data-testid="payroll-more-cancel"]').trigger('click')
    await wrapper.get('[data-testid="payroll-more-reason"]').setValue('Synthetic cancellation reason.')
    await wrapper.get('[data-testid="payroll-more-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/payroll/runs/7/cancel',
      { reason: 'Synthetic cancellation reason.' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('creates a correction with the server action and exact period/reason', async () => {
    resource.get.mockResolvedValue(detailRaw({
      status: 'PUBLISHED',
      allowed_actions: ['payroll.run.detail.correct'],
    }))
    resource.post.mockResolvedValue(detailRaw({
      status: 'PUBLISHED',
      allowed_actions: ['payroll.run.detail.correct'],
    }))

    const wrapper = await mountAt(PayrollRunDetailPage, '/payroll/runs/7?tab=export')
    await wrapper.get('[data-testid="payroll-more-correction"]').trigger('click')
    await wrapper.get('[data-testid="payroll-more-period"]').setValue('2026-12')
    await wrapper.get('[data-testid="payroll-more-reason"]').setValue('Synthetic correction reason.')
    await wrapper.get('[data-testid="payroll-more-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/payroll/runs/7/corrections',
      { period: '2026-12', reason: 'Synthetic correction reason.' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('keeps exports available on a published run and downloads the real API response', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/payroll/runs/7') return Promise.resolve(detailRaw({ status: 'PUBLISHED' }))
      return Promise.resolve({
        filename: 'payroll-bank-2026-11-run-7.csv',
        content: 'employee_id,period\n21,2026-11\n',
        sha256: 'a'.repeat(64),
      })
    })

    const originalCreate = URL.createObjectURL
    const originalRevoke = URL.revokeObjectURL
    const createObjectURL = vi.fn(() => 'blob:payroll-export')
    const revokeObjectURL = vi.fn()
    Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: createObjectURL })
    Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: revokeObjectURL })

    try {
      const wrapper = await mountAt(PayrollRunDetailPage, '/payroll/runs/7?tab=export')
      const bank = wrapper.get('[data-testid="payroll-export-bank"]')
      expect(bank.attributes('disabled')).toBeUndefined()

      await bank.trigger('click')
      await flushPromises()

      expect(resource.get).toHaveBeenCalledWith('/v1/payroll/runs/7/exports/bank')
      expect(createObjectURL).toHaveBeenCalledTimes(1)
      expect(wrapper.text()).toContain('银行代发导出已由服务端生成')
      expect(wrapper.text()).toContain('a'.repeat(64))
      expect(wrapper.html()).not.toContain('/v1/payroll/runs/7/exports/bank?')
    } finally {
      Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: originalCreate })
      Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: originalRevoke })
    }
  })

  it('does not fabricate pay data when the run detail has no employee rows', async () => {
    resource.get.mockResolvedValue(detailRaw({ employees: [], employee_count: 0 }))

    const wrapper = await mountAt(PayrollRunDetailPage, '/payroll/runs/7?tab=payslips')

    expect(wrapper.text()).toContain('该批次没有员工行')
    expect(wrapper.findAll('[data-testid^="payroll-employee-"]')).toHaveLength(0)
  })
})

describe('payroll action bar and payslip drawer', () => {
  it('only enables the actions whose state transition is legal', async () => {
    const wrapper = mount(PayrollActionBar, {
      props: {
        status: 'REVIEWED',
        period: '2026-11',
        allowedActions: [
          'payroll.run.detail.lock-inputs',
          'payroll.run.detail.approve',
          'payroll.run.detail.publish',
        ],
      },
      global: { plugins: [ElementPlus], stubs: { ElDialog: OverlayStub, teleport: true } },
    })

    expect(wrapper.get('[data-testid="payroll-action-approve"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.get('[data-testid="payroll-action-lock-inputs"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="payroll-action-publish"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="payroll-action-reason-publish"]').text()).toContain(
      '仅已批准的批次可以发布',
    )
  })

  it('renders the payslip drawer line items from returned employee amounts only', async () => {
    const wrapper = mount(PayslipDrawer, {
      props: {
        modelValue: true,
        run: {
          id: 7,
          period: '2026-11',
          runType: 'REGULAR',
          status: 'PUBLISHED',
          correctionOfRunId: null,
          payDate: '2026-12-05',
          employeeCount: 1,
          createdAt: '2026-11-20T02:00:00Z',
          grossCents: 800000,
          deductionsCents: 200000,
          netCents: 600000,
        },
        employee: {
          employmentId: 21,
          status: 'CALCULATED',
          failureReason: null,
          grossCents: 800000,
          taxableCents: 800000,
          deductionsCents: 200000,
          netCents: 600000,
          employerCostCents: 1000000,
          employeeSiCents: 64000,
          employeeHfCents: 96000,
          taxCents: 40000,
        },
      },
      global: { plugins: [ElementPlus], stubs: { ElDrawer: OverlayStub, teleport: true } },
    })

    expect(wrapper.get('[data-testid="payslip-line-gross"]').text()).toContain('¥8,000.00')
    expect(wrapper.get('[data-testid="payslip-line-net"]').text()).toContain('¥6,000.00')
    expect(wrapper.get('[data-testid="payslip-line-tax"]').text()).toContain('-¥400.00')
    expect(wrapper.text()).toContain('2026-11 · 员工 #21')
  })

  it('shows the field-permission explanation instead of zeros when amounts are absent', async () => {
    const wrapper = mount(PayslipDrawer, {
      props: {
        modelValue: true,
        run: {
          id: 7,
          period: '2026-11',
          runType: 'REGULAR',
          status: 'CALCULATED',
          correctionOfRunId: null,
          payDate: '2026-12-05',
          employeeCount: 1,
          createdAt: '2026-11-20T02:00:00Z',
        },
        employee: { employmentId: 21, status: 'PENDING', failureReason: null },
      },
      global: { plugins: [ElementPlus], stubs: { ElDrawer: OverlayStub, teleport: true } },
    })

    expect(wrapper.get('[data-testid="payslip-masked"]').text()).toContain('金额已按字段策略隐藏')
    expect(wrapper.text()).not.toContain('¥0.00')
  })
})
