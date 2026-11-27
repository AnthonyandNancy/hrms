import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
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

const messages = vi.hoisted(() => ({
  success: vi.fn(),
  error: vi.fn(),
}))

const dialogs = vi.hoisted(() => ({
  confirm: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
  resourcePatch: resource.patch,
  resourceDelete: resource.delete,
}))

vi.mock('element-plus', async (importOriginal) => {
  const actual = await importOriginal<typeof import('element-plus')>()
  return {
    ...actual,
    ElMessage: { success: messages.success, error: messages.error },
    ElMessageBox: { ...actual.ElMessageBox, confirm: dialogs.confirm },
  }
})

import EmployeeProfileSection from '@/modules/people/employees/components/EmployeeProfileSection.vue'
import EmployeeFileList from '@/modules/people/employees/components/EmployeeFileList.vue'
import EmployeeChangeList from '@/modules/people/employees/components/EmployeeChangeList.vue'

const EDUCATION_ROW = {
  id: 61,
  employment_id: 1,
  school: '合成大学',
  degree: 'BACHELOR',
  major: '计算机科学',
  start_date: '2010-09-01',
  end_date: '2014-06-30',
}

const APPROVED_CHANGE = {
  id: 91,
  employment_id: 1,
  status: 'APPROVED',
  reason: '更正合成教育信息',
  diff: { change_type: 'PROFILE_CORRECTION' },
  requested_by: 10,
  submitted_at: '2026-11-20T01:00:00Z',
  decided_at: '2026-11-21T02:00:00Z',
  decided_by: 42,
  case_id: 88,
}

const DRAFT_CHANGE = {
  id: 92,
  employment_id: 1,
  status: 'DRAFT',
  reason: '补充合成联系人',
  diff: { change_type: 'CONTACT_UPDATE' },
  requested_by: 10,
  submitted_at: null,
  decided_at: null,
  decided_by: null,
  case_id: null,
}

const FILE_ROW = {
  id: 71,
  employment_id: 1,
  category: 'CONTRACT',
  original_name: '合成合同.pdf',
  mime: 'application/pdf',
  size_bytes: 2048,
  uploaded_by: 10,
  uploaded_by_name: '人事专员',
  uploaded_at: '2026-11-20T03:04:00Z',
  legal_hold: false,
}

const FILE_HIDDEN_ROW = {
  id: 72,
  employment_id: 1,
  category: 'OTHER',
  original_name: '合成附件.pdf',
  mime: 'application/pdf',
  size_bytes: 1024,
}

async function mountComponent(
  component: typeof EmployeeProfileSection | typeof EmployeeFileList | typeof EmployeeChangeList,
  props: { employmentId: number; resource?: 'education' | 'experiences' | 'contacts' | 'certificates' },
  capabilities: string[] = ['people.employee.read', 'people.employee.manage', 'people.document.read', 'people.document.download', 'workflow.case.read'],
): Promise<VueWrapper> {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/cases/:id', name: 'case-detail', component: { template: '<div />' } },
      { path: '/', name: 'home', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  await router.isReady()
  const wrapper = mount(component, {
    props,
    global: { plugins: [pinia, router, ElementPlus] },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

function buttonByText(wrapper: VueWrapper, text: string) {
  return wrapper.findAll('button').find((button) => button.text().trim() === text)
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
  resource.delete.mockReset()
  messages.success.mockReset()
  messages.error.mockReset()
  dialogs.confirm.mockReset()
  document.body.innerHTML = ''
})

describe('EmployeeProfileSection', () => {
  it('lists sub-profile rows and renders hidden or masked fields as 无权查看', async () => {
    resource.get.mockResolvedValue({
      items: [
        EDUCATION_ROW,
        {
          id: 62,
          degree: 'BACHELOR',
          start_date: '2015-09-01',
          school: { display: null, masked: true, profile: 'P2_SENSITIVE' },
        },
      ],
      total: 2,
    })

    const wrapper = await mountComponent(EmployeeProfileSection, { employmentId: 1, resource: 'education' })

    expect(resource.get).toHaveBeenCalledWith('/v1/employees/1/education')
    const text = wrapper.text()
    expect(text).toContain('合成大学')
    expect(text).toContain('本科')
    expect(text).not.toContain('BACHELOR')
    expect(text.match(/无权查看/g)?.length ?? 0).toBeGreaterThan(0)
    expect(text).not.toContain('P2_SENSITIVE')
  })

  it('creates a new education row with effective dates and reloads the real projection', async () => {
    resource.get.mockResolvedValueOnce({ items: [EDUCATION_ROW], total: 1 })
    resource.post.mockResolvedValue({ id: 63 })
    resource.get.mockResolvedValueOnce({ items: [EDUCATION_ROW, { id: 63, school: '合成理工大学', degree: 'MASTER', start_date: '2020-09-01' }], total: 2 })

    const wrapper = await mountComponent(EmployeeProfileSection, { employmentId: 1, resource: 'education' })

    await buttonByText(wrapper, '新增教育经历')!.trigger('click')
    await flushPromises()

    await wrapper.get('[data-testid="profile-field-school"]').setValue('合成理工大学')
    await wrapper.get('[data-testid="profile-field-degree"]').setValue('MASTER')
    await wrapper.get('[data-testid="profile-field-start_date"]').setValue('2020-09-01')
    await buttonByText(wrapper, '保存')!.trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/employees/1/education',
      expect.objectContaining({
        school: '合成理工大学',
        degree: 'MASTER',
        start_date: '2020-09-01',
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('合成理工大学')
  })

  it('edits an existing row through PATCH and keeps the list on a field validation error', async () => {
    resource.get.mockResolvedValue({ items: [EDUCATION_ROW], total: 1 })
    resource.patch.mockRejectedValue(
      new ApiError('VALIDATION_FAILED', '提交内容未通过校验', {
        status: 422,
        fieldErrors: { school: ['学校名称过长'] },
      }),
    )

    const wrapper = await mountComponent(EmployeeProfileSection, { employmentId: 1, resource: 'education' })
    const edit = wrapper.findAll('button').find((button) => button.text().trim() === '编辑')
    await edit!.trigger('click')
    await flushPromises()

    await wrapper.get('[data-testid="profile-field-school"]').setValue('合成超长大学')
    await buttonByText(wrapper, '保存')!.trigger('click')
    await flushPromises()

    expect(resource.patch).toHaveBeenCalledWith(
      '/v1/employees/1/education/61',
      expect.objectContaining({ school: '合成超长大学' }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.get('[data-testid="profile-form-error"]').text()).toContain('提交内容未通过校验')
    expect(wrapper.text()).toContain('学校名称过长')
  })

  it('shows an isolated forbidden state and hides mutating controls for read-only callers', async () => {
    resource.get.mockRejectedValue(new ApiError('FORBIDDEN', 'forbidden', { status: 403 }))

    const wrapper = await mountComponent(
      EmployeeProfileSection,
      { employmentId: 1, resource: 'contacts' },
      ['people.employee.read'],
    )

    expect(wrapper.get('[data-testid="profile-forbidden"]').text()).toContain('无权查看')
    expect(buttonByText(wrapper, '新增联系人')).toBeUndefined()
  })
})

describe('EmployeeFileList', () => {
  it('shows filename, size, uploader and uploaded time, and marks omitted metadata as 无权查看', async () => {
    resource.get.mockResolvedValue({ items: [FILE_ROW, FILE_HIDDEN_ROW], total: 2 })

    const wrapper = await mountComponent(EmployeeFileList, { employmentId: 1 })

    const text = wrapper.text()
    expect(text).toContain('合成合同.pdf')
    expect(text).toContain('2.0 KB')
    expect(text).toContain('人事专员')
    expect(text).toContain('2026年11月20日 03:04')
    expect(text).toContain('无权查看')
  })

  it('rejects an oversized file client-side with Chinese copy and never calls the server', async () => {
    resource.get.mockResolvedValue({ items: [], total: 0 })
    const wrapper = await mountComponent(EmployeeFileList, { employmentId: 1 })
    const input = wrapper.get('[data-testid="file-upload-input"]')
    const oversized = new File(['x'], 'synthetic.pdf', { type: 'application/pdf' })
    Object.defineProperty(oversized, 'size', { value: 21 * 1024 * 1024 })
    Object.defineProperty(input.element, 'files', { value: [oversized], configurable: true })

    await input.trigger('change')
    await wrapper.get('[data-testid="file-upload-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="file-upload-error"]').text()).toContain('20 MB')
    expect(resource.post).not.toHaveBeenCalled()
  })

  it('rejects an unsupported file type client-side with Chinese copy', async () => {
    resource.get.mockResolvedValue({ items: [], total: 0 })
    const wrapper = await mountComponent(EmployeeFileList, { employmentId: 1 })
    const input = wrapper.get('[data-testid="file-upload-input"]')
    const unsupported = new File(['x'], 'synthetic.exe', { type: 'application/x-msdownload' })
    Object.defineProperty(input.element, 'files', { value: [unsupported], configurable: true })

    await input.trigger('change')
    await wrapper.get('[data-testid="file-upload-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="file-upload-error"]').text()).toContain('不支持该文件类型')
    expect(resource.post).not.toHaveBeenCalled()
  })

  it('downloads only through the URL returned by the signed-download API', async () => {
    resource.get
      .mockResolvedValueOnce({ items: [FILE_ROW], total: 1 })
      .mockResolvedValueOnce({
        document_id: 71,
        url: 'https://files.example.test/synthetic-signed?signature=abc',
        expires_in_minutes: 5,
      })
    const createElement = vi.spyOn(document, 'createElement')
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

    const wrapper = await mountComponent(EmployeeFileList, { employmentId: 1 })
    await wrapper.get('[data-testid="file-download-71"]').trigger('click')
    await flushPromises()

    expect(resource.get).toHaveBeenLastCalledWith('/v1/documents/71/signed-download')
    const anchor = createElement.mock.results
      .map((result) => result.value)
      .find((element): element is HTMLAnchorElement => element instanceof HTMLAnchorElement)
    expect(anchor?.getAttribute('href')).toBe('https://files.example.test/synthetic-signed?signature=abc')
    expect(wrapper.text()).not.toContain('/documents/71/download')
  })

  it('asks for confirmation before deleting and reports capability-restricted controls', async () => {
    resource.get.mockResolvedValue({ items: [FILE_ROW], total: 1 })
    resource.delete.mockResolvedValue({ deleted: true })
    dialogs.confirm.mockResolvedValue('confirm')

    const wrapper = await mountComponent(EmployeeFileList, { employmentId: 1 })
    await wrapper.get('[data-testid="file-delete-71"]').trigger('click')
    await flushPromises()

    expect(dialogs.confirm).toHaveBeenCalledTimes(1)
    expect(String(dialogs.confirm.mock.calls[0]?.[0])).toContain('合成合同.pdf')
    expect(resource.delete).toHaveBeenCalledWith('/v1/documents/71', expect.objectContaining({ idempotencyKey: expect.any(String) }))

    wrapper.unmount()
    resource.get.mockResolvedValue({ items: [FILE_ROW], total: 1 })
    const readOnly = await mountComponent(EmployeeFileList, { employmentId: 1 }, ['people.document.read'])
    expect(readOnly.find('[data-testid="file-download-71"]').exists()).toBe(false)
    expect(readOnly.find('[data-testid="file-delete-71"]').exists()).toBe(false)
  })
})

describe('EmployeeChangeList', () => {
  it('shows status timeline, Chinese status and approver decision when present', async () => {
    resource.get.mockResolvedValue({ items: [APPROVED_CHANGE, DRAFT_CHANGE], total: 2 })

    const wrapper = await mountComponent(EmployeeChangeList, { employmentId: 1 })

    const text = wrapper.text()
    expect(text).toContain('已通过')
    expect(text).toContain('档案信息更正')
    expect(text).not.toContain('APPROVED')
    expect(text).toContain('审批人：用户 #42')
    expect(text).toContain('2026年11月21日 02:00')
    expect(wrapper.get('[data-testid="change-timeline"]').text()).toContain('审批通过')
    expect(wrapper.get('[data-testid="change-decision"]').text()).toContain('审批结果：已通过')

    await wrapper.findAll('button').find((button) => button.text().trim() === '查看' && button.attributes('aria-label')?.includes('#92'))!.trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="change-detail"]').text()).toContain('草稿')
    expect(wrapper.find('[data-testid="change-detail-edit"]').exists()).toBe(true)
  })

  it('creates a draft, then submits it and never offers edit after submit', async () => {
    resource.get.mockResolvedValueOnce({ items: [DRAFT_CHANGE], total: 1 })
    resource.post.mockResolvedValueOnce({ id: 93 })
    resource.get.mockResolvedValueOnce({ items: [DRAFT_CHANGE, { ...DRAFT_CHANGE, id: 93, reason: '合成新变更草稿' }], total: 2 })

    const wrapper = await mountComponent(EmployeeChangeList, { employmentId: 1 })

    await buttonByText(wrapper, '新建变更申请')!.trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="change-form-reason"]').setValue('合成新变更草稿')
    await buttonByText(wrapper, '保存')!.trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/employees/1/change_requests',
      expect.objectContaining({
        reason: '合成新变更草稿',
        diff: expect.objectContaining({ change_type: 'PROFILE_CORRECTION' }),
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )

    await wrapper.get('[data-testid="change-submit-92"]').trigger('click')
    await flushPromises()
    expect(resource.post).toHaveBeenCalledWith(
      '/v1/employees/1/change_requests/92/submit',
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('shows only 无权查看 for omitted decision fields and hides write controls without the capability', async () => {
    resource.get.mockResolvedValue({
      items: [
        {
          id: 94,
          status: 'APPROVED',
          reason: '合成隐藏字段',
          diff: { change_type: 'OTHER' },
          requested_by: 10,
          submitted_at: '2026-11-20T01:00:00Z',
        },
      ],
      total: 1,
    })

    const wrapper = await mountComponent(EmployeeChangeList, { employmentId: 1 }, ['people.employee.read'])

    expect(wrapper.get('[data-testid="change-detail"]').text()).toContain('审批人：无权查看')
    expect(wrapper.get('[data-testid="change-detail"]').text()).toContain('决定时间：无权查看')
    expect(buttonByText(wrapper, '新建变更申请')).toBeUndefined()
    expect(wrapper.findAll('button').some((button) => button.text().trim() === '提交')).toBe(false)
  })

  it('renders a forbidden state without blanking the page container', async () => {
    resource.get.mockRejectedValue(new ApiError('FORBIDDEN', 'forbidden', { status: 403 }))

    const wrapper = await mountComponent(EmployeeChangeList, { employmentId: 1 })

    expect(wrapper.get('[data-testid="change-forbidden"]').text()).toContain('无权查看')
    expect(wrapper.find('[data-testid="employee-change-list"]').exists()).toBe(true)
  })
})
