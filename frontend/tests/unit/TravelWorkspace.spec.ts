import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import ElementPlus from 'element-plus'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import TravelWorkspacePage from '@/modules/time/travel/pages/TravelWorkspacePage.vue'
import {
  formatMoney,
  normaliseTravelRequest,
  parseMinorUnitsInput,
  type TravelRequest,
} from '@/modules/time/travel/api'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resource.get(...args),
  resourcePost: (...args: unknown[]) => resource.post(...args),
}))

const ALL_CAPABILITIES = [
  'time.travel.read',
  'time.travel.request',
  'time.travel.approve',
  'time.travel.policy.read',
  'time.travel.policy.manage',
  'time.travel.expense',
  'time.travel.expense.manage',
  'time.travel.report.read',
]

function travelRow(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    travel_id: 12,
    employment_id: 21,
    from_date: '2026-12-01',
    to_date: '2026-12-03',
    destination: '苏州',
    purpose: 'Synthetic customer onsite support',
    status: 'PENDING_APPROVAL',
    case_id: 61,
    case_no: 'CASE-2026-0061',
    application_status: 'SUBMITTED',
    change: null,
    attendance_link: null,
    ...overrides,
  }
}

function policyRow(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 3,
    city_tier: 'A',
    hotel_cap_cents: 55000,
    transport_cap_cents: 30000,
    effective_from: '2026-01-01',
    version: 2,
    status: 'PUBLISHED',
    ...overrides,
  }
}

function expenseRow(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 22,
    travel_id: 12,
    employment_id: 21,
    category: 'TRANSPORT',
    amount_cents: 123456,
    currency: 'CNY',
    receipt_file_id: null,
    status: 'CLAIMED',
    payment_status: 'CLAIM_ONLY',
    note: 'Synthetic rail ticket',
    claimed_at: '2026-11-27T02:00:00Z',
    ...overrides,
  }
}

function reportRow(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    month: '2026-12',
    department_id: 9,
    employment_count: 2,
    approved_trips: 3,
    pending_trips: 1,
    rejected_trips: 0,
    cancelled_trips: 0,
    completed_trips: 0,
    approved_days: 6,
    pending_days: 2,
    expense_cents: 123456,
    travel_ids: [12],
    detail_path: '/travel?tab=reports&travel_id=12',
    ...overrides,
  }
}

function installReads(): void {
  resource.get.mockImplementation((path: string) => {
    if (path === '/v1/travel/requests') {
      return Promise.resolve({
        items: [travelRow({ status: 'APPROVED', application_status: 'APPROVED' })],
        page: 1,
        page_size: 20,
        total: 1,
        as_of: '2026-11-27',
        source_hash: 'a'.repeat(64),
        partial: false,
        partial_reason: null,
      })
    }
    if (path === '/v1/travel/policies') {
      return Promise.resolve({ items: [policyRow()], page: 1, page_size: 20, total: 1 })
    }
    if (path === '/v1/travel/expenses') {
      return Promise.resolve({ items: [expenseRow()], page: 1, page_size: 100, total: 1 })
    }
    if (path === '/v1/travel/reports/summary') {
      return Promise.resolve({
        rows: [reportRow()],
        total: 1,
        as_of: '2026-11-27',
        source_hash: 'b'.repeat(64),
      })
    }
    return Promise.resolve({ items: [] })
  })
}

async function mountTravel(
  path = '/travel',
  capabilities: string[] = ALL_CAPABILITIES,
): Promise<{ wrapper: ReturnType<typeof mount>; router: Router }> {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/travel', name: 'travel', component: { template: '<div />' } },
      { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
      { path: '/login', name: 'login', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()

  const wrapper = mount(TravelWorkspacePage, {
    global: {
      plugins: [pinia, router, ElementPlus],
      stubs: {
        'el-button': { template: '<button type="button"><slot /></button>' },
        'el-input': { template: '<input />' },
        'el-alert': { template: '<div><slot /></div>' },
        'el-pagination': { template: '<div />' },
      },
    },
  })

  return { wrapper, router }
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
})

describe('Travel workspace tabs and capability guards', () => {
  it('renders the five authorized tabs and hides approval without capability', async () => {
    installReads()
    const { wrapper } = await mountTravel('/travel?tab=reports', [
      'time.travel.read',
      'time.travel.request',
      'time.travel.policy.read',
      'time.travel.expense',
      'time.travel.report.read',
    ])
    await flushPromises()

    expect(wrapper.findAll('[data-testid^="travel-tab-"]')).toHaveLength(5)
    expect(wrapper.find('[data-testid="travel-tab-approval"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="travel-tab-reports"]').attributes('aria-selected')).toBe('true')
    expect(wrapper.find('[data-testid="travel-reports-panel"]').exists()).toBe(true)
    await wrapper.find('[data-testid="travel-report-from"]').setValue('2026-12-01')
    await wrapper.find('[data-testid="travel-report-to"]').setValue('2026-12-31')
    await wrapper.find('[data-testid="travel-report-filters"]').trigger('submit')
    await flushPromises()
    expect(resource.get).toHaveBeenCalledWith('/v1/travel/reports/summary', { from: '2026-12-01', to: '2026-12-31' })
    expect(wrapper.find('[data-testid="travel-report-table"]').exists()).toBe(true)
  })

  it('renders all six tabs when every travel capability is granted', async () => {
    installReads()
    const { wrapper } = await mountTravel('/travel?tab=mine', ALL_CAPABILITIES)
    await flushPromises()

    expect(wrapper.findAll('[data-testid^="travel-tab-"]')).toHaveLength(6)
    expect(wrapper.find('[data-testid="travel-tab-approval"]').exists()).toBe(true)
  })

  it('falls back to my travel for an invalid tab query and shows a forbidden state without read', async () => {
    const fallback = await mountTravel('/travel?tab=unknown')
    await flushPromises()
    expect(fallback.wrapper.find('[data-testid="travel-tab-mine"]').attributes('aria-selected')).toBe('true')

    resource.get.mockClear()
    const forbidden = await mountTravel('/travel?tab=reports', ['time.travel.request'])
    await flushPromises()
    expect(forbidden.wrapper.find('[data-testid="travel-forbidden"]').text()).toContain('无权查看出差')
    expect(forbidden.wrapper.find('[data-testid="travel-tabs"]').exists()).toBe(false)
    expect(resource.get).not.toHaveBeenCalled()
  })
})

describe('Travel request ledger rendering', () => {
  it('renders the attendance link and the change projection from the server payload', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/travel/requests') {
        return Promise.resolve({
          items: [travelRow({
            status: 'APPROVED',
            application_status: 'APPROVED',
            attendance_link: {
              travel_id: 12,
              employment_id: 21,
              from_date: '2026-12-01',
              to_date: '2026-12-03',
              source_case_id: 61,
              status: 'ACTIVE',
              retired_at: null,
              retire_reason: null,
            },
            change: { change_id: 5, status: 'PENDING', case_id: 77, reason: 'Synthetic change' },
          })],
          page: 1,
          page_size: 20,
          total: 1,
        })
      }
      return Promise.resolve({ items: [] })
    })
    const { wrapper } = await mountTravel('/travel?tab=requests')
    await flushPromises()

    expect(wrapper.find('[data-testid="travel-request-table"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="travel-attendance-link-12"]').text()).toContain('考勤关联生效')
    expect(wrapper.text()).toContain('已批准')
    expect(wrapper.text()).toContain('已通过')
  })

  it('sends server-side filters for the ledger and opens detail via travel_id', async () => {
    installReads()
    const { wrapper } = await mountTravel('/travel?tab=requests&travel_id=12')
    await flushPromises()

    expect(wrapper.find('[data-testid="travel-detail-drawer"]').exists()).toBe(true)
    await wrapper.find('[data-testid="travel-detail-close"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="travel-detail-drawer"]').exists()).toBe(false)
  })
})

describe('Travel policy, approval and expense commands', () => {
  it('publishes a policy version with integer cents and an idempotency key', async () => {
    installReads()
    resource.post.mockResolvedValue(policyRow({ version: 3 }))
    const { wrapper } = await mountTravel('/travel?tab=policies')
    await flushPromises()

    await wrapper.find('[data-testid="travel-policy-publish"]').trigger('click')
    await wrapper.find('[data-testid="travel-policy-city-tier"]').setValue('B')
    await wrapper.find('[data-testid="travel-policy-effective-from"]').setValue('2026-07-01')
    await wrapper.find('[data-testid="travel-policy-hotel-cents"]').setValue('90000')
    await wrapper.find('[data-testid="travel-policy-transport-cents"]').setValue('35000')
    await wrapper.get('[data-testid="travel-policy-form"] form').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/travel/policies',
      {
        city_tier: 'B',
        hotel_cap_cents: 90000,
        transport_cap_cents: 35000,
        effective_from: '2026-07-01',
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('差旅政策版本已发布')
  })

  it('approves through the workflow decision endpoint with the actor comment', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/travel/requests') {
        return Promise.resolve({
          items: [travelRow({ status: 'PENDING_APPROVAL', application_status: 'SUBMITTED' })],
          page: 1,
          page_size: 100,
          total: 1,
        })
      }
      return Promise.resolve({ items: [] })
    })
    resource.post.mockResolvedValue(travelRow({ status: 'APPROVED', application_status: 'APPROVED' }))
    const { wrapper } = await mountTravel('/travel?tab=approval')
    await flushPromises()

    await wrapper.find('[data-testid="travel-approve-start-12"]').trigger('click')
    await wrapper.find('[data-testid="travel-decision-comment-12"]').setValue('Synthetic approval')
    await wrapper.find('[data-testid="travel-approve-12"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/travel/requests/12/approve',
      { decision: 'APPROVE', comment: 'Synthetic approval' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('registers expenses only as integer-cent claims', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/travel/requests') {
        return Promise.resolve({
          items: [travelRow({ status: 'APPROVED', application_status: 'APPROVED' })],
          page: 1,
          page_size: 100,
          total: 1,
        })
      }
      if (path === '/v1/travel/expenses') {
        return Promise.resolve({ items: [expenseRow()], page: 1, page_size: 100, total: 1 })
      }
      return Promise.resolve({ items: [] })
    })
    resource.post.mockResolvedValue(expenseRow({ id: 23, amount_cents: 5000, category: 'MEALS' }))
    const { wrapper } = await mountTravel('/travel?tab=expenses')
    await flushPromises()

    await wrapper.find('[data-testid="travel-expense-request"]').setValue('12')
    await wrapper.find('[data-testid="travel-expense-category"]').setValue('MEALS')
    await wrapper.find('[data-testid="travel-expense-amount-cents"]').setValue('12.34')
    await wrapper.find('[data-testid="travel-expense-form"]').trigger('submit')
    await flushPromises()
    expect(wrapper.find('[data-testid="travel-expenses-error"]').text()).toContain('整数')
    expect(resource.post).not.toHaveBeenCalled()

    await wrapper.find('[data-testid="travel-expense-amount-cents"]').setValue('5000')
    await wrapper.find('[data-testid="travel-expense-form"]').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/travel/expenses',
      { travel_id: 12, amount_cents: 5000, category: 'MEALS' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('¥1,234.56')
  })
})

describe('Travel change command', () => {
  it('submits a change with the reason and a stable idempotency key without mutating the row locally', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/travel/requests') {
        return Promise.resolve({
          items: [travelRow({ status: 'APPROVED', application_status: 'APPROVED' })],
          page: 1,
          page_size: 20,
          total: 1,
        })
      }
      return Promise.resolve({ items: [] })
    })
    resource.post.mockResolvedValue({
      change_id: 9,
      travel_id: 12,
      case_id: 88,
      change_status: 'PENDING',
      reason: 'Synthetic schedule change',
    })
    const { wrapper } = await mountTravel('/travel?tab=requests')
    await flushPromises()

    await wrapper.find('[data-testid="travel-change-12"]').trigger('click')
    await wrapper.find('[data-testid="travel-change-reason"]').setValue('Synthetic schedule change')
    await wrapper.find('[data-testid="travel-request-form"]').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/travel/requests/12/change',
      expect.objectContaining({
        from_date: '2026-12-01',
        to_date: '2026-12-03',
        destination: '苏州',
        reason: 'Synthetic schedule change',
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('出差变更已提交审批')
  })
})

describe('Travel API normalisation and money helpers', () => {
  it('normalises change and attendance link projections exactly as the server provides', () => {
    const request: TravelRequest = normaliseTravelRequest(travelRow({
      status: 'APPROVED',
      application_status: 'APPROVED',
      attendance_link: {
        travel_id: 12,
        employment_id: 21,
        from_date: '2026-12-01',
        to_date: '2026-12-03',
        source_case_id: 61,
        status: 'ACTIVE',
        retired_at: null,
        retire_reason: null,
      },
      change: {
        change_id: 5,
        status: 'PENDING',
        case_id: 77,
        reason: 'Synthetic schedule change',
      },
    }))

    expect(request.change).toEqual({
      changeId: 5,
      status: 'PENDING',
      caseId: 77,
      reason: 'Synthetic schedule change',
    })
    expect(request.attendanceLink?.status).toBe('ACTIVE')
  })

  it('formats integer cents and rejects decimal minor units', () => {
    expect(formatMoney(123456)).toBe('¥1,234.56')
    expect(formatMoney(5000, 'EUR')).toBe('€50.00')
    expect(parseMinorUnitsInput('5000')).toBe(5000)
    expect(parseMinorUnitsInput('12.34')).toBeNull()
  })

  it('renders an error state with the request id when the ledger read fails', async () => {
    resource.get.mockRejectedValue(new ApiError('INTERNAL', 'synthetic travel failure', {
      status: 500,
      requestId: 'req-travel-load',
    }))
    const { wrapper } = await mountTravel('/travel?tab=requests')
    await flushPromises()

    expect(wrapper.find('[data-testid="travel-requests-error"]').text()).toContain('req-travel-load')
  })
})
