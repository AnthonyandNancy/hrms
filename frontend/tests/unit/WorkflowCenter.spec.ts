import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import WorkflowCenterPage from '@/modules/platform/pages/WorkflowCenterPage.vue'
import WorkflowCaseDetailPage from '@/modules/platform/workflow/pages/WorkflowCaseDetailPage.vue'
import {
  WORKFLOW_CASE_TYPE_OPTIONS,
  getWorkflowTimeline,
  listWorkflowCases,
  type WorkflowCaseQuery,
} from '@/modules/platform/api'

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

function useCapabilities(capabilities: string[]): void {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })
}

function testRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/workflow', name: 'workflow', component: { template: '<div />' } },
      { path: '/workflow/cases/:id', name: 'workflow-case-detail', component: { template: '<div />' } },
    ],
  })
}

async function mountPage(
  component: object,
  capabilities: string[],
  path = '/workflow',
  options: Record<string, unknown> = {},
) {
  useCapabilities(capabilities)
  const router = testRouter()
  await router.push(path)
  await router.isReady()
  return mount(component, {
    global: {
      plugins: [ElementPlus, router],
      stubs: {
        teleport: true,
        'el-select': true,
        'el-option': true,
      },
    },
    ...options,
  })
}

function listResponse(items: unknown[] = [], overrides: Record<string, unknown> = {}) {
  return { items, page: 1, page_size: 20, total: items.length, ...overrides }
}

function workflowCase(overrides: Record<string, unknown> = {}) {
  return {
    id: 71,
    case_no: 'CASE-20261127-000071',
    type: 'LEAVE_REQUEST',
    subject_type: 'employment',
    subject_id: 9,
    title: '十一月事假申请',
    application_status: 'SUBMITTED',
    effect_status: 'NONE',
    requester_user_id: 12,
    template_id: 3,
    template_version: 2,
    business_date: '2026-11-27',
    submitted_at: '2026-11-27T01:00:00Z',
    closed_at: null,
    current_node_key: 'manager_approval',
    is_mine: false,
    is_awaiting_me: true,
    effects: [],
    ...overrides,
  }
}

function timeline(overrides: Record<string, unknown> = {}) {
  return {
    case_id: 71,
    application: { status: 'SUBMITTED', submitted_at: '2026-11-27T01:00:00Z', closed_at: null },
    effect: { status: 'NONE', effects: [] },
    items: [
      {
        type: 'DECISION',
        decision_id: 5,
        action: 'APPROVE',
        node_key: 'manager_approval',
        actor_user_id: 15,
        comment: '同意，请按计划休假。',
        occurred_at: '2026-11-27T02:00:00Z',
      },
    ],
    ...overrides,
  }
}

function mockList(path: string, body: unknown): void {
  resource.get.mockImplementation((requested: string) => {
    if (requested === path) return Promise.resolve(body)
    if (requested === '/v1/tasks/my') return Promise.resolve(listResponse([]))
    return Promise.resolve(null)
  })
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.put.mockReset()
  resource.patch.mockReset()
  resource.delete.mockReset()
})

describe('workflow center consistency', () => {
  it('offers only case types that a real business module emits', () => {
    const values = WORKFLOW_CASE_TYPE_OPTIONS.map((option) => option.value)

    expect(new Set(values).size).toBe(values.length)
    expect(values).toEqual(expect.arrayContaining([
      'LEAVE_REQUEST',
      'ATTENDANCE_CORRECTION',
      'TRAVEL_REQUEST',
      'ONBOARDING',
      'PROBATION_CONFIRM',
      'ASSIGNMENT_CHANGE',
      'TERMS_CHANGE',
      'CONTRACT_RENEW',
      'OFFBOARD',
      'REHIRE',
      'NOTICE_APPROVAL',
      'PAYROLL_APPROVAL',
      'REQUISITION_APPROVAL',
      'OFFER_APPROVAL',
      'PERFORMANCE_APPEAL',
    ]))
    expect(values).not.toContain('TRANSFER_REQUEST')
    expect(values).not.toContain('CONTRACT_RENEWAL')
  })

  it('renders the pending, decided, requested and all views and sends real server filters', async () => {
    mockList('/v1/cases', listResponse([workflowCase()]))

    const wrapper = await mountPage(WorkflowCenterPage, ['workflow.case.read', 'workflow.case.decide'])
    await flushPromises()

    expect(wrapper.find('[data-testid="workflow-view-my_approvals"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="workflow-view-my_decided"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="workflow-view-my_requests"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="workflow-view-all"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="workflow-type-REQUISITION_APPROVAL"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="workflow-type-TRANSFER_REQUEST"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="workflow-case-row-71"]').text()).toContain('直属经理审批')

    const vm = wrapper.vm as unknown as {
      filters: { status: string; type: string; view: string }
      changeView: (view: string) => void
    }
    vm.filters.status = 'IN_REVIEW'
    vm.filters.type = 'REQUISITION_APPROVAL'
    // The "all" view legitimately reads the personal task projection first;
    // isolate the decided-view request set.
    resource.get.mockClear()
    vm.changeView('my_decided')
    await flushPromises()

    const calls = resource.get.mock.calls.map((call) => [call[0], call[1]])
    expect(calls).toContainEqual([
      '/v1/cases',
      expect.objectContaining({ status: 'IN_REVIEW', type: 'REQUISITION_APPROVAL', view: 'my_decided' }),
    ])
    // The decided view does not borrow the current pending-task projection.
    expect(calls.map((call) => call[0])).not.toContain('/v1/tasks/my')
  })

  it('lists only the caller tasks and describes an empty approval queue honestly', async () => {
    mockList('/v1/cases', listResponse([]))

    const wrapper = await mountPage(WorkflowCenterPage, ['workflow.case.read', 'workflow.case.decide'])
    await flushPromises()
    const vm = wrapper.vm as unknown as { changeView: (view: string) => void }
    vm.changeView('my_approvals')
    await flushPromises()

    expect(wrapper.find('[data-testid="workflow-empty"]').text()).toContain('当前没有指派给你的待办审批')
    expect(resource.get).toHaveBeenCalledWith('/v1/tasks/my')
    expect(resource.get).toHaveBeenCalledWith(
      '/v1/cases',
      expect.objectContaining({ view: 'my_approvals' }),
    )
  })

  it('shows timeline actor and timestamp and the Chinese current node on the detail page', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/cases/71') return Promise.resolve(workflowCase())
      if (path === '/v1/cases/71/timeline') return Promise.resolve(timeline())
      return Promise.resolve(null)
    })

    const wrapper = await mountPage(
      WorkflowCaseDetailPage,
      ['workflow.case.read', 'workflow.case.decide'],
      '/workflow/cases/71',
      { props: { caseId: '71' } },
    )
    await flushPromises()

    const timelineText = wrapper.get('[data-testid="workflow-timeline"]').text()
    expect(timelineText).toContain('审批决定')
    expect(timelineText).toContain('操作人：用户 #15')
    expect(timelineText).toContain('2026-11-27 10:00')
    expect(wrapper.text()).toContain('直属经理审批')
    expect(wrapper.text()).not.toContain('manager_approval')
    expect(wrapper.find('[data-testid="workflow-decision-actions"]').exists()).toBe(true)
  })

  it('offers a decision only to the current assignee with the decide capability', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/cases/71') return Promise.resolve(workflowCase({ is_awaiting_me: false }))
      if (path === '/v1/cases/71/timeline') return Promise.resolve(timeline())
      return Promise.resolve(null)
    })

    const nonAssignee = await mountPage(
      WorkflowCaseDetailPage,
      ['workflow.case.read', 'workflow.case.decide'],
      '/workflow/cases/71',
      { props: { caseId: '71' } },
    )
    await flushPromises()
    expect(nonAssignee.find('[data-testid="workflow-decision-actions"]').exists()).toBe(false)
    expect(nonAssignee.get('[data-testid="workflow-readonly"]').text()).toContain('没有指派给你的待办')

    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/cases/71') return Promise.resolve(workflowCase())
      if (path === '/v1/cases/71/timeline') return Promise.resolve(timeline())
      return Promise.resolve(null)
    })

    const readOnlyCapability = await mountPage(
      WorkflowCaseDetailPage,
      ['workflow.case.read'],
      '/workflow/cases/71',
      { props: { caseId: '71' } },
    )
    await flushPromises()
    expect(readOnlyCapability.find('[data-testid="workflow-decision-actions"]').exists()).toBe(false)
    expect(readOnlyCapability.get('[data-testid="workflow-readonly"]').text()).toContain('没有审批决策权限')
  })

  it('renders a decided case read-only with no decision controls', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/cases/71') {
        return Promise.resolve(workflowCase({
          application_status: 'APPROVED',
          is_awaiting_me: false,
          closed_at: '2026-11-27T03:00:00Z',
        }))
      }
      if (path === '/v1/cases/71/timeline') {
        return Promise.resolve(timeline({
          application: { status: 'APPROVED', submitted_at: '2026-11-27T01:00:00Z', closed_at: '2026-11-27T03:00:00Z' },
        }))
      }
      return Promise.resolve(null)
    })

    const wrapper = await mountPage(
      WorkflowCaseDetailPage,
      ['workflow.case.read', 'workflow.case.decide'],
      '/workflow/cases/71',
      { props: { caseId: '71' } },
    )
    await flushPromises()

    expect(wrapper.get('[data-testid="workflow-readonly"]').text()).toContain('已有最终结果')
    expect(wrapper.find('[data-testid="workflow-decision-actions"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('已通过')
  })

  it('does not request the case list without the read capability', async () => {
    const wrapper = await mountPage(WorkflowCenterPage, [])

    expect(wrapper.find('[data-testid="workflow-forbidden"]').exists()).toBe(true)
    expect(resource.get).not.toHaveBeenCalled()
  })
})

describe('workflow API client', () => {
  it('sends effect status and business date filters when a caller supplies them', async () => {
    resource.get.mockResolvedValue(listResponse([]))
    const query: WorkflowCaseQuery = {
      page: 2,
      pageSize: 50,
      view: 'my_decided',
      status: 'APPROVED',
      effectStatus: 'FAILED',
      businessDate: '2026-11-27',
      type: 'LEAVE_REQUEST',
    }

    await listWorkflowCases(query)

    expect(resource.get).toHaveBeenCalledWith('/v1/cases', {
      page: 2,
      page_size: 50,
      view: 'my_decided',
      status: 'APPROVED',
      effect_status: 'FAILED',
      business_date: '2026-11-27',
      type: 'LEAVE_REQUEST',
    })
  })

  it('rejects a timeline response whose actor id is not an integer', async () => {
    resource.get.mockResolvedValue({
      ...timeline(),
      items: [{ ...timeline().items[0], actor_user_id: 'not-a-number' }],
    })

    await expect(getWorkflowTimeline(71)).rejects.toBeInstanceOf(ApiError)
  })
})
