import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import WorkspaceView from '@/views/WorkspaceView.vue'

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
  payslips: [],
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

async function mountWorkspace(capabilities: string[] = ['iam.session.manage', 'workflow.case.read']) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSessionStore()
  session.capabilities = capabilities

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/workspace', name: 'workspace', component: { template: '<div />' } },
      { path: '/workflow/cases/:id', name: 'workflow-case-detail', component: { template: '<div />' } },
      { path: '/workflow', name: 'workflow', component: { template: '<div />' } },
      { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
    ],
  })
  await router.push('/workspace')
  await router.isReady()

  const wrapper = mount(WorkspaceView, { global: { plugins: [pinia, router, ElementPlus] } })
  await flushPromises()
  return { wrapper, router }
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.put.mockReset()
  resource.patch.mockReset()
  resource.delete.mockReset()
})

describe('workspace home', () => {
  it('loads the caller workspace and personal tasks from the real APIs', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/me/workspace') return Promise.resolve(workspacePayload)
      if (path === '/v1/tasks/my') return Promise.resolve(taskPayload)
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const { wrapper } = await mountWorkspace()

    expect(resource.get).toHaveBeenCalledWith('/v1/me/workspace')
    expect(resource.get).toHaveBeenCalledWith('/v1/tasks/my')
    expect(wrapper.text()).toContain('合成自助员工')
    expect(wrapper.text()).toContain('我的待办')
    expect(wrapper.text()).toContain('合成请假审批任务')
    expect(wrapper.find('[data-testid="workspace-quick-actions"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="self-task-link-88"]').attributes('href')).toBe('/workflow/cases/88')
  })

  it('loads the self-service payslip panel from /v1/payroll/payslips without payroll.run.read', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/me/workspace') return Promise.resolve(workspacePayload)
      if (path === '/v1/payroll/payslips') {
        return Promise.resolve({
          items: [{
            id: 501,
            run_id: 77,
            employment_id: 11,
            version: 1,
            period: '2026-10',
            pay_date: '2026-10-31',
            gross_cents: 400000,
            deductions_cents: 100000,
            net_cents: 300000,
            published_at: '2026-10-31T00:00:00Z',
          }],
          page: 1,
          page_size: 20,
          total: 1,
        })
      }
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const { wrapper } = await mountWorkspace(['iam.session.manage', 'payroll.payslip.read.self'])

    expect(resource.get).toHaveBeenCalledWith('/v1/payroll/payslips', undefined)
    expect(resource.get).not.toHaveBeenCalledWith('/v1/payroll/runs', expect.anything())
    expect(wrapper.find('[data-testid="workspace-self-payslips"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('2026-10')
    expect(wrapper.text()).toContain('¥3,000.00')
  })

  it('shows management tasks but no self-service actions when the account has no employment', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/me/workspace') {
        return Promise.resolve({
          ...workspacePayload,
          employment_linked: false,
          profile: { ...workspacePayload.profile, employmentId: null, employeeNo: '', name: '' },
          payslips: [],
        })
      }
      if (path === '/v1/tasks/my') return Promise.resolve(taskPayload)
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const { wrapper } = await mountWorkspace()

    expect(wrapper.text()).toContain('当前账号未绑定员工')
    expect(wrapper.text()).toContain('合成请假审批任务')
    expect(wrapper.find('[data-testid="workspace-start-leave"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="workspace-quick-actions"]').exists()).toBe(false)
  })

  it('does not call the task API without workflow read and explains the missing capability', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/me/workspace') return Promise.resolve(workspacePayload)
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const { wrapper } = await mountWorkspace(['iam.session.manage'])

    expect(resource.get).not.toHaveBeenCalledWith('/v1/tasks/my')
    expect(wrapper.find('[data-testid="workspace-tasks"]').exists()).toBe(false)
  })

  it('keeps the workspace visible and marks tasks as partial when only the task read fails', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/me/workspace') return Promise.resolve(workspacePayload)
      if (path === '/v1/tasks/my') {
        return Promise.reject(new ApiError('SERVICE_UNAVAILABLE', 'Unavailable.', { status: 503 }))
      }
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const { wrapper } = await mountWorkspace()

    expect(wrapper.text()).toContain('合成自助员工')
    expect(wrapper.text()).toContain('待办列表暂时无法加载')
    expect(wrapper.text()).not.toContain('{"')
  })
})
