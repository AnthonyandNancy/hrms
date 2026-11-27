import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSessionStore } from '@/stores/session'
import WorkflowCaseDetailPage from '@/modules/platform/workflow/pages/WorkflowCaseDetailPage.vue'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
  resourcePut: vi.fn(),
  resourcePatch: vi.fn(),
  resourceDelete: vi.fn(),
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

async function mountDetail(capabilities: string[], detail: Record<string, unknown>) {
  useCapabilities(capabilities)
  const router = testRouter()
  await router.push('/workflow/cases/71')
  await router.isReady()

  resource.get.mockImplementation((path: string) => {
    if (path === '/v1/cases/71') return Promise.resolve(detail)
    if (path === '/v1/cases/71/timeline') {
      return Promise.resolve({
        case_id: 71,
        application: { status: detail.application_status, submitted_at: null, closed_at: null },
        effect: { status: detail.effect_status, effects: detail.effects },
        items: [],
      })
    }
    return Promise.resolve(null)
  })

  const wrapper = mount(WorkflowCaseDetailPage, {
    global: {
      plugins: [ElementPlus, router],
      stubs: { teleport: true, 'el-select': true, 'el-option': true },
    },
    props: { caseId: '71' },
  })
  await flushPromises()
  return wrapper
}

function baseCase(overrides: Record<string, unknown> = {}) {
  return {
    id: 71,
    case_no: 'CASE-20261127-000071',
    type: 'LEAVE_REQUEST',
    subject_type: 'employment',
    subject_id: 9,
    title: '十一月事假申请',
    payload: { reason: 'Synthetic family visit' },
    application_status: 'APPROVED',
    effect_status: 'FAILED',
    requester_user_id: 12,
    requester_display_name: 'demo.requester',
    current_assignee_user_id: null,
    current_assignee_display_name: null,
    decided_by_user_id: 10,
    decided_by_display_name: 'demo.approver',
    template_id: 3,
    template_version: 2,
    business_date: '2026-11-27',
    submitted_at: '2026-11-27T01:00:00Z',
    closed_at: '2026-11-27T03:00:00Z',
    current_node_key: null,
    is_mine: false,
    is_awaiting_me: false,
    decision_context: {
      is_awaiting_me: false,
      can_admin_override: false,
      requires_reason: false,
    },
    effects: [],
    ...overrides,
  }
}

function failedEffectCase() {
  return baseCase({
    effects: [{
      effect_id: 91,
      effect_type: 'workflow.leave.reserve',
      status: 'FAILED',
      attempts: 2,
      requires_escalation: false,
      failure_summary: 'Downstream timeout',
      failed_at: '2026-11-27T03:00:00Z',
    }],
  })
}

function awaitingCase() {
  return baseCase({
    application_status: 'SUBMITTED',
    effect_status: 'NONE',
    closed_at: null,
    current_node_key: 'manager_approval',
    current_assignee_user_id: 10,
    current_assignee_display_name: 'demo.approver',
    is_awaiting_me: true,
    decision_context: {
      is_awaiting_me: true,
      can_admin_override: true,
      requires_reason: false,
    },
  })
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
})

describe('workflow failed effect retry', () => {
  it('retries by effect id and never posts another decision', async () => {
    const wrapper = await mountDetail(
      ['workflow.case.read', 'workflow.case.admin'],
      failedEffectCase(),
    )
    resource.post.mockResolvedValue({
      effect_id: 91,
      case_id: 71,
      effect_status: 'APPLIED',
      attempts: 3,
      requires_escalation: false,
    })

    expect(wrapper.find('[data-testid="workflow-effect-failed"]').exists()).toBe(true)
    await wrapper.find('[data-testid="workflow-effect-retry"]').trigger('click')
    await wrapper.find('[data-testid="workflow-effect-retry-reason"]').setValue('已修复下游服务，按幂等键重试')
    await wrapper.find('[data-testid="workflow-effect-retry-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/effects/91/retry',
      { reason: '已修复下游服务，按幂等键重试' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(resource.post).not.toHaveBeenCalledWith(
      '/v1/cases/71/decisions',
      expect.anything(),
      expect.anything(),
    )
    expect(wrapper.find('[data-testid="workflow-effect-retry-message"]').text()).toContain('生效任务已重试成功')
  })

  it('keeps failure visible and never claims success when the retry still fails', async () => {
    const wrapper = await mountDetail(
      ['workflow.case.read', 'workflow.case.admin'],
      failedEffectCase(),
    )
    resource.post.mockResolvedValue({
      effect_id: 91,
      case_id: 71,
      effect_status: 'FAILED',
      attempts: 3,
      requires_escalation: false,
    })

    await wrapper.find('[data-testid="workflow-effect-retry"]').trigger('click')
    await wrapper.find('[data-testid="workflow-effect-retry-reason"]').setValue('重新执行后仍然失败，请审计')
    await wrapper.find('[data-testid="workflow-effect-retry-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('已重试但仍失败')
    expect(wrapper.find('[data-testid="workflow-effect-failed"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="workflow-effect-status"]').text()).toBe('生效失败')
  })

  it('requires a ten-character retry reason before calling the server', async () => {
    const wrapper = await mountDetail(
      ['workflow.case.read', 'workflow.case.admin'],
      failedEffectCase(),
    )

    await wrapper.find('[data-testid="workflow-effect-retry"]').trigger('click')
    await wrapper.find('[data-testid="workflow-effect-retry-reason"]').setValue('太短')
    await wrapper.find('[data-testid="workflow-effect-retry-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('效果重试需填写至少 10 个字符的原因')
    expect(resource.post).not.toHaveBeenCalled()
  })

  it('shows no retry control to a non-admin reader', async () => {
    const wrapper = await mountDetail(['workflow.case.read'], failedEffectCase())

    expect(wrapper.find('[data-testid="workflow-effect-failed"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="workflow-effect-retry"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('重试需流程管理员能力')
  })
})

describe('workflow decision reason', () => {
  it('requires an explicit admin override reason of at least ten characters', async () => {
    const wrapper = await mountDetail(
      ['workflow.case.read', 'workflow.case.decide', 'workflow.case.admin'],
      baseCase({
        application_status: 'SUBMITTED',
        effect_status: 'NONE',
        closed_at: null,
        current_node_key: 'manager_approval',
        current_assignee_user_id: 55,
        current_assignee_display_name: 'demo.other',
        is_awaiting_me: false,
        decision_context: {
          is_awaiting_me: false,
          can_admin_override: true,
          requires_reason: true,
        },
      }),
    )

    await wrapper.find('[data-testid="workflow-decision-approve"]').trigger('click')
    await wrapper.find('[data-testid="workflow-decision-comment"]').setValue('同意，按制度代批。')
    expect(wrapper.text()).toContain('管理员覆盖需填写至少 10 个字符的理由')
    expect(wrapper.find('[data-testid="workflow-decision-submit"]').attributes('disabled')).toBeDefined()

    await wrapper.find('[data-testid="workflow-admin-override-reason"]').setValue('直属主管长期休假，由管理员按制度代批。')
    expect(wrapper.find('[data-testid="workflow-decision-submit"]').attributes('disabled')).toBeUndefined()

    resource.post.mockResolvedValue({
      case_id: 71,
      application_status: 'APPROVED',
      effect_status: 'NONE',
      effects: [],
    })
    await wrapper.find('[data-testid="workflow-decision-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/cases/71/decisions',
      expect.objectContaining({
        action: 'APPROVE',
        comment: '同意，按制度代批。',
        reason: '直属主管长期休假，由管理员按制度代批。',
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('sends a direct assignee decision without an override reason', async () => {
    const wrapper = await mountDetail(
      ['workflow.case.read', 'workflow.case.decide'],
      awaitingCase(),
    )
    resource.post.mockResolvedValue({
      case_id: 71,
      application_status: 'APPROVED',
      effect_status: 'NONE',
      effects: [],
    })

    await wrapper.find('[data-testid="workflow-decision-approve"]').trigger('click')
    await wrapper.find('[data-testid="workflow-decision-comment"]').setValue('同意，请按计划休假。')
    await wrapper.find('[data-testid="workflow-decision-submit"]').trigger('click')
    await flushPromises()

    const body = (resource.post.mock.calls[0]?.[1] ?? {}) as Record<string, unknown>
    expect(body).toEqual({ action: 'APPROVE', comment: '同意，请按计划休假。' })
    expect(body).not.toHaveProperty('reason')
    expect(body).not.toHaveProperty('requester_user_id')
    expect(body).not.toHaveProperty('approver_user_id')
  })
})
