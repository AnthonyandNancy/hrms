import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import ElementPlus from 'element-plus'
import OvertimeTravelWorkspacePage from '@/modules/time/overtime/pages/OvertimeTravelWorkspacePage.vue'
import OvertimeRequestDrawer from '@/modules/time/overtime/components/OvertimeRequestDrawer.vue'
import OvertimeRecordTable from '@/modules/time/overtime/components/OvertimeRecordTable.vue'
import TravelRequestDrawer from '@/modules/time/overtime/components/TravelRequestDrawer.vue'
import TravelExpensePanel from '@/modules/time/overtime/components/TravelExpensePanel.vue'
import {
  formatMinorUnits,
  type OvertimePolicy,
  type TravelExpense,
  type TravelRequest,
} from '@/modules/time/overtime/api'

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

function overtimeRecordRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 7,
    employment_id: 21,
    business_date: '2026-11-20',
    minutes: 120,
    status: 'APPROVED',
    case_id: 31,
    settlement_method: null,
    settlement_id: null,
    ...overrides,
  }
}

function overtimeRequestRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 5,
    employment_id: 21,
    business_date: '2026-11-20',
    declared_minutes: 150,
    confirmed_minutes: null,
    status: 'APPROVED',
    reason: 'Synthetic release support',
    case_id: 31,
    overtime_record_id: null,
    recorded_at: '2026-11-20T10:00:00Z',
    ...overrides,
  }
}

function overtimeProjection(items: Record<string, unknown>[], overrides: Record<string, unknown> = {}) {
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
    ...overrides,
  }
}

function travelRequestRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    travel_id: 12,
    employment_id: 21,
    from_date: '2026-12-01',
    to_date: '2026-12-03',
    destination: '苏州',
    purpose: '合成客户现场支持',
    status: 'APPROVED',
    case_id: 61,
    case_no: 'CASE-2026-0061',
    application_status: 'APPROVED',
    ...overrides,
  }
}

function travelExpenseRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 22,
    travel_id: 12,
    employment_id: 21,
    category: 'TRANSPORT',
    amount_cents: 123456,
    currency: 'EUR',
    receipt_file_id: null,
    status: 'CLAIMED',
    payment_status: 'CLAIM_ONLY',
    note: 'Synthetic rail ticket',
    claimed_at: '2026-11-27T02:00:00Z',
    ...overrides,
  }
}

function travelExpense(id: number, amountCents: number, currency: string): TravelExpense {
  return {
    id,
    travelId: 12,
    employmentId: 21,
    category: 'TRANSPORT',
    amountCents,
    currency,
    receiptFileId: null,
    status: 'CLAIMED',
    paymentStatus: 'CLAIM_ONLY',
    note: 'Synthetic expense',
    claimedAt: '2026-11-27T02:00:00Z',
  }
}

function approvedTravelRequest(): TravelRequest {
  return {
    travelId: 12,
    employmentId: 21,
    fromDate: '2026-12-01',
    toDate: '2026-12-03',
    destination: '苏州',
    purpose: '合成客户现场支持',
    status: 'APPROVED',
    caseId: 61,
    caseNo: 'CASE-2026-0061',
    applicationStatus: 'APPROVED',
  }
}

const ALL_CAPABILITIES = [
  'time.overtime.read',
  'time.overtime.request',
  'time.overtime.confirm',
  'time.overtime.settle',
  'time.timeoff.read',
  'time.travel.read',
  'time.travel.request',
  'time.travel.expense',
  'time.travel.expense.manage',
  'time.travel.policy.read',
]

function installSuccessfulReads(overrides: Record<string, unknown> = {}): void {
  resource.get.mockImplementation((path: string) => {
    if (path in overrides) return Promise.resolve(overrides[path])
    if (path === '/v1/time/overtime/policies') return Promise.resolve({ items: [POLICY], total: 1, as_of: '2026-11-27', source: 'COMPANY_VERSION', current: POLICY })
    if (path === '/v1/time/overtime/records') {
      return Promise.resolve({ items: [overtimeRecordRaw()], page: 1, page_size: 20, total: 1 })
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
          reason: 'Synthetic',
          recorded_at: '2026-11-20T10:00:00Z',
          payroll_period_frozen: false,
        }],
        total: 1,
        as_of: '2026-11-27',
      })
    }
    if (path === '/v1/time/overtime/requests') {
      return Promise.resolve(overtimeProjection([overtimeRequestRaw()]))
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
          reason: 'Synthetic',
          recorded_at: '2026-11-20T10:00:00Z',
        }],
        available_minutes: 120,
        settlement_count: 1,
        page: 1,
        page_size: 20,
        total: 1,
        as_of: '2026-11-27',
        freshness: 'LIVE',
        source_hash: 'hash',
        partial: false,
        partial_reason: null,
        drilldown: null,
      })
    }
    if (path === '/v1/travel/requests') {
      return Promise.resolve({ items: [travelRequestRaw()], page: 1, page_size: 20, total: 1 })
    }
    if (path === '/v1/travel/expenses') {
      return Promise.resolve({ items: [travelExpenseRaw()], page: 1, page_size: 20, total: 1 })
    }
    if (path === '/v1/travel/policies') {
      return Promise.resolve({
        items: [{
          id: 3,
          city_tier: 'TIER_2',
          hotel_cap_cents: 55000,
          transport_cap_cents: 30000,
          effective_from: '2026-01-01',
          version: 2,
          status: 'PUBLISHED',
        }],
        page: 1,
        page_size: 20,
        total: 1,
      })
    }
    return Promise.resolve({ items: [] })
  })
}

async function mountPage(
  capabilities: string[] = ALL_CAPABILITIES,
): Promise<{ wrapper: ReturnType<typeof mount>; router: Router }> {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/overtime', name: 'overtime', component: { template: '<div />' } },
      { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
      { path: '/login', name: 'login', component: { template: '<div />' } },
    ],
  })
  await router.push('/overtime')
  await router.isReady()

  const wrapper = mount(OvertimeTravelWorkspacePage, {
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

describe('OvertimeTravelWorkspace sections and filters', () => {
  it('renders separate overtime and travel sections with filters, policies and Chinese statuses', async () => {
    installSuccessfulReads()
    const { wrapper } = await mountPage()
    await flushPromises()

    const overtime = wrapper.get('[data-testid="overtime-section"]')
    const travel = wrapper.get('[data-testid="travel-section"]')

    expect(overtime.text()).toContain('加班')
    expect(overtime.get('[data-testid="overtime-filters"]')).toBeTruthy()
    expect(overtime.text()).toContain('法定节假日')
    expect(overtime.text()).toContain('1.500000')
    expect(overtime.text()).toContain('待确认')
    expect(overtime.text()).toContain('待结算')

    expect(travel.text()).toContain('出差')
    expect(travel.get('[data-testid="travel-filters"]')).toBeTruthy()
    expect(travel.text()).toContain('已批准')
    expect(travel.text()).toContain('已通过')
    expect(travel.text()).toContain('二线城市')
    expect(travel.text()).toContain('TIER_2')
    expect(travel.text()).toContain('€1,234.56')

    expect(resource.get).toHaveBeenCalledWith('/v1/time/overtime/records')
    expect(resource.get).toHaveBeenCalledWith('/v1/time/overtime/policies', {})
    expect(resource.get).toHaveBeenCalledWith('/v1/travel/requests', {})
    expect(resource.get).toHaveBeenCalledWith('/v1/travel/expenses', {})
  })
})

describe('Overtime request drawer', () => {
  it('shows the applicable policy and submits planned time as integer declared minutes', async () => {
    resource.post.mockResolvedValue(overtimeRequestRaw())
    const wrapper = mount(OvertimeRequestDrawer, {
      props: { modelValue: true, policy: POLICY },
    })

    expect(wrapper.get('[data-testid="overtime-policy"]').text()).toContain('1.500000')
    expect(wrapper.get('[data-testid="overtime-policy"]').text()).toContain('法定节假日')

    await wrapper.get('[data-testid="overtime-date"]').setValue('2026-11-20')
    await wrapper.get('[data-testid="overtime-start"]').setValue('18:00')
    await wrapper.get('[data-testid="overtime-end"]').setValue('20:30')
    await wrapper.get('[data-testid="overtime-reason"]').setValue('Synthetic release support')
    await wrapper.get('[data-testid="overtime-compensation"]').setValue('TIMEOFF')
    await wrapper.get('[data-testid="overtime-request-form"]').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/time/overtime/requests',
      {
        business_date: '2026-11-20',
        declared_minutes: 150,
        reason: 'Synthetic release support',
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.emitted('submitted')?.[0]?.[0]).toMatchObject({ status: 'APPROVED' })
  })

  it('renders a server 422 inline and reuses the same idempotency key when retrying the same form', async () => {
    resource.post
      .mockRejectedValueOnce(
        new ApiError('VALIDATION_FAILED', '申报分钟数不合法。', {
          status: 422,
          requestId: 'req-overtime-422',
          fieldErrors: { declared_minutes: ['申报分钟数不合法。'] },
        }),
      )
      .mockResolvedValueOnce(overtimeRequestRaw())

    const wrapper = mount(OvertimeRequestDrawer, {
      props: { modelValue: true, policy: POLICY },
    })
    await wrapper.get('[data-testid="overtime-date"]').setValue('2026-11-20')
    await wrapper.get('[data-testid="overtime-start"]').setValue('18:00')
    await wrapper.get('[data-testid="overtime-end"]').setValue('20:30')
    await wrapper.get('[data-testid="overtime-reason"]').setValue('Synthetic release support')
    await wrapper.get('[data-testid="overtime-request-form"]').trigger('submit')
    await flushPromises()

    const inline = wrapper.get('[data-testid="overtime-request-error"]')
    expect(inline.text()).toContain('申报分钟数不合法')
    expect(inline.text()).toContain('req-overtime-422')
    expect(wrapper.emitted('submitted')).toBeUndefined()

    await wrapper.get('[data-testid="overtime-request-form"]').trigger('submit')
    await flushPromises()

    const firstKey = resource.post.mock.calls[0]?.[2] as { idempotencyKey?: string }
    const secondKey = resource.post.mock.calls[1]?.[2] as { idempotencyKey?: string }
    expect(secondKey.idempotencyKey).toBe(firstKey.idempotencyKey)
    expect(wrapper.emitted('submitted')).toHaveLength(1)
  })
})

describe('Overtime confirmation and settlement', () => {
  it('confirms an approved request with explicit minutes and reuses the same key after a 422 retry', async () => {
    installSuccessfulReads()
    resource.post
      .mockRejectedValueOnce(
        new ApiError('VALIDATION_FAILED', '确认分钟数无效。', {
          status: 422,
          requestId: 'req-confirm-422',
          fieldErrors: { confirmed_minutes: ['确认分钟数无效。'] },
        }),
      )
      .mockResolvedValueOnce(overtimeRequestRaw({ status: 'CONFIRMED', confirmed_minutes: 130 }))
    const { wrapper } = await mountPage()
    await flushPromises()

    await wrapper.get('[data-testid="overtime-confirm-5"]').trigger('click')
    await wrapper.get('[data-testid="overtime-confirm-minutes-5"]').setValue('130')
    expect(resource.post).not.toHaveBeenCalled()
    await wrapper.get('[data-testid="overtime-confirm-submit-5"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('req-confirm-422')

    await wrapper.get('[data-testid="overtime-confirm-submit-5"]').trigger('click')
    await flushPromises()

    const firstCall = resource.post.mock.calls[0]
    expect(firstCall).toEqual([
      '/v1/time/overtime/requests/5/confirm',
      { confirmed_minutes: 130 },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ])
    const firstKey = firstCall?.[2] as { idempotencyKey?: string }
    const secondKey = resource.post.mock.calls[1]?.[2] as { idempotencyKey?: string }
    expect(secondKey.idempotencyKey).toBe(firstKey.idempotencyKey)
  })

  it('settles a record only after explicit confirmation and retries with the same idempotency key', async () => {
    resource.post
      .mockRejectedValueOnce(
        new ApiError('VALIDATION_FAILED', '结算原因未通过校验。', {
          status: 422,
          requestId: 'req-settlement-422',
          fieldErrors: { reason: ['结算原因未通过校验。'] },
        }),
      )
      .mockResolvedValueOnce({
        settlement_id: 9,
        overtime_record_id: 7,
        employment_id: 21,
        method: 'TIMEOFF',
        minutes: 120,
        replayed: false,
      })
    const wrapper = mount(OvertimeRecordTable, {
      props: {
        records: [{
          id: 7,
          employmentId: 21,
          businessDate: '2026-11-20',
          minutes: 120,
          status: 'APPROVED',
          caseId: 31,
          settlementMethod: null,
          settlementId: null,
        }],
        canSettle: true,
      },
    })

    await wrapper.get('[data-testid="overtime-settle-7"]').trigger('click')
    await wrapper.get('[data-testid="overtime-settlement-method-7"]').setValue('TIMEOFF')
    await wrapper.get('[data-testid="overtime-settlement-reason-7"]').setValue('Synthetic time-off choice')
    expect(resource.post).not.toHaveBeenCalled()
    await wrapper.get('[data-testid="overtime-settlement-confirm-7"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('req-settlement-422')

    await wrapper.get('[data-testid="overtime-settlement-confirm-7"]').trigger('click')
    await flushPromises()

    expect(resource.post.mock.calls[0]?.[1]).toEqual({
      method: 'TIMEOFF',
      reason: 'Synthetic time-off choice',
    })
    const firstKey = resource.post.mock.calls[0]?.[2] as { idempotencyKey?: string }
    const secondKey = resource.post.mock.calls[1]?.[2] as { idempotencyKey?: string }
    expect(secondKey.idempotencyKey).toBe(firstKey.idempotencyKey)
    expect(wrapper.emitted('settled')).toHaveLength(1)
  })
})

describe('Travel requests and expenses', () => {
  it('formats API currency, rejects decimal minor units and keeps a stable key across a 422 retry', async () => {
    expect(formatMinorUnits(123456, 'EUR')).toBe('€1,234.56')
    expect(formatMinorUnits(4899, 'USD')).toBe('$48.99')
    expect(formatMinorUnits(4899, 'CNY')).toBe('¥48.99')

    resource.post
      .mockRejectedValueOnce(
        new ApiError('VALIDATION_FAILED', '金额必须是最小货币单位的整数。', {
          status: 422,
          requestId: 'req-expense-422',
          fieldErrors: { amount_cents: ['金额必须是最小货币单位的整数。'] },
        }),
      )
      .mockResolvedValueOnce(travelExpenseRaw({ id: 23, amount_cents: 5000, category: 'MEALS' }))

    const wrapper = mount(TravelExpensePanel, {
      props: {
        requests: [approvedTravelRequest()],
        expenses: [travelExpense(22, 123456, 'EUR')],
        currency: 'EUR',
        canSubmit: true,
        loading: false,
        error: '',
      },
    })

    expect(wrapper.text()).toContain('€1,234.56')
    await wrapper.get('[data-testid="travel-expense-request"]').setValue('12')
    await wrapper.get('[data-testid="travel-expense-category"]').setValue('MEALS')
    await wrapper.get('[data-testid="travel-expense-amount-cents"]').setValue('12.34')
    await wrapper.get('[data-testid="travel-expense-note"]').setValue('Synthetic meal')
    await wrapper.get('[data-testid="travel-expense-form"]').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-testid="travel-expense-validation"]').text()).toContain('整数')
    expect(resource.post).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="travel-expense-amount-cents"]').setValue('5000')
    await wrapper.get('[data-testid="travel-expense-form"]').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-testid="travel-expense-error"]').text()).toContain('req-expense-422')
    expect(wrapper.get('[data-testid="travel-expense-error"]').text()).toContain('金额必须是')
    expect(wrapper.emitted('submitted')).toBeUndefined()

    await wrapper.get('[data-testid="travel-expense-form"]').trigger('submit')
    await flushPromises()

    expect(resource.post.mock.calls[0]?.[1]).toEqual({
      travel_id: 12,
      amount_cents: 5000,
      category: 'MEALS',
      note: 'Synthetic meal',
    })
    const firstKey = resource.post.mock.calls[0]?.[2] as { idempotencyKey?: string }
    const secondKey = resource.post.mock.calls[1]?.[2] as { idempotencyKey?: string }
    expect(secondKey.idempotencyKey).toBe(firstKey.idempotencyKey)
    expect(wrapper.emitted('submitted')).toHaveLength(1)
  })

  it('shows a travel request 422 inline and reuses the same idempotency key on retry', async () => {
    resource.post
      .mockRejectedValueOnce(
        new ApiError('VALIDATION_FAILED', '出差日期不合法。', {
          status: 422,
          requestId: 'req-travel-422',
          fieldErrors: { to_date: ['出差日期不合法。'] },
        }),
      )
      .mockResolvedValueOnce(travelRequestRaw({ status: 'PENDING_APPROVAL' }))

    const wrapper = mount(TravelRequestDrawer, {
      props: { modelValue: true, currency: 'CNY' },
    })
    await wrapper.get('[data-testid="travel-from-date"]').setValue('2026-12-10')
    await wrapper.get('[data-testid="travel-to-date"]').setValue('2026-12-12')
    await wrapper.get('[data-testid="travel-destination"]').setValue('南京')
    await wrapper.get('[data-testid="travel-purpose"]').setValue('Synthetic supplier review')
    await wrapper.get('[data-testid="travel-request-form"]').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-testid="travel-request-error"]').text()).toContain('req-travel-422')
    expect(wrapper.emitted('submitted')).toBeUndefined()

    await wrapper.get('[data-testid="travel-request-form"]').trigger('submit')
    await flushPromises()

    const firstKey = resource.post.mock.calls[0]?.[2] as { idempotencyKey?: string }
    const secondKey = resource.post.mock.calls[1]?.[2] as { idempotencyKey?: string }
    expect(secondKey.idempotencyKey).toBe(firstKey.idempotencyKey)
    expect(wrapper.emitted('submitted')).toHaveLength(1)
  })

  it('keeps planned estimated cost in the UI without inventing an unsupported transport field', async () => {
    installSuccessfulReads()
    resource.post.mockResolvedValue(travelRequestRaw({ status: 'PENDING_APPROVAL' }))
    const { wrapper } = await mountPage()
    await flushPromises()

    await wrapper.get('[data-testid="open-travel-request"]').trigger('click')
    expect(wrapper.get('[data-testid="travel-estimated-disclosure"]').text()).toContain('接口')
    await wrapper.get('[data-testid="travel-from-date"]').setValue('2026-12-10')
    await wrapper.get('[data-testid="travel-to-date"]').setValue('2026-12-12')
    await wrapper.get('[data-testid="travel-destination"]').setValue('南京')
    await wrapper.get('[data-testid="travel-purpose"]').setValue('Synthetic supplier review')
    await wrapper.get('[data-testid="travel-estimated-cost-cents"]').setValue('88000')
    await wrapper.get('[data-testid="travel-request-form"]').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/travel/requests',
      {
        from_date: '2026-12-10',
        to_date: '2026-12-12',
        destination: '南京',
        purpose: 'Synthetic supplier review',
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(JSON.stringify(resource.post.mock.calls[0]?.[1])).not.toContain('estimated')
  })
})

describe('OvertimeTravelWorkspace capability guards and five states', () => {
  it('renders a forbidden state for each section without issuing unauthorized reads', async () => {
    const { wrapper } = await mountPage(['time.overtime.request', 'time.travel.request'])
    await flushPromises()

    expect(wrapper.get('[data-testid="overtime-forbidden"]').text()).toContain('无权查看加班')
    expect(wrapper.get('[data-testid="travel-forbidden"]').text()).toContain('无权查看出差')
    expect(wrapper.find('[data-testid="travel-expense-forbidden"]').exists()).toBe(false)
    expect(resource.get).not.toHaveBeenCalled()
  })

  it('hides every write control when only read capabilities are present', async () => {
    installSuccessfulReads()
    const { wrapper } = await mountPage([
      'time.overtime.read',
      'time.travel.read',
      'time.travel.expense',
      'time.travel.policy.read',
    ])
    await flushPromises()

    expect(wrapper.find('[data-testid="open-overtime-request"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="overtime-confirm-5"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="overtime-settle-7"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="open-travel-request"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="travel-expense-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="travel-expense-submit"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="travel-expenses"]').text()).toContain('€1,234.56')
    expect(wrapper.get('[data-testid="travel-expenses"]').text()).toContain('没有费用登记权限')

    // Approval and policy publishing have real endpoints but belong to their
    // governance pages; this workspace must not add hidden shortcuts.
    expect(wrapper.find('[data-testid="travel-approve-12"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="travel-policy-publish"]').exists()).toBe(false)

    const noExpenseCapability = await mountPage([
      'time.overtime.read',
      'time.travel.read',
      'time.travel.policy.read',
    ])
    await flushPromises()
    expect(noExpenseCapability.wrapper.get('[data-testid="travel-expense-forbidden"]').text()).toContain('无权查看出差费用')
    expect(noExpenseCapability.wrapper.find('[data-testid="travel-expense-form"]').exists()).toBe(false)
  })

  it('renders loading, empty, error and partial states independently inside each section', async () => {
    let resolveOvertime: ((value: unknown) => void) | undefined
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/time/overtime/records') {
        return new Promise((resolve) => { resolveOvertime = resolve })
      }
      if (path === '/v1/travel/requests') return Promise.resolve({ items: [], total: 0 })
      if (path === '/v1/time/overtime/policies') return Promise.resolve({ items: [POLICY], total: 1, as_of: '2026-11-27', source: 'COMPANY_VERSION', current: POLICY })
      if (path === '/v1/time/overtime/requests') return Promise.resolve(overtimeProjection([]))
      return Promise.resolve({ items: [], total: 0 })
    })

    const loading = await mountPage()
    expect(loading.wrapper.get('[data-testid="overtime-loading"]')).toBeTruthy()
    resolveOvertime?.({ items: [], total: 0, page: 1, page_size: 20 })
    await flushPromises()
    expect(loading.wrapper.get('[data-testid="overtime-empty"]').text()).toContain('暂无加班')
    expect(loading.wrapper.get('[data-testid="travel-empty"]').text()).toContain('暂无出差')
    loading.wrapper.unmount()

    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/time/overtime/records') {
        return Promise.reject(new ApiError('INTERNAL', 'synthetic overtime failure', {
          status: 500,
          requestId: 'req-overtime-load',
        }))
      }
      if (path === '/v1/travel/requests') return Promise.resolve({ items: [travelRequestRaw()], total: 1 })
      if (path === '/v1/time/overtime/policies') return Promise.resolve({ items: [POLICY], total: 1, as_of: '2026-11-27', source: 'COMPANY_VERSION', current: POLICY })
      if (path === '/v1/time/overtime/requests') return Promise.resolve(overtimeProjection([]))
      if (path === '/v1/travel/expenses') return Promise.resolve({ items: [travelExpenseRaw()], total: 1 })
      if (path === '/v1/travel/policies') return Promise.resolve({ items: [], total: 0 })
      return Promise.resolve({ items: [] })
    })
    const failed = await mountPage()
    await flushPromises()
    expect(failed.wrapper.get('[data-testid="overtime-error"]').text()).toContain('req-overtime-load')
    expect(failed.wrapper.find('[data-testid="travel-section"]').exists()).toBe(true)
    failed.wrapper.unmount()

    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/time/overtime/records') {
        return Promise.resolve({
          items: [overtimeRecordRaw()],
          total: 1,
          partial: true,
          partial_reason: '加班投影仍在校准中',
        })
      }
      if (path === '/v1/time/overtime/requests') return Promise.resolve(overtimeProjection([overtimeRequestRaw()]))
      if (path === '/v1/time/overtime/policies') return Promise.resolve({ items: [POLICY], total: 1, as_of: '2026-11-27', source: 'COMPANY_VERSION', current: POLICY })
      if (path === '/v1/time/overtime/timeoff-balances') return Promise.resolve({ items: [], available_minutes: 0 })
      if (path === '/v1/travel/requests') return Promise.resolve({ items: [travelRequestRaw()], total: 1 })
      if (path === '/v1/travel/expenses') {
        return Promise.reject(new ApiError('INTERNAL', 'synthetic expense projection failure', {
          status: 500,
          requestId: 'req-expense-load',
        }))
      }
      if (path === '/v1/travel/policies') return Promise.resolve({ items: [], total: 0 })
      return Promise.resolve({ items: [] })
    })
    const partial = await mountPage()
    await flushPromises()
    expect(partial.wrapper.get('[data-testid="overtime-partial"]').text()).toContain('加班投影仍在校准中')
    expect(partial.wrapper.get('[data-testid="travel-partial"]').text()).toContain('req-expense-load')
  })
})
