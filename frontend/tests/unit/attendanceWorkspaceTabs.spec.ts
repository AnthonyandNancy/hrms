import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { useSessionStore } from '@/stores/session'
import AttendanceWorkspacePage from '@/modules/time/attendance/pages/AttendanceWorkspacePage.vue'

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

function monthPayload(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    month: '2026-11',
    timezone: 'Asia/Shanghai',
    employment_id: 1,
    employee_name: 'E0001',
    days: [
      {
        date: '2026-11-24',
        business_date: '2026-11-24',
        weekday: 'Tuesday',
        status: 'LATE',
        employment_id: 1,
        employee_name: 'E0001',
        first_in: '2026-11-24T01:12:00Z',
        last_out: '2026-11-24T10:00:00Z',
        scheduled_minutes: 480,
        worked_minutes: 468,
        late_minutes: 12,
        early_leave_minutes: 0,
        absence_minutes: 12,
        exceptionLabel: '迟到 12 分钟',
      },
    ],
    summary: { expectedDays: 1, presentDays: 1, lateCount: 1, absentCount: 0, leaveDays: 0, remoteDays: 0 },
    exceptions: [],
    ...overrides,
  }
}

function recordPage(): Record<string, unknown> {
  return {
    items: [
      {
        employment_id: 1,
        employee_no: 'E0001',
        employee_name: '合成员工',
        business_date: '2026-11-24',
        first_in: '2026-11-24T01:12:00Z',
        last_out: '2026-11-24T10:00:00Z',
        source: 'DEVICE',
        status: 'LATE',
        late_minutes: 12,
        early_leave_minutes: 0,
        absence_minutes: 0,
        exception_label: '迟到 12 分钟',
      },
    ],
    page: 1,
    page_size: 20,
    total: 1,
    as_of: '2026-11-27',
    partial: false,
    partial_reason: null,
  }
}

function punchPage(): Record<string, unknown> {
  return {
    items: [
      {
        id: 9,
        employment_id: 1,
        punched_at_utc: '2026-11-24T01:12:00Z',
        source: 'DEVICE',
        device_id: 'DEV-SYNTH-01',
      },
    ],
    page: 1,
    page_size: 20,
    total: 1,
    as_of: '2026-11-27',
    partial: false,
    partial_reason: null,
  }
}

async function mountPage(capabilities: string[], query = ''): Promise<{ wrapper: ReturnType<typeof mount>; router: Router }> {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/attendance', name: 'attendance', component: { template: '<div />' } }],
  })
  await router.push(`/attendance${query}`)
  await router.isReady()

  const wrapper = mount(AttendanceWorkspacePage, {
    global: {
      plugins: [pinia, router],
      stubs: {
        'el-pagination': { template: '<div />' },
        'el-input': { template: '<input />' },
      },
    },
  })
  return { wrapper, router }
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
  resource.get.mockImplementation((path: string) => {
    if (path === '/v1/time/attendance') return Promise.resolve(monthPayload())
    if (path === '/v1/time/attendance/records') return Promise.resolve(recordPage())
    if (path === '/v1/time/punches') return Promise.resolve(punchPage())
    if (path === '/v1/time/attendance/rules') {
      return Promise.resolve({
        items: [
          {
            id: 1,
            version: 'TIME-RULES-2026.1',
            effective_from: '2026-01-01',
            checksum: 'a'.repeat(64),
            config: { count_break: true, overtime_after_scheduled: true, incomplete_threshold_minutes: 1 },
            is_effective: true,
          },
        ],
      })
    }
    if (path === '/v1/time/shifts') {
      return Promise.resolve({
        items: [
          {
            id: 1,
            code: 'DAY',
            name: '白班',
            start_local_time: '09:00',
            end_local_time: '18:00',
            crosses_midnight: false,
            break_minutes: 60,
            late_grace_minutes: 0,
            early_grace_minutes: 0,
            standard_hours_decimal: '8.000000',
            version: 1,
            valid_from: '2026-01-01',
            valid_to: '9999-12-31',
          },
        ],
      })
    }
    if (path === '/v1/time/shift-schedules') {
      return Promise.resolve({
        items: [
          {
            id: 3,
            employment_id: 1,
            business_date: '2026-11-24',
            shift_template_id: 1,
            shift_code: 'DAY',
            shift_name: '白班',
            start_local_time: '09:00',
            end_local_time: '18:00',
            crosses_midnight: false,
          },
        ],
        page: 1,
        page_size: 20,
        total: 1,
        as_of: '2026-11-27',
      })
    }
    if (path === '/v1/time/attendance/closings') {
      return Promise.resolve({
        items: [
          {
            id: 5,
            month: '2026-11',
            status: 'CLOSED',
            closed_by: 10,
            closed_at: '2026-11-27T01:00:00Z',
            reopened_by: null,
            reopened_at: null,
            reopen_reason: null,
          },
        ],
        page: 1,
        page_size: 20,
        total: 1,
        as_of: '2026-11-27',
      })
    }
    if (path === '/v1/time/attendance/devices') {
      return Promise.resolve({
        items: [
          {
            id: 7,
            code: 'DEV-SYNTH-01',
            name: '合成门禁',
            adapter: 'ZK-LAN',
            health: 'ONLINE',
            last_seen_at: '2026-11-27T01:00:00Z',
            active: true,
          },
        ],
        page: 1,
        page_size: 20,
        total: 1,
        as_of: '2026-11-27',
      })
    }
    return Promise.resolve({ items: [] })
  })
})

describe('M09 attendance role-aware workspace tabs', () => {
  it('declares the ten-tab type and visibleTabs gate and no TimePayrollPage dependency', () => {
    const page = readFileSync(resolve('src/modules/time/attendance/pages/AttendanceWorkspacePage.vue'), 'utf8')

    expect(page).toContain("type AttendanceTab = 'my' | 'punches' | 'calendar' | 'exceptions' | 'corrections' | 'rules' | 'shifts' | 'schedules' | 'closing' | 'devices'")
    expect(page).toContain('visibleTabs')
    expect(page).not.toContain('TimePayrollPage')
  })

  it('shows only self-service tabs for a plain employee', async () => {
    const { wrapper } = await mountPage(['time.attendance.read'], '?month=2026-11')
    await flushPromises()

    expect(wrapper.find('[data-testid="attendance-tab-my"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="attendance-tab-punches"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="attendance-tab-rules"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="attendance-tab-shifts"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="attendance-tab-closing"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="attendance-tab-devices"]').exists()).toBe(false)
  })

  it('shows management tabs only when the actor holds their read capabilities and real devices exist', async () => {
    const { wrapper } = await mountPage([
      'time.attendance.read',
      'time.punch.read',
      'time.attendance.correct.request',
      'time.attendance.manage',
      'time.attendance.reopen',
      'time.attendance.rule.read',
      'time.shift.read',
      'time.shift.manage',
      'time.shift.schedule.read',
      'time.attendance.device.read',
      'time.attendance.export',
    ], '?month=2026-11')
    await flushPromises()

    for (const tab of ['my', 'punches', 'calendar', 'exceptions', 'corrections', 'rules', 'shifts', 'schedules', 'closing', 'devices']) {
      expect(wrapper.find(`[data-testid="attendance-tab-${tab}"]`).exists(), tab).toBe(true)
    }
  })

  it('hides the devices tab when the real device list is empty', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/time/attendance') return Promise.resolve(monthPayload())
      if (path === '/v1/time/attendance/devices') return Promise.resolve({ items: [], total: 0 })
      return Promise.resolve({ items: [] })
    })

    const { wrapper } = await mountPage(['time.attendance.read', 'time.attendance.device.read', 'time.attendance.manage'], '?month=2026-11')
    await flushPromises()

    expect(wrapper.find('[data-testid="attendance-tab-devices"]').exists()).toBe(false)
  })

  it('restores an authorised active tab from the query string', async () => {
    const { wrapper } = await mountPage(
      ['time.attendance.read', 'time.attendance.rule.read'],
      '?tab=rules&month=2026-11',
    )
    await flushPromises()

    expect(wrapper.get('[data-testid="attendance-tab-rules"]').attributes('aria-current')).toBe('page')
    expect(wrapper.text()).toContain('TIME-RULES-2026.1')
  })

  it('requests both scoped records and raw punches for the punches tab', async () => {
    const { wrapper } = await mountPage(
      ['time.attendance.read', 'time.punch.read', 'time.attendance.manage'],
      '?tab=punches&month=2026-11&employee=1',
    )
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith(
      '/v1/time/attendance/records',
      expect.objectContaining({ from: '2026-11-01', to: '2026-11-30', employment_id: 1, page: 1, page_size: 20 }),
    )
    expect(resource.get).toHaveBeenCalledWith(
      '/v1/time/punches',
      expect.objectContaining({ from: '2026-11-01', to: '2026-11-30', employment_id: 1 }),
    )
    expect(wrapper.text()).toContain('考勤明细')
    expect(wrapper.text()).toContain('原始打卡')
  })

  it('downloads the server CSV through the audited export endpoint', async () => {
    const originalCreate = URL.createObjectURL
    const originalRevoke = URL.revokeObjectURL
    const createObjectURL = vi.fn(() => 'blob:attendance-export')
    const revokeObjectURL = vi.fn()
    Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: createObjectURL })
    Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: revokeObjectURL })
    const fetchMock = vi.fn(async () => new Response('employee_no,status\nE0001,LATE\n', {
      status: 200,
      headers: { 'Content-Type': 'text/csv; charset=UTF-8' },
    }))
    vi.stubGlobal('fetch', fetchMock)

    try {
      const { wrapper } = await mountPage(
        ['time.attendance.read', 'time.punch.read', 'time.attendance.manage', 'time.attendance.export'],
        '?tab=punches&month=2026-11&employee=1',
      )
      await flushPromises()
      await wrapper.get('[data-testid="attendance-export"]').trigger('click')
      await flushPromises()

      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining('/attendance/records/export?'),
        expect.objectContaining({ credentials: 'include' }),
      )
      const firstExportUrl = String((fetchMock.mock.calls as unknown as Array<[string]>)[0]?.[0] ?? '')
      expect(firstExportUrl).toContain('from=2026-11-01')
      expect(firstExportUrl).toContain('to=2026-11-30')
      expect(firstExportUrl).toContain('employment_id=1')
      expect(createObjectURL).toHaveBeenCalledTimes(1)
      expect(wrapper.text()).toContain('考勤明细 CSV 已生成并下载')
    } finally {
      vi.unstubAllGlobals()
      Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: originalCreate })
      Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: originalRevoke })
    }
  })

  it('does not call the punch endpoint without time.punch.read', async () => {
    const { wrapper } = await mountPage(
      ['time.attendance.read', 'time.attendance.manage'],
      '?tab=punches&month=2026-11&employee=1',
    )
    await flushPromises()

    expect(resource.get).not.toHaveBeenCalledWith('/v1/time/punches', expect.anything())
    expect(wrapper.find('[data-testid="attendance-punch-table"]').exists()).toBe(false)
  })

  it('renders the explicit unconfigured message rather than fabricating shift data', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/time/attendance') return Promise.resolve(monthPayload())
      if (path === '/v1/time/shifts') return Promise.resolve({ items: [], total: 0 })
      return Promise.resolve({ items: [] })
    })

    const { wrapper } = await mountPage(
      ['time.attendance.read', 'time.shift.read', 'time.shift.manage'],
      '?tab=shifts&month=2026-11',
    )
    await flushPromises()

    expect(wrapper.text()).toContain('还没有配置班次')
    expect(wrapper.find('[data-testid="attendance-shift-form"]').exists()).toBe(true)
  })
})
