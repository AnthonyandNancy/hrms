import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useSessionStore } from '@/stores/session'
import MovementDetailPage from '@/modules/people/lifecycle/pages/MovementDetailPage.vue'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resource.get(...args),
  resourcePost: (...args: unknown[]) => resource.post(...args),
}))

const CAPABILITIES = [
  'lifecycle.case.read',
  'lifecycle.transfer.manage',
  'workflow.case.read',
  'workflow.case.create',
  'workflow.case.decide',
  'people.employee.read',
  'org.unit.read',
  'iam.user.read',
]

function page(items: unknown[]): Record<string, unknown> {
  return { items, page: 1, page_size: 100, total: items.length }
}

function lifecycleDetail(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    case_id: 31,
    case_no: 'CASE-2026-0031',
    title: 'Assignment change for employment 21',
    case_type: 'ASSIGNMENT_CHANGE',
    kind: 'transfer',
    application_status: 'APPROVED',
    effect_status: 'APPLIED',
    domain_status: 'EFFECTIVE',
    subject_type: 'employment',
    subject_id: 21,
    payload: {
      employment_id: 21,
      org_unit_id: 4,
      effective_date: '2026-12-01',
      reason: 'Synthetic department transfer',
      base_salary_minor: 123456,
      salary_currency: 'CNY',
    },
    employment_id: 21,
    result_employment_id: 21,
    effective_date: '2026-12-01',
    requester_user_id: 10,
    requester_display_name: 'Synthetic HR',
    current_assignee_user_id: null,
    current_assignee_display_name: null,
    current_node_key: 'hr_review',
    is_mine: true,
    is_awaiting_me: false,
    submitted_at: '2026-11-20T01:00:00Z',
    closed_at: '2026-11-21T02:30:00Z',
    before: {
      employment_id: 21,
      employment_status: 'ACTIVE',
      assignment: { assignment_id: 5, org_unit_id: 3, valid_from: '2026-01-01' },
    },
    after: {
      employment_id: 21,
      assignment_id: 6,
      org_unit_id: 4,
      effective_date: '2026-12-01',
    },
    effect_result: { employment_id: 21, assignment_id: 6, effective_date: '2026-12-01' },
    detail_frozen_at: '2026-11-21T02:30:00Z',
    employment: {
      employment_id: 21,
      person_id: 11,
      employee_no: 'E-1001',
      status: 'ACTIVE',
      valid_from: '2026-01-01',
      valid_to: '9999-12-31',
    },
    checklist: [],
    ...overrides,
  }
}

function workflowCase(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 31,
    case_no: 'CASE-2026-0031',
    type: 'ASSIGNMENT_CHANGE',
    subject_type: 'employment',
    subject_id: 21,
    title: 'Assignment change for employment 21',
    payload: {
      employment_id: 21,
      org_unit_id: 4,
      effective_date: '2026-12-01',
      reason: 'Synthetic department transfer',
    },
    application_status: 'SUBMITTED',
    effect_status: 'NONE',
    requester_user_id: 10,
    requester_display_name: 'Synthetic HR',
    template_id: 3,
    template_version: 1,
    business_date: '2026-11-20',
    submitted_at: '2026-11-20T01:00:00Z',
    closed_at: null,
    current_node_key: 'hr_review',
    is_mine: true,
    is_awaiting_me: true,
    effects: [],
    ...overrides,
  }
}

function timeline(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    case_id: 31,
    application: { status: 'SUBMITTED', submitted_at: '2026-11-20T01:00:00Z', closed_at: null },
    effect: { status: 'NONE', effects: [] },
    items: [
      {
        type: 'DECISION',
        decision_id: 5,
        action: 'APPROVE',
        node_key: 'hr_review',
        actor_user_id: 10,
        comment: 'Synthetic approval',
        occurred_at: '2026-11-21T02:30:00Z',
      },
    ],
    ...overrides,
  }
}

function install(overrides: Record<string, unknown> = {}): void {
  resource.get.mockImplementation((path: string) => {
    if (path in overrides) return Promise.resolve(overrides[path])
    if (path === '/v1/lifecycle/cases/31') return Promise.resolve(lifecycleDetail())
    if (path === '/v1/cases/31') return Promise.resolve(workflowCase())
    if (path === '/v1/cases/31/timeline') return Promise.resolve(timeline())
    if (path === '/v1/users') {
      return Promise.resolve(page([{ id: 10, username: 'demo.hr', display_name: 'Synthetic HR' }]))
    }
    if (path === '/v1/employees') {
      return Promise.resolve(page([{
        employment_id: 21,
        person_id: 11,
        person_name: 'Synthetic Employee',
        employee_no: 'E-1001',
        status: 'ACTIVE',
        valid_from: '2026-01-01',
      }]))
    }
    if (path === '/v1/departments') {
      return Promise.resolve(page([{ id: 4, code: 'PLATFORM', name: '平台部' }]))
    }
    return Promise.resolve(page([]))
  })
}

async function mountDetail(path = '/hr/movements/31'): Promise<{ wrapper: ReturnType<typeof mount>; router: Router }> {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({
    status: 'authenticated',
    capabilities: CAPABILITIES,
    principal: {
      userId: 10,
      employmentId: 21,
      displayName: 'Synthetic HR',
      email: 'demo.hr@example.test',
      employmentNo: 'E-1001',
      jobTitle: 'HR',
      orgName: '平台部',
      initials: 'SH',
      isPrivileged: false,
      hasMfa: true,
      authVersion: 1,
      tenantTimezone: 'Asia/Shanghai',
    },
  })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/hr/movements', component: { template: '<div />' } },
      { path: '/hr/movements/:id', component: { template: '<div />' } },
      { path: '/hr/employees/:id', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(MovementDetailPage, {
    global: { plugins: [pinia, router] },
  })
  return { wrapper, router }
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
})

describe('movement detail and frozen effect result', () => {
  it('renders the effective banner, before/after snapshots and Workflow timeline', async () => {
    install()
    const { wrapper } = await mountDetail()
    await flushPromises()

    expect(wrapper.find('[data-testid="movement-snapshots"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('生效前')
    expect(wrapper.text()).toContain('生效后')
    expect(wrapper.text()).toContain('生效结果')
    expect(wrapper.text()).toContain('2026年12月1日')
    expect(wrapper.text()).toContain('已生效')
    expect(wrapper.text()).toContain('Synthetic approval')

    expect(resource.get).toHaveBeenCalledWith('/v1/lifecycle/cases/31')
    expect(resource.get).toHaveBeenCalledWith('/v1/cases/31')
    expect(resource.get).toHaveBeenCalledWith('/v1/cases/31/timeline')
  })

  it('keeps approved effects read-only and does not render a decision panel', async () => {
    install({
      '/v1/cases/31': workflowCase({
        application_status: 'APPROVED',
        effect_status: 'APPLIED',
        is_awaiting_me: false,
        closed_at: '2026-11-21T02:30:00Z',
      }),
      '/v1/cases/31/timeline': timeline({
        application: { status: 'APPROVED', submitted_at: '2026-11-20T01:00:00Z', closed_at: '2026-11-21T02:30:00Z' },
        effect: { status: 'APPLIED', effects: [] },
      }),
    })
    const { wrapper } = await mountDetail()
    await flushPromises()

    expect(wrapper.find('[data-testid="movement-decision-panel"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="movement-withdraw-panel"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="movement-readonly"]').exists()).toBe(true)
  })

  it('shows a withdraw panel only for the requester of an open case and posts with an idempotency key', async () => {
    install({
      '/v1/lifecycle/cases/31': lifecycleDetail({
        application_status: 'SUBMITTED',
        effect_status: 'NONE',
        domain_status: 'DRAFT',
        current_assignee_user_id: 12,
        is_awaiting_me: false,
        before: {},
        after: {},
        effect_result: {},
      }),
      '/v1/cases/31': workflowCase({ is_awaiting_me: false }),
    })
    const { wrapper } = await mountDetail()
    await flushPromises()

    expect(wrapper.find('[data-testid="movement-withdraw-panel"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="movement-decision-panel"]').exists()).toBe(false)

    await wrapper.get('[data-testid="movement-withdraw-comment"]').setValue('Synthetic withdrawal reason')
    resource.post.mockResolvedValueOnce({ id: 31, case_no: 'CASE-2026-0031', status: 'WITHDRAWN', effect_status: 'NONE' })
    await wrapper.get('[data-testid="movement-withdraw"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/cases/31/withdraw',
      { comment: 'Synthetic withdrawal reason' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })
})
