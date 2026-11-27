import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  patch: vi.fn(),
  delete: vi.fn(),
}))

const axiosDelete = vi.hoisted(() => vi.fn())

const messages = vi.hoisted(() => ({
  success: vi.fn(),
  error: vi.fn(),
}))

const dialogs = vi.hoisted(() => ({
  prompt: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
  resourcePatch: resource.patch,
  resourceDelete: resource.delete,
}))

vi.mock('axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('axios')>()
  const mocked = {
    ...actual,
    default: { ...actual.default, delete: axiosDelete },
    delete: axiosDelete,
  }
  return mocked
})

vi.mock('element-plus', async (importOriginal) => {
  const actual = await importOriginal<typeof import('element-plus')>()
  return {
    ...actual,
    ElMessage: { success: messages.success, error: messages.error },
    ElMessageBox: { ...actual.ElMessageBox, prompt: dialogs.prompt },
  }
})

import EmployeeProfileSection from '@/modules/people/employees/components/EmployeeProfileSection.vue'

const ROW = {
  id: 61,
  employment_id: 1,
  school: '合成大学',
  degree: 'BACHELOR',
  major: '计算机科学',
  start_date: '2010-09-01',
  end_date: '2014-06-30',
}

async function mountSection(capabilities: string[] = ['people.employee.read', 'people.employee.manage']) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }],
  })
  await router.push('/')
  await router.isReady()
  const wrapper = mount(EmployeeProfileSection, {
    props: { employmentId: 1, resource: 'education' },
    global: { plugins: [pinia, router, ElementPlus] },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
  resource.delete.mockReset()
  axiosDelete.mockReset()
  messages.success.mockReset()
  messages.error.mockReset()
  dialogs.prompt.mockReset()
  dialogs.prompt.mockResolvedValue({ value: '合成重复记录，经员工确认退役' })
  resource.get.mockResolvedValue({ items: [ROW], total: 1 })
  document.body.innerHTML = ''
})

describe('employee profile retire', () => {
  it('shows the retire action only when people.employee.manage is granted', async () => {
    const reader = await mountSection(['people.employee.read'])
    expect(reader.find('[aria-label="退役教育经历 #61"]').exists()).toBe(false)

    const manager = await mountSection(['people.employee.read', 'people.employee.manage'])
    expect(manager.find('[aria-label="退役教育经历 #61"]').exists()).toBe(true)
  })

  it('sends DELETE with a reason, effective date and idempotency key, then reloads the live list', async () => {
    axiosDelete.mockResolvedValue({ data: { data: { retired: true, id: 61 } } })
    resource.get
      .mockResolvedValueOnce({ items: [ROW], total: 1 })
      .mockResolvedValueOnce({ items: [], total: 0 })

    const wrapper = await mountSection()
    await wrapper.get('[aria-label="退役教育经历 #61"]').trigger('click')
    await flushPromises()

    expect(axiosDelete).toHaveBeenCalledWith(
      '/v1/employees/1/education/61',
      expect.objectContaining({
        data: expect.objectContaining({
          reason: expect.any(String),
          effective_from: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
        }),
        headers: expect.objectContaining({
          'Idempotency-Key': expect.any(String),
        }),
      }),
    )
    expect(messages.success).toHaveBeenCalled()
    expect(resource.get).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).not.toContain('合成大学')
  })

  it('refreshes rather than pretending success when the row is already retired', async () => {
    axiosDelete.mockRejectedValue(
      new ApiError('CONFLICT', 'already retired', {
        status: 409,
        details: { reason: 'PROFILE_ALREADY_RETIRED' },
      }),
    )

    const wrapper = await mountSection()
    await wrapper.get('[aria-label="退役教育经历 #61"]').trigger('click')
    await flushPromises()

    expect(messages.error).toHaveBeenCalledWith(expect.stringContaining('已退役'))
    expect(resource.get).toHaveBeenCalledTimes(2)
  })

  it('does not send DELETE when the retire confirmation is cancelled', async () => {
    dialogs.prompt.mockRejectedValue(new Error('cancel'))
    const wrapper = await mountSection()

    await wrapper.get('[aria-label="退役教育经历 #61"]').trigger('click')
    await flushPromises()

    expect(dialogs.prompt).toHaveBeenCalledTimes(1)
    expect(axiosDelete).not.toHaveBeenCalled()
  })
})
