import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  patch: vi.fn(),
  delete: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
  resourcePut: resource.put,
  resourcePatch: resource.patch,
  resourceDelete: resource.delete,
}))

import SelfServicePage from '@/modules/platform/self-service/pages/SelfServicePage.vue'

const workspacePayload = {
  employment_linked: true,
  profile: {
    employmentId: 11,
    employeeNo: 'SELF-0011',
    name: '合成自助员工',
    initials: '合成',
    jobTitle: '合成运营专员',
    orgName: '合成数字产品部',
    managerName: '合成直属经理',
    workLocation: '合成上海办公点',
    email: 'self.service@example.test',
    phone: { display: null, masked: true, profile: 'P1_INTERNAL', revealable: false, exportable: false },
  },
  contract: null,
  businessDate: '2026-11-23',
  attendanceSummary: {
    expectedDays: 20,
    presentDays: 19,
    lateCount: 1,
    absentCount: 0,
    leaveDays: 0,
    remoteDays: 0,
  },
  attendanceMonth: '2026-11',
  attendanceException: null,
  leaveBalances: [],
  myRequests: [],
  payslips: [
    {
      id: 31,
      period: '2026-10',
      payDate: '2026-11-05',
      netCents: 123456,
      state: 'PUBLISHED',
    },
  ],
  notices: [],
  freshness: '2026-11-23T06:00:00Z',
}

const taskPayload = {
  items: [
    {
      task_id: 61,
      case_id: 88,
      case_no: 'CASE-SELF-0088',
      title: '合成请假审批任务',
      node_key: 'manager_approval',
      application_status: 'SUBMITTED',
      effect_status: 'NONE',
      due_at: '2026-11-26T06:00:00Z',
      sla_state: 'ON_TRACK',
    },
  ],
  page: 1,
  page_size: 20,
  total: 1,
}

const ALL_CAPABILITIES = [
  'iam.session.manage',
  'workflow.case.read',
  'time.attendance.read',
  'time.leave.read',
  'time.overtime.read',
  'payroll.payslip.read.self',
  'payroll.run.read',
  'training.program.read',
]

const ALL_PAGES = [
  { pageCode: 'attendance', path: '/attendance' },
  { pageCode: 'leave', path: '/leave' },
  { pageCode: 'overtime', path: '/overtime' },
  { pageCode: 'payroll-payslips', path: '/payroll/payslips' },
  { pageCode: 'training', path: '/training' },
  { pageCode: 'workflow', path: '/workflow' },
]

async function mountPage(
  capabilities: string[] = ALL_CAPABILITIES,
  pages = ALL_PAGES,
  includeWorkflowDetail = true,
) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSessionStore()
  session.capabilities = capabilities
  session.pages = pages

  const routes: RouteRecordRaw[] = [
    { path: '/self-service', name: 'self-service', component: { template: '<div />' } },
    { path: '/attendance', name: 'attendance', component: { template: '<div />' } },
    { path: '/leave', name: 'leave', component: { template: '<div />' } },
    { path: '/overtime', name: 'overtime', component: { template: '<div />' } },
    { path: '/payroll/payslips', name: 'payroll-payslips', component: { template: '<div />' } },
    { path: '/training', name: 'training', component: { template: '<div />' } },
    ...(includeWorkflowDetail
      ? [{ path: '/workflow/cases/:id', name: 'workflow-case-detail', component: { template: '<div />' } }]
      : [{ path: '/cases/:id', name: 'case-detail', component: { template: '<div />' } }]),
    { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
    { path: '/:pathMatch(.*)*', name: 'catchall', component: { template: '<div />' } },
  ]
  const router = createRouter({ history: createMemoryHistory(), routes })
  await router.push('/self-service')
  await router.isReady()

  const wrapper = mount(SelfServicePage, {
    global: { plugins: [pinia, router, ElementPlus] },
  })
  return { wrapper, router }
}

function mockWorkspaceAndTasks(workspace: unknown = workspacePayload, tasks: unknown = taskPayload): void {
  resource.get.mockImplementation((path: string) => {
    if (path === '/v1/me/workspace') return Promise.resolve(workspace)
    if (path === '/v1/tasks/my') return Promise.resolve(tasks)
    return Promise.reject(new Error(`Unexpected path ${path}`))
  })
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.put.mockReset()
  resource.patch.mockReset()
  resource.delete.mockReset()
})

describe('self-service portal', () => {
  it('renders only the caller profile fields, shows 无权查看 for withheld fields, and links self tasks to their case', async () => {
    mockWorkspaceAndTasks({
      ...workspacePayload,
      profile: {
        employmentId: 11,
        employeeNo: 'SELF-0011',
        name: '合成自助员工',
        initials: '合成',
        jobTitle: '合成运营专员',
        orgName: '合成数字产品部',
        phone: { display: null, masked: true, profile: 'P1_INTERNAL', revealable: false, exportable: false },
      },
    })

    const { wrapper } = await mountPage(ALL_CAPABILITIES, ALL_PAGES, false)
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/me/workspace')
    expect(resource.get).toHaveBeenCalledWith('/v1/tasks/my')

    const text = wrapper.text()
    expect(text).toContain('我的档案')
    expect(text).toContain('合成自助员工')
    expect(text).toContain('SELF-0011')
    expect(text).toContain('合成运营专员')
    expect(text).toContain('合成数字产品部')
    expect(text).toContain('无权查看')

    // Fields the projection omitted must never be blank, inferred or guessed.
    expect(text).not.toContain('基本工资')
    expect(text).not.toContain('身份证')
    expect(text).not.toContain('银行卡')
    expect(wrapper.find('[data-profile-field="managerName"]').text()).toContain('无权查看')
    expect(wrapper.find('[data-profile-field="email"]').text()).toContain('无权查看')
    expect(wrapper.find('[data-profile-field="phone"]').text()).toContain('无权查看')

    expect(wrapper.text()).toContain('我的待办')
    expect(wrapper.text()).toContain('合成请假审批任务')
    expect(wrapper.text()).toContain('CASE-SELF-0088')
    expect(wrapper.text()).toContain('2026-11-26')
    expect(wrapper.get('[data-testid="self-task-link-88"]').attributes('href')).toBe('/cases/88')
    expect(wrapper.text()).not.toContain('{"')
  })

  it('prefers the granted workflow detail page for task links', async () => {
    mockWorkspaceAndTasks()
    const detailPages = [...ALL_PAGES, { pageCode: 'workflow-case-detail', path: '/workflow/cases/:id' }]
    const { wrapper } = await mountPage(ALL_CAPABILITIES, detailPages)
    await flushPromises()

    expect(wrapper.get('[data-testid="self-task-link-88"]').attributes('href')).toBe('/workflow/cases/88')
  })

  it('shows the latest own payslips as facts without inventing an amount the server omitted', async () => {
    mockWorkspaceAndTasks({
      ...workspacePayload,
      payslips: [
        { id: 31, period: '2026-10', payDate: '2026-11-05', state: 'PUBLISHED' },
      ],
    })

    const { wrapper } = await mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('2026年10月')
    expect(wrapper.text()).toContain('无权查看金额')
    expect(wrapper.text()).not.toContain('¥0.00')
  })

  it('hides entries the caller has no capability for and never calls the task API without workflow read', async () => {
    mockWorkspaceAndTasks(workspacePayload, taskPayload)

    const { wrapper } = await mountPage(
      ['iam.session.manage', 'time.attendance.read'],
      [{ pageCode: 'attendance', path: '/attendance' }],
    )
    await flushPromises()

    expect(wrapper.find('[data-testid="self-entry-attendance"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="self-entry-leave"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="self-entry-overtime"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="self-entry-training"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="self-entry-payslips"]').exists()).toBe(false)
    expect(resource.get).not.toHaveBeenCalledWith('/v1/tasks/my')
    expect(wrapper.find('[data-testid="tasks-unavailable"]').text()).toContain('无权查看待办')
  })

  it('disables the payslip page entry with a Chinese reason when the route capability is missing', async () => {
    mockWorkspaceAndTasks()

    const { wrapper } = await mountPage(
      ALL_CAPABILITIES.filter((capability) => capability !== 'payroll.run.read'),
    )
    await flushPromises()

    const entry = wrapper.get('[data-testid="self-entry-payslips"]')
    expect(entry.text()).toContain('我的工资条')
    expect(entry.text()).toContain('工资条页面仅对薪酬角色开放')
    expect(entry.find('a').exists()).toBe(false)
  })

  it('renders a loading state before both caller-scoped reads settle', async () => {
    let resolveWorkspace: ((value: unknown) => void) | undefined
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/me/workspace') {
        return new Promise((resolve) => {
          resolveWorkspace = resolve
        })
      }
      if (path === '/v1/tasks/my') return new Promise(() => undefined)
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const { wrapper } = await mountPage()
    await wrapper.vm.$nextTick()

    expect(wrapper.text()).toContain('正在加载员工自助数据')
    resolveWorkspace?.(workspacePayload)
  })

  it('renders an honest empty state when the caller has no employee record', async () => {
    mockWorkspaceAndTasks({
      ...workspacePayload,
      employment_linked: false,
      profile: {
        employmentId: null,
        employeeNo: '',
        name: '',
        initials: '—',
        jobTitle: null,
        orgName: null,
        managerName: null,
        workLocation: null,
        email: null,
        phone: null,
      },
      payslips: [],
    }, { items: [], page: 1, page_size: 20, total: 0 })

    const { wrapper } = await mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('当前账号未绑定员工')
    expect(wrapper.text()).toContain('只展示本人数据')
    expect(wrapper.text()).not.toContain('合成自助员工')
    expect(wrapper.text()).not.toContain('系统管理')
  })

  it('renders a forbidden state for a 403 projection instead of a dead page', async () => {
    resource.get.mockRejectedValue(
      new ApiError('FORBIDDEN', 'Forbidden.', { status: 403, requestId: 'req-self-forbidden' }),
    )

    const { wrapper } = await mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('无权查看员工自助门户')
    expect(wrapper.text()).toContain('req-self-forbidden')
    expect(wrapper.find('[data-testid="self-service-retry"]').exists()).toBe(false)
  })

  it('renders an error state with the request id when the projection fails', async () => {
    resource.get.mockRejectedValue(
      new ApiError('INTERNAL', 'Internal error', { status: 500, requestId: 'req-self-500' }),
    )

    const { wrapper } = await mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('员工自助门户暂时无法加载')
    expect(wrapper.text()).toContain('req-self-500')
    expect(wrapper.find('[data-testid="self-service-retry"]').exists()).toBe(true)
  })

  it('shows the profile as partial when only the task read fails', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/me/workspace') return Promise.resolve(workspacePayload)
      if (path === '/v1/tasks/my') {
        return Promise.reject(new ApiError('SERVICE_UNAVAILABLE', 'Unavailable.', { status: 503 }))
      }
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const { wrapper } = await mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('合成自助员工')
    expect(wrapper.text()).toContain('部分数据暂不可用')
    expect(wrapper.text()).toContain('待办列表暂时无法加载')
    expect(wrapper.text()).not.toContain('{"')
  })
})
