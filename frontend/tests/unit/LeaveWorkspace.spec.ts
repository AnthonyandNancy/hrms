import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import LeaveWorkspacePage from '@/modules/time/leave/pages/LeaveWorkspacePage.vue'
import LeaveLedgerPanel from '@/modules/time/leave/components/LeaveLedgerPanel.vue'
import type { LeaveBalance } from '@/modules/time/leave/api'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resource.get(...args),
  resourcePost: (...args: unknown[]) => resource.post(...args),
}))

function rawBalance(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
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
    ...overrides,
  }
}

function rawTypes(): Record<string, unknown> {
  return {
    items: [
      {
        id: 11,
        code: 'ANNUAL',
        name: '年假',
        unit: 'DAY',
        is_statutory: false,
        is_annual: true,
        annual_entitlement_days: '10.00',
        requires_attachment: false,
        allow_negative: false,
        version: 1,
      },
      {
        id: 12,
        code: 'SICK',
        name: '病假',
        unit: 'DAY',
        is_statutory: true,
        is_annual: false,
        annual_entitlement_days: null,
        requires_attachment: true,
        allow_negative: false,
        version: 1,
      },
    ],
    page: 1,
    page_size: 20,
    total: 2,
  }
}

function rawRequests(): Record<string, unknown> {
  return {
    items: [
      {
        id: 33,
        caseId: 33,
        caseNo: 'CASE-000033',
        applicationStatus: 'SUBMITTED',
        effectStatus: null,
        leaveTypeId: 11,
        fromDate: '2026-12-01',
        toDate: '2026-12-02',
        minutes: 960,
        state: 'SUBMITTED',
      },
    ],
    total: 1,
  }
}

function rawPreview(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    workingDays: 2,
    requestedDays: 2,
    availableBefore: 7,
    availableAfter: 5,
    unit: 'DAY',
    conflicts: [],
    note: 'Balance is reserved at submit and settled on approval.',
    leaveTypeCode: 'ANNUAL',
    requestedMinutes: 960,
    availableBeforeMinutes: 3360,
    availableAfterMinutes: 2400,
    standardDayMinutes: 480,
    ...overrides,
  }
}

function rawLedger(): Record<string, unknown> {
  return {
    leave_type_code: 'ANNUAL',
    leave_type_name: '年假',
    unit: 'DAY',
    as_of: '2026-11-25',
    opening_minutes: 0,
    closing_minutes: 3840,
    entries: [
      {
        id: '1',
        entryType: 'GRANT',
        businessDate: '2026-01-01',
        quantityMinutes: 4800,
        balanceAfter: 4800,
        reason: '期初发放',
      },
      {
        id: '2',
        entryType: 'RESERVE',
        businessDate: '2026-11-24',
        quantityMinutes: -960,
        balanceAfter: 3840,
        reason: '请假占用',
        sourceCaseNo: 'CASE-000033',
      },
    ],
  }
}

function defaultGet(path: string): Promise<unknown> {
  if (path === '/v1/time/leave/balances') {
    return Promise.resolve([
      rawBalance(),
      rawBalance({
        leaveTypeCode: 'COMP',
        leaveTypeName: '调休',
        unit: 'HOUR',
        granted: 0,
        used: 0,
        pending: 0,
        available: 0,
        grantedMinutes: 4800,
        consumedMinutes: 600,
        reservedMinutes: 120,
        availableMinutes: 4080,
        standardDayMinutes: null,
      }),
    ])
  }
  if (path === '/v1/time/leave/types') return Promise.resolve(rawTypes())
  if (path === '/v1/time/leave/requests') return Promise.resolve(rawRequests())
  if (path === '/v1/time/leave/ledger') return Promise.resolve(rawLedger())
  return Promise.reject(new Error(`Unexpected GET ${path}`))
}

async function mountPage(capabilities: string[]): Promise<{ wrapper: ReturnType<typeof mount>; router: Router }> {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/leave', name: 'leave', component: { template: '<div />' } },
      { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
      { path: '/login', name: 'login', component: { template: '<div />' } },
    ],
  })
  await router.push('/leave')
  await router.isReady()

  const wrapper = mount(LeaveWorkspacePage, {
    global: {
      plugins: [pinia, router],
      stubs: {
        'el-button': { template: '<button type="button"><slot /></button>' },
      },
    },
  })
  return { wrapper, router }
}

function requestSubmissions() {
  return resource.post.mock.calls.filter(([path]) => path === '/v1/time/leave/requests')
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.get.mockImplementation((path: string) => defaultGet(path))
})

describe('LeaveWorkspace balances and request submission', () => {
  it('renders per-type entitled, used, pending and available balances with the API as-of date and units', async () => {
    const { wrapper } = await mountPage(['time.leave.read'])
    await flushPromises()

    const card = wrapper.get('[data-testid="leave-balance-ANNUAL"]')
    expect(card.text()).toContain('年假')
    expect(card.text()).toContain('应享')
    expect(card.text()).toContain('10 天')
    expect(card.text()).toContain('已用')
    expect(card.text()).toContain('2 天')
    expect(card.text()).toContain('待占用')
    expect(card.text()).toContain('1 天')
    expect(card.text()).toContain('可用')
    expect(card.text()).toContain('7 天')
    expect(card.text()).toContain('截至 2026-11-25')
    expect(wrapper.get('[data-testid="leave-as-of"]').text()).toContain('2026-11-25')

    const hourly = wrapper.get('[data-testid="leave-balance-COMP"]')
    expect(hourly.text()).toContain('调休')
    expect(hourly.text()).toContain('小时')
    expect(hourly.text()).toContain('68 小时')

    const row = wrapper.get('[data-testid="leave-request-33"]')
    expect(row.text()).toContain('CASE-000033')
    expect(row.text()).toContain('年假')
    expect(row.text()).toContain('待审批')
    expect(row.text()).toContain('2 天')
    expect(row.text()).toContain('2026-12-01')
    expect(row.text()).toContain('2026-12-02')

    // Business-date honesty: the browser clock must not supply an as_of
    // fallback. Only the server decides "today" in the company timezone.
    const balanceCall = resource.get.mock.calls.find(([path]) => path === '/v1/time/leave/balances')
    expect(balanceCall).toHaveLength(1)
    expect(wrapper.find('[data-testid="open-leave-request"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="leave-cancel-33"]').exists()).toBe(false)
  })

  it('previews before submitting and reuses the idempotency key for a retry of the same submission', async () => {
    let submitAttempts = 0
    resource.post.mockImplementation((path: string) => {
      if (path === '/v1/time/leave/preview') return Promise.resolve(rawPreview())
      if (path === '/v1/time/leave/requests') {
        submitAttempts += 1
        if (submitAttempts === 1) {
          return Promise.reject(new ApiError('NETWORK_ERROR', '网络连接失败', { status: 0 }))
        }
        return Promise.resolve({
          caseId: 44,
          caseNo: 'CASE-000044',
          applicationStatus: 'SUBMITTED',
          effectStatus: null,
          requestedMinutes: 960,
        })
      }
      return Promise.reject(new Error(`Unexpected POST ${path}`))
    })

    const { wrapper } = await mountPage(['time.leave.read', 'time.leave.request'])
    await flushPromises()
    await wrapper.get('[data-testid="open-leave-request"]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-testid="leave-start"]').setValue('2026-12-01')
    await wrapper.get('[data-testid="leave-end"]').setValue('2026-12-02')
    await wrapper.get('[data-testid="leave-reason"]').setValue('合成事假事由')
    await flushPromises()

    expect(resource.post.mock.calls[0]?.[0]).toBe('/v1/time/leave/preview')

    // Changing the leave type is also a material change: the server must be
    // asked for a new deduction/conflict preview before submit is allowed.
    const previewsBeforeTypeChange = resource.post.mock.calls.filter(
      ([path]) => path === '/v1/time/leave/preview',
    ).length
    await wrapper.get('[data-testid="leave-type"]').setValue('SICK')
    await flushPromises()
    const previewCalls = resource.post.mock.calls.filter(([path]) => path === '/v1/time/leave/preview')
    expect(previewCalls.length).toBeGreaterThan(previewsBeforeTypeChange)
    expect(previewCalls.at(-1)?.[1]).toMatchObject({ leaveTypeCode: 'SICK' })

    await wrapper.get('[data-testid="leave-form"]').trigger('submit')
    await flushPromises()
    await wrapper.get('[data-testid="leave-form"]').trigger('submit')
    await flushPromises()

    const submissions = requestSubmissions()
    expect(submissions, wrapper.text()).toHaveLength(2)
    expect(submissions[0]?.[1]).toMatchObject({
      leaveTypeCode: 'SICK',
      startDate: '2026-12-01',
      endDate: '2026-12-02',
      halfDay: false,
      reason: '合成事假事由',
    })

    const firstKey = (submissions[0]?.[2] as { idempotencyKey?: string } | undefined)?.idempotencyKey
    const retryKey = (submissions[1]?.[2] as { idempotencyKey?: string } | undefined)?.idempotencyKey
    expect(firstKey).toEqual(expect.any(String))
    expect(retryKey).toBe(firstKey)
    expect(wrapper.text()).toContain('请假申请已提交：CASE-000044')

    // A different payload earns a different key; the old submission is not
    // retried under the same idempotency identity.
    await wrapper.get('[data-testid="open-leave-request"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="leave-start"]').setValue('2026-12-08')
    await wrapper.get('[data-testid="leave-end"]').setValue('2026-12-09')
    await wrapper.get('[data-testid="leave-reason"]').setValue('合成事假事由二')
    await flushPromises()
    await wrapper.get('[data-testid="leave-form"]').trigger('submit')
    await flushPromises()

    const nextSubmission = requestSubmissions().at(-1)
    expect(nextSubmission?.[1]).toMatchObject({ startDate: '2026-12-08', endDate: '2026-12-09' })
    const nextKey = (nextSubmission?.[2] as { idempotencyKey?: string } | undefined)?.idempotencyKey
    expect(nextKey).toEqual(expect.any(String))
    expect(nextKey).not.toBe(firstKey)
  })

  it('shows the computed deduction and blocking conflicts from the preview before submit', async () => {
    resource.post.mockImplementation((path: string) => {
      if (path === '/v1/time/leave/preview') {
        return Promise.resolve(
          rawPreview({
            unit: 'HOUR',
            requestedDays: 0,
            availableBefore: 68,
            availableAfter: 66,
            requestedMinutes: 120,
            availableBeforeMinutes: 4080,
            availableAfterMinutes: 3960,
            conflicts: [
              {
                code: 'DATE_CONFLICT',
                severity: 'BLOCKING',
                message: 'The selected date conflicts with an existing shift.',
              },
            ],
          }),
        )
      }
      if (path === '/v1/time/leave/requests') {
        return Promise.resolve({ caseId: 46, caseNo: 'CASE-000046', applicationStatus: 'SUBMITTED' })
      }
      return Promise.reject(new Error(`Unexpected POST ${path}`))
    })

    const { wrapper } = await mountPage(['time.leave.read', 'time.leave.request'])
    await flushPromises()
    await wrapper.get('[data-testid="open-leave-request"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="leave-type"]').setValue('COMP')
    await wrapper.get('[data-testid="leave-start"]').setValue('2026-12-01')
    await wrapper.get('[data-testid="leave-end"]').setValue('2026-12-01')
    await wrapper.get('[data-testid="leave-reason"]').setValue('合成调休事由')
    await flushPromises()

    const preview = wrapper.get('[data-testid="leave-preview"]')
    expect(preview.text()).toContain('本次扣减')
    expect(preview.text()).toContain('2 小时')
    expect(preview.text()).toContain('68 小时')
    expect(preview.text()).toContain('66 小时')
    // Conflicts are rendered in Chinese, and a BLOCKING conflict prevents the
    // request from being sent even though the preview succeeded.
    expect(preview.text()).toContain('所选日期存在排班冲突')
    await wrapper.get('[data-testid="leave-form"]').trigger('submit')
    await flushPromises()
    expect(requestSubmissions()).toHaveLength(0)
    expect(wrapper.get('[data-testid="leave-submit-error"]').text()).toContain('阻断性冲突')
  })

  it('blocks submission while a changed date range has no fresh preview', async () => {
    let previewCalls = 0
    let resolvePendingPreview: ((value: unknown) => void) | undefined
    resource.post.mockImplementation((path: string) => {
      if (path === '/v1/time/leave/preview') {
        previewCalls += 1
        if (previewCalls === 1) return Promise.resolve(rawPreview())
        return new Promise((resolve) => {
          resolvePendingPreview = resolve
        })
      }
      if (path === '/v1/time/leave/requests') {
        return Promise.resolve({ caseId: 45, caseNo: 'CASE-000045', applicationStatus: 'SUBMITTED' })
      }
      return Promise.reject(new Error(`Unexpected POST ${path}`))
    })

    const { wrapper } = await mountPage(['time.leave.read', 'time.leave.request'])
    await flushPromises()
    await wrapper.get('[data-testid="open-leave-request"]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-testid="leave-reason"]').setValue('合成事假事由')
    await wrapper.get('[data-testid="leave-start"]').setValue('2026-12-01')
    await wrapper.get('[data-testid="leave-end"]').setValue('2026-12-02')
    await flushPromises()

    await wrapper.get('[data-testid="leave-start"]').setValue('2026-12-03')
    await wrapper.get('[data-testid="leave-end"]').setValue('2026-12-03')
    await flushPromises()

    await wrapper.get('[data-testid="leave-form"]').trigger('submit')
    await flushPromises()

    expect(requestSubmissions()).toHaveLength(0)
    expect(wrapper.get('[data-testid="leave-submit-error"]').text()).toContain('预览已过期')
    resolvePendingPreview?.(rawPreview())
    await flushPromises()
  })

  it('shows a server 422 inline, keeps the drawer open and exposes the request id', async () => {
    resource.post.mockImplementation((path: string) => {
      if (path === '/v1/time/leave/preview') return Promise.resolve(rawPreview())
      if (path === '/v1/time/leave/requests') {
        return Promise.reject(
          new ApiError('VALIDATION_FAILED', '提交内容未通过校验', {
            status: 422,
            requestId: 'req-leave-422',
            fieldErrors: { reason: ['请假事由至少需要 5 个字。'] },
          }),
        )
      }
      return Promise.reject(new Error(`Unexpected POST ${path}`))
    })

    const { wrapper } = await mountPage(['time.leave.read', 'time.leave.request'])
    await flushPromises()
    await wrapper.get('[data-testid="open-leave-request"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="leave-reason"]').setValue('合成事假事由')
    await wrapper.get('[data-testid="leave-start"]').setValue('2026-12-01')
    await wrapper.get('[data-testid="leave-end"]').setValue('2026-12-02')
    await flushPromises()
    await wrapper.get('[data-testid="leave-form"]').trigger('submit')
    await flushPromises()

    const inline = wrapper.get('[data-testid="leave-submit-error"]')
    expect(inline.text()).toContain('请假事由至少需要 5 个字')
    expect(inline.text()).toContain('req-leave-422')
    expect(wrapper.find('[role="dialog"][aria-label="申请请假"]').exists()).toBe(true)
  })
})

describe('LeaveWorkspace request cancellation and ledger', () => {
  it('asks for confirmation before cancelling and shows a Chinese failure reason', async () => {
    resource.post.mockRejectedValue(
      new ApiError('CONFLICT', 'The leave request was not found.', {
        status: 409,
        requestId: 'req-cancel-1',
        details: { reason: 'LEAVE_REQUEST_NOT_FOUND' },
      }),
    )

    const { wrapper } = await mountPage(['time.leave.read', 'time.leave.request.cancel'])
    await flushPromises()

    await wrapper.get('[data-testid="leave-cancel-33"]').trigger('click')
    expect(wrapper.get('[data-testid="leave-cancel-dialog"]').text()).toContain('CASE-000033')
    expect(resource.post).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="leave-cancel-confirm"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/time/leave/requests/33/cancel',
      { reason: '页面撤回请假申请' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    const cancelKeys = resource.post.mock.calls
      .filter(([path]) => path === '/v1/time/leave/requests/33/cancel')
      .map(([, , options]) => (options as { idempotencyKey?: string }).idempotencyKey)
    expect(cancelKeys).toHaveLength(1)
    const error = wrapper.get('[data-testid="leave-cancel-error"]')
    expect(error.text()).toContain('撤回失败')
    expect(error.text()).toContain('未找到该请假申请')
  })

  it('renders credits, debits and the server running balance with the API as-of date', async () => {
    resource.get.mockResolvedValue(rawLedger())
    const balances: LeaveBalance[] = [
      {
        leaveTypeId: 11,
        leaveTypeCode: 'ANNUAL',
        leaveTypeName: '年假',
        unit: 'DAY',
        entitled: 10,
        used: 2,
        pending: 1,
        available: 7,
        asOf: '2026-11-25',
        standardDayMinutes: 480,
        expiresOn: null,
      },
    ]

    const wrapper = mount(LeaveLedgerPanel, { props: { balances } })
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/time/leave/ledger', { leave_type: 'ANNUAL' })
    expect(wrapper.get('[data-testid="leave-ledger-as-of"]').text()).toContain('2026-11-25')
    expect(wrapper.text()).toContain('只读')
    expect(wrapper.get('[data-testid="leave-ledger-entry-1"]').text()).toContain('发放')
    expect(wrapper.get('[data-testid="leave-ledger-entry-1"]').text()).toContain('计入')
    expect(wrapper.get('[data-testid="leave-ledger-entry-1"]').text()).toContain('10 天')
    expect(wrapper.get('[data-testid="leave-ledger-entry-2"]').text()).toContain('占用')
    expect(wrapper.get('[data-testid="leave-ledger-entry-2"]').text()).toContain('扣减')
    expect(wrapper.get('[data-testid="leave-ledger-entry-2"]').text()).toContain('-2 天')
    expect(wrapper.get('[data-testid="leave-ledger-entry-2"]').text()).toContain('8 天')
  })
})

describe('LeaveWorkspace states and capability guards', () => {
  it('renders the forbidden state without requesting leave data when read is absent', async () => {
    const { wrapper } = await mountPage(['time.leave.request.cancel'])
    await flushPromises()

    expect(wrapper.text()).toContain('无权查看请假')
    expect(resource.get).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="open-leave-request"]').exists()).toBe(false)
  })

  it('renders loading and then the empty state from real API outcomes', async () => {
    let resolveAll: (() => void) | undefined
    const gate = new Promise<void>((resolve) => {
      resolveAll = resolve
    })
    resource.get.mockImplementation(async (path: string) => {
      await gate
      return defaultGet(path)
    })

    const loading = await mountPage(['time.leave.read'])
    await flushPromises()
    expect(loading.wrapper.find('.loading-state').exists()).toBe(true)

    resolveAll?.()
    await flushPromises()
    expect(loading.wrapper.find('.loading-state').exists()).toBe(false)
    expect(loading.wrapper.text()).toContain('年假')
    loading.wrapper.unmount()

    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/time/leave/types') return Promise.resolve(rawTypes())
      if (path === '/v1/time/leave/balances') return Promise.resolve([])
      if (path === '/v1/time/leave/requests') return Promise.resolve({ items: [], total: 0 })
      return Promise.reject(new Error(`Unexpected GET ${path}`))
    })

    const empty = await mountPage(['time.leave.read'])
    await flushPromises()
    expect(empty.wrapper.text()).toContain('暂无请假数据')
  })

  it('renders the error state with the server request id when every section fails', async () => {
    resource.get.mockRejectedValue(
      new ApiError('INTERNAL', '合成请假加载失败', {
        status: 500,
        requestId: 'req-leave-load',
      }),
    )

    const { wrapper } = await mountPage(['time.leave.read'])
    await flushPromises()

    expect(wrapper.text()).toContain('请假工作台暂时无法加载')
    expect(wrapper.text()).toContain('req-leave-load')
  })

  it('renders a partial state when one section fails but balances remain available', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/time/leave/balances') {
    return Promise.resolve([
      rawBalance(),
      rawBalance({
        leaveTypeCode: 'COMP',
        leaveTypeName: '调休',
        unit: 'HOUR',
        granted: 0,
        used: 0,
        pending: 0,
        available: 0,
        grantedMinutes: 4800,
        consumedMinutes: 600,
        reservedMinutes: 120,
        availableMinutes: 4080,
        standardDayMinutes: null,
      }),
    ])
  }
      if (path === '/v1/time/leave/types') return Promise.resolve(rawTypes())
      if (path === '/v1/time/leave/requests') {
        return Promise.reject(new ApiError('INTERNAL', '合成申请列表失败', { status: 500 }))
      }
      return Promise.reject(new Error(`Unexpected GET ${path}`))
    })

    const { wrapper } = await mountPage(['time.leave.read'])
    await flushPromises()

    const partial = wrapper.get('[data-testid="leave-partial"]')
    expect(partial.text()).toContain('部分数据')
    expect(partial.text()).toContain('请假申请')
    expect(wrapper.find('[data-testid="leave-balance-ANNUAL"]').exists()).toBe(true)
  })
})
