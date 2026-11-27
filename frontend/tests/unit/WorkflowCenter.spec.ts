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
  getWorkflowTimeline,
  listWorkflowCases,
  type WorkflowCaseQuery,
} from '@/modules/platform/workflow/api'

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
    payload: { reason: 'Synthetic family visit', amount_minor: 125000 },
    application_status: 'SUBMITTED',
    effect_status: 'NONE',
    requester_user_id: 12,
    requester_display_name: 'demo.requester',
    current_assignee_user_id: 10,
    current_assignee_display_name: 'demo.approver',
    decided_by_user_id: null,
    decided_by_display_name: null,
    template_id: 3,
    template_version: 2,
    business_date: '2026-11-27',
    submitted_at: '2026-11-27T01:00:00Z',
    closed_at: null,
    current_node_key: 'manager_approval',
    is_mine: false,
    is_awaiting_me: true,
    decision_context: {
      is_awaiting_me: true,
      can_admin_override: true,
      requires_reason: false,
    },
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
        actor_display_name: 'demo.decider',
        decided_by_display_name: 'demo.decider',
        on_behalf_of_user_id: null,
        on_behalf_of_display_name: null,
        delegation_id: null,
        admin_override: false,
        reason: null,
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

describe('workflow center four views', () => {
  it('defaults to my pending work and exposes only the final business views', async () => {
    mockList('/v1/cases', listResponse([workflowCase()]))

    const wrapper = await mountPage(WorkflowCenterPage, ['workflow.case.read', 'workflow.case.admin'])
    await flushPromises()

    expect(wrapper.find('[data-testid="workflow-view-my_approvals"]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.find('[data-testid="workflow-view-my_approvals"]').text()).toBe('我的待办')
    expect(wrapper.text()).toContain('我发起的')
    expect(wrapper.text()).toContain('我审批的')
    expect(wrapper.text()).toContain('抄送我的')
    expect(wrapper.text()).toContain('模板设置')
    expect(wrapper.text()).not.toContain('全部可见')
    expect(wrapper.find('[data-testid="workflow-view-all"]').exists()).toBe(false)
    expect(resource.get).toHaveBeenCalledWith('/v1/cases', expect.objectContaining({ view: 'my_approvals' }))
    expect(wrapper.find('[data-testid="workflow-case-row-71"]').text()).toContain('demo.requester')
    expect(wrapper.find('[data-testid="workflow-case-row-71"]').text()).toContain('demo.approver')
  })

  it('does not request /v1/tasks/my for the decided or requested views', async () => {
    mockList('/v1/cases', listResponse([workflowCase()]))

    const wrapper = await mountPage(WorkflowCenterPage, ['workflow.case.read'])
    await flushPromises()
    const vm = wrapper.vm as unknown as {
      filters: { status: string; type: string; view: string }
      changeView: (view: string) => void
    }
    vm.filters.status = 'APPROVED'
    vm.filters.type = 'LEAVE_REQUEST'
    resource.get.mockClear()
    vm.changeView('my_decided')
    await flushPromises()

    const calls = resource.get.mock.calls.map((call) => [call[0], call[1]])
    expect(calls).toContainEqual([
      '/v1/cases',
      expect.objectContaining({ status: 'APPROVED', type: 'LEAVE_REQUEST', view: 'my_decided' }),
    ])
    expect(calls.map((call) => call[0])).not.toContain('/v1/tasks/my')
  })

  it('renders template versions for readers without an admin create button', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/workflow/templates') {
        return Promise.resolve(listResponse([
          {
            id: 1,
            code: 'talent.offer',
            name: 'Offer 审批',
            version: 1,
            status: 'PUBLISHED',
            node_count: 3,
            effect_count: 1,
            definition: { nodes: [], effects: [] },
            created_at: '2026-11-27T01:00:00Z',
          },
        ]))
      }
      if (path === '/v1/cases') return Promise.resolve(listResponse([]))
      if (path === '/v1/tasks/my') return Promise.resolve(listResponse([]))
      return Promise.resolve(null)
    })

    const wrapper = await mountPage(WorkflowCenterPage, ['workflow.case.read'])
    await flushPromises()
    await wrapper.get('[data-testid="workflow-view-templates"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="workflow-templates-panel"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="workflow-template-version"]').text()).toContain('Offer 审批')
    expect(wrapper.find('[data-testid="workflow-template-create"]').exists()).toBe(false)
    // Switching to templates does not read the case list with a fake view.
    expect(resource.get).not.toHaveBeenCalledWith('/v1/cases', expect.objectContaining({ view: 'templates' }))
  })

  it('shows the admin create control only when workflow.case.admin is present', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/workflow/templates') {
        return Promise.resolve(listResponse([{
          id: 1,
          code: 'talent.offer',
          name: 'Offer 审批',
          version: 1,
          status: 'PUBLISHED',
          definition: {},
        }]))
      }
      return Promise.resolve(listResponse([]))
    })

    const wrapper = await mountPage(WorkflowCenterPage, ['workflow.case.read', 'workflow.case.admin'])
    await flushPromises()
    await wrapper.get('[data-testid="workflow-view-templates"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="workflow-template-create"]').exists()).toBe(true)
  })

  it('does not request the case list without workflow.case.read', async () => {
    const wrapper = await mountPage(WorkflowCenterPage, [])

    expect(wrapper.find('[data-testid="workflow-forbidden"]').exists()).toBe(true)
    expect(resource.get).not.toHaveBeenCalled()
  })
})

describe('workflow case detail', () => {
  it('shows server display names, decision context and the Chinese current node', async () => {
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

    expect(wrapper.find('[data-testid="workflow-case-summary"]').text()).toContain('demo.requester')
    expect(wrapper.find('[data-testid="workflow-case-summary"]').text()).toContain('demo.approver')
    expect(wrapper.find('[data-testid="workflow-decision-context"]').exists()).toBe(true)
    const timelineText = wrapper.find('[data-testid="workflow-timeline"]').text()
    expect(timelineText).toContain('审批决定')
    expect(timelineText).toContain('操作人：demo.decider')
    expect(timelineText).toContain('2026-11-27 10:00')
    expect(wrapper.text()).toContain('直属经理审批')
    expect(wrapper.text()).not.toContain('manager_approval')
    expect(wrapper.text()).not.toContain('SHOULD-NOT-LEAK')
  })

  it('offers a decision only for the server-confirmed assignee with decide capability', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/cases/71') {
        return Promise.resolve(workflowCase({
          is_awaiting_me: false,
          decision_context: { is_awaiting_me: false, can_admin_override: false, requires_reason: false },
        }))
      }
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
    expect(nonAssignee.find('[data-testid="workflow-decision-context"]').exists()).toBe(false)
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
    expect(readOnlyCapability.find('[data-testid="workflow-decision-context"]').exists()).toBe(false)
    expect(readOnlyCapability.get('[data-testid="workflow-readonly"]').text()).toContain('没有审批决策权限')
  })

  it('renders a decided case read-only and maps the actor timestamp', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/cases/71') {
        return Promise.resolve(workflowCase({
          application_status: 'APPROVED',
          is_awaiting_me: false,
          closed_at: '2026-11-27T03:00:00Z',
          decided_by_user_id: 15,
          decided_by_display_name: 'demo.decider',
          decision_context: { is_awaiting_me: false, can_admin_override: false, requires_reason: false },
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

    expect(wrapper.find('[data-testid="workflow-readonly"]').text()).toContain('已有最终结果')
    expect(wrapper.find('[data-testid="workflow-decision-context"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="workflow-case-summary"]').text()).toContain('demo.decider')
    expect(wrapper.text()).toContain('已通过')
  })

  it('does not request detail or timeline without workflow.case.read', async () => {
    const wrapper = await mountPage(
      WorkflowCaseDetailPage,
      [],
      '/workflow/cases/71',
      { props: { caseId: '71' } },
    )

    expect(wrapper.find('[data-testid="workflow-detail-forbidden"]').exists()).toBe(true)
    expect(resource.get).not.toHaveBeenCalled()
  })
})

describe('workflow API client', () => {
  it('sends the selected server view and business date filters', async () => {
    resource.get.mockResolvedValue(listResponse([]))
    const query: WorkflowCaseQuery = {
      page: 2,
      pageSize: 50,
      view: 'my_cc',
      status: 'APPROVED',
      businessDate: '2026-11-27',
      type: 'LEAVE_REQUEST',
    }

    await listWorkflowCases(query)

    expect(resource.get).toHaveBeenCalledWith('/v1/cases', {
      page: 2,
      page_size: 50,
      view: 'my_cc',
      status: 'APPROVED',
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
