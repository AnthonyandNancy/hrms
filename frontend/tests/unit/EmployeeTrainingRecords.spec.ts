import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import { useSessionStore } from '@/stores/session'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resource.get(...args),
  resourcePost: vi.fn(),
  resourcePatch: vi.fn(),
}))

import EmployeeTrainingRecordsPanel from '@/modules/talent/training/components/EmployeeTrainingRecordsPanel.vue'

function page(items: unknown[]): Record<string, unknown> {
  return { items, page: 1, page_size: 20, total: items.length }
}

function mountPanel(capabilities: string[] = ['training.record.read']) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSessionStore()
  session.capabilities = capabilities
  session.status = 'authenticated'
  return mount(EmployeeTrainingRecordsPanel, {
    props: { employmentId: 1 },
    global: { plugins: [pinia, ElementPlus] },
  })
}

beforeEach(() => {
  resource.get.mockReset()
})

describe('employee training records read-only entry', () => {
  it('renders the real projection and offers no manual training write', async () => {
    resource.get.mockResolvedValue(page([{
      enrollment_id: 77,
      employment_id: 1,
      session_id: 91,
      course_code: 'SAFE-01',
      course_title: '合成安全培训',
      session_starts_on: '2026-12-01',
      status: 'COMPLETED',
      attended: true,
      completion_result: 'PASS',
      score: 88,
      completed_at: '2026-12-01T09:00:00Z',
      checkin_count: 1,
      study_minutes: 150,
      feedback_count: 1,
      latest_exam_version: 2,
    }]))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-testid="employee-training-records"]').text()).toContain('合成安全培训')
    expect(wrapper.text()).toContain('150 分钟')
    expect(wrapper.text()).toContain('v2')
    expect(resource.get).toHaveBeenCalledWith(
      '/v1/training/records',
      expect.objectContaining({ employment_id: 1, as_of: expect.any(String) }),
    )
    expect(wrapper.find('[data-testid="training-record-create"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="training-record-complete"]').exists()).toBe(false)
  })

  it('hides the export action without training.record.read and makes no request', async () => {
    const wrapper = mountPanel(['people.employee.read'])
    await flushPromises()

    expect(wrapper.get('[data-testid="employee-training-forbidden"]').text()).toContain('training.record.read')
    expect(wrapper.find('[data-testid="employee-training-export"]').exists()).toBe(false)
    expect(resource.get).not.toHaveBeenCalled()
  })

  it('uses the whitelist export command with the employee scope', async () => {
    resource.get
      .mockResolvedValueOnce(page([]))
      .mockResolvedValueOnce(page([{ employment_id: 1, course_code: 'SAFE-01', study_minutes: 150 }]))

    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-testid="employee-training-export"]').trigger('click')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith(
      '/v1/training/records/export',
      expect.objectContaining({
        employment_id: 1,
        fields: expect.arrayContaining(['employment_id', 'course_code', 'study_minutes']),
      }),
    )
    expect(wrapper.get('[data-testid="employee-training-export-status"]').text()).toContain('敏感访问审计')
  })

  it('keeps a genuine empty state when no record exists', async () => {
    resource.get.mockResolvedValue(page([]))
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.text()).toContain('暂无培训记录')
    expect(wrapper.findAll('[data-testid^="employee-training-row-"]')).toHaveLength(0)
  })
})
