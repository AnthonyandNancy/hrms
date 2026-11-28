import { flushPromises, mount } from '@vue/test-utils'
import ElementPlus from 'element-plus'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useSessionStore } from '@/stores/session'
import LeaveWorkspacePage from '@/modules/time/leave/pages/LeaveWorkspacePage.vue'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  patch: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resource.get(...args),
  resourcePost: (...args: unknown[]) => resource.post(...args),
  resourcePatch: (...args: unknown[]) => resource.patch(...args),
}))

const balances = [
  {
    leaveTypeCode: 'ANNUAL',
    leaveTypeName: '年假',
    unit: 'DAY',
    granted: 10,
    used: 2,
    pending: 1,
    available: 7,
    asOf: '2026-11-25',
    leaveTypeId: 11,
    standardDayMinutes: 480,
    expiresOn: null,
  },
]

const types = {
  items: [
    {
      id: 11,
      code: 'ANNUAL',
      name: '年假',
      unit: 'DAY',
      is_statutory: false,
      is_annual: true,
      requires_attachment: false,
      allow_negative: false,
      status: 'ACTIVE',
      policy_version: 2,
      entitlement_minutes: 4800,
      version: 1,
    },
  ],
  page: 1,
  page_size: 20,
  total: 1,
}

const requests = {
  items: [
    {
      id: 33,
      caseId: 33,
      caseNo: 'CASE-000033',
      applicationStatus: 'APPROVED',
      effectStatus: 'APPLIED',
      employment_id: 1,
      actor_user_id: 10,
      leaveTypeId: 11,
      fromDate: '2026-12-01',
      toDate: '2026-12-02',
      minutes: 960,
      state: 'APPROVED',
    },
  ],
  total: 1,
}

const policies = {
  items: [
    {
      id: 5,
      leave_type_id: 11,
      leave_type_code: 'ANNUAL',
      leave_type_name: '年假',
      unit: 'DAY',
      version: 2,
      status: 'PUBLISHED',
      effective_from: '2027-01-01',
      effective_to: '2027-12-31',
      applies_to: { scope: 'COMPANY' },
      accrual: { mode: 'ANNUAL_FIXED', minutes_per_year: 4800 },
      carryover: null,
      min_notice_days: 0,
      max_consecutive_days: null,
      requires_attachment_over_days: null,
      allow_half_day: true,
      legal_basis: 'Synthetic policy.',
      published_at: '2026-11-25T00:00:00.000000Z',
      published_by: 10,
      retired_at: null,
      retire_reason: null,
    },
  ],
  page: 1,
  page_size: 20,
  total: 1,
}

function defaultGet(path: string): Promise<unknown> {
  if (path === '/v1/time/leave/balances') return Promise.resolve(balances)
  if (path === '/v1/time/leave/types') return Promise.resolve(types)
  if (path === '/v1/time/leave/requests') return Promise.resolve(requests)
  if (path === '/v1/time/leave/policies') return Promise.resolve(policies)
  return Promise.reject(new Error(`Unexpected GET ${path}`))
}

async function mountPage(capabilities: string[], initialPath = '/leave'): Promise<ReturnType<typeof mount>> {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({
    status: 'authenticated',
    capabilities,
    principal: { employmentId: 1 } as never,
  })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/leave', name: 'leave', component: { template: '<div />' } },
      { path: '/login', name: 'login', component: { template: '<div />' } },
      { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
    ],
  })
  await router.push(initialPath)
  await router.isReady()

  return mount(LeaveWorkspacePage, {
    global: {
      plugins: [pinia, router, ElementPlus],
    },
  })
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
  resource.get.mockImplementation(defaultGet)
})

describe('Leave workspace seven-tab closure', () => {
  it('renders the permitted tabs and falls back to mine for an invalid tab', async () => {
    const wrapper = await mountPage(
      [
        'time.leave.read',
        'time.leave.request',
        'time.leave.request.cancel',
        'time.leave.ledger.read',
        'time.leave.type.manage',
        'time.leave.policy.manage',
        'time.leave.admin',
        'time.leave.request.on-behalf',
        'time.leave.report.read',
      ],
      '/leave?tab=unknown',
    )
    await flushPromises()

    expect(wrapper.get('[data-testid="leave-tab-mine"]').attributes('aria-selected')).toBe('true')
    for (const tab of ['mine', 'requests', 'types', 'policies', 'balances', 'calendar', 'reports']) {
      expect(wrapper.find(`[data-testid="leave-tab-${tab}"]`).exists()).toBe(true)
    }
  })

  it('hides management tabs without their capabilities', async () => {
    const wrapper = await mountPage(['time.leave.read'])
    await flushPromises()

    expect(wrapper.find('[data-testid="leave-tab-types"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="leave-tab-policies"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="leave-tab-requests"]').exists()).toBe(false)
  })

  it('renders real leave types and policy versions without raw JSON or technical codes', async () => {
    const wrapper = await mountPage(
      ['time.leave.read', 'time.leave.type.manage', 'time.leave.policy.manage', 'time.leave.policy.read'],
      '/leave?tab=policies',
    )
    await flushPromises()

    const policyPanel = wrapper.get('[data-testid="leave-policies-table"]')
    expect(policyPanel.text()).toContain('年假')
    expect(policyPanel.text()).toContain('按年固定额度')
    expect(policyPanel.text()).toContain('已发布')
    expect(policyPanel.text()).not.toContain('ANNUAL_FIXED')
    expect(policyPanel.text()).not.toContain('{')
    expect(policyPanel.text()).not.toContain('expiry_rule')
  })

  it('shows unconfigured state with a direct type-config path instead of four empty headers', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/time/leave/types') return Promise.resolve({ items: [], total: 0 })
      if (path === '/v1/time/leave/balances') return Promise.resolve([])
      if (path === '/v1/time/leave/requests') return Promise.resolve({ items: [], total: 0 })
      if (path === '/v1/time/leave/policies') return Promise.resolve({ items: [], total: 0 })
      return Promise.reject(new Error(`Unexpected GET ${path}`))
    })

    const wrapper = await mountPage(['time.leave.read', 'time.leave.type.manage'])
    await flushPromises()

    expect(wrapper.get('[data-testid="leave-unconfigured"]').text()).toContain('尚未配置假期类型')
  })

  it('requires an explicit subject before opening the on-behalf drawer', async () => {
    const wrapper = await mountPage(
      ['time.leave.read', 'time.leave.request.on-behalf', 'time.leave.admin'],
      '/leave?tab=requests',
    )
    await flushPromises()

    const openButton = wrapper.get('[data-testid="leave-on-behalf-open"]')
    expect(openButton.attributes('disabled')).toBeDefined()
    expect(wrapper.find('[role="dialog"][aria-label="申请请假"]').exists()).toBe(false)

    await wrapper.get('[data-testid="leave-on-behalf-subject"]').setValue('2')
    await flushPromises()
    expect(wrapper.get('[data-testid="leave-on-behalf-open"]').attributes('disabled')).toBeUndefined()
  })

  it('routes HTML never renders the seven folded leave URLs as menu points', async () => {
    const wrapper = await mountPage(['time.leave.read'], '/leave?tab=reports')
    await flushPromises()

    const text = wrapper.text()
    for (const oldPath of [
      '/leave/requests',
      '/leave/types',
      '/leave/policies',
      '/leave/balances',
      '/leave/calendar',
      '/leave/cancellations',
      '/leave/reports',
    ]) {
      expect(text).not.toContain(oldPath)
    }
  })
})
