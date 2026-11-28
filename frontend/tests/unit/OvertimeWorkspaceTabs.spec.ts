import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ElementPlus from 'element-plus'
import OvertimeWorkspacePage from '@/modules/time/overtime/pages/OvertimeWorkspacePage.vue'
import OvertimePolicyPanel from '@/modules/time/overtime/components/OvertimePolicyPanel.vue'
import OvertimeConfirmPanel from '@/modules/time/overtime/components/OvertimeConfirmPanel.vue'
import OvertimeSettlementPanel from '@/modules/time/overtime/components/OvertimeSettlementPanel.vue'
import { useSessionStore } from '@/stores/session'
import { ApiError } from '@/api/errors'
import type { OvertimePolicy } from '@/modules/time/overtime/api'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resource.get(...args),
  resourcePost: (...args: unknown[]) => resource.post(...args),
}))

const POLICY: OvertimePolicy = {
  id: 1,
  version: 1,
  status: 'PUBLISHED',
  effectiveFrom: '2026-01-01',
  effectiveTo: '2026-12-31',
  timeUnitMinutes: 30,
  multiplier: '1.500000',
  allowPay: true,
  allowTimeoff: true,
  statutoryHolidayMultiplier: '3.000000',
  allocation: 'EMPLOYEE_CHOICE',
  rounding: 'HALF_UP',
  legalBasis: 'Synthetic policy',
  source: 'COMPANY_VERSION',
  note: '',
  publishedAt: '2026-01-01T00:00:00Z',
  retiredAt: null,
  retireReason: null,
}

function request(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 5,
    employment_id: 21,
    employee_no: 'E0021',
    employee_name: '合成员工',
    business_date: '2026-11-20',
    declared_minutes: 150,
    confirmed_minutes: null,
    status: 'APPROVED',
    reason: 'Synthetic release support',
    case_id: null,
    overtime_record_id: null,
    policy_version_id: null,
    policy_snapshot: null,
    settlement_method: null,
    settlement_id: null,
    attendance_status: 'PRESENT',
    attendance_worked_minutes: 600,
    attendance_overtime_minutes: 90,
    payroll_period_frozen: false,
    recorded_at: '2026-11-20T10:00:00Z',
    ...overrides,
  }
}

function normalizedRequest(overrides: Record<string, unknown> = {}) {
  const raw = request(overrides)
  return {
    id: raw.id,
    employmentId: raw.employment_id,
    businessDate: raw.business_date,
    declaredMinutes: raw.declared_minutes,
    confirmedMinutes: raw.confirmed_minutes,
    status: raw.status,
    reason: raw.reason,
    caseId: raw.case_id,
    overtimeRecordId: raw.overtime_record_id,
    policyVersionId: raw.policy_version_id,
    policySnapshot: raw.policy_snapshot,
    employeeNo: raw.employee_no,
    employeeName: raw.employee_name,
    settlementMethod: raw.settlement_method,
    settlementId: raw.settlement_id,
    attendanceStatus: raw.attendance_status,
    attendanceWorkedMinutes: raw.attendance_worked_minutes,
    attendanceOvertimeMinutes: raw.attendance_overtime_minutes,
    payrollPeriodFrozen: raw.payroll_period_frozen,
    recordedAt: raw.recorded_at,
  }
}

function projection(items: Array<Record<string, unknown>>, overrides: Record<string, unknown> = {}) {
  return {
    items,
    page: 1,
    page_size: 20,
    total: items.length,
    as_of: '2026-11-27',
    freshness: 'LIVE',
    source_hash: 'hash',
    partial: false,
    partial_reason: null,
    drilldown: null,
    view: 'self',
    ...overrides,
  }
}

function installReads(overrides: Record<string, unknown> = {}): void {
  resource.get.mockImplementation((path: string) => {
    if (path in overrides) return Promise.resolve(overrides[path])
    if (path === '/v1/time/overtime/policies') {
      return Promise.resolve({ items: [POLICY], total: 1, as_of: '2026-11-27', source: 'COMPANY_VERSION', current: POLICY })
    }
    if (path === '/v1/time/overtime/requests') {
      return Promise.resolve(projection([request()]))
    }
    if (path === '/v1/time/overtime/timeoff-balances') {
      return Promise.resolve({
        items: [{
          settlement_id: 1,
          overtime_record_id: 7,
          employment_id: 21,
          business_date: '2026-11-20',
          method: 'TIMEOFF',
          minutes: 120,
          reason: 'Synthetic timeoff',
          recorded_at: '2026-11-20T10:00:00Z',
        }],
        available_minutes: 120,
        settlement_count: 1,
        total: 1,
        as_of: '2026-11-27',
        partial: false,
        partial_reason: null,
      })
    }
    if (path === '/v1/time/overtime/settlements') {
      return Promise.resolve({
        items: [{
          overtime_record_id: 7,
          employment_id: 21,
          business_date: '2026-11-20',
          minutes: 120,
          status: 'APPROVED',
          settlement_id: 1,
          method: 'TIMEOFF',
          reason: 'Synthetic timeoff',
          recorded_at: '2026-11-20T10:00:00Z',
          payroll_period_frozen: false,
        }],
        total: 1,
        as_of: '2026-11-27',
      })
    }
    return Promise.resolve({ items: [] })
  })
}

async function mountWorkspace(
  capabilities: string[],
  initialPath = '/overtime',
  employmentId: number | null = 1,
): Promise<{ wrapper: ReturnType<typeof mount>; router: Router }> {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({
    status: 'authenticated',
    capabilities,
    principal: employmentId === null ? null : { employmentId } as never,
  })

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/overtime', name: 'overtime', component: { template: '<div />' } },
      { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
    ],
  })
  await router.push(initialPath)
  await router.isReady()

  const wrapper = mount(OvertimeWorkspacePage, {
    global: {
      plugins: [pinia, router, ElementPlus],
      stubs: {
        'el-button': { template: '<button type="button"><slot /></button>' },
      },
    },
  })
  return { wrapper, router }
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
})

const ALL_CAPABILITIES = [
  'time.overtime.read',
  'time.overtime.request',
  'time.overtime.confirm',
  'time.overtime.settle',
  'time.overtime.manage',
  'time.timeoff.read',
]

describe('Overtime workspace tabs and states', () => {
  it('renders six capability-visible tabs and falls back from an invalid tab', async () => {
    installReads()
    const { wrapper, router } = await mountWorkspace(ALL_CAPABILITIES, '/overtime?tab=not-a-tab')
    await flushPromises()

    expect(wrapper.findAll('[data-testid^="overtime-tab-"]')).toHaveLength(6)
    expect(wrapper.find('[data-testid="overtime-tab-mine"]').attributes('aria-current')).toBe('page')
    expect(router.currentRoute.value.query.tab).toBe('mine')
  })

  it('initialises a legal query tab, hides policy write without manage and issues no unauthorized team read', async () => {
    installReads()
    const { wrapper } = await mountWorkspace(
      ['time.overtime.read', 'time.timeoff.read'],
      '/overtime?tab=policies',
    )
    await flushPromises()

    expect(wrapper.find('[data-testid="overtime-tab-policies"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="overtime-policy-create"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="overtime-tab-confirm"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="overtime-tab-settlement"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="overtime-tab-mine"]').attributes('aria-current')).toBe('page')
    expect(resource.get.mock.calls.some((call) => {
      const params = call[1] as Record<string, unknown> | undefined
      return call[0] === '/v1/time/overtime/requests' && params?.view === 'team'
    })).toBe(false)
  })

  it('renders unconfigured, not a fabricated default, when the policy list is empty', async () => {
    installReads({
      '/v1/time/overtime/policies': { items: [], total: 0, as_of: '2026-11-27', source: 'COMPANY_VERSION', current: null },
    })
    const { wrapper } = await mountWorkspace(ALL_CAPABILITIES)
    await flushPromises()

    expect(wrapper.find('[data-testid="overtime-unconfigured"]').text()).toContain('尚未配置公司加班规则')
    expect(wrapper.find('[data-testid="open-overtime-request"]').exists()).toBe(false)
  })

  it('renders loading, error and partial states from the server contract', async () => {
    let resolveRequests: ((value: unknown) => void) | undefined
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/time/overtime/requests') {
        return new Promise((resolve) => { resolveRequests = resolve })
      }
      if (path === '/v1/time/overtime/policies') {
        return Promise.resolve({ items: [POLICY], total: 1, as_of: '2026-11-27', source: 'COMPANY_VERSION', current: POLICY })
      }
      return Promise.resolve({ items: [], total: 0, as_of: '2026-11-27' })
    })
    const loading = await mountWorkspace(['time.overtime.read'])
    expect(loading.wrapper.find('[data-testid="overtime-loading"]')).toBeTruthy()
    resolveRequests?.(projection([], { partial: true, partial_reason: '加班投影仍在校准中' }))
    await flushPromises()
    expect(loading.wrapper.find('[data-testid="overtime-partial"]').text()).toContain('本人加班申请')
    loading.wrapper.unmount()

    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/time/overtime/requests') return Promise.reject(new Error('synthetic overtime failure'))
      if (path === '/v1/time/overtime/policies') return Promise.reject(new Error('synthetic policy failure'))
      return Promise.resolve({ items: [] })
    })
    const failed = await mountWorkspace(['time.overtime.read'])
    await flushPromises()
    expect(failed.wrapper.find('[data-testid="overtime-error"]').exists()).toBe(true)
  })
})

describe('Overtime policy writing', () => {
  it('posts a versionless fixed-point policy and never includes a client version', async () => {
    resource.post.mockResolvedValue(POLICY)
    const wrapper = mount(OvertimePolicyPanel, {
      props: {
        policies: { items: [], total: 0, asOf: '2026-11-27', source: 'COMPANY_VERSION', current: null },
        canManage: true,
      },
    })

    await wrapper.find('[data-testid="overtime-policy-create"]').trigger('click')
    await flushPromises()
    const fields = wrapper.findAll('[data-testid="overtime-policy-editor"] input[type="date"]')
    await fields[0]?.setValue('2027-01-01')
    await fields[1]?.setValue('2027-12-31')
    await wrapper.find('[data-testid="overtime-policy-editor"]').trigger('submit')
    await flushPromises()

    const body = resource.post.mock.calls[0]?.[1] as Record<string, unknown>
    expect(body.effective_from).toBe('2027-01-01')
    expect(body.multiplier).toBe('1.500000')
    expect(body).not.toHaveProperty('version')
  })

  it('retires only on explicit confirmation and uses an idempotency key', async () => {
    resource.post.mockResolvedValue({ ...POLICY, status: 'RETIRED', retiredAt: '2026-11-27T00:00:00Z' })
    const wrapper = mount(OvertimePolicyPanel, {
      props: {
        policies: { items: [POLICY], total: 1, asOf: '2026-11-27', source: 'COMPANY_VERSION', current: POLICY },
        canManage: true,
      },
    })

    await wrapper.find('[data-testid="overtime-policy-retire-1"]').trigger('click')
    expect(resource.post).not.toHaveBeenCalled()
    await wrapper.find('[data-testid="overtime-policy-retire-reason-1"]').setValue('制度换版')
    await wrapper.find('[data-testid="overtime-policy-retire-submit-1"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/time/overtime/policies/1/retire',
      { reason: '制度换版' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })
})

describe('Overtime confirmation and settlement', () => {
  it('confirms actual minutes with a stable retry key', async () => {
    resource.post
      .mockRejectedValueOnce(new Error('synthetic transient failure'))
      .mockResolvedValueOnce(request({
        status: 'CONFIRMED',
        confirmed_minutes: 90,
        overtime_record_id: 44,
        policy_version_id: 1,
        policy_snapshot: { version: 1, multiplier: '1.500000' },
      }))

    const wrapper = mount(OvertimeConfirmPanel, {
      props: { requests: [normalizedRequest({ status: 'APPROVED' })] as never, canConfirm: true, policyConfigured: true },
    })
    await wrapper.find('[data-testid="overtime-confirm-5"]').trigger('click')
    await wrapper.find('[data-testid="overtime-confirm-minutes-5"]').setValue('90')
    await wrapper.find('[data-testid="overtime-confirm-submit-5"]').trigger('click')
    await flushPromises()
    expect(wrapper.emitted('error')).toHaveLength(1)

    await wrapper.find('[data-testid="overtime-confirm-submit-5"]').trigger('click')
    await flushPromises()

    const first = resource.post.mock.calls[0]
    const second = resource.post.mock.calls[1]
    expect(first?.[0]).toBe('/v1/time/overtime/requests/5/confirm')
    expect(first?.[1]).toEqual({ confirmed_minutes: 90 })
    expect((first?.[2] as { idempotencyKey: string }).idempotencyKey)
      .toBe((second?.[2] as { idempotencyKey: string }).idempotencyKey)
    expect(wrapper.emitted('confirmed')).toHaveLength(1)
  })

  it('shows the real freeze reason and keeps PAY disabled when the row is frozen', async () => {
    resource.post.mockRejectedValue(new ApiError(
      'CONFLICT',
      'The payroll period is frozen.',
      { status: 409, details: { reason: 'PAYROLL_PERIOD_FROZEN' } },
    ))

    const wrapper = mount(OvertimeSettlementPanel, {
      props: {
        requests: [normalizedRequest({
          status: 'CONFIRMED',
          confirmed_minutes: 90,
          overtime_record_id: 44,
          settlement_method: null,
          payroll_period_frozen: true,
        })] as never,
        canSettle: true,
      },
    })

    await wrapper.find('[data-testid="overtime-settle-44"]').trigger('click')
    await flushPromises()
    const options = wrapper.findAll('[data-testid="overtime-settlement-method-44"] option')
    expect(options[1]?.attributes('disabled')).toBeDefined()
    await wrapper.find('[data-testid="overtime-settlement-reason-44"]').setValue('Synthetic frozen settlement')
    await wrapper.find('[data-testid="overtime-settlement-confirm-44"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('仅可选择调休')
    expect(wrapper.text()).toContain('仅调休')
  })
})

describe('OvertimeWorkspace no-employment gating', () => {
  it('does not request self overtime or timeoff when the principal has no employment', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/time/overtime/policies') return Promise.resolve({ items: [], current: null })
      return Promise.resolve({ items: [], total: 0 })
    })
    const { wrapper } = await mountWorkspace(['time.overtime.read'], '/overtime', null)
    await flushPromises()

    expect(wrapper.find('[data-testid="no-active-employment"]').exists()).toBe(true)
    expect(resource.get.mock.calls.some(([path]) => path === '/v1/time/overtime/requests')).toBe(false)
    expect(resource.get.mock.calls.some(([path]) => path === '/v1/time/overtime/timeoff-balances')).toBe(false)
  })
})
