import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createMemoryHistory, createRouter } from 'vue-router'
import { defineComponent } from 'vue'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import WorkflowCenterPage from '@/modules/platform/pages/WorkflowCenterPage.vue'
import WorkflowCaseDetailPage from '@/modules/platform/workflow/pages/WorkflowCaseDetailPage.vue'
import NotificationsPage from '@/modules/platform/pages/NotificationsPage.vue'

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

const OverlayStub = defineComponent({
  props: { modelValue: Boolean, title: String },
  emits: ['update:modelValue', 'close'],
  template: `<div v-if="modelValue" class="el-overlay-stub" role="dialog">
    <h2>{{ title }}</h2>
    <slot />
    <slot name="footer" />
  </div>`,
})

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

async function mountPage(component: object, capabilities: string[], options: Record<string, unknown> = {}) {
  useCapabilities(capabilities)
  const router = testRouter()
  await router.push('/workflow')
  await router.isReady()
  return mount(component, {
    global: {
      plugins: [ElementPlus, router],
      stubs: {
        'el-dialog': OverlayStub,
        'el-drawer': OverlayStub,
        'el-select': true,
        'el-option': true,
        teleport: true,
      },
    },
    ...options,
  })
}

function listResponse(items: unknown[], overrides: Record<string, unknown> = {}) {
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

function timeline(caseOverrides: Record<string, unknown> = {}) {
  return {
    case_id: 71,
    application: { status: 'SUBMITTED', submitted_at: '2026-11-27T01:00:00Z', closed_at: null },
    effect: { status: 'NONE', effects: [] },
    items: [
      {
        type: 'WORKFLOW_EVENT',
        event_type: 'case.submitted',
        occurred_at: '2026-11-27T01:00:00Z',
        actor_user_id: 12,
        payload: {},
      },
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
    ...caseOverrides,
  }
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.put.mockReset()
  resource.patch.mockReset()
  resource.delete.mockReset()
})

describe('platform workspaces', () => {
  const pages: Array<[string, string]> = [
    ['src/modules/platform/pages/WorkflowCenterPage.vue', 'workflow-center-page'],
    ['src/modules/platform/workflow/pages/WorkflowCaseDetailPage.vue', 'workflow-case-detail-page'],
        ['src/modules/platform/pages/SystemSettingsPage.vue', 'system-settings-page'],
    ['src/modules/platform/pages/NotificationsPage.vue', 'notifications-page'],
    ['src/modules/platform/pages/SystemAuditPage.vue', 'system-audit-page'],
  ]

  it('replaces the generic shell in every page with PageShell and no mode switch', () => {
    const root = resolve(__dirname, '../..')
    for (const [page, marker] of pages) {
      const source = readFileSync(resolve(root, page), 'utf8')
      expect(source, page).toContain('PageShell')
      expect(source, page).toContain(`data-testid="${marker}"`)
      expect(source, page).not.toMatch(/PlatformWorkspace|platformPageConfig|mode\s*===/)
    }
  })

  it('renders the workflow list with Chinese labels, filters and a detail link', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/cases') return Promise.resolve(listResponse([workflowCase()]))
      if (path === '/v1/tasks/my') return Promise.resolve(listResponse([]))
      return Promise.resolve(null)
    })

    const wrapper = await mountPage(WorkflowCenterPage, ['workflow.case.read', 'workflow.case.decide'])
    await flushPromises()

    expect(wrapper.find('[data-testid="workflow-center-page"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('审批流程中心')
    expect(wrapper.find('[data-testid="workflow-case-table"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="workflow-case-row-71"]').text()).toContain('CASE-20261127-000071')
    expect(wrapper.find('[data-testid="workflow-case-row-71"]').text()).toContain('请假申请')
    expect(wrapper.find('[data-testid="workflow-case-row-71"]').text()).toContain('已提交')
    expect(wrapper.find('[data-testid="workflow-case-row-71"]').text()).not.toContain('LEAVE_REQUEST')
    expect(wrapper.find('[data-testid="workflow-case-row-71"]').text()).not.toContain('SUBMITTED')
    expect(wrapper.find('[data-testid="workflow-case-link-71"]').attributes('href')).toBe('/workflow/cases/71')
    expect(wrapper.find('[data-testid="workflow-status-filter"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="workflow-type-filter"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="workflow-view-my_approvals"]').exists()).toBe(true)
  })

  it('sends the selected workflow filters to the real case endpoint', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/cases') return Promise.resolve(listResponse([]))
      if (path === '/v1/tasks/my') return Promise.resolve(listResponse([]))
      return Promise.resolve(null)
    })

    const wrapper = await mountPage(WorkflowCenterPage, ['workflow.case.read'])
    await flushPromises()
    const vm = wrapper.vm as unknown as {
      filters: { status: string; type: string; view: string }
      applyFilters: () => void
      changeView: (view: string) => void
    }
    vm.filters.status = 'IN_REVIEW'
    vm.filters.type = 'LEAVE_REQUEST'
    vm.changeView('my_approvals')
    await flushPromises()

    const calls = resource.get.mock.calls.map((call) => [call[0], call[1]])
    expect(calls).toContainEqual([
      '/v1/cases',
      expect.objectContaining({ status: 'IN_REVIEW', type: 'LEAVE_REQUEST', view: 'my_approvals' }),
    ])
  })

  it('renders the five states on the workflow list', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/cases') return Promise.resolve(listResponse([], { partial: true, partial_reason: '部分组织范围暂不可用' }))
      if (path === '/v1/tasks/my') return Promise.reject(new ApiError('INTERNAL', 'Boom', { status: 500 }))
      return Promise.resolve(null)
    })
    const partial = await mountPage(WorkflowCenterPage, ['workflow.case.read'])
    await flushPromises()
    expect(partial.find('[data-testid="workflow-empty"]').exists()).toBe(true)
    expect(partial.find('[data-testid="workflow-partial"]').text()).toContain('个人待办时限加载失败')

    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/cases') return Promise.resolve(listResponse([workflowCase()]))
      if (path === '/v1/tasks/my') return Promise.resolve(listResponse([]))
      return Promise.resolve(null)
    })
    const loading = await mountPage(WorkflowCenterPage, ['workflow.case.read'])
    expect(loading.find('[data-testid="workflow-loading"]').exists()).toBe(true)
    await flushPromises()

    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/cases') return Promise.reject(new ApiError('FORBIDDEN', 'Forbidden', { status: 403 }))
      if (path === '/v1/tasks/my') return Promise.resolve(listResponse([]))
      return Promise.resolve(null)
    })
    const forbidden = await mountPage(WorkflowCenterPage, ['workflow.case.read'])
    await flushPromises()
    expect(forbidden.find('[data-testid="workflow-forbidden"]').exists()).toBe(true)

    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/cases') return Promise.reject(new ApiError('INTERNAL', 'Boom', { status: 500 }))
      if (path === '/v1/tasks/my') return Promise.resolve(listResponse([]))
      return Promise.resolve(null)
    })
    const failed = await mountPage(WorkflowCenterPage, ['workflow.case.read'])
    await flushPromises()
    expect(failed.find('[data-testid="workflow-error"]').exists()).toBe(true)
  })

  it('renders case summary, timeline and a gated decision action for the current assignee', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/cases/71') return Promise.resolve(workflowCase({ payload: { reason: 'Synthetic family reason' } }))
      if (path === '/v1/cases/71/timeline') return Promise.resolve(timeline())
      return Promise.resolve(null)
    })
    resource.post.mockResolvedValue({})

    const wrapper = await mountPage(WorkflowCaseDetailPage, ['workflow.case.read', 'workflow.case.decide'], {
      props: { caseId: '71' },
    })
    await flushPromises()

    expect(wrapper.find('[data-testid="workflow-case-detail-page"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('CASE-20261127-000071')
    expect(wrapper.text()).toContain('请假申请')
    expect(wrapper.find('[data-testid="workflow-timeline"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="workflow-timeline"]').text()).toContain('案例已提交')
    expect(wrapper.find('[data-testid="workflow-timeline"]').text()).toContain('同意')
    expect(wrapper.find('[data-testid="workflow-decision-context"]').exists()).toBe(true)

    await wrapper.find('textarea').setValue('同意，按计划执行。')
    await wrapper.get('[data-testid="workflow-decision-approve"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="workflow-decision-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/cases/71/decisions',
      { action: 'APPROVE', comment: '同意，按计划执行。' },
      expect.objectContaining({ idempotencyKey: expect.stringContaining('workflow-71-approve') }),
    )
  })

  it('renders a decided case as read-only with no decision action', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/cases/71') {
        return Promise.resolve(workflowCase({
          application_status: 'APPROVED',
          is_awaiting_me: false,
          closed_at: '2026-11-27T03:00:00Z',
        }))
      }
      if (path === '/v1/cases/71/timeline') return Promise.resolve(timeline())
      return Promise.resolve(null)
    })

    const wrapper = await mountPage(WorkflowCaseDetailPage, ['workflow.case.read', 'workflow.case.decide'], {
      props: { caseId: '71' },
    })
    await flushPromises()

    expect(wrapper.find('[data-testid="workflow-readonly"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="workflow-decision-actions"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('已通过')
  })

  it('lists notifications in Chinese, marks one read and keeps unread filtering', async () => {
    resource.get.mockResolvedValue([
      {
        id: 9,
        type: 'NOTICE_PUBLISHED',
        payload: { notice_id: 42, requires_ack: false },
        read: false,
        createdAt: '2026-11-27T01:00:00Z',
      },
      {
        id: 10,
        type: 'APPROVAL_PENDING',
        payload: { case_id: 71 },
        read: true,
        createdAt: '2026-11-26T01:00:00Z',
      },
    ])
    resource.post.mockResolvedValue({
      id: 9,
      type: 'NOTICE_PUBLISHED',
      payload: { notice_id: 42, requires_ack: false },
      read: true,
      createdAt: '2026-11-27T01:00:00Z',
    })

    const wrapper = await mountPage(NotificationsPage, ['notification.read'])
    await flushPromises()

    expect(wrapper.find('[data-testid="notifications-page"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="notification-row-9"]').text()).toContain('公告发布')
    expect(wrapper.find('[data-testid="notification-row-9"]').text()).not.toContain('NOTICE_PUBLISHED')
    expect(wrapper.find('[data-testid="notification-unread-9"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="notification-mark-read-9"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="notification-mark-read-10"]').exists()).toBe(false)

    await wrapper.get('[data-testid="notification-mark-read-9"]').trigger('click')
    await flushPromises()
    expect(resource.post).toHaveBeenCalledWith('/v1/engagement/notifications/9/read', {})

    await wrapper.get('[data-testid="notification-filter-unread"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="notification-row-9"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="notification-row-10"]').exists()).toBe(false)
  })

  it('is forbidden for notifications without the read capability and shows no channel preference editor', async () => {
    resource.get.mockRejectedValue(new ApiError('FORBIDDEN', 'Forbidden', { status: 403 }))
    const wrapper = await mountPage(NotificationsPage, [])
    await flushPromises()
    expect(wrapper.find('[data-testid="notification-forbidden"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('渠道偏好')
  })


})
