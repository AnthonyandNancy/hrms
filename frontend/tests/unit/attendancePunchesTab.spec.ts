import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it } from 'vitest'
import { useSessionStore } from '@/stores/session'
import AttendanceRecordTable from '@/modules/time/attendance/components/AttendanceRecordTable.vue'
import type { AttendancePunch, AttendanceRecord } from '@/modules/time/attendance/api'

const record: AttendanceRecord = {
  employmentId: 1,
  employeeNo: 'E0001',
  employeeName: '合成员工',
  businessDate: '2026-11-24',
  firstIn: '2026-11-24T01:12:00Z',
  lastOut: '2026-11-24T10:00:00Z',
  source: 'DEVICE',
  status: 'LATE',
  lateMinutes: 12,
  earlyLeaveMinutes: 0,
  absenceMinutes: 0,
  exceptionLabel: '迟到 12 分钟',
}

const punch: AttendancePunch = {
  id: 9,
  employmentId: 1,
  punchedAtUtc: '2026-11-24T01:12:00Z',
  source: 'DEVICE',
  deviceId: 'DEV-SYNTH-01',
}

function mountTable(capabilities: string[], showPunches = true) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({
    status: 'authenticated',
    capabilities,
    principal: { employmentId: 1 } as never,
  })

  return mount(AttendanceRecordTable, {
    props: {
      records: [record],
      punches: [punch],
      loading: false,
      error: '',
      page: 1,
      pageSize: 20,
      total: 1,
      showPunches,
      canExport: capabilities.includes('time.attendance.export'),
      canCorrect: capabilities.includes('time.attendance.correct.request'),
    },
    global: {
      plugins: [pinia],
      stubs: { 'el-pagination': { template: '<div />' } },
    },
  })
}

describe('M09 attendance records/punches table', () => {
  it('renders scoped record fields and real punch evidence without a hash column', () => {
    const wrapper = mountTable(['time.attendance.read', 'time.punch.read', 'time.attendance.export'])

    expect(wrapper.text()).toContain('E0001')
    expect(wrapper.text()).toContain('合成员工')
    expect(wrapper.text()).toContain('迟到 12 分钟')
    expect(wrapper.get('[data-testid="attendance-punch-9"]').text()).toContain('DEV-SYNTH-01')
    expect(wrapper.text()).not.toContain('raw_payload_hash')
  })

  it('gates export and correction buttons by rendered capability', () => {
    const readOnly = mountTable(['time.attendance.read'])
    expect(readOnly.find('[data-testid="attendance-export"]').exists()).toBe(false)
    expect(readOnly.find('[data-testid="attendance-record-correct-1-2026-11-24"]').exists()).toBe(false)

    const manager = mountTable(['time.attendance.read', 'time.attendance.export', 'time.attendance.correct.request'])
    expect(manager.find('[data-testid="attendance-export"]').exists()).toBe(true)
    expect(manager.find('[data-testid="attendance-record-correct-1-2026-11-24"]').exists()).toBe(true)
  })

  it('does not show the punch table when the caller lacks time.punch.read', () => {
    const wrapper = mountTable(['time.attendance.read'], false)
    expect(wrapper.find('[data-testid="attendance-punch-table"]').exists()).toBe(false)
  })
})
