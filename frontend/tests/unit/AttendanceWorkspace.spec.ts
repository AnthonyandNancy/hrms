import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import AttendanceWorkspacePage from '@/modules/time/attendance/pages/AttendanceWorkspacePage.vue'
import AttendanceCalendar from '@/modules/time/attendance/components/AttendanceCalendar.vue'
import AttendanceCorrectionDrawer from '@/modules/time/attendance/components/AttendanceCorrectionDrawer.vue'
import type { AttendanceDay } from '@/modules/time/attendance/api'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resource.get(...args),
  resourcePost: (...args: unknown[]) => resource.post(...args),
}))

function day(overrides: Partial<AttendanceDay> = {}): AttendanceDay {
  return {
    businessDate: '2026-11-02',
    status: 'PRESENT',
    employeeId: 1,
    employeeName: '合成员工',
    firstIn: '2026-11-02T01:00:00Z',
    lastOut: '2026-11-02T10:00:00Z',
    scheduledMinutes: 480,
    workedMinutes: 480,
    overtimeMinutes: 0,
    lateMinutes: 0,
    earlyLeaveMinutes: 0,
    absenceMinutes: 0,
    exceptionLabel: null,
    ruleVersion: 'TIME-RULES-2026.1',
    ...overrides,
  }
}

function rawPeriod(
  days: Record<string, unknown>[] = [rawDay()],
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    month: '2026-11',
    timezone: 'Asia/Shanghai',
    employment_id: 1,
    employee_name: '合成员工',
    days,
    ...overrides,
  }
}

function rawDay(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    business_date: '2026-11-02',
    status: 'PRESENT',
    employment_id: 1,
    employee_name: '合成员工',
    first_in: '2026-11-02T01:00:00Z',
    last_out: '2026-11-02T10:00:00Z',
    scheduled_minutes: 480,
    worked_minutes: 480,
    late_minutes: 0,
    early_leave_minutes: 0,
    absence_minutes: 0,
    ...overrides,
  }
}

async function mountPage(capabilities: string[]): Promise<{ wrapper: ReturnType<typeof mount>; router: Router }> {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/attendance', name: 'attendance', component: { template: '<div />' } },
      { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
      { path: '/login', name: 'login', component: { template: '<div />' } },
    ],
  })
  await router.push('/attendance')
  await router.isReady()

  const wrapper = mount(AttendanceWorkspacePage, {
    global: {
      plugins: [pinia, router],
      stubs: {
        'el-button': { template: '<button type="button"><slot /></button>' },
      },
    },
  })
  return { wrapper, router }
}

function daysCallCount(): number {
  return resource.get.mock.calls.filter(([path]) => path === '/v1/time/attendance').length
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
})

describe('AttendanceWorkspace calendar', () => {
  it('renders present, late, early, absent and leave markers with a legend', () => {
    const wrapper = mount(AttendanceCalendar, {
      props: {
        month: '2026-11',
        timezone: 'Asia/Shanghai',
        days: [
          day({ businessDate: '2026-11-02', status: 'PRESENT' }),
          day({ businessDate: '2026-11-03', status: 'LATE', lateMinutes: 12 }),
          day({ businessDate: '2026-11-04', status: 'EARLY', earlyLeaveMinutes: 20 }),
          day({ businessDate: '2026-11-05', status: 'ABSENT', workedMinutes: 0, absenceMinutes: 480 }),
          day({ businessDate: '2026-11-06', status: 'LEAVE', firstIn: null, lastOut: null, workedMinutes: 0 }),
        ],
        canCorrect: false,
      },
    })

    expect(wrapper.get('[data-testid="attendance-legend"]').text()).toContain('出勤')
    expect(wrapper.get('[data-testid="attendance-legend"]').text()).toContain('迟到')
    expect(wrapper.get('[data-testid="attendance-legend"]').text()).toContain('早退')
    expect(wrapper.get('[data-testid="attendance-legend"]').text()).toContain('缺勤')
    expect(wrapper.get('[data-testid="attendance-legend"]').text()).toContain('休假')

    expect(wrapper.get('[data-business-date="2026-11-02"]').text()).toContain('出勤')
    expect(wrapper.get('[data-business-date="2026-11-03"]').text()).toContain('迟到')
    expect(wrapper.get('[data-business-date="2026-11-04"]').text()).toContain('早退')
    expect(wrapper.get('[data-business-date="2026-11-05"]').text()).toContain('缺勤')
    expect(wrapper.get('[data-business-date="2026-11-06"]').text()).toContain('休假')
  })

  it('opens a day detail popover with clock-in, clock-out and server-computed minutes', async () => {
    const wrapper = mount(AttendanceCalendar, {
      props: {
        month: '2026-11',
        timezone: 'Asia/Shanghai',
        days: [day({ workedMinutes: 507 })],
        canCorrect: false,
      },
    })

    await wrapper.get('[data-business-date="2026-11-02"]').trigger('click')

    const detail = wrapper.get('[role="dialog"][aria-label="当日详情"]')
    expect(detail.text()).toContain('上班')
    expect(detail.text()).toContain('09:00')
    expect(detail.text()).toContain('下班')
    expect(detail.text()).toContain('18:00')
    expect(detail.text()).toContain('计入工时')
    expect(detail.text()).toContain('8 小时 27 分钟')
  })
})

describe('AttendanceWorkspace correction and exceptions', () => {
  it('uses the API business month, timezone and timezone-aware punch times instead of local time', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/time/attendance') {
        return Promise.resolve({
          month: '2026-11',
          timezone: 'America/New_York',
          employment_id: 1,
          employee_name: '合成员工',
          days: [
            rawDay({
              first_in: '2026-11-02T14:30:00Z',
              last_out: '2026-11-02T23:00:00Z',
              worked_minutes: 510,
            }),
            rawDay({
              business_date: '2026-11-03',
              first_in: '09:00',
              last_out: '18:00',
              worked_minutes: 480,
            }),
          ],
        })
      }
      return Promise.resolve({ items: [] })
    })

    const { wrapper } = await mountPage(['time.attendance.read'])
    await flushPromises()

    expect(wrapper.text()).toContain('业务月份：2026-11')
    expect(wrapper.text()).toContain('业务时区：America/New_York')
    await wrapper.get('[data-business-date="2026-11-02"]').trigger('click')
    expect(wrapper.get('[role="dialog"][aria-label="当日详情"]').text()).toContain('09:30')
    expect(wrapper.get('[role="dialog"][aria-label="当日详情"]').text()).toContain('18:00')
    expect(wrapper.get('[role="dialog"][aria-label="当日详情"]').text()).toContain('8 小时 30 分钟')

    await wrapper.get('[data-business-date="2026-11-03"]').trigger('click')
    const localClockDetail = wrapper.get('[role="dialog"][aria-label="当日详情"]').text()
    expect(localClockDetail).toContain('09:00')
    expect(localClockDetail).toContain('18:00')
    expect(localClockDetail).toContain('8 小时')
  })

  it('blocks a correction whose reason is shorter than the server minimum', async () => {
    resource.get.mockImplementation((path: string) =>
      Promise.resolve(path === '/v1/time/attendance' ? rawPeriod() : { items: [] }),
    )
    const { wrapper } = await mountPage(['time.attendance.read', 'time.attendance.correct.request'])
    await flushPromises()

    await wrapper.get('[data-testid="open-correction"]').trigger('click')
    await wrapper.get('[data-testid="correction-date"]').setValue('2026-11-03')
    await wrapper.get('[data-testid="correction-reason"]').setValue('太短')
    await wrapper.get('[data-testid="correction-submit"]').trigger('click')
    await wrapper.get('[data-testid="correction-form"]').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('原因至少需要 5 个字符')
    expect(resource.post).not.toHaveBeenCalled()
  })

  it('shows a server 422 inline without closing the correction drawer', async () => {
    resource.post.mockRejectedValue(
      new ApiError('VALIDATION_FAILED', '原因至少需要 5 个字符，且不能只包含空白。', {
        status: 422,
        requestId: 'req-correction-422',
        fieldErrors: { reason: ['原因至少需要 5 个字符。'] },
      }),
    )

    const wrapper = mount(AttendanceCorrectionDrawer, {
      props: {
        modelValue: true,
        businessDate: '2026-11-03',
        timezone: 'Asia/Shanghai',
        employmentId: 1,
      },
    })
    await wrapper.get('[data-testid="correction-reason"]').setValue('合成测试补卡')
    await wrapper.get('[data-testid="correction-submit"]').trigger('click')
    await wrapper.get('[data-testid="correction-form"]').trigger('submit')
    await flushPromises()

    const inline = wrapper.get('[data-testid="correction-error"]')
    expect(inline.text()).toContain('原因至少需要 5 个字符')
    expect(inline.text()).toContain('req-correction-422')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(wrapper.emitted('submitted')).toBeUndefined()
  })

  it('submits the correction payload and refetches attendance days after success', async () => {
    resource.get.mockImplementation((path: string) =>
      Promise.resolve(path === '/v1/time/attendance' ? rawPeriod() : { items: [] }),
    )
    resource.post.mockResolvedValue({
      accepted: true,
      requestNo: 'COR-000001',
      caseId: 81,
      correctionId: 91,
      businessDate: '2026-11-03',
    })

    const { wrapper } = await mountPage(['time.attendance.read', 'time.attendance.correct.request'])
    await flushPromises()
    expect(daysCallCount()).toBe(1)

    await wrapper.get('[data-testid="open-correction"]').trigger('click')
    await wrapper.get('[data-testid="correction-date"]').setValue('2026-11-03')
    await wrapper.get('[data-testid="correction-reason"]').setValue('合成测试补卡')
    await wrapper.get('[data-testid="correction-submit"]').trigger('click')
    await wrapper.get('[data-testid="correction-form"]').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/time/attendance/corrections',
      expect.objectContaining({
        date: '2026-11-03',
        correctionType: 'MISSING_PUNCH',
        reason: '合成测试补卡',
        employment_id: 1,
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(daysCallCount()).toBe(2)
    expect(wrapper.text()).toContain('补卡申请已提交：COR-000001')
  })

  it('requires a note and explicit confirmation before submitting an exception correction', async () => {
    resource.get.mockResolvedValue(rawPeriod([
      rawDay({
        business_date: '2026-11-03',
        status: 'LATE',
        late_minutes: 12,
        exception_label: '迟到 12 分钟',
      }),
    ]))
    resource.post.mockResolvedValue({
      accepted: true,
      requestNo: 'COR-000002',
      caseId: 82,
      correctionId: 92,
      businessDate: '2026-11-03',
    })

    const { wrapper } = await mountPage([
      'time.attendance.read',
      'time.attendance.manage',
    ])
    await flushPromises()

    await wrapper.get('[data-testid="exception-resolve-2026-11-03"]').trigger('click')
    await wrapper.get('[data-testid="exception-confirm-2026-11-03"]').trigger('click')
    expect(wrapper.text()).toContain('处理说明不能为空')
    expect(resource.post).not.toHaveBeenCalled()

    expect(wrapper.text()).toContain('确认提交异常修正')
    await wrapper.get('[data-testid="exception-note-2026-11-03"]').setValue('已核实设备漏卡')
    await wrapper.get('[data-testid="exception-confirm-2026-11-03"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/time/attendance/corrections',
      expect.objectContaining({
        date: '2026-11-03',
        correctionType: 'WRONG_STATUS',
        reason: '已核实设备漏卡',
        employment_id: 1,
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(daysCallCount()).toBe(2)
  })
})

describe('AttendanceWorkspace states and capability guards', () => {
  it('hides correction, resolve and rule controls when their capabilities are absent', async () => {
    resource.get.mockImplementation((path: string) =>
      Promise.resolve(
        path === '/v1/time/attendance'
          ? rawPeriod()
          : { items: [{ id: 5, business_date: '2026-11-03', exception_type: 'MISSING_PUNCH', status: 'OPEN' }] },
      ),
    )

    const readOnly = await mountPage(['time.attendance.read'])
    await flushPromises()
    expect(readOnly.wrapper.find('[data-testid="open-correction"]').exists()).toBe(false)
    expect(readOnly.wrapper.find('[data-testid="exception-resolve-5"]').exists()).toBe(false)
    expect(readOnly.wrapper.find('[data-testid="attendance-rules-link"]').exists()).toBe(false)
    readOnly.wrapper.unmount()

    const ruleManager = await mountPage(['time.attendance.read', 'time.attendance.rule.manage'])
    await flushPromises()
    expect(ruleManager.wrapper.get('[data-testid="attendance-rules-link"]').attributes('href')).toBe('/attendance/rules')
  })

  it('renders the forbidden state without requesting attendance data', async () => {
    const { wrapper } = await mountPage(['time.attendance.correct.request'])
    await flushPromises()

    expect(wrapper.text()).toContain('无权查看考勤')
    expect(resource.get).not.toHaveBeenCalled()
  })

  it('renders loading, empty, error and partial states from real API outcomes', async () => {
    let resolveDays: ((value: unknown) => void) | undefined
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/time/attendance') {
        return new Promise((resolve) => {
          resolveDays = resolve
        })
      }
      return Promise.resolve({ items: [] })
    })

    const loading = await mountPage(['time.attendance.read'])
    expect(loading.wrapper.find('.loading-state').exists()).toBe(true)
    resolveDays?.(rawPeriod())
    await flushPromises()
    loading.wrapper.unmount()

    resource.get.mockImplementation((path: string) =>
      Promise.resolve(
        path === '/v1/time/attendance'
          ? { month: '2026-11', timezone: 'Asia/Shanghai', days: [] }
          : { items: [] },
      ),
    )
    const empty = await mountPage(['time.attendance.read'])
    await flushPromises()
    expect(empty.wrapper.text()).toContain('本月暂无考勤记录')
    empty.wrapper.unmount()

    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/time/attendance') {
        return Promise.reject(new ApiError('INTERNAL', 'synthetic attendance failure', {
          status: 500,
          requestId: 'req-attendance-load',
        }))
      }
      return Promise.resolve({ items: [] })
    })
    const failed = await mountPage(['time.attendance.read'])
    await flushPromises()
    expect(failed.wrapper.text()).toContain('考勤工作台暂时无法加载')
    expect(failed.wrapper.text()).toContain('req-attendance-load')
    failed.wrapper.unmount()

    resource.get.mockImplementation((path: string) =>
      Promise.resolve(
        path === '/v1/time/attendance'
          ? rawPeriod([rawDay()], { partial: true, partial_reason: '考勤投影仍在校准中' })
          : { items: [] },
      ),
    )
    const partial = await mountPage(['time.attendance.read'])
    await flushPromises()
    expect(partial.wrapper.get('[data-testid="attendance-partial"]').text()).toContain('考勤投影仍在校准中')
  })
})
