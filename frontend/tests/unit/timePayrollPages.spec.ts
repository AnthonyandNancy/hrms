import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import { readFileSync, readdirSync } from 'node:fs'
import { resolve } from 'node:path'
import { PAGE_REGISTRY } from '@/router/registry'
import type { SessionBootstrap } from '@/api/contracts'
import { useSessionStore } from '@/stores/session'
import { ApiError } from '@/api/errors'
import MoneyText from '@/components/MoneyText.vue'
import {
  TIME_PAYROLL_API_PATHS,
  TIME_PAYROLL_COMPONENT_KEYS,
  TIME_PAYROLL_DEFINITIONS,
  timePayrollComponentFor,
} from '@/views/timepay'

const resourceGet = vi.fn()
const resourcePost = vi.fn()

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resourceGet(...args),
  resourcePost: (...args: unknown[]) => resourcePost(...args),
}))

const ALL_CAPABILITIES = [
  'time.attendance.read',
  'time.attendance.correct.request',
  'time.attendance.manage',
  'time.attendance.reopen',
  'time.leave.read',
  'time.leave.request',
  'time.leave.request.cancel',
  'time.overtime.read',
  'time.overtime.request',
  'time.overtime.confirm',
  'time.overtime.settle',
  'time.timeoff.read',
  'time.travel.read',
  'time.travel.request',
  'payroll.run.read',
  'payroll.run.prepare',
  'payroll.run.review',
  'payroll.run.approve',
  'payroll.run.publish',
  'payroll.run.close',
  'payroll.run.correct',
  'payroll.payslip.read',
  'payroll.compensation.read',
  'payroll.compensation.manage',
  'payroll.salary-adjustment.manage',
]

function syntheticSession(includeWriteCapabilities = false): SessionBootstrap & { authenticated: true } {
  return {
    authenticated: true as const,
    principal: {
      userId: 9001,
      tenantId: 1,
      employmentId: 501,
      displayName: 'Synthetic Reviewer',
      tenantName: 'Synthetic Tenant',
      legalEntityName: 'Synthetic Legal Entity',
      email: 'synthetic.reviewer@example.invalid',
      employmentNo: 'E9001',
      jobTitle: 'Test Reviewer',
      orgName: 'Quality',
      initials: 'SR',
      isPrivileged: true,
      hasMfa: true,
      authVersion: 1,
      tenantTimezone: 'Asia/Shanghai',
    },
    capabilities: includeWriteCapabilities
      ? [...ALL_CAPABILITIES]
      : ['time.attendance.read', 'time.leave.read', 'time.overtime.read', 'time.travel.read', 'payroll.run.read'],
    csrfToken: 'synthetic-csrf',
    sessionExpiresAt: null,
    mfaEnrollmentRequired: false,
  }
}

async function mountPage(key: string, includeWriteCapabilities = false) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = syntheticSession(includeWriteCapabilities)
  useSessionStore().$patch({
    status: 'authenticated',
    principal: session.principal,
    capabilities: session.capabilities,
    csrfToken: session.csrfToken,
    sessionExpiresAt: session.sessionExpiresAt,
    mfaEnrollmentRequired: session.mfaEnrollmentRequired,
  })

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      { path: '/login', name: 'login', component: { template: '<div />' } },
      { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
      { path: '/:pathMatch(.*)*', name: 'catchall', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  await router.isReady()

  const component = timePayrollComponentFor(key)
  if (component === null) throw new Error(`Missing time/payroll component for ${key}`)

  return mount(component, {
    global: { plugins: [pinia, router, ElementPlus] },
  })
}

const attendanceMonth = {
  month: '2026-11',
  timezone: 'Asia/Shanghai',
  employeeName: 'E9001',
  days: [
    {
      date: '2026-11-25',
      weekday: 'Wednesday',
      status: 'PRESENT',
      scheduledStart: '09:00',
      scheduledEnd: '18:00',
      firstIn: '08:57',
      lastOut: '18:04',
      workedMinutes: 487,
      exceptionLabel: null,
      scheduled_minutes: 480,
    },
  ],
  summary: { expectedDays: 1, presentDays: 1, lateCount: 0, absentCount: 0, leaveDays: 0, remoteDays: 0 },
  exceptions: [],
}

const payrollRuns = {
  items: [
    {
      id: 5,
      legal_entity_id: 1,
      period: '2026-11',
      run_type: 'REGULAR',
      status: 'DRAFT',
      pay_date: '2026-11-30',
      employee_count: 2,
      gross_cents: 1200000,
      deductions_cents: 200000,
      net_cents: 1000000,
    },
  ],
  page: 1,
  page_size: 20,
  total: 1,
}

describe('time/payroll registry mapping', () => {
  it('covers exactly the 43 Wave 3 registry keys and returns a component for each', () => {
    const registryKeys = PAGE_REGISTRY.filter(
      (page) =>
        page.wave === 'Wave 3' &&
        ['attendance/', 'leave/', 'overtime/', 'travel/', 'payroll/'].some((prefix) =>
          page.componentKey.startsWith(prefix),
        ),
    )
      .map((page) => page.componentKey)
      .sort()

    expect(TIME_PAYROLL_COMPONENT_KEYS).toHaveLength(43)
    expect([...TIME_PAYROLL_COMPONENT_KEYS].sort()).toEqual(registryKeys)

    for (const key of registryKeys) {
      expect(timePayrollComponentFor(key)).not.toBeNull()
    }
    expect(timePayrollComponentFor('hr/employees/index')).toBeNull()
  })

  it('declares a live GET path for every page and keeps the public /v1 path in the endpoint map', () => {
    const allowedGetPaths = [
      '/v1/time/attendance',
      '/v1/time/calendars/',
      '/v1/time/shifts',
      '/v1/time/leave/balances',
      '/v1/time/leave/requests',
      '/v1/time/leave/types',
      '/v1/time/leave/policies',
      '/v1/time/leave/ledger',
      '/v1/time/overtime/records',
      '/v1/time/overtime/policies',
      '/v1/time/overtime/requests',
      '/v1/time/overtime/timeoff-balances',
      '/v1/travel/requests',
      '/v1/payroll/runs',
      '/v1/payroll/items',
      '/v1/payroll/schemes',
      '/v1/payroll/payslips',
      '/v1/payroll/statutory-parameters',
      '/v1/payroll/runs/{id}/inputs',
      '/v1/payroll/employee-plans',
      '/v1/payroll/adjustments',
    ]

    expect(TIME_PAYROLL_DEFINITIONS).toHaveLength(43)
    for (const definition of TIME_PAYROLL_DEFINITIONS) {
      const endpoint: string = TIME_PAYROLL_API_PATHS[definition.key] ?? ''
      expect(endpoint).toBe(definition.endpoint)
      expect(endpoint.startsWith('/v1/')).toBe(true)
      if (endpoint.startsWith('/v1/time/attendance')) {
        expect(endpoint).toBe('/v1/time/attendance')
      } else {
        expect(allowedGetPaths.some((allowed) => endpoint.startsWith(allowed))).toBe(true)
      }
    }
  })
})

describe('representative time/payroll mounts', () => {
  beforeEach(() => {
    resourceGet.mockReset()
    resourcePost.mockReset()
  })

  it('mounts the attendance calendar and requests the live attendance month projection', async () => {
    resourceGet.mockResolvedValue(attendanceMonth)

    const wrapper = await mountPage('attendance/calendar')
    await flushPromises()

    expect(resourceGet).toHaveBeenCalledWith('/time/attendance', expect.objectContaining({ month: expect.any(String) }))
    expect(wrapper.get('[data-testid="attendance-calendar"]').text()).toContain('2026-11-25')
    expect(wrapper.text()).toContain('出勤')
  })

  it('mounts leave balances and renders ledger-derived balances without synthesizing values', async () => {
    resourceGet.mockResolvedValue([
      {
        leaveTypeCode: 'ANNUAL',
        leaveTypeName: '年假',
        unit: 'DAY',
        granted: 10,
        used: 2,
        pending: 1,
        available: 7,
        expiresOn: '2027-03-31',
      },
    ])

    const wrapper = await mountPage('leave/balances')
    await flushPromises()

    expect(resourceGet).toHaveBeenCalledWith('/time/leave/balances', expect.any(Object))
    expect(wrapper.get('[data-testid="leave-balances"]').text()).toContain('年假')
    expect(wrapper.text()).toContain('7')
    expect(wrapper.text()).toContain('天可用')
  })

  it('mounts leave types from the live catalog endpoint', async () => {
    resourceGet.mockResolvedValue({
      items: [
        {
          id: 1,
          code: 'ANNUAL',
          name: '年假',
          unit: 'DAY',
          is_statutory: true,
          is_annual: true,
          requires_attachment: false,
          allow_negative: false,
          version: 2,
        },
      ],
      total: 1,
    })

    const wrapper = await mountPage('leave/types')
    await flushPromises()

    expect(resourceGet).toHaveBeenCalledWith('/time/leave/types', expect.any(Object))
    expect(wrapper.text()).toContain('年假')
    expect(wrapper.text()).toContain('ANNUAL')
  })

  it('mounts overtime settlement and posts the real settlement endpoint', async () => {
    resourceGet.mockResolvedValue({
      items: [
        {
          id: 11,
          employment_id: 501,
          business_date: '2026-11-20',
          minutes: 90,
          status: 'APPROVED',
          case_id: 44,
          settlement_method: null,
          settlement_id: null,
        },
      ],
      total: 1,
    })
    resourcePost.mockResolvedValue({ settlement_id: 99, overtime_record_id: 11, method: 'PAY', minutes: 90 })

    const wrapper = await mountPage('overtime/settlement', true)
    await flushPromises()

    expect(resourceGet).toHaveBeenCalledWith('/time/overtime/records', expect.any(Object))
    await wrapper.get('[data-testid="settle-PAY-11"]').trigger('click')
    await flushPromises()

    expect(resourcePost).toHaveBeenCalledWith(
      '/overtime/records/11/settlements',
      { method: 'PAY', reason: '页面结算操作' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('结算已完成')
  })

  it('mounts the overtime application and posts the real request endpoint', async () => {
    resourceGet.mockResolvedValue({
      items: [
        {
          id: 31,
          employment_id: 501,
          business_date: '2026-11-24',
          declared_minutes: 90,
          confirmed_minutes: null,
          status: 'APPROVED',
          reason: 'Synthetic release support',
          case_id: null,
          overtime_record_id: null,
          recorded_at: '2026-11-24T02:00:00Z',
        },
      ],
      page: 1,
      page_size: 20,
      total: 1,
      as_of: '2026-11-25',
      freshness: 'LIVE',
      source_hash: 'synthetic',
      partial: false,
      partial_reason: null,
      drilldown: null,
    })
    resourcePost.mockResolvedValue({
      id: 32,
      employment_id: 501,
      business_date: '2026-11-26',
      declared_minutes: 120,
      confirmed_minutes: null,
      status: 'APPROVED',
      reason: 'Synthetic month-end support',
      case_id: null,
      overtime_record_id: null,
      recorded_at: '2026-11-25T02:00:00Z',
    })

    const wrapper = await mountPage('overtime/index', true)
    await flushPromises()

    expect(resourceGet).toHaveBeenCalledWith('/time/overtime/requests', expect.any(Object))
    expect(wrapper.get('[data-testid="overtime-apply"]').text()).toContain('1 小时 30 分钟')
    await wrapper.get('[data-testid="overtime-business-date"]').setValue('2026-11-26')
    await wrapper.get('[data-testid="overtime-declared-minutes"]').setValue('120')
    await wrapper.get('[data-testid="overtime-reason"]').setValue('Synthetic month-end support')
    await wrapper.get('[data-testid="overtime-submit"]').trigger('click')
    await flushPromises()

    expect(resourcePost).toHaveBeenCalledWith(
      '/time/overtime/requests',
      {
        business_date: '2026-11-26',
        declared_minutes: 120,
        reason: 'Synthetic month-end support',
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('加班申请已提交')
  })

  it('mounts overtime confirmation and posts the real confirm endpoint', async () => {
    resourceGet.mockResolvedValue({
      items: [
        {
          id: 41,
          employment_id: 501,
          business_date: '2026-11-20',
          declared_minutes: 120,
          confirmed_minutes: null,
          status: 'APPROVED',
          reason: 'Synthetic release support',
          case_id: 44,
          overtime_record_id: null,
          recorded_at: '2026-11-20T02:00:00Z',
        },
      ],
      page: 1,
      page_size: 20,
      total: 1,
      as_of: '2026-11-25',
      freshness: 'LIVE',
      source_hash: 'synthetic',
      partial: false,
      partial_reason: null,
      drilldown: null,
    })
    resourcePost.mockResolvedValue({
      id: 41,
      employment_id: 501,
      business_date: '2026-11-20',
      declared_minutes: 120,
      confirmed_minutes: 90,
      status: 'CONFIRMED',
      reason: 'Synthetic release support',
      case_id: 44,
      overtime_record_id: 77,
      recorded_at: '2026-11-20T02:00:00Z',
    })

    const wrapper = await mountPage('overtime/confirm', true)
    await flushPromises()

    expect(resourceGet).toHaveBeenCalledWith('/time/overtime/requests', expect.any(Object))
    await wrapper.get('[data-testid="confirm-minutes-41"]').setValue('90')
    await wrapper.get('[data-testid="confirm-41"]').trigger('click')
    await flushPromises()

    expect(resourcePost).toHaveBeenCalledWith(
      '/time/overtime/requests/41/confirm',
      { confirmed_minutes: 90 },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('加班工时已确认')
  })

  it('mounts timeoff balances and renders the server-derived projection', async () => {
    resourceGet.mockResolvedValue({
      items: [
        {
          settlement_id: 99,
          overtime_record_id: 77,
          employment_id: 501,
          business_date: '2026-11-20',
          method: 'TIMEOFF',
          minutes: 120,
          reason: 'Synthetic time-off compensation',
          recorded_at: '2026-11-21T02:00:00Z',
        },
      ],
      page: 1,
      page_size: 100,
      total: 1,
      as_of: '2026-11-25',
      freshness: 'LIVE',
      source_hash: 'synthetic',
      partial: false,
      partial_reason: null,
      drilldown: null,
      employment_id: 501,
      available_minutes: 120,
      settlement_count: 1,
    })

    const wrapper = await mountPage('overtime/timeoff', true)
    await flushPromises()

    expect(resourceGet).toHaveBeenCalledWith('/time/overtime/timeoff-balances', expect.any(Object))
    expect(wrapper.get('[data-testid="overtime-timeoff"]').text()).toContain('2 小时')
    expect(wrapper.text()).toContain('2026-11-20')
    expect(wrapper.text()).toContain('TIMEOFF')
  })

  it('removes the leave cancellation gap and uses the live leave request list', async () => {
    resourceGet.mockResolvedValue({
      items: [
        {
          id: 33,
          caseId: 33,
          caseNo: 'CASE-000033',
          title: '年假申请',
          type: 'LEAVE',
          applicationStatus: 'APPROVED',
          fromDate: '2026-12-01',
          toDate: '2026-12-02',
          minutes: 960,
          state: 'APPROVED',
        },
      ],
      total: 1,
    })

    const wrapper = await mountPage('leave/cancellations', true)
    await flushPromises()

    expect(resourceGet).toHaveBeenCalledWith('/time/leave/requests', expect.any(Object))
    expect(wrapper.find('[data-testid="backend-gap"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('CASE-000033')
    expect(wrapper.find('[data-testid="cancel-leave-33"]').exists()).toBe(true)
  })

  it('mounts travel requests and cancels through the live travel endpoint', async () => {
    resourceGet.mockResolvedValue({
      items: [
        {
          travel_id: 12,
          employment_id: 501,
          from_date: '2026-12-01',
          to_date: '2026-12-03',
          destination: 'Synthetic City',
          purpose: 'Supplier audit',
          status: 'PENDING_APPROVAL',
        },
      ],
      total: 1,
    })
    resourcePost.mockResolvedValue({ travel_id: 12, status: 'CANCELLED' })

    const wrapper = await mountPage('travel/requests', true)
    await flushPromises()

    expect(resourceGet).toHaveBeenCalledWith('/travel/requests', expect.any(Object))
    await wrapper.get('[data-testid="cancel-travel-12"]').trigger('click')
    await flushPromises()

    expect(resourcePost).toHaveBeenCalledWith(
      '/travel/requests/12/cancel',
      { reason: '页面取消出差申请' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('出差申请已取消')
  })

  it('creates a travel request with the backend snake_case payload', async () => {
    resourceGet.mockResolvedValue({ items: [], total: 0 })
    resourcePost.mockResolvedValue({ travel_id: 20, status: 'PENDING_APPROVAL' })

    const wrapper = await mountPage('travel/index', true)
    await flushPromises()

    await wrapper.get('[data-testid="travel-from-date"]').setValue('2026-12-10')
    await wrapper.get('[data-testid="travel-to-date"]').setValue('2026-12-12')
    await wrapper.get('[data-testid="travel-destination"]').setValue('Synthetic City')
    await wrapper.get('[data-testid="travel-purpose"]').setValue('Synthetic supplier visit')
    await wrapper.get('[data-testid="travel-create-submit"]').trigger('click')
    await flushPromises()

    expect(resourcePost).toHaveBeenCalledWith(
      '/travel/requests',
      {
        from_date: '2026-12-10',
        to_date: '2026-12-12',
        destination: 'Synthetic City',
        purpose: 'Synthetic supplier visit',
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('mounts payroll runs, renders integer cents through MoneyText, and posts a real transition action', async () => {
    resourceGet.mockResolvedValue(payrollRuns)
    resourcePost.mockResolvedValue({ id: 5, status: 'INPUT_LOCKED' })

    const wrapper = await mountPage('payroll/batches', true)
    await flushPromises()

    expect(resourceGet).toHaveBeenCalledWith('/payroll/runs', expect.objectContaining({ page: 1 }))
    expect(wrapper.get('[data-testid="payroll-runs"]').text()).toContain('¥12,000.00')
    expect(wrapper.findAllComponents(MoneyText).length).toBeGreaterThan(0)

    await wrapper.get('[data-testid="payroll-action-lock-inputs-5"]').trigger('click')
    await flushPromises()

    expect(resourcePost).toHaveBeenCalledWith(
      '/payroll/runs/5/lock-inputs',
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('工资批次操作已提交')
  })

  it('mounts payroll payslips and renders server-provided cents through MoneyText', async () => {
    resourceGet.mockResolvedValue({
      items: [
        {
          id: 4,
          run_id: 7,
          employment_id: 501,
          version: 1,
          gross_cents: 1200000,
          deductions_cents: 200000,
          net_cents: 1000000,
          employer_cost_cents: 1500000,
          published_at: '2026-11-30T02:00:00Z',
        },
      ],
      total: 1,
    })

    const wrapper = await mountPage('payroll/payslips')
    await flushPromises()

    expect(resourceGet).toHaveBeenCalledWith('/payroll/payslips', expect.any(Object))
    expect(wrapper.get('[data-testid="payroll-payslips"]').text()).toContain('¥10,000.00')
    expect(wrapper.findAllComponents(MoneyText).length).toBeGreaterThan(0)
  })

  it('never synthesizes amounts when the payroll server omits P3 cents', async () => {
    resourceGet.mockResolvedValue({
      items: [{ id: 4, run_id: 7, employment_id: 501, version: 1, published_at: '2026-11-30T02:00:00Z' }],
      total: 1,
    })

    const wrapper = await mountPage('payroll/payslips')
    await flushPromises()

    expect(wrapper.get('[data-testid="payroll-payslips"]').text()).toContain('已按字段权限隐藏')
    expect(wrapper.text()).not.toContain('¥')
    expect(wrapper.text()).not.toContain('0.00')
  })

  it('shows the dedicated no-active-employment state for a payroll payslip 409', async () => {
    resourceGet.mockRejectedValue(
      new ApiError('NO_ACTIVE_EMPLOYMENT', 'No active employment', {
        status: 409,
        requestId: 'req-payslip-no-employment',
      }),
    )

    const wrapper = await mountPage('payroll/payslips')
    await flushPromises()

    expect(wrapper.find('[data-testid="no-active-employment"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('当前账号未绑定员工')
    expect(wrapper.text()).toContain('req-payslip-no-employment')
    expect(wrapper.text()).not.toContain('暂时无法加载')
  })

  it('keeps the request id on a payslip 5xx page error', async () => {
    resourceGet.mockRejectedValue(
      new ApiError('INTERNAL', 'boom', { status: 500, requestId: 'req-payslip-500' }),
    )

    const wrapper = await mountPage('payroll/payslips')
    await flushPromises()

    expect(wrapper.text()).toContain('暂时无法加载')
    expect(wrapper.text()).toContain('req-payslip-500')
    expect(wrapper.find('[data-testid="no-active-employment"]').exists()).toBe(false)
  })

  it('keeps the request id on an attendance 5xx page error', async () => {
    resourceGet.mockRejectedValue(
      new ApiError('INTERNAL', 'boom', { status: 500, requestId: 'req-attendance-500' }),
    )

    const wrapper = await mountPage('attendance/calendar')
    await flushPromises()

    expect(wrapper.text()).toContain('暂时无法加载')
    expect(wrapper.text()).toContain('req-attendance-500')
  })

  it('mounts the compensation archive and renders server cents via MoneyText without a backend gap', async () => {
    resourceGet.mockResolvedValue({
      items: [
        {
          id: 31,
          employment_id: 501,
          legal_entity_id: 1,
          effective_from: '2026-01-01',
          currency: 'CNY',
          salary_scheme_code: 'SCHEME-A',
          base_salary_cents: 1000000,
          created_at: '2026-11-25T02:00:00Z',
        },
        {
          id: 32,
          employment_id: 501,
          legal_entity_id: 1,
          effective_from: '2026-07-01',
          currency: 'CNY',
          salary_scheme_code: 'SCHEME-A',
          base_salary_cents: 1200000,
          created_at: '2026-11-25T02:00:00Z',
        },
      ],
      page: 1,
      page_size: 20,
      total: 2,
      as_of: '2026-11-25',
      freshness: 'LIVE',
      source_hash: '',
      partial: false,
      partial_reason: null,
      drilldown: null,
    })

    const wrapper = await mountPage('payroll/employee-plans', true)
    await flushPromises()

    expect(resourceGet).toHaveBeenCalledWith('/payroll/employee-plans', expect.objectContaining({ legal_entity_id: 1 }))
    expect(wrapper.find('[data-testid="backend-gap"]').exists()).toBe(false)
    const text = wrapper.get('[data-testid="payroll-employee-plans"]').text()
    expect(text).toContain('¥10,000.00')
    expect(text).toContain('¥12,000.00')
    expect(wrapper.findAllComponents(MoneyText).length).toBeGreaterThan(0)
  })

  it('does not synthesize compensation amounts when the server omits P3 cents', async () => {
    resourceGet.mockResolvedValue({
      items: [
        {
          id: 33,
          employment_id: 501,
          legal_entity_id: 1,
          effective_from: '2026-08-01',
          currency: 'CNY',
          salary_scheme_code: 'SCHEME-A',
          created_at: '2026-11-25T02:00:00Z',
        },
      ],
      page: 1,
      page_size: 20,
      total: 1,
      as_of: '2026-11-25',
      freshness: 'LIVE',
      source_hash: '',
      partial: false,
      partial_reason: null,
      drilldown: null,
    })

    const wrapper = await mountPage('payroll/employee-plans')
    await flushPromises()

    const text = wrapper.get('[data-testid="payroll-employee-plans"]').text()
    expect(text).toContain('已按字段权限隐藏')
    expect(text).not.toContain('¥')
    expect(text).not.toContain('0.00')
  })

  it('mounts salary adjustments with the approved case and integer cents', async () => {
    resourceGet.mockResolvedValue({
      items: [
        {
          id: 41,
          employment_id: 501,
          legal_entity_id: 1,
          effective_from: '2026-10-01',
          currency: 'CNY',
          case_id: 77,
          compensation_id: 32,
          reason: 'Synthetic approved salary change',
          base_salary_cents: 1400000,
          created_at: '2026-11-25T02:00:00Z',
        },
      ],
      page: 1,
      page_size: 20,
      total: 1,
      as_of: '2026-11-25',
      freshness: 'LIVE',
      source_hash: '',
      partial: false,
      partial_reason: null,
      drilldown: null,
    })

    const wrapper = await mountPage('payroll/adjustments', true)
    await flushPromises()

    expect(resourceGet).toHaveBeenCalledWith('/payroll/adjustments', expect.objectContaining({ legal_entity_id: 1 }))
    const text = wrapper.get('[data-testid="payroll-adjustments"]').text()
    expect(text).toContain('CASE-77')
    expect(text).toContain('¥14,000.00')
    expect(wrapper.find('[data-testid="backend-gap"]').exists()).toBe(false)
  })

  it('loads payroll inputs from the selected real run endpoint and shows no backend gap', async () => {
    resourceGet.mockImplementation((path: string) => {
      if (path === '/payroll/runs') return Promise.resolve(payrollRuns)
      if (path === '/payroll/runs/5/inputs') {
        return Promise.resolve({
          items: [
            {
              id: 91,
              employment_id: 501,
              snapshot_type: 'ATTENDANCE_MONTH',
              snapshot_version: 3,
              source_hash: 'sha256:synthetic',
              payload: { worked_minutes: 9600 },
              created_at: '2026-11-25T02:00:00Z',
            },
          ],
          page: 1,
          page_size: 20,
          total: 1,
        })
      }
      return Promise.resolve({ items: [], total: 0 })
    })

    const wrapper = await mountPage('payroll/inputs')
    await flushPromises()

    expect(resourceGet).toHaveBeenCalledWith('/payroll/runs', expect.objectContaining({ legal_entity_id: 1 }))
    expect(resourceGet).toHaveBeenCalledWith('/payroll/runs/5/inputs')
    expect(wrapper.find('[data-testid="backend-gap"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('ATTENDANCE_MONTH')
    expect(wrapper.text()).toContain('sha256:synthetic')
  })
})

describe('live write actions', () => {
  beforeEach(() => {
    resourceGet.mockReset()
    resourcePost.mockReset()
  })

  it('submits a compensation version as integer cents through the real endpoint', async () => {
    resourceGet.mockResolvedValue({
      items: [],
      page: 1,
      page_size: 20,
      total: 0,
      as_of: '2026-11-25',
      freshness: 'LIVE',
      source_hash: '',
      partial: false,
      partial_reason: null,
      drilldown: null,
    })
    resourcePost.mockResolvedValue({ id: 51, base_salary_cents: 1550000 })

    const wrapper = await mountPage('payroll/employee-plans', true)
    await flushPromises()

    await wrapper.get('[data-testid="compensation-employment-id"]').setValue('501')
    await wrapper.get('[data-testid="compensation-effective-from"]').setValue('2026-12-01')
    await wrapper.get('[data-testid="compensation-base-salary-cents"]').setValue('1550000')
    await wrapper.get('[data-testid="compensation-currency"]').setValue('CNY')
    await wrapper.get('[data-testid="compensation-form"]').trigger('submit')
    await flushPromises()

    expect(resourcePost).toHaveBeenCalledWith(
      '/payroll/employee-plans',
      {
        employment_id: 501,
        effective_from: '2026-12-01',
        base_salary_cents: 1550000,
        currency: 'CNY',
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('薪酬档案版本已新增')
  })

  it('rejects a fractional compensation amount before calling the server', async () => {
    resourceGet.mockResolvedValue({
      items: [],
      page: 1,
      page_size: 20,
      total: 0,
      as_of: '2026-11-25',
      freshness: 'LIVE',
      source_hash: '',
      partial: false,
      partial_reason: null,
      drilldown: null,
    })

    const wrapper = await mountPage('payroll/employee-plans', true)
    await flushPromises()

    await wrapper.get('[data-testid="compensation-employment-id"]').setValue('501')
    await wrapper.get('[data-testid="compensation-effective-from"]').setValue('2026-12-01')
    await wrapper.get('[data-testid="compensation-base-salary-cents"]').setValue('15500.50')
    await wrapper.get('[data-testid="compensation-form"]').trigger('submit')
    await flushPromises()

    expect(resourcePost).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('整数分')
  })

  it('submits a salary adjustment with its approved workflow case id', async () => {
    resourceGet.mockResolvedValue({
      items: [],
      page: 1,
      page_size: 20,
      total: 0,
      as_of: '2026-11-25',
      freshness: 'LIVE',
      source_hash: '',
      partial: false,
      partial_reason: null,
      drilldown: null,
    })
    resourcePost.mockResolvedValue({ id: 61, case_id: 77, base_salary_cents: 1600000 })

    const wrapper = await mountPage('payroll/adjustments', true)
    await flushPromises()

    await wrapper.get('[data-testid="adjustment-employment-id"]').setValue('501')
    await wrapper.get('[data-testid="adjustment-case-id"]').setValue('77')
    await wrapper.get('[data-testid="adjustment-effective-from"]').setValue('2026-12-15')
    await wrapper.get('[data-testid="adjustment-base-salary-cents"]').setValue('1600000')
    await wrapper.get('[data-testid="adjustment-reason"]').setValue('Synthetic approved salary change')
    await wrapper.get('[data-testid="adjustment-form"]').trigger('submit')
    await flushPromises()

    expect(resourcePost).toHaveBeenCalledWith(
      '/payroll/adjustments',
      {
        employment_id: 501,
        case_id: 77,
        effective_from: '2026-12-15',
        base_salary_cents: 1600000,
        currency: 'CNY',
        reason: 'Synthetic approved salary change',
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('调薪记录已应用')
  })

  it('submits an attendance correction through the real correction endpoint', async () => {
    resourceGet.mockResolvedValue(attendanceMonth)
    resourcePost.mockResolvedValue({ accepted: true, requestNo: 'COR-000123' })

    const wrapper = await mountPage('attendance/corrections', true)
    await flushPromises()

    await wrapper.get('[data-testid="correction-date"]').setValue('2026-11-24')
    await wrapper.get('[data-testid="correction-first-in"]').setValue('08:55')
    await wrapper.get('[data-testid="correction-last-out"]').setValue('18:10')
    await wrapper.get('[data-testid="correction-reason"]').setValue('Synthetic device failure')
    await wrapper.get('[data-testid="correction-submit"]').trigger('click')
    await flushPromises()

    expect(resourcePost).toHaveBeenCalledWith(
      '/time/attendance/corrections',
      {
        date: '2026-11-24',
        correctionType: 'MISSING_PUNCH',
        firstIn: '08:55',
        lastOut: '18:10',
        reason: 'Synthetic device failure',
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('COR-000123')
  })

  it('submits a leave request and cancels a leave request through live endpoints', async () => {
    resourceGet.mockImplementation((path: string) => {
      if (path === '/time/leave/types') {
        return Promise.resolve({
          items: [{ id: 1, code: 'ANNUAL', name: '年假', unit: 'DAY' }],
          total: 1,
        })
      }
      if (path === '/time/leave/balances') {
        return Promise.resolve([
          {
            leaveTypeCode: 'ANNUAL',
            leaveTypeName: '年假',
            unit: 'DAY',
            granted: 10,
            used: 2,
            pending: 1,
            available: 7,
          },
        ])
      }
      return Promise.resolve({
        items: [
          {
            id: 33,
            caseId: 33,
            caseNo: 'CASE-000033',
            title: '年假申请',
            type: 'LEAVE',
            applicationStatus: 'SUBMITTED',
            fromDate: '2026-12-01',
            toDate: '2026-12-02',
            minutes: 960,
            state: 'RESERVED',
          },
        ],
        total: 1,
      })
    })
    resourcePost.mockResolvedValue({ caseId: 34, caseNo: 'CASE-000034', applicationStatus: 'SUBMITTED' })

    const applyPage = await mountPage('leave/index', true)
    await flushPromises()

    await applyPage.get('[data-testid="leave-type"]').setValue('ANNUAL')
    await applyPage.get('[data-testid="leave-start"]').setValue('2026-12-08')
    await applyPage.get('[data-testid="leave-end"]').setValue('2026-12-09')
    await applyPage.get('[data-testid="leave-reason"]').setValue('Synthetic family event')
    await applyPage.get('[data-testid="leave-submit"]').trigger('click')
    await flushPromises()

    expect(resourcePost).toHaveBeenCalledWith(
      '/time/leave/requests',
      {
        leaveTypeCode: 'ANNUAL',
        startDate: '2026-12-08',
        endDate: '2026-12-09',
        halfDay: false,
        reason: 'Synthetic family event',
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )

    const requestsPage = await mountPage('leave/requests', true)
    await flushPromises()
    await requestsPage.get('[data-testid="cancel-leave-33"]').trigger('click')
    await flushPromises()

    expect(resourcePost).toHaveBeenCalledWith(
      '/time/leave/requests/33/cancel',
      { reason: '页面撤回请假申请' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(requestsPage.text()).toContain('请假申请已撤回')
  })

  it('closes, reopens, and recalculates attendance through live attendance endpoints', async () => {
    resourceGet.mockResolvedValue(attendanceMonth)
    resourcePost.mockResolvedValue({ closure_id: 88, status: 'CLOSED' })

    const wrapper = await mountPage('attendance/closing', true)
    await flushPromises()

    await wrapper.get('[data-testid="attendance-close-month"]').setValue('2026-11')
    await wrapper.get('[data-testid="attendance-close"]').trigger('click')
    await flushPromises()
    expect(resourcePost).toHaveBeenCalledWith(
      '/time/attendance/closings',
      { month: '2026-11', legal_entity_id: 1 },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )

    await wrapper.get('[data-testid="attendance-reopen"]').trigger('click')
    await flushPromises()
    expect(resourcePost).toHaveBeenCalledWith(
      '/time/attendance/closings/88/reopen',
      { reason: '页面解锁考勤月结' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )

    await wrapper.get('[data-testid="attendance-recalculate"]').trigger('click')
    await flushPromises()
    expect(resourcePost).toHaveBeenCalledWith(
      '/time/attendance/recalculate',
      { month: '2026-11', legal_entity_id: 1 },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })
})

describe('money and float hygiene', () => {
  it('contains no floating point money arithmetic in the owned timepay workspace', () => {
    const root = resolve(process.cwd(), 'src/views/timepay')
    const files = readdirSync(root, { recursive: true })
      .map((entry) => String(entry))
      .filter((entry) => entry.endsWith('.ts') || entry.endsWith('.vue'))

    expect(files.length).toBeGreaterThan(0)
    for (const relative of files) {
      const source = readFileSync(`${root}/${relative}`, 'utf8')
      expect(source, relative).not.toMatch(/parseFloat\s*\(/)
      expect(source, relative).not.toMatch(/Number\.parseFloat\s*\(/)
      expect(source, relative).not.toMatch(/\/\s*100\b/)
      expect(source, relative).not.toMatch(/\*\s*100\b/)
    }
  })
})
