import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router'
import ElementPlus from 'element-plus'
import type { Component } from 'vue'
import { ApiError } from '@/api/errors'
import { PAGE_REGISTRY } from '@/router/registry'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  patch: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
  resourcePatch: resource.patch,
  resourcePut: resource.put,
  resourceDelete: resource.delete,
}))

import { PLATFORM_COMPONENT_KEYS, platformComponentFor } from '@/views/platform'

const wave5Keys = PAGE_REGISTRY.filter((page) => page.wave === 'Wave 5').map((page) => page.componentKey)

function listResponse(items: unknown[] = []) {
  return { items, page: 1, page_size: 20, total: items.length }
}

const dashboard = {
  asOf: '2026-11-25',
  freshness: '2026-11-25T06:00:00Z',
  greeting: 'Welcome back',
  metrics: [
    {
      code: 'headcount',
      label: 'Headcount',
      value: 42,
      unit: 'COUNT',
      status: 'AVAILABLE',
      reason: null,
      trend: null,
      trendDirection: 'FLAT',
      drilldownLabel: 'View details',
      sourceHash: 'synthetic-hash',
      computedAt: '2026-11-25T06:00:00Z',
    },
    {
      code: 'pending_approvals',
      label: 'Pending approvals',
      value: 3,
      unit: 'COUNT',
      status: 'AVAILABLE',
      reason: null,
      trend: null,
      trendDirection: 'FLAT',
      drilldownLabel: 'View details',
      sourceHash: 'synthetic-hash-2',
      computedAt: '2026-11-25T06:00:00Z',
    },
  ],
  pendingApprovals: [
    {
      id: 77,
      caseNo: 'CASE-2026-0077',
      title: 'Synthetic approval task',
      status: 'IN_APPROVAL',
      ageDays: 4,
      dueAt: '2026-11-26T06:00:00Z',
    },
  ],
  recentNotices: [],
  contractAlerts: [],
}

const headcountMetric = {
  metric: 'headcount',
  label: 'Headcount',
  predicate: 'synthetic',
  asOf: '2026-11-25',
  total: 2,
  unit: 'COUNT',
  status: 'AVAILABLE',
  reason: null,
  columns: [
    { key: 'primary', label: 'Name' },
    { key: 'secondary', label: 'Organization' },
    { key: 'status', label: 'Status' },
  ],
  items: [
    {
      id: '1',
      primary: 'Synthetic Employee',
      secondary: 'Synthetic Manufacturing Department · E1001',
      status: 'ACTIVE',
      detail: 'Synthetic Manufacturing Department · E1001',
      value: 1,
      link: '/employees?employee=1',
      employee: 'Synthetic Employee',
      employee_no: 'E1001',
      org_path: 'Synthetic Manufacturing Department',
    },
  ],
  rows: [],
}

async function mountAt(key: string, path: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const routes: RouteRecordRaw[] = [
    { path, name: `platform-${key.replaceAll('/', '-')}`, component: { template: '<div />' } },
    { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
    { path: '/login', name: 'login', component: { template: '<div />' } },
    { path: '/:pathMatch(.*)*', name: 'catchall', component: { template: '<div />' } },
  ]
  const router = createRouter({ history: createMemoryHistory(), routes })
  await router.push(path)
  await router.isReady()

  const component = platformComponentFor(key)
  if (component === null) throw new Error(`No platform component registered for ${key}`)
  const wrapper = mount(component as Component, {
    global: { plugins: [pinia, router, ElementPlus] },
  })
  return { wrapper, router }
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
  resource.put.mockReset()
  resource.delete.mockReset()
})

describe('Wave 5 platform and business pages', () => {
  it('maps exactly all 36 Wave 5 registry component keys', () => {
    expect(PLATFORM_COMPONENT_KEYS).toHaveLength(36)
    expect(new Set(PLATFORM_COMPONENT_KEYS).size).toBe(36)
    expect([...PLATFORM_COMPONENT_KEYS].sort()).toEqual([...wave5Keys].sort())
    expect(wave5Keys).toHaveLength(36)
    for (const key of wave5Keys) {
      expect(platformComponentFor(key), key).not.toBeNull()
    }
    expect(platformComponentFor('dashboard/not-real')).toBeNull()
  })

  it('loads department headcount from the live dashboard and metric projections', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/reporting/dashboard') return Promise.resolve(dashboard)
      if (path === '/v1/reporting/dashboard/metrics/headcount') return Promise.resolve(headcountMetric)
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const { wrapper } = await mountAt('dashboard/departments', '/dashboard/departments')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/reporting/dashboard', expect.any(Object))
    expect(resource.get).toHaveBeenCalledWith(
      '/v1/reporting/dashboard/metrics/headcount',
      expect.objectContaining({ as_of: '2026-11-25' }),
    )
    expect(wrapper.text()).toContain('Synthetic Manufacturing Department')
    expect(wrapper.text()).toContain('Synthetic Employee')
    expect(wrapper.text()).toContain('42')
    expect(wrapper.text()).not.toContain('{"')
  })

  it('routes a 403 report load to the forbidden page with its request id', async () => {
    resource.get.mockRejectedValue(
      new ApiError('FORBIDDEN', 'Forbidden.', { status: 403, requestId: 'req-report-forbidden' }),
    )

    const { wrapper, router } = await mountAt('reports/payroll', '/reports/payroll')
    await flushPromises()
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/reporting/definitions')
    expect(router.currentRoute.value.name).toBe('forbidden')
    expect(router.currentRoute.value.query.request_id).toBe('req-report-forbidden')
    expect(wrapper.text()).not.toContain('{"')
  })

  it('creates a notice with the real validated fields and an idempotency key', async () => {
    resource.get.mockResolvedValue(listResponse())
    resource.post.mockResolvedValue({
      id: 91,
      title: 'Synthetic notice',
      summary: 'Synthetic body',
      body: ['Synthetic body'],
      status: 'PUBLISHED',
      audienceLabel: '全体员工',
      publishedAt: '2026-11-25T07:00:00Z',
      retractedAt: null,
      authorName: null,
      requiresAck: true,
      acknowledged: false,
      acknowledgementCount: 0,
      audienceCount: 10,
    })

    const { wrapper } = await mountAt('notices/editor', '/notices/editor')
    await flushPromises()

    await wrapper.get('[data-testid="notice-title"]').setValue('Synthetic notice')
    await wrapper.get('[data-testid="notice-body"]').setValue('Synthetic body')
    await wrapper.get('[data-testid="notice-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/engagement/notices',
      expect.objectContaining({
        title: 'Synthetic notice',
        body: 'Synthetic body',
        audience: 'ALL',
        requiresAck: true,
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('公告已发布')
  })

  it('creates a user with username, email, password and privileged flag', async () => {
    resource.get.mockResolvedValue(
      listResponse([
        {
          id: 1,
          username: 'synthetic.admin',
          email: 'admin@example.invalid',
          status: 'ACTIVE',
          is_privileged: true,
          mfa_enrolled: true,
          auth_version: 1,
        },
      ]),
    )
    resource.post.mockResolvedValue({ id: 2, username: 'synthetic.user', status: 'ACTIVE' })

    const { wrapper } = await mountAt('system/system-users', '/system/system-users')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/users', expect.objectContaining({ page: 1 }))
    await wrapper.get('[data-testid="user-username"]').setValue('synthetic.user')
    await wrapper.get('[data-testid="user-email"]').setValue('user@example.invalid')
    await wrapper.get('[data-testid="user-password"]').setValue('SyntheticPassword123!')
    await wrapper.get('[data-testid="user-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/users',
      expect.objectContaining({
        username: 'synthetic.user',
        email: 'user@example.invalid',
        password: 'SyntheticPassword123!',
        is_privileged: false,
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('synthetic.admin')
    expect(wrapper.text()).toContain('用户已创建')
  })

  it('renders menus, actions, field capabilities and scopes from permissions plus the capability catalog', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/me/permissions') {
        return Promise.resolve({
          menus: [
            {
              key: '系统管理',
              label: '系统管理',
              order: 101,
              icon: 'Menu',
              pages: [{ page_code: 'system-permissions', path: '/system/permissions', menu_label: '操作权限配置' }],
            },
          ],
          actions: ['iam.permission.read', 'iam.role.manage'],
          field_capabilities: ['P1_INTERNAL', 'P3_RESTRICTED'],
          scope_labels: [
            { anchor_type: 'LEGAL_ENTITY', label: '本公司/法人' },
            { anchor_type: 'SELF', label: '仅本人' },
          ],
          auth_version: 3,
          pages: [],
          wildcard: false,
        })
      }
      if (path === '/v1/capabilities') {
        return Promise.resolve({
          capabilities: [
            {
              code: 'iam.permission.read',
              resource: 'iam_permission',
              actions: ['read'],
              anchor_types: ['LEGAL_ENTITY'],
              field_profile_max: 'P1_INTERNAL',
              risk: 'medium',
              sod_group: null,
              description: 'Read permission resources.',
            },
          ],
          read_only: true,
        })
      }
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const { wrapper } = await mountAt('system/system-permissions', '/system/system-permissions')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/me/permissions')
    expect(resource.get).toHaveBeenCalledWith('/v1/capabilities', expect.objectContaining({ legal_entity_id: 1 }))
    expect(wrapper.text()).toContain('系统管理')
    expect(wrapper.text()).toContain('iam.permission.read')
    expect(wrapper.text()).toContain('P3 受限')
    expect(wrapper.text()).toContain('本公司/法人')
    expect(wrapper.text()).not.toContain('{"')
  })

  it('renders audit events and access review without dumping JSON', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/auth/events') {
        return Promise.resolve(
          listResponse([
            {
              id: 7,
              user_id: 1,
              event_type: 'login.success',
              result: 'SUCCESS',
              metadata: { ip: '127.0.0.1' },
              request_id: 'req-event-7',
              occurred_at: '2026-11-25T06:10:00Z',
            },
          ]),
        )
      }
      if (path === '/v1/access-review') {
        return Promise.resolve({
          generated_at: '2026-11-25T06:20:00Z',
          unused_high_risk_bindings: [
            {
              binding_id: 12,
              principal_id: 9,
              capability_code: 'iam.audit.read',
              risk: 'high',
              created_at: '2026-08-01T00:00:00Z',
            },
          ],
          sod_risks: [
            {
              principal_id: 9,
              capabilities: ['payroll.run.prepare', 'payroll.run.approve'],
              kind: 'CUMULATIVE',
            },
          ],
        })
      }
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const { wrapper } = await mountAt('system/system-audit', '/system/system-audit')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/auth/events', expect.objectContaining({ page: 1 }))
    expect(resource.get).toHaveBeenCalledWith('/v1/access-review', expect.objectContaining({ legal_entity_id: 1 }))
    expect(wrapper.text()).toContain('login.success')
    expect(wrapper.text()).toContain('req-event-7')
    expect(wrapper.text()).toContain('iam.audit.read')
    expect(wrapper.text()).toContain('CUMULATIVE')
    expect(wrapper.text()).not.toContain('{"')
  })

  it('runs the payroll report through POST /v1/reporting/run with the definition code', async () => {
    resource.get.mockResolvedValue([
      {
        id: 5,
        code: 'PAYROLL_COST',
        name: 'Payroll cost by legal entity',
        description: 'Synthetic payroll report definition',
        asOfSupported: true,
        drilldownRoute: '/dashboard/metric/payroll_cost_by_entity',
        filters: [],
        fields: [
          { key: 'legal_entity', label: 'Legal entity', profile: 'P0_PUBLIC', exportable: true },
          { key: 'gross_cents', label: 'Gross', profile: 'P2_SENSITIVE', exportable: true },
          { key: 'salary', label: 'Restricted salary detail', profile: 'P3_RESTRICTED', exportable: false },
        ],
      },
    ])
    resource.post.mockResolvedValue({
      runId: 9,
      definitionCode: 'PAYROLL_COST',
      title: 'Payroll cost by legal entity',
      subtitle: '2026-11-25',
      columns: [{ key: 'legal_entity', label: 'Legal entity', profile: 'P0_PUBLIC' }],
      rows: [{ legal_entity: 'Synthetic Legal Entity' }],
      chart: [],
      totalRows: 1,
      generatedAt: '2026-11-25T07:00:00Z',
      watermark: null,
    })

    const { wrapper } = await mountAt('reports/payroll', '/reports/payroll')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/reporting/definitions')
    expect(resource.post).toHaveBeenCalledWith(
      '/v1/reporting/run',
      { definition_code: 'PAYROLL_COST', filters: {} },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('Synthetic Legal Entity')
    expect(wrapper.text()).not.toContain('{"')
  })

  it('creates an export through POST /v1/reporting/exports and renders the short-lived download link', async () => {
    resource.get.mockResolvedValue([
      {
        id: 2,
        code: 'WORKFORCE_HEADCOUNT',
        name: 'Workforce headcount',
        description: 'Synthetic headcount definition',
        asOfSupported: true,
        drilldownRoute: null,
        filters: [],
        fields: [
          { key: 'employee', label: 'Employee', profile: 'P1_INTERNAL', exportable: true },
          { key: 'status', label: 'Status', profile: 'P0_PUBLIC', exportable: true },
        ],
      },
    ])
    resource.post.mockResolvedValue({
      id: '44',
      reportCode: 'WORKFORCE_HEADCOUNT',
      reportName: 'Workforce headcount',
      status: 'COMPLETED',
      purpose: 'AUDIT',
      rowCount: 2,
      createdAt: '2026-11-25T07:00:00Z',
      expiresAt: '2026-11-26T07:00:00Z',
      downloadCount: 0,
      maxDownloads: 5,
      watermark: 'synthetic-watermark',
      fileName: 'workforce_headcount-44.csv',
      downloadToken: 'synthetic-token',
      downloadUrl: '/v1/reporting/exports/44/download?token=synthetic-token',
    })

    const { wrapper } = await mountAt('reports/exports', '/reports/exports')
    await flushPromises()

    await wrapper.get('[data-testid="export-purpose"]').setValue('AUDIT')
    await wrapper.get('[data-testid="export-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/reporting/exports',
      {
        reportCode: 'WORKFORCE_HEADCOUNT',
        purpose: 'AUDIT',
        fields: ['employee', 'status'],
        filters: {},
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.get('[data-testid="export-download"]').attributes('href')).toBe(
      '/v1/reporting/exports/44/download?token=synthetic-token',
    )
    expect(wrapper.text()).not.toContain('{"')
  })

  it('acknowledges and retracts a notice through the notice-specific POST routes', async () => {
    const notice = {
      id: 31,
      title: 'Synthetic acknowledgment notice',
      summary: 'Synthetic notice body',
      body: ['Synthetic notice body'],
      status: 'PUBLISHED',
      audience: 'ALL',
      audienceLabel: '全体员工',
      orgUnitIds: [],
      roleCodes: [],
      publishedAt: '2026-11-25T06:00:00Z',
      retractedAt: null,
      authorName: null,
      requiresAck: true,
      acknowledged: false,
      acknowledgementCount: 1,
      audienceCount: 8,
    }
    resource.get.mockResolvedValue([notice])
    resource.post.mockResolvedValue({
      ...notice,
      acknowledged: true,
      acknowledgementCount: 2,
    })

    const { wrapper } = await mountAt('notices/index', '/notices')
    await flushPromises()

    const acknowledge = wrapper.findAll('button').find((button) => button.text() === '确认已读')
    expect(acknowledge, 'acknowledge button').toBeDefined()
    await acknowledge!.trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/engagement/notices/31/acknowledge',
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )

    const retract = wrapper.findAll('button').find((button) => button.text() === '撤回')
    expect(retract, 'retract button').toBeDefined()
    await retract!.trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/engagement/notices/31/retract',
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('updates user status with PATCH semantics and assigns a role using only validated fields', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/users') {
        return Promise.resolve(
          listResponse([
            {
              id: 5,
              username: 'synthetic.assignee',
              email: null,
              status: 'ACTIVE',
              is_privileged: false,
              mfa_enrolled: true,
              auth_version: 1,
            },
          ]),
        )
      }
      if (path === '/v1/users/5/bindings') return Promise.resolve({ bindings: [] })
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })
    resource.post.mockResolvedValue({})
    resource.patch.mockResolvedValue({ id: 5, status: 'LOCKED', auth_version: 2 })

    const { wrapper } = await mountAt('system/system-users', '/system/system-users')
    await flushPromises()

    const manage = wrapper.findAll('button').find((button) => button.text() === '管理')
    expect(manage, 'manage button').toBeDefined()
    await manage!.trigger('click')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith(
      '/v1/users/5/bindings',
      expect.objectContaining({ legal_entity_id: 1 }),
    )

    await wrapper.get('[data-testid="user-status"]').setValue('LOCKED')
    await wrapper.get('[data-testid="user-status-reason"]').setValue('Synthetic security review')
    await wrapper.get('[data-testid="user-status-submit"]').trigger('click')
    await flushPromises()

    expect(resource.patch).toHaveBeenCalledWith(
      '/v1/users/5',
      { status: 'LOCKED', reason: 'Synthetic security review' },
      expect.objectContaining({
        headers: expect.objectContaining({ 'X-Legal-Entity-Id': '1' }),
        idempotencyKey: expect.any(String),
      }),
    )

    await wrapper.get('[data-testid="role-code"]').setValue('HR_SPECIALIST')
    await wrapper.get('[data-testid="role-reason"]').setValue('Synthetic HR support')
    await wrapper.get('[data-testid="role-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/users/5/roles',
      {
        role_code: 'HR_SPECIALIST',
        reason: 'Synthetic HR support',
        acknowledge_sod_risk: false,
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('creates an exception role through POST /v1/roles with the real capability selection', async () => {
    resource.get.mockResolvedValue({
      roles: [
        {
          code: 'HR_SPECIALIST',
          name: 'HR Specialist',
          is_template: true,
          purpose: 'Synthetic role purpose',
          expires_at: null,
          capabilities: [
            { capability: 'people.employee.read', anchor_type: 'LEGAL_ENTITY', field_profile: 'P1_INTERNAL' },
          ],
        },
      ],
    })
    resource.post.mockResolvedValue({
      code: 'TEMP_REPORT_VIEWER',
      name: 'Temporary report viewer',
      is_template: false,
      purpose: 'Synthetic quarterly reporting access.',
      expires_at: null,
      capabilities: [],
    })

    const { wrapper } = await mountAt('system/system-roles', '/system/system-roles')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/roles', expect.objectContaining({ legal_entity_id: 1 }))
    expect(wrapper.text()).toContain('HR Specialist')

    await wrapper.get('[data-testid="role-create-code"]').setValue('TEMP_REPORT_VIEWER')
    await wrapper.get('[data-testid="role-create-name"]').setValue('Temporary report viewer')
    await wrapper.get('[data-testid="role-create-purpose"]').setValue('Synthetic quarterly reporting access.')
    await wrapper.get('[data-testid="role-create-capability"]').setValue('reporting.export')
    await wrapper.get('[data-testid="role-create-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/roles',
      expect.objectContaining({
        code: 'TEMP_REPORT_VIEWER',
        purpose: 'Synthetic quarterly reporting access.',
        capabilities: [
          { capability: 'reporting.export', anchor_type: 'LEGAL_ENTITY', field_profile: 'P1_INTERNAL' },
        ],
      }),
      expect.objectContaining({
        idempotencyKey: expect.any(String),
        headers: expect.objectContaining({ 'X-Legal-Entity-Id': '1' }),
      }),
    )
    expect(wrapper.text()).toContain('例外角色已创建')
  })

  it('loads the legal-entity calendar for 2026 and the real leave-type catalog', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/time/calendars/1') {
        return Promise.resolve({
          legal_entity_id: 1,
          year: 2026,
          timezone: 'Asia/Shanghai',
          workweek: [1, 2, 3, 4, 5],
          standard_day_minutes: 480,
          version: 2,
          holidays: [
            { date: '2026-10-01', name: 'Synthetic National Day', type: 'STATUTORY', source: 'SYNTHETIC' },
          ],
        })
      }
      if (path === '/v1/time/leave/types') {
        return Promise.resolve(
          listResponse([
            {
              id: 1,
              code: 'ANNUAL',
              name: 'Synthetic Annual Leave',
              unit: 'DAY',
              is_statutory: false,
              is_annual: true,
              annual_entitlement_days: '10.00',
              requires_attachment: false,
              allow_negative: false,
              version: 1,
            },
          ]),
        )
      }
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const { wrapper } = await mountAt('system/system-calendars', '/system/system-calendars')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/time/calendars/1', { year: 2026 })
    expect(resource.get).toHaveBeenCalledWith('/v1/time/leave/types')
    expect(wrapper.text()).toContain('Asia/Shanghai')
    expect(wrapper.text()).toContain('Synthetic National Day')
    expect(wrapper.text()).toContain('Synthetic Annual Leave')
    expect(wrapper.text()).not.toContain('{"')
  })

  it('loads self-service data from GET /v1/me/workspace and renders profile facts', async () => {
    resource.get.mockResolvedValue({
      employment_linked: true,
      profile: {
        employmentId: 1,
        employeeNo: 'E1001',
        name: 'Synthetic Self Service Employee',
        initials: 'SE',
        jobTitle: 'Synthetic Operator',
        orgName: 'Synthetic Assembly Department',
        managerName: 'Synthetic Manager',
        workLocation: 'Synthetic Plant',
        email: 'employee@example.invalid',
        phone: { display: null, masked: true, profile: 'P1_INTERNAL', revealable: false, exportable: false },
      },
      contract: null,
      businessDate: '2026-11-25',
      attendanceSummary: {
        expectedDays: 20,
        presentDays: 19,
        lateCount: 1,
        absentCount: 0,
        leaveDays: 1,
        remoteDays: 2,
      },
      attendanceMonth: '2026-11',
      attendanceException: null,
      leaveBalances: [],
      myRequests: [],
      payslips: [],
      notices: [],
      freshness: '2026-11-25T06:00:00Z',
    })

    const { wrapper } = await mountAt('self-service', '/self-service')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/me/workspace')
    expect(wrapper.text()).toContain('Synthetic Self Service Employee')
    expect(wrapper.text()).toContain('Synthetic Assembly Department')
    expect(wrapper.text()).toContain('19')
    expect(wrapper.text()).not.toContain('{"')
  })

  it('loads Workflow templates and my tasks from their real APIs', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/tasks/my') {
        return Promise.resolve(
          listResponse([
            {
              task_id: 61,
              case_id: 88,
              case_no: 'CASE-2026-0088',
              title: 'Synthetic workflow task',
              node_key: 'hr_review',
              application_status: 'IN_APPROVAL',
              effect_status: 'SCHEDULED',
              due_at: '2026-11-26T06:00:00Z',
              sla_state: 'ON_TRACK',
            },
          ]),
        )
      }
      if (path === '/v1/workflow/templates') {
        return Promise.resolve(
          listResponse([
            {
              id: 4,
              code: 'leave.request',
              name: 'Leave request',
              version: 1,
              status: 'PUBLISHED',
              definition: {
                nodes: [
                  { key: 'start', name: 'Start', type: 'START', sequence: 1 },
                  { key: 'approval', name: 'Manager approval', type: 'APPROVAL', sequence: 2 },
                ],
              },
            },
          ]),
        )
      }
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const { wrapper } = await mountAt('workflow', '/workflow')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/tasks/my')
    expect(resource.get).toHaveBeenCalledWith('/v1/workflow/templates')
    expect(wrapper.text()).toContain('Synthetic workflow task')
    expect(wrapper.text()).toContain('Leave request')
    expect(wrapper.text()).toContain('Manager approval')
    expect(wrapper.text()).not.toContain('{"')
  })

  it('loads notification templates and delivery attempts from the real Platform endpoints', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/tasks/my') return Promise.resolve(listResponse([]))
      if (path === '/v1/engagement/notices') {
        return Promise.resolve([
          {
            id: 9,
            title: 'Synthetic notification notice',
            summary: 'Synthetic body',
            body: ['Synthetic body'],
            status: 'PUBLISHED',
            audience: 'ALL',
            audienceLabel: '全体员工',
            publishedAt: '2026-11-25T06:00:00Z',
            requiresAck: false,
            acknowledged: true,
            acknowledgementCount: 1,
            audienceCount: 10,
          },
        ])
      }
      if (path === '/v1/notification-templates') {
        return Promise.resolve(
          listResponse([
            {
              id: 3,
              code: 'APPROVAL_PENDING',
              channel: 'IN_APP',
              subject: 'Approval pending',
              body: 'You have a pending approval.',
              version: 1,
              active: true,
            },
          ]),
        )
      }
      if (path === '/v1/notifications/deliveries') {
        return Promise.resolve(
          listResponse([
            {
              id: 11,
              delivery_id: 11,
              template_code: 'APPROVAL_PENDING',
              recipient_user_id: 1,
              channel: 'IN_APP',
              status: 'FAILED',
              attempts: 1,
              last_error: 'Synthetic transient failure',
              sent_at: null,
            },
          ]),
        )
      }
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })
    resource.post.mockResolvedValue({ id: 11, status: 'SENT', attempts: 2 })

    const { wrapper } = await mountAt('notifications', '/notifications')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/tasks/my')
    expect(resource.get).toHaveBeenCalledWith('/v1/engagement/notices', { filter: 'all' })
    expect(resource.get).toHaveBeenCalledWith('/v1/notification-templates', expect.objectContaining({ page: 1 }))
    expect(resource.get).toHaveBeenCalledWith('/v1/notifications/deliveries', expect.objectContaining({ page: 1 }))
    expect(wrapper.text()).toContain('Synthetic notification notice')
    expect(wrapper.text()).toContain('APPROVAL_PENDING')
    expect(wrapper.text()).toContain('Synthetic transient failure')
    expect(wrapper.text()).not.toContain('后端接口待补')

    await wrapper.get('[data-testid="delivery-retry"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/notifications/deliveries/11/retry',
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('renders a true empty state when the dashboard returns no metrics or panels', async () => {
    resource.get.mockResolvedValue({
      asOf: '2026-11-25',
      freshness: '2026-11-25T06:00:00Z',
      greeting: 'Welcome back',
      metrics: [],
      pendingApprovals: [],
      recentNotices: [],
      contractAlerts: [],
    })

    const { wrapper } = await mountAt('dashboard/index', '/dashboard')
    await flushPromises()

    expect(wrapper.text()).toContain('当前没有可展示的真实数据')
    expect(wrapper.text()).not.toContain('{"')
  })

  it('shows the server reason when a dashboard metric is explicitly unavailable', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/reporting/dashboard') {
        return Promise.resolve({
          ...dashboard,
          metrics: [
            {
              ...dashboard.metrics[0],
              code: 'recruitment_funnel',
              label: 'Recruitment funnel',
              status: 'UNAVAILABLE',
              reason: 'Talent projections are not installed.',
              value: 0,
            },
          ],
          pendingApprovals: [],
        })
      }
      if (path === '/v1/reporting/dashboard/metrics/recruitment_funnel') {
        return Promise.resolve({
          ...headcountMetric,
          metric: 'recruitment_funnel',
          label: 'Recruitment funnel',
          status: 'UNAVAILABLE',
          reason: 'Talent projections are not installed.',
          total: 0,
          items: [],
          rows: [],
        })
      }
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const { wrapper } = await mountAt('dashboard/recruitment', '/dashboard/recruitment')
    await flushPromises()

    expect(wrapper.text()).toContain('Talent projections are not installed.')
    expect(wrapper.text()).toContain('不可用')
    expect(wrapper.text()).not.toContain('{"')
  })

  it('loads account security state from GET /v1/me and drives MFA plus logout-all writes', async () => {
    resource.get.mockResolvedValue({
      id: 1,
      tenant_id: 1,
      username: 'synthetic.operator',
      email: 'operator@example.invalid',
      employment_id: 1,
      is_privileged: true,
      has_mfa: false,
      mfa_verified: true,
      auth_version: 4,
      tenant_name: 'Synthetic Manufacturing',
      legal_entity_name: 'Synthetic Manufacturing',
      roles: ['HR_ADMIN'],
    })
    resource.post.mockImplementation((path: string) => {
      if (path === '/v1/auth/mfa/totp/enroll') {
        return Promise.resolve({
          factor_id: 12,
          secret: 'SYNTHETICSECRET',
          otpauth_uri: 'otpauth://totp/HRMS:synthetic.operator?secret=SYNTHETICSECRET',
        })
      }
      if (path === '/v1/auth/mfa/totp/confirm') {
        return Promise.resolve({ confirmed: true, recovery_codes: ['SYNTHETIC-CODE-1'] })
      }
      if (path === '/v1/auth/logout-all') return Promise.resolve({ logged_out: true, sessions_revoked: true })
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const { wrapper } = await mountAt('account', '/account')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/me')
    expect(wrapper.text()).toContain('synthetic.operator')
    expect(wrapper.text()).toContain('HR_ADMIN')

    await wrapper.get('[data-testid="mfa-enroll"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="mfa-factor-id"]').element).toHaveProperty('value', '12')

    await wrapper.get('[data-testid="mfa-code"]').setValue('123456')
    await wrapper.get('[data-testid="mfa-confirm"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('SYNTHETIC-CODE-1')

    await wrapper.get('[data-testid="logout-all"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/auth/mfa/totp/enroll',
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(resource.post).toHaveBeenCalledWith(
      '/v1/auth/mfa/totp/confirm',
      { factor_id: 12, code: '123456' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(resource.post).toHaveBeenCalledWith(
      '/v1/auth/logout-all',
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('posts notice creation with the selected ORG audience ids and real required-ack flag', async () => {
    resource.get.mockResolvedValue([])
    resource.post.mockResolvedValue({ id: 101, title: 'Synthetic ORG notice', status: 'PUBLISHED' })

    const { wrapper } = await mountAt('notices/editor', '/notices/editor')
    await flushPromises()

    await wrapper.get('[data-testid="notice-title"]').setValue('Synthetic ORG notice')
    await wrapper.get('[data-testid="notice-body"]').setValue('Synthetic ORG body')
    await wrapper.get('[data-testid="notice-audience"]').setValue('ORG')
    await wrapper.get('[data-testid="notice-org-ids"]').setValue('10, 12')
    await wrapper.get('[data-testid="notice-requires-ack"]').setValue(false)
    await wrapper.get('[data-testid="notice-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/engagement/notices',
      {
        title: 'Synthetic ORG notice',
        body: 'Synthetic ORG body',
        audience: 'ORG',
        audienceLabel: '全体员工',
        requiresAck: false,
        orgUnitIds: [10, 12],
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('opens a notice detail via GET /v1/engagement/notices/{notice}', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/engagement/notices') {
        return Promise.resolve([
          {
            id: 41,
            title: 'Synthetic detail notice',
            summary: 'Synthetic summary',
            body: ['Synthetic summary'],
            status: 'PUBLISHED',
            audience: 'ALL',
            audienceLabel: '全体员工',
            publishedAt: '2026-11-25T06:00:00Z',
            retractedAt: null,
            authorName: null,
            requiresAck: false,
            acknowledged: true,
            acknowledgementCount: 1,
            audienceCount: 10,
          },
        ])
      }
      if (path === '/v1/engagement/notices/41') {
        return Promise.resolve({
          id: 41,
          title: 'Synthetic detail notice',
          summary: 'Synthetic summary',
          body: ['First synthetic paragraph', 'Second synthetic paragraph'],
          status: 'PUBLISHED',
          audience: 'ALL',
          audienceLabel: '全体员工',
          publishedAt: '2026-11-25T06:00:00Z',
          retractedAt: null,
          authorName: null,
          requiresAck: false,
          acknowledged: true,
          acknowledgementCount: 1,
          audienceCount: 10,
        })
      }
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const { wrapper } = await mountAt('notices/index', '/notices')
    await flushPromises()

    const detailButton = wrapper.findAll('button').find((button) => button.text() === '查看全文')
    expect(detailButton, 'detail button').toBeDefined()
    await detailButton!.trigger('click')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/engagement/notices/41')
    expect(wrapper.get('[data-testid="notice-detail"]').text()).toContain('First synthetic paragraph')
    expect(wrapper.text()).not.toContain('{"')
  })

  it('keeps successful panels visible when a secondary audit read fails, with a partial request id', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/auth/events') {
        return Promise.resolve(
          listResponse([
            {
              id: 8,
              user_id: 1,
              event_type: 'role.assigned',
              result: 'SUCCESS',
              metadata: null,
              request_id: 'req-event-8',
              occurred_at: '2026-11-25T06:30:00Z',
            },
          ]),
        )
      }
      if (path === '/v1/access-review') {
        return Promise.reject(
          new ApiError('SERVICE_UNAVAILABLE', 'Unavailable.', { status: 503, requestId: 'req-review-503' }),
        )
      }
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const { wrapper, router } = await mountAt('system/system-audit', '/system/system-audit')
    await flushPromises()

    expect(router.currentRoute.value.name).not.toBe('forbidden')
    expect(wrapper.text()).toContain('role.assigned')
    expect(wrapper.get('[data-testid="platform-partial"]').text()).toContain('req-review-503')
    expect(wrapper.text()).not.toContain('{"')
  })

  it('shows the health-benefits gap without pretending an API exists', async () => {
    const { wrapper } = await mountAt('benefits', '/benefits')
    await flushPromises()

    expect(wrapper.get('[data-testid="backend-gap"]').text()).toContain('后端接口待补: 社保公积金与福利模块')
    expect(wrapper.text()).toContain('GET /v1/benefits')
    expect(resource.get).not.toHaveBeenCalled()
    expect(wrapper.text()).not.toContain('{"')
  })

  it('renders the server request id on a 5xx page-load error', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/auth/events') {
        return Promise.reject(
          new ApiError('INTERNAL', 'Internal error.', { status: 500, requestId: 'req-audit-500' }),
        )
      }
      return Promise.resolve({
        generated_at: '2026-11-25T06:20:00Z',
        unused_high_risk_bindings: [],
        sod_risks: [],
      })
    })

    const { wrapper } = await mountAt('system/system-audit', '/system/system-audit')
    await flushPromises()

    expect(wrapper.get('[data-testid="request-id"]').text()).toContain('req-audit-500')
    expect(wrapper.text()).not.toContain('{"')
  })

  it('loads menu overrides and deletes one through DELETE /v1/system/menus/{page_code}', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/me/permissions') {
        return Promise.resolve({ menus: [], actions: [], field_capabilities: [], scope_labels: [], pages: [] })
      }
      if (path === '/v1/capabilities') return Promise.resolve({ capabilities: [], read_only: true })
      if (path === '/v1/system/menus') {
        return Promise.resolve(
          listResponse([
            { page_code: 'system-roles', label: '角色（隐藏）', visible: false, sort_order: 7 },
          ]),
        )
      }
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })
    resource.delete.mockResolvedValue({ page_code: 'system-roles', deleted: true })

    const { wrapper } = await mountAt('system/system-menus', '/system/system-menus')
    await flushPromises()

    expect(wrapper.text()).toContain('system-roles')
    expect(wrapper.text()).toContain('角色（隐藏）')

    await wrapper.get('[data-testid="menu-override-delete"]').trigger('click')
    await flushPromises()

    expect(resource.delete).toHaveBeenCalledWith(
      '/v1/system/menus/system-roles',
      expect.objectContaining({
        idempotencyKey: expect.any(String),
        headers: expect.objectContaining({ 'X-Legal-Entity-Id': '1' }),
      }),
    )
    expect(wrapper.text()).toContain('菜单覆盖已删除')
  })

  it('loads data scope templates and tightens one through PUT', async () => {
    resource.get.mockResolvedValue(
      listResponse([
        {
          template: 'ORG_SUBTREE',
          anchor_type: 'ORG_SUBTREE',
          label: '本组织及下级',
          active: true,
          sort_order: 3,
          description: 'Synthetic scope template',
        },
      ]),
    )
    resource.put.mockResolvedValue({
      template: 'ORG_SUBTREE',
      anchor_type: 'ORG_SUBTREE',
      label: '本组织及下级（收窄）',
      active: true,
      sort_order: 3,
    })

    const { wrapper } = await mountAt('system/system-data-scopes', '/system/system-data-scopes')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/system/data-scopes', expect.objectContaining({ page: 1 }))
    expect(wrapper.text()).toContain('ORG_SUBTREE')
    expect(wrapper.text()).not.toContain('后端接口待补')

    await wrapper.get('[data-testid="data-scope-label"]').setValue('本组织及下级（收窄）')
    await wrapper.get('[data-testid="data-scope-submit"]').trigger('click')
    await flushPromises()

    expect(resource.put).toHaveBeenCalledWith(
      '/v1/system/data-scopes/ORG_SUBTREE',
      expect.objectContaining({ label: '本组织及下级（收窄）', active: true }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('loads field policies and writes only a tighter profile', async () => {
    resource.get.mockResolvedValue(
      listResponse([
        { field_key: 'employee.salary', profile: 'P3_RESTRICTED', declared_profile: 'P3_RESTRICTED', can_tighten: false },
      ]),
    )
    resource.put.mockResolvedValue({
      field_key: 'employee.salary',
      profile: 'P3_RESTRICTED',
      declared_profile: 'P3_RESTRICTED',
      can_tighten: false,
    })

    const { wrapper } = await mountAt('system/system-field-policies', '/system/system-field-policies')
    await flushPromises()

    expect(wrapper.text()).toContain('employee.salary')
    expect(wrapper.text()).toContain('P3_RESTRICTED')
    expect(wrapper.text()).not.toContain('后端接口待补')

    await wrapper.get('[data-testid="field-policy-submit"]').trigger('click')
    await flushPromises()

    expect(resource.put).toHaveBeenCalledWith(
      '/v1/system/field-policies/employee.salary',
      { field_profile: 'P1_INTERNAL' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('loads dictionaries and parameters and writes both through Platform services', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/system/dictionaries') {
        return Promise.resolve(
          listResponse([
            { id: 2, type: 'EMPLOYEE_STATUS', code: 'PROBATION', label: '试用', sort_order: 1, active: true },
          ]),
        )
      }
      if (path === '/v1/system/parameters') {
        return Promise.resolve(
          listResponse([
            { id: 4, key: 'mail.password', scope: 'TENANT', value: null, sensitive: true, masked: true },
          ]),
        )
      }
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })
    resource.post.mockResolvedValue({ id: 3, type: 'EMPLOYEE_STATUS', code: 'ACTIVE', label: '在职' })
    resource.put.mockResolvedValue({ id: 4, key: 'mail.password', scope: 'TENANT', value: null, sensitive: true, masked: true })

    const { wrapper } = await mountAt('system/system-settings', '/system/system-settings')
    await flushPromises()

    expect(wrapper.text()).toContain('PROBATION')
    expect(wrapper.text()).toContain('mail.password')
    expect(wrapper.text()).toContain('已遮罩')
    expect(wrapper.text()).not.toContain('后端接口待补')

    await wrapper.get('[data-testid="dictionary-type"]').setValue('EMPLOYEE_STATUS')
    await wrapper.get('[data-testid="dictionary-code"]').setValue('ACTIVE')
    await wrapper.get('[data-testid="dictionary-label"]').setValue('在职')
    await wrapper.get('[data-testid="dictionary-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/system/dictionaries',
      expect.objectContaining({ type: 'EMPLOYEE_STATUS', code: 'ACTIVE', label: '在职' }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )

    await wrapper.get('[data-testid="parameter-key"]').setValue('mail.password')
    await wrapper.get('[data-testid="parameter-value"]').setValue('synthetic-smtp-secret')
    await wrapper.get('[data-testid="parameter-sensitive"]').setValue(true)
    await wrapper.get('[data-testid="parameter-submit"]').trigger('click')
    await flushPromises()

    expect(resource.put).toHaveBeenCalledWith(
      '/v1/system/parameters',
      expect.objectContaining({ key: 'mail.password', scope: 'TENANT', value: 'synthetic-smtp-secret', sensitive: true }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('publishes a calendar version through POST /v1/system/calendars/{id}/publish', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/time/calendars/1') {
        return Promise.resolve({
          legal_entity_id: 1,
          year: 2026,
          timezone: 'Asia/Shanghai',
          workweek: [1, 2, 3, 4, 5],
          standard_day_minutes: 480,
          version: 2,
          holidays: [{ date: '2026-10-01', name: 'Synthetic National Day', type: 'PUBLIC', source: 'ADMIN' }],
        })
      }
      if (path === '/v1/time/leave/types') return Promise.resolve(listResponse([]))
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })
    resource.post.mockResolvedValue({
      legal_entity_id: 1,
      version: 3,
      timezone: 'Asia/Shanghai',
      workweek: [1, 2, 3, 4, 5],
      standard_day_minutes: 480,
      holidays: [],
    })

    const { wrapper } = await mountAt('system/system-calendars', '/system/system-calendars')
    await flushPromises()

    expect(wrapper.text()).not.toContain('后端接口待补')

    await wrapper.get('[data-testid="calendar-publish-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/system/calendars/1/publish',
      expect.objectContaining({
        timezone: 'Asia/Shanghai',
        workweek: [1, 2, 3, 4, 5],
        standard_day_minutes: 480,
        holidays: [{ date: '2026-10-01', name: 'Synthetic National Day', type: 'PUBLIC' }],
      }),
      expect.objectContaining({
        idempotencyKey: expect.any(String),
        headers: expect.objectContaining({ 'X-Legal-Entity-Id': '1' }),
      }),
    )
    expect(wrapper.text()).toContain('工作日历已发布新版本')
  })
})
