import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { defineComponent } from 'vue'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'

const detailApi = vi.hoisted(() => ({
  getEmployee: vi.fn(),
  loadEmployeeTab: vi.fn(),
  archiveEmployee: vi.fn(),
  restoreEmployee: vi.fn(),
}))

const dialogs = vi.hoisted(() => ({
  prompt: vi.fn(),
  confirm: vi.fn(),
}))

vi.mock('@/modules/people/employees/detailApi', () => detailApi)
vi.mock('@/modules/people/employees/components/EmployeeProfileSection.vue', () => ({
  default: defineComponent({
    name: 'EmployeeProfileSection',
    props: { employmentId: Number, resource: String },
    template: '<div :data-testid="`profile-${resource}`">profile editor</div>',
  }),
}))
vi.mock('element-plus', () => ({
  ElMessageBox: {
    prompt: dialogs.prompt,
    confirm: dialogs.confirm,
  },
}))

import EmployeeDetailPage from '@/modules/people/employees/pages/EmployeeDetailPage.vue'
import type { EmployeeTabPayload } from '@/modules/people/employees/detailApi'

const ACTIVE_EMPLOYEE = {
  employment_id: 1,
  person_id: 9,
  person_name: '合成员工',
  employee_no: 'E0001',
  status: 'ACTIVE',
  employment_type: 'FULL_TIME',
  hire_date: '2026-01-02',
  valid_from: '2026-01-05',
  valid_to: '9999-12-31',
  archived_at: null,
  manager_employment_id: 2,
  current_assignment: {
    id: 11,
    org_unit_id: 10,
    position_id: 4,
    assignment_type: 'PRIMARY',
    is_primary: true,
    valid_from: '2026-01-02',
    valid_to: '9999-12-31',
  },
  person: { id: 9, legal_name: '合成员工', preferred_name: null, email: 'synthetic@example.test' },
  terms: {
    id: 21,
    version: 1,
    effective_from: '2026-01-02',
    effective_to: '9999-12-31',
    work_location: '上海',
    work_schedule: '标准工时',
    probation_end_date: '2026-04-01',
  },
}

const ARCHIVED_EMPLOYEE = {
  ...ACTIVE_EMPLOYEE,
  status: 'TERMINATED',
  archived_at: '2026-11-26T08:00:00Z',
  archive_reason: '合成归档原因',
}

function section(kind: string, label: string, rows: Record<string, unknown>[]): EmployeeTabPayload {
  return {
    sections: [
      {
        key: kind,
        kind,
        label,
        rows,
        total: rows.length,
      },
    ],
  }
}

function tabPayload(tab: string): EmployeeTabPayload {
  switch (tab) {
    case 'employment':
      return section('timeline', '任职与异动记录', [
        {
          id: 31,
          type: 'ASSIGNMENT',
          event: 'ASSIGNMENT_STARTED',
          effective_date: '2026-01-02',
          metadata: { assignment_type: 'PRIMARY' },
        },
      ])
    case 'contracts':
      return section('contracts', '合同版本', [
        {
          id: 41,
          contract_no: 'CON-2026-0041',
          contract_type: 'FIXED_TERM',
          start_date: '2026-01-02',
          end_date: '2026-12-31',
          status: 'ACTIVE',
          version: 1,
        },
      ])
    case 'credentials':
      return {
        sections: [
          {
            key: 'certificates',
            kind: 'certificates',
            label: '证照信息',
            rows: [
              {
                id: 51,
                certificate_type: 'PROFESSIONAL',
                certificate_no: 'CERT-0001',
                issuer: '合成认证机构',
                issued_on: '2026-02-01',
                expires_on: '2029-01-31',
              },
            ],
            total: 1,
          },
          { key: 'documents', kind: 'documents', label: '附件', rows: [], total: 0 },
        ],
      }
    case 'education':
      return section('education', '教育经历', [
        {
          id: 61,
          school: '合成大学',
          degree: 'BACHELOR',
          major: '计算机科学',
          start_date: '2010-09-01',
          end_date: '2014-06-30',
        },
      ])
    case 'experience':
      return section('experiences', '工作经历', [
        {
          id: 71,
          company: '合成前公司',
          title: '工程师',
          start_date: '2014-07-01',
          end_date: '2025-12-31',
          leave_reason: '职业发展',
        },
      ])
    case 'contacts':
      return section('contacts', '联系方式', [
        {
          id: 81,
          name: '合成联系人',
          relationship: 'SPOUSE',
          phone: { display: null, masked: true, profile: 'P2_SENSITIVE' },
          email: 'contact@example.test',
          is_emergency: true,
        },
      ])
    case 'changes':
      return section('changes', '变更申请', [
        { id: 91, status: 'SUBMITTED', reason: '更正合成信息', submitted_at: '2026-11-20T08:00:00Z' },
      ])
    default:
      return { sections: [] }
  }
}

async function mountPage(
  path = '/hr/employees/1',
  capabilities: string[] = [
    'people.employee.read',
    'people.employee.manage',
    'people.employee.archive',
    'people.employee.restore',
  ],
): Promise<{ wrapper: VueWrapper; router: Router }> {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/hr/employees', name: 'hr-employees', component: { template: '<div>员工列表</div>' } },
      { path: '/hr/employees/:id', name: 'employee-detail', component: { template: '<div />' } },
      { path: '/forbidden', name: 'forbidden', component: { template: '<div>无权限</div>' } },
      { path: '/login', name: 'login', component: { template: '<div>登录</div>' } },
    ],
  })
  await router.push(path)
  await router.isReady()

  const wrapper = mount(EmployeeDetailPage, {
    global: { plugins: [pinia, router] },
  })
  await flushPromises()
  return { wrapper, router }
}

function actionButton(wrapper: VueWrapper, name: string) {
  return wrapper.findAll('button').find((button) => button.text() === name)
}

beforeEach(() => {
  detailApi.getEmployee.mockReset()
  detailApi.loadEmployeeTab.mockReset()
  detailApi.archiveEmployee.mockReset()
  detailApi.restoreEmployee.mockReset()
  dialogs.prompt.mockReset()
  dialogs.confirm.mockReset()

  detailApi.getEmployee.mockResolvedValue(ACTIVE_EMPLOYEE)
  detailApi.loadEmployeeTab.mockImplementation((_id: number, tab: string) => Promise.resolve(tabPayload(tab)))
  detailApi.archiveEmployee.mockResolvedValue({ employment_id: 1, archived_at: '2026-11-26T08:00:00Z' })
  detailApi.restoreEmployee.mockResolvedValue({ employment_id: 1, archived_at: null })
})

describe('EmployeeDetailPage', () => {
  it('renders the required summary fields with Chinese status copy', async () => {
    const { wrapper } = await mountPage()

    expect(detailApi.getEmployee).toHaveBeenCalledWith(1)
    const text = wrapper.text()
    expect(text).toContain('姓名')
    expect(text).toContain('合成员工')
    expect(text).toContain('员工编号')
    expect(text).toContain('E0001')
    expect(text).toContain('部门')
    expect(text).toContain('部门 #10')
    expect(text).toContain('岗位')
    expect(text).toContain('岗位 #4')
    expect(text).toContain('在职')
    expect(text).toContain('2026年1月2日')
    expect(text).not.toContain('ACTIVE')
  })

  it('capability-guards edit, archive and restore actions', async () => {
    const { wrapper } = await mountPage('/hr/employees/1', ['people.employee.read'])

    expect(actionButton(wrapper, '编辑')).toBeUndefined()
    expect(actionButton(wrapper, '归档')).toBeUndefined()
    expect(actionButton(wrapper, '恢复')).toBeUndefined()
  })

  it('names the employee in the archive confirmation and calls the archive command', async () => {
    dialogs.prompt.mockResolvedValue({ value: '合成员工离职归档' })
    const { wrapper } = await mountPage()

    await actionButton(wrapper, '归档')!.trigger('click')
    await flushPromises()

    expect(dialogs.prompt).toHaveBeenCalledTimes(1)
    expect(String(dialogs.prompt.mock.calls[0]?.[0])).toContain('合成员工')
    expect(String(dialogs.prompt.mock.calls[0]?.[0])).toContain('E0001')
    expect(detailApi.archiveEmployee).toHaveBeenCalledWith(1, '合成员工离职归档')
  })

  it('does not archive when the confirmation is cancelled', async () => {
    dialogs.prompt.mockRejectedValue(new Error('cancel'))
    const { wrapper } = await mountPage()

    await actionButton(wrapper, '归档')!.trigger('click')
    await flushPromises()

    expect(detailApi.archiveEmployee).not.toHaveBeenCalled()
  })

  it('shows an archived banner and offers only restore for an archived employee', async () => {
    detailApi.getEmployee.mockResolvedValue(ARCHIVED_EMPLOYEE)
    dialogs.confirm.mockResolvedValue('confirm')
    const { wrapper } = await mountPage()

    expect(wrapper.text()).toContain('该员工已归档')
    expect(wrapper.text()).toContain('合成归档原因')
    expect(actionButton(wrapper, '编辑')).toBeUndefined()
    expect(actionButton(wrapper, '归档')).toBeUndefined()
    expect(actionButton(wrapper, '恢复')).toBeDefined()

    await actionButton(wrapper, '恢复')!.trigger('click')
    await flushPromises()

    expect(dialogs.confirm).toHaveBeenCalledTimes(1)
    expect(String(dialogs.confirm.mock.calls[0]?.[0])).toContain('合成员工')
    expect(detailApi.restoreEmployee).toHaveBeenCalledWith(1)
  })

  it('loads tab endpoints lazily and only for the activated tab', async () => {
    const { wrapper } = await mountPage()

    expect(detailApi.loadEmployeeTab).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="tab-employment"]').trigger('click')
    await flushPromises()
    expect(detailApi.loadEmployeeTab).toHaveBeenNthCalledWith(1, 1, 'employment')

    await wrapper.get('[data-testid="tab-contracts"]').trigger('click')
    await flushPromises()
    expect(detailApi.loadEmployeeTab).toHaveBeenNthCalledWith(2, 1, 'contracts')

    await wrapper.get('[data-testid="tab-overview"]').trigger('click')
    await flushPromises()
    expect(detailApi.loadEmployeeTab).toHaveBeenCalledTimes(2)
  })

  it('renders tab rows with Chinese enum labels and no raw codes', async () => {
    const { wrapper } = await mountPage('/hr/employees/1?tab=contracts')

    expect(detailApi.loadEmployeeTab).toHaveBeenCalledWith(1, 'contracts')
    const text = wrapper.text()
    expect(text).toContain('CON-2026-0041')
    expect(text).toContain('固定期限')
    expect(text).toContain('生效中')
    expect(text).not.toContain('FIXED_TERM')
    expect(text).not.toContain('ACTIVE')
  })

  it('isolates a failed tab so the summary and other tabs stay usable', async () => {
    detailApi.loadEmployeeTab.mockImplementation((_id: number, tab: string) => {
      if (tab === 'contracts') {
        return Promise.reject(new ApiError('INTERNAL', 'synthetic tab outage', { status: 500, requestId: 'req-tab-500' }))
      }
      return Promise.resolve(tabPayload(tab))
    })
    const { wrapper } = await mountPage()

    await wrapper.get('[data-testid="tab-contracts"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="tab-error"]').text()).toContain('请求编号')
    expect(wrapper.get('[data-testid="tab-error"]').text()).toContain('req-tab-500')
    expect(wrapper.text()).toContain('合成员工')

    await wrapper.get('[data-testid="tab-employment"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('任职开始')
  })

  it('renders no summary table for a workspace tab and leaves the single editor to its component', async () => {
    const { wrapper } = await mountPage()

    await wrapper.get('[data-testid="tab-education"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="tab-education-panel"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="tab-workspace-note"]').text()).toContain('避免同一份档案出现重复表格')
    expect(wrapper.find('[data-testid="profile-education"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-testid="profile-education"]')).toHaveLength(1)
    expect(wrapper.text()).not.toContain('合成大学')
  })

  it('renders a forbidden state inside the tab without blanking the page', async () => {
    detailApi.loadEmployeeTab.mockRejectedValue(
      new ApiError('FORBIDDEN', 'forbidden tab', { status: 403, requestId: 'req-tab-403' }),
    )
    const { wrapper } = await mountPage()

    await wrapper.get('[data-testid="tab-employment"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="tab-forbidden"]').text()).toContain('无权查看')
    expect(wrapper.text()).toContain('合成员工')
    expect(wrapper.find('[data-testid="tab-employment-panel"]').exists()).toBe(true)
  })

  it('renders 无权查看 for omitted and masked projected fields, never a fabricated value', async () => {
    const { employee_no: _employeeNo, terms: _terms, ...withoutProjectedFields } = ACTIVE_EMPLOYEE
    detailApi.getEmployee.mockResolvedValue(withoutProjectedFields)
    const { wrapper } = await mountPage()

    const summary = wrapper.get('[data-testid="employee-summary"]').text()
    expect(summary).toContain('无权查看')
    expect(summary).not.toContain('E0001')
    expect(summary).toContain('工作地点')

    await wrapper.get('[data-testid="tab-employment"]').trigger('click')
    await flushPromises()
    const timeline = wrapper.get('[data-testid="tab-employment-panel"]').text()
    expect(timeline).not.toContain('P2_SENSITIVE')
  })

  it('renders the API-driven 404 and 403 employee states without leaking a record', async () => {
    detailApi.getEmployee.mockRejectedValueOnce(
      new ApiError('NOT_FOUND', 'not found', { status: 404, requestId: 'req-404' }),
    )
    const missing = await mountPage('/hr/employees/999')

    expect(missing.wrapper.get('[data-testid="employee-not-found"]').text()).toContain('404')
    expect(missing.wrapper.text()).toContain('员工不存在或不在你的数据范围内')
    expect(missing.wrapper.text()).toContain('req-404')
    expect(missing.wrapper.text()).not.toContain('合成员工')
    expect(detailApi.loadEmployeeTab).not.toHaveBeenCalled()

    detailApi.getEmployee.mockRejectedValueOnce(
      new ApiError('FORBIDDEN', 'forbidden', { status: 403, requestId: 'req-403' }),
    )
    const forbidden = await mountPage('/hr/employees/1')

    expect(forbidden.wrapper.get('[data-testid="employee-forbidden"]').text()).toContain('无权查看')
    expect(forbidden.wrapper.text()).not.toContain('合成员工')
    expect(forbidden.wrapper.text()).not.toContain('req-403')
  })

  it('falls back an invalid tab to overview and returns to the list with filters', async () => {
    const { wrapper, router } = await mountPage('/hr/employees/1?tab=payroll&q=%E5%90%88%E6%88%90&status=ACTIVE&page=2')

    expect(wrapper.get('[data-testid="tab-overview-panel"]').text()).toContain('基本信息')
    expect(detailApi.loadEmployeeTab).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="back-to-list"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/hr/employees')
    expect(router.currentRoute.value.query).toEqual({ q: '合成', status: 'ACTIVE', page: '2' })
  })
})
