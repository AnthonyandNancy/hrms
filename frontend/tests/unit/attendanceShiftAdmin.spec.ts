import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useSessionStore } from '@/stores/session'
import AttendanceShiftAdminPage from '@/modules/time/attendance/components/AttendanceShiftAdmin.vue'
import type { ShiftTemplate } from '@/modules/time/attendance/api'

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

const shift: ShiftTemplate = {
  id: 1,
  code: 'DAY',
  name: '白班',
  startLocalTime: '09:00',
  endLocalTime: '18:00',
  crossesMidnight: false,
  breakMinutes: 60,
  lateGraceMinutes: 0,
  earlyGraceMinutes: 0,
  standardHoursDecimal: '8.000000',
  version: 1,
  validFrom: '2026-01-01',
  validTo: '9999-12-31',
  supersededAt: null,
}

function mountAdmin(capabilities: string[]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })

  return mount(AttendanceShiftAdminPage, {
    props: { shifts: [shift], loading: false, error: '' },
    global: { plugins: [pinia] },
  })
}

beforeEach(() => {
  resource.post.mockReset()
  resource.patch.mockReset()
})

describe('M09 shift administration UI', () => {
  it('posts a new shift through the real shift endpoint with an idempotency key', async () => {
    resource.post.mockResolvedValue({
      id: 2,
      code: 'NIGHT',
      name: '夜班',
      start_local_time: '21:00',
      end_local_time: '06:00',
      crosses_midnight: true,
      break_minutes: 60,
      late_grace_minutes: 0,
      early_grace_minutes: 0,
      standard_hours_decimal: '8.000000',
      version: 1,
      valid_from: '2026-11-01',
      valid_to: '9999-12-31',
    })

    const wrapper = mountAdmin(['time.shift.manage'])
    await wrapper.get('[data-testid="attendance-shift-code"]').setValue('NIGHT')
    await wrapper.get('[data-testid="attendance-shift-name"]').setValue('夜班')
    await wrapper.get('[data-testid="attendance-shift-start"]').setValue('21:00')
    await wrapper.get('[data-testid="attendance-shift-end"]').setValue('06:00')
    await wrapper.get('[data-testid="attendance-shift-crosses"]').setValue(true)
    await wrapper.get('[data-testid="attendance-shift-valid-from"]').setValue('2026-11-01')
    await wrapper.get('[data-testid="attendance-shift-form"]').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/time/shifts',
      expect.objectContaining({
        code: 'NIGHT',
        name: '夜班',
        start_local_time: '21:00',
        end_local_time: '06:00',
        crosses_midnight: true,
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.emitted('changed')?.[0]?.[0]).toContain('NIGHT')
  })

  it('does not render write controls without time.shift.manage', async () => {
    const wrapper = mountAdmin(['time.shift.read'])

    expect(wrapper.find('[data-testid="attendance-shift-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="attendance-shift-edit-1"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('DAY')
  })

  it('requires a real disable reason and sends it to the audited endpoint', async () => {
    resource.post.mockResolvedValue({
      ...shift,
      validTo: '2026-11-23',
    })

    const wrapper = mountAdmin(['time.shift.manage'])
    await wrapper.get('[data-testid="attendance-shift-disable-1"]').trigger('click')
    expect(wrapper.text()).toContain('停用原因至少需要 5 个字符')
    expect(resource.post).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="attendance-shift-disable-reason-1"]').setValue('合成停用原因')
    await wrapper.get('[data-testid="attendance-shift-disable-1"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/time/shifts/1/disable',
      { reason: '合成停用原因' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })
})
