import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ElementPlus from 'element-plus'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import MovementWorkspacePage from '@/modules/people/lifecycle/pages/MovementWorkspacePage.vue'
import MovementDetailPage from '@/modules/people/lifecycle/pages/MovementDetailPage.vue'
import MovementFormDrawer from '@/modules/people/lifecycle/components/MovementFormDrawer.vue'
import MovementTimeline from '@/modules/people/lifecycle/components/MovementTimeline.vue'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resource.get(...args),
  resourcePost: (...args: unknown[]) => resource.post(...args),
}))

const ALL_CAPABILITIES = [
  'lifecycle.case.read',
  'lifecycle.onboarding.manage',
  'lifecycle.probation.manage',
  'lifecycle.transfer.manage',
  'lifecycle.terms.manage',
  'lifecycle.secondment.manage',
  'lifecycle.offboard.manage',
  'workflow.case.read',
  'workflow.case.decide',
  'people.employee.read',
  'org.unit.read',
  'iam.user.read',
]

function page(items: unknown[], overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return { items, page: 1, page_size: 100, total: items.length, ...overrides }
}

function workflowCaseRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
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

function lifecycleCaseRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    case_id: 31,
    case_no: 'CASE-2026-0031',
    case_type: 'ASSIGNMENT_CHANGE',
    domain_status: 'DRAFT',
    application_status: 'SUBMITTED',
    effect_status: 'NONE',
    subject_type: 'employment',
    subject_id: 21,
    employment_id: 21,
    result_employment_id: null,
    effective_at: null,
    recorded_at: '2026-11-20T01:00:00Z',
    ...overrides,
  }
}

function employeeRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    employment_id: 21,
    person_id: 11,
    person_name: 'Synthetic Employee',
    employee_no: 'E-1001',
    status: 'ACTIVE',
    employment_type: 'FULL_TIME',
    valid_from: '2026-01-01',
    valid_to: '9999-12-31',
    current_assignment: { org_unit_id: 4, position_id: null },
    ...overrides,
  }
}

function userRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 10,
    username: 'demo.hr',
    display_name: 'Synthetic HR',
    email: 'demo.hr@example.test',
    status: 'ACTIVE',
    roles: [{ role_code: 'HR_ADMIN' }],
    ...overrides,
  }
}

function orgRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 4,
    code: 'PLATFORM',
    name: '平台部',
    type: 'DEPARTMENT',
    status: 'ACTIVE',
    valid_from: '2026-01-01',
    valid_to: '9999-12-31',
    ...overrides,
  }
}

function secondmentRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    assignment_id: 77,
    employment_id: 21,
    employee_no: 'E-1001',
    person_name: 'Synthetic Employee',
    host_org_unit_id: 4,
    position_id: null,
    assignment_type: 'SECONDMENT',
    is_primary: false,
    valid_from: '2027-01-01',
    valid_to: '2027-03-31',
    ...overrides,
  }
}

function timelineResponse(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    case_id: 31,
    application: {
      status: 'SUBMITTED',
      submitted_at: '2026-11-20T01:00:00Z',
      closed_at: null,
    },
    effect: { status: 'NONE', effects: [] },
    items: [
      {
        type: 'WORKFLOW_EVENT',
        event_type: 'case.submitted',
        occurred_at: '2026-11-20T01:00:00Z',
        actor_user_id: 10,
        payload: { node_key: 'hr_review' },
      },
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

function installWorkspaceReads(overrides: Record<string, unknown> = {}): void {
  resource.get.mockImplementation((path: string) => {
    if (path in overrides) return Promise.resolve(overrides[path])
    if (path === '/v1/cases') return Promise.resolve(page([workflowCaseRaw()]))
    if (path === '/v1/lifecycle/cases') return Promise.resolve(page([lifecycleCaseRaw()]))
    if (path === '/v1/lifecycle/secondments') {
      return Promise.resolve({
        ...page([secondmentRaw()]),
        as_of: '2026-11-23',
        freshness: 'LIVE',
        source_hash: 'hash',
        partial: false,
        partial_reason: null,
        drilldown: null,
      })
    }
    if (path === '/v1/employees') return Promise.resolve(page([employeeRaw()]))
    if (path === '/v1/users') return Promise.resolve(page([userRaw()]))
    if (path === '/v1/departments') return Promise.resolve(page([orgRaw()]))
    return Promise.resolve(page([]))
  })
}

async function mountWorkspace(
  capabilities: string[] = ALL_CAPABILITIES,
): Promise<{ wrapper: ReturnType<typeof mount>; router: Router }> {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/hr/movements', name: 'hr-movements', component: { template: '<div />' } },
      { path: '/hr/movements/:id', name: 'hr-movements-by-id', component: { template: '<div />' } },
      { path: '/hr/employees/:id', name: 'employee-detail', component: { template: '<div />' } },
      { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
    ],
  })
  await router.push('/hr/movements')
  await router.isReady()

  const wrapper = mount(MovementWorkspacePage, {
    global: {
      plugins: [pinia, router, ElementPlus],
      stubs: {
        'el-button': { template: '<button type="button"><slot /></button>' },
        'el-alert': { template: '<div role="alert"><slot name="title" /><slot /></div>' },
        'el-drawer': { props: ['modelValue'], template: '<section v-if="modelValue"><slot /></section>' },
      },
    },
  })
  return { wrapper, router }
}

async function mountDrawer(
  options: {
    allowedKinds?: string[]
    employees?: unknown[]
    orgUnits?: unknown[]
  } = {},
): Promise<{ wrapper: ReturnType<typeof mount>; router: Router }> {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities: ALL_CAPABILITIES })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }],
  })
  await router.push('/')
  await router.isReady()

  const wrapper = mount(MovementFormDrawer, {
    props: {
      modelValue: true,
      allowedKinds: (options.allowedKinds ?? [
        'onboarding',
        'probation',
        'transfer',
        'salary',
        'secondment',
        'offboarding',
      ]) as never,
      employees: (options.employees ?? [
        { employmentId: 21, name: 'Synthetic Employee', employeeNo: 'E-1001', status: 'ACTIVE', validFrom: '2026-01-01' },
      ]) as never,
      orgUnits: (options.orgUnits ?? [
        { id: 4, code: 'PLATFORM', name: '平台部' },
      ]) as never,
    },
    global: { plugins: [pinia, router] },
  })
  return { wrapper, router }
}

async function mountDetail(
  capabilities: string[] = ALL_CAPABILITIES,
  path = '/hr/movements/31',
): Promise<{ wrapper: ReturnType<typeof mount>; router: Router }> {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({
    status: 'authenticated',
    capabilities,
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
      { path: '/hr/movements', name: 'hr-movements', component: { template: '<div />' } },
      { path: '/hr/movements/:id', name: 'hr-movements-by-id', component: { template: '<div />' } },
      { path: '/hr/employees/:id', name: 'employee-detail', component: { template: '<div />' } },
      { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()

  const wrapper = mount(MovementDetailPage, {
    global: {
      plugins: [pinia, router],
      stubs: {
        'el-button': { template: '<button type="button"><slot /></button>' },
        'el-alert': { template: '<div role="alert"><slot name="title" /><slot /></div>' },
        'el-drawer': { props: ['modelValue'], template: '<section v-if="modelValue"><slot /></section>' },
      },
    },
  })
  return { wrapper, router }
}

function installDetailReads(overrides: {
  lifecycle?: Record<string, unknown>
  workflow?: Record<string, unknown>
  timeline?: Record<string, unknown>
} = {}): void {
  const lifecycle = overrides.lifecycle ?? {
    case_id: 31,
    case_no: 'CASE-2026-0031',
    case_type: 'ASSIGNMENT_CHANGE',
    application_status: 'SUBMITTED',
    effect_status: 'NONE',
    domain_status: 'DRAFT',
    subject_type: 'employment',
    subject_id: 21,
    payload: {
      employment_id: 21,
      org_unit_id: 4,
      effective_date: '2026-12-01',
      reason: 'Synthetic department transfer',
    },
    employment_id: 21,
    result_employment_id: null,
    employment: {
      employment_id: 21,
      person_id: 11,
      employee_no: 'E-1001',
      status: 'ACTIVE',
      valid_from: '2026-01-01',
      valid_to: '9999-12-31',
    },
    checklist: [],
  }

  resource.get.mockImplementation((path: string) => {
    if (path === '/v1/lifecycle/cases/31') return Promise.resolve(lifecycle)
    if (path === '/v1/cases/31') return Promise.resolve(overrides.workflow ?? workflowCaseRaw())
    if (path === '/v1/cases/31/timeline') return Promise.resolve(overrides.timeline ?? timelineResponse())
    if (path === '/v1/users') return Promise.resolve(page([userRaw()]))
    return Promise.resolve(page([]))
  })
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
})

describe('MovementWorkspacePage', () => {
  it('lists real movement cases with type, status, employee, effective date, initiator and filters', async () => {
    installWorkspaceReads()
    const { wrapper } = await mountWorkspace()
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith(
      '/v1/cases',
      expect.objectContaining({ type: 'ASSIGNMENT_CHANGE', page: 1, page_size: 100 }),
    )
    expect(wrapper.text()).toContain('调岗/调部门')
    expect(wrapper.text()).toContain('CASE-2026-0031')
    expect(wrapper.text()).toContain('Synthetic Employee')
    expect(wrapper.text()).toContain('2026年12月1日')
    expect(wrapper.text()).toContain('Synthetic HR')
    expect(wrapper.text()).toContain('已提交')
    expect(wrapper.find('[data-testid="movement-table"]').exists()).toBe(true)

    await wrapper.findComponent('[data-testid="movement-type-filter"]').setValue('salary')
    await flushPromises()
    expect(wrapper.text()).not.toContain('CASE-2026-0031')
    expect(wrapper.text()).toContain('暂无员工异动')
  })

  it('paginates the loaded movement projection and shows a partial note when a source is incomplete', async () => {
    const workflowCases = Array.from({ length: 11 }, (_, index) =>
      workflowCaseRaw({
        id: index + 1,
        case_no: `CASE-2026-${String(index + 1).padStart(4, '0')}`,
        payload: {
          employment_id: 21,
          org_unit_id: 4,
          effective_date: '2026-12-01',
          reason: 'Synthetic pagination transfer',
        },
      }),
    )
    const lifecycleCases = Array.from({ length: 11 }, (_, index) =>
      lifecycleCaseRaw({ case_id: index + 1, case_no: `CASE-2026-${String(index + 1).padStart(4, '0')}` }),
    )

    installWorkspaceReads({
      '/v1/cases': page(workflowCases),
      '/v1/lifecycle/cases': page(lifecycleCases),
    })
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/cases') return Promise.resolve(page(workflowCases))
      if (path === '/v1/lifecycle/cases') return Promise.resolve(page(lifecycleCases))
      if (path === '/v1/employees') return Promise.resolve(page([employeeRaw()]))
      if (path === '/v1/users') return Promise.resolve(page([userRaw()]))
      if (path === '/v1/departments') return Promise.resolve(page([orgRaw()]))
      if (path === '/v1/lifecycle/secondments') return Promise.reject(new ApiError('FORBIDDEN', 'Forbidden', { status: 403 }))
      return Promise.resolve(page([]))
    })

    const { wrapper } = await mountWorkspace()
    await flushPromises()

    expect(wrapper.text()).toContain('部分数据')
    expect(wrapper.text()).toContain('借调')
    expect(wrapper.find('[data-testid="movement-next-page"]').exists()).toBe(true)

    await wrapper.get('[data-testid="movement-next-page"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('第 2 / 2 页')
    expect(wrapper.text()).toContain('CASE-2026-0001')
  })

  it('renders loading, empty and retryable error states without inventing rows', async () => {
    resource.get.mockImplementation(
      () =>
        new Promise((resolve) => {
          setTimeout(() => resolve(page([])), 0)
        }),
    )

    const { wrapper } = await mountWorkspace()
    expect(wrapper.text()).toContain('正在加载员工异动')

    await vi.waitFor(() => {
      expect(wrapper.text()).toContain('暂无员工异动')
    })
    expect(wrapper.find('[data-testid="movement-table"]').exists()).toBe(false)

    resource.get.mockReset()
    resource.get.mockRejectedValue(
      new ApiError('INTERNAL', 'Failed to load movements', { status: 500, requestId: 'req-movement-500' }),
    )
    const retry = await mountWorkspace()
    await flushPromises()
    expect(retry.wrapper.text()).toContain('req-movement-500')
    expect(retry.wrapper.text()).toContain('重试')
  })

  it('shows a forbidden state without issuing reads when lifecycle.case.read is absent', async () => {
    const { wrapper } = await mountWorkspace(['people.employee.read'])
    await flushPromises()

    expect(resource.get).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('无权')
    expect(wrapper.find('[data-testid="movement-table"]').exists()).toBe(false)
  })

  it('guards create controls per movement capability and hides salary without lifecycle.terms.manage', async () => {
    installWorkspaceReads()
    const { wrapper } = await mountWorkspace([
      'lifecycle.case.read',
      'lifecycle.transfer.manage',
      'workflow.case.read',
      'people.employee.read',
      'org.unit.read',
    ])
    await flushPromises()

    expect(wrapper.find('[data-testid="open-movement-drawer"]').exists()).toBe(true)
    await wrapper.get('[data-testid="open-movement-drawer"]').trigger('click')
    await flushPromises()

    const typeSelect = wrapper.get('[data-testid="movement-kind"]')
    const optionValues = typeSelect.findAll('option').map((option) => option.attributes('value'))
    expect(optionValues).toContain('transfer')
    expect(optionValues).not.toContain('salary')
    expect(optionValues).not.toContain('secondment')
    expect(optionValues).not.toContain('offboarding')
  })
})

describe('MovementFormDrawer', () => {
  it('creates a transfer draft with the real contract and keeps 422 field errors inline', async () => {
    resource.post.mockRejectedValueOnce(
      new ApiError('VALIDATION_FAILED', 'The given data was invalid.', {
        status: 422,
        fieldErrors: { org_unit_id: ['The target organization unit does not exist.'] },
      }),
    )

    const { wrapper } = await mountDrawer()
    await wrapper.get('[data-testid="movement-kind"]').setValue('transfer')
    await flushPromises()
    await wrapper.get('[data-testid="movement-employment"]').setValue('21')
    await wrapper.get('[data-testid="movement-target-org"]').setValue('4')
    await wrapper.get('[data-testid="movement-effective-date"]').setValue('2026-12-01')
    await wrapper.get('[data-testid="movement-reason"]').setValue('Synthetic department transfer')
    await wrapper.get('[data-testid="movement-save-draft"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/lifecycle/transfers',
      {
        employment_id: 21,
        org_unit_id: 4,
        position_id: null,
        manager_assignment_id: null,
        effective_date: '2026-12-01',
        reason: 'Synthetic department transfer',
        submit: false,
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('目标组织不存在或已停用')

    resource.post.mockResolvedValueOnce({ case_id: 44, case_no: 'CASE-2026-0044', case_type: 'ASSIGNMENT_CHANGE' })
    await wrapper.get('[data-testid="movement-save-draft"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenLastCalledWith(
      '/v1/lifecycle/transfers',
      expect.objectContaining({ submit: false }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.emitted('created')).toBeTruthy()
  })

  it('rejects decimal salary input, previews integer minor units and displays formatted currency', async () => {
    const { wrapper } = await mountDrawer()
    await wrapper.get('[data-testid="movement-kind"]').setValue('salary')
    await flushPromises()
    await wrapper.get('[data-testid="movement-employment"]').setValue('21')
    await wrapper.get('[data-testid="movement-effective-date"]').setValue('2027-01-01')
    await wrapper.get('[data-testid="movement-salary-minor"]').setValue('12345.67')
    await flushPromises()
    await wrapper.get('[data-testid="movement-reason"]').setValue('Synthetic annual raise')
    await wrapper.get('[data-testid="movement-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('金额必须是最小货币单位的非负整数')

    await wrapper.get('[data-testid="movement-salary-minor"]').setValue('123456')
    await flushPromises()
    expect(wrapper.text()).toContain('¥1,234.56')

    resource.post.mockResolvedValueOnce({ case_id: 45, case_no: 'CASE-2026-0045', case_type: 'TERMS_CHANGE' })
    await wrapper.get('[data-testid="movement-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/lifecycle/salary-changes',
      {
        employment_id: 21,
        effective_date: '2027-01-01',
        base_salary_minor: 123456,
        salary_currency: 'CNY',
        salary_scheme_code: null,
        reason: 'Synthetic annual raise',
        submit: true,
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('validates secondment date ordering before transport and shows the effective-dated disclosure', async () => {
    const { wrapper } = await mountDrawer()
    await wrapper.get('[data-testid="movement-kind"]').setValue('secondment')
    await flushPromises()
    await wrapper.get('[data-testid="movement-employment"]').setValue('21')
    await wrapper.get('[data-testid="movement-host-org"]').setValue('4')
    await wrapper.get('[data-testid="movement-effective-date"]').setValue('2027-03-31')
    await wrapper.get('[data-testid="movement-secondment-to"]').setValue('2026-12-01')
    await wrapper.get('[data-testid="movement-reason"]').setValue('Synthetic secondment')
    await wrapper.get('[data-testid="movement-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('借调结束日期必须晚于开始日期')
    expect(wrapper.text()).toContain('不会改写主任职')

    await wrapper.get('[data-testid="movement-effective-date"]').setValue('2026-12-01')
    await wrapper.get('[data-testid="movement-secondment-to"]').setValue('2027-03-31')
    resource.post.mockResolvedValueOnce({
      assignment_id: 77,
      employment_id: 21,
      host_org_unit_id: 4,
      assignment_type: 'SECONDMENT',
      is_primary: false,
      valid_from: '2026-12-01',
      valid_to: '2027-03-31',
    })
    await wrapper.get('[data-testid="movement-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/lifecycle/secondments',
      {
        employment_id: 21,
        host_org_unit_id: 4,
        position_id: null,
        effective_from: '2026-12-01',
        effective_to: '2027-03-31',
        reason: 'Synthetic secondment',
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })
})

describe('MovementDetailPage and MovementTimeline', () => {
  it('shows the object summary, prominent effective date and real timeline with actor and timestamp', async () => {
    installDetailReads()
    const { wrapper } = await mountDetail()
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/lifecycle/cases/31')
    expect(resource.get).toHaveBeenCalledWith('/v1/cases/31/timeline')
    expect(wrapper.text()).toContain('CASE-2026-0031')
    expect(wrapper.text()).toContain('调岗/调部门')
    expect(wrapper.text()).toContain('2026年12月1日')
    expect(wrapper.text()).toContain('不会立即变更')
    expect(wrapper.text()).toContain('Synthetic HR')
    expect(wrapper.text()).toContain('2026-11-21 10:30')
    expect(wrapper.findComponent(MovementTimeline).exists()).toBe(true)
    expect(wrapper.text()).toContain('批准')
  })

  it('lets an awaiting assignee approve and reloads the case after a decision', async () => {
    installDetailReads()
    resource.post.mockResolvedValueOnce({
      case_id: 31,
      application_status: 'APPROVED',
      effect_status: 'APPLIED',
      effects: [],
    })
    const { wrapper } = await mountDetail()
    await flushPromises()

    expect(wrapper.find('[data-testid="movement-decision-panel"]').exists()).toBe(true)
    await wrapper.get('[data-testid="movement-decision-comment"]').setValue('Synthetic approval')
    await wrapper.get('[data-testid="movement-approve"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/cases/31/decisions',
      { action: 'APPROVE', comment: 'Synthetic approval' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('审批决定已提交')
  })

  it('keeps a decided case read-only even for a user with workflow.case.decide', async () => {
    installDetailReads({
      lifecycle: {
        case_id: 31,
        case_no: 'CASE-2026-0031',
        case_type: 'ASSIGNMENT_CHANGE',
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
        },
        employment_id: 21,
        result_employment_id: 21,
        employment: {
          employment_id: 21,
          person_id: 11,
          employee_no: 'E-1001',
          status: 'ACTIVE',
          valid_from: '2026-01-01',
          valid_to: '9999-12-31',
        },
        checklist: [],
      },
      workflow: workflowCaseRaw({
        application_status: 'APPROVED',
        effect_status: 'APPLIED',
        is_awaiting_me: false,
        closed_at: '2026-11-21T02:30:00Z',
      }),
    })

    const { wrapper } = await mountDetail()
    await flushPromises()

    expect(wrapper.text()).toContain('该案例已结束，只读')
    expect(wrapper.find('[data-testid="movement-decision-panel"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="movement-approve"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="movement-reject"]').exists()).toBe(false)
  })

  it('shows a forbidden state without reads when lifecycle.case.read is absent', async () => {
    const { wrapper } = await mountDetail(['workflow.case.read'])
    await flushPromises()

    expect(resource.get).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('无权')
  })
})
