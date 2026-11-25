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
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
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
})
