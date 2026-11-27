import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import { createMemoryHistory, createRouter } from 'vue-router'
import { defineComponent, nextTick } from 'vue'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import NotificationsPage from '@/modules/platform/pages/NotificationsPage.vue'
import SystemAuditPage from '@/modules/platform/pages/SystemAuditPage.vue'
import DataTable from '@/components/DataTable.vue'

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

function listResponse(items: unknown[], overrides: Record<string, unknown> = {}) {
  return { items, page: 1, page_size: 20, total: items.length, ...overrides }
}

function inboxNotification(overrides: Record<string, unknown> = {}) {
  return {
    id: 9,
    type: 'NOTICE_PUBLISHED',
    payload: { notice_id: 42, requires_ack: false },
    read: false,
    createdAt: '2026-11-27T01:00:00Z',
    ...overrides,
  }
}

function auditEvent(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    user_id: 15,
    event_type: 'login.succeeded',
    result: 'SUCCESS',
    metadata: { method: 'PASSWORD', session_id: 'sess-1' },
    request_id: 'req-audit-1',
    occurred_at: '2026-11-27T01:00:00Z',
    ...overrides,
  }
}

function mountPage(
  component: object,
  capabilities: string[],
  options: Record<string, unknown> = {},
) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/notifications', name: 'notifications', component: { template: '<div />' } },
      { path: '/system/audit', name: 'system-audit', component: { template: '<div />' } },
      { path: '/notices', name: 'notices', component: { template: '<div />' } },
      { path: '/workflow/cases/:id', name: 'workflow-case-detail', component: { template: '<div />' } },
    ],
  })

  return router.push('/notifications').then(() => router.isReady()).then(() =>
    mount(component, {
      global: {
        plugins: [pinia, router, ElementPlus],
        stubs: {
          'el-dialog': OverlayStub,
          'el-drawer': OverlayStub,
          'el-select': true,
          'el-option': true,
          teleport: true,
        },
      },
      ...options,
    }),
  )
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.put.mockReset()
  resource.patch.mockReset()
  resource.delete.mockReset()
})

describe('notification center workspace', () => {
  it('lists the caller inbox with Chinese type, time and read state, and marks one notification read', async () => {
    resource.get.mockResolvedValue([
      inboxNotification(),
      inboxNotification({ id: 10, type: 'APPROVAL_PENDING', payload: { case_id: 71 }, read: true, createdAt: '2026-11-26T01:00:00Z' }),
    ])
    resource.post.mockResolvedValue(inboxNotification({ read: true }))

    const wrapper = await mountPage(NotificationsPage, ['notification.read'])
    await flushPromises()

    expect(wrapper.find('[data-testid="notifications-page"]').exists()).toBe(true)
    const first = wrapper.get('[data-testid="notification-row-9"]')
    expect(first.text()).toContain('公告发布')
    expect(first.text()).toContain('2026-11-27')
    expect(first.text()).toContain('未读')
    expect(first.text()).not.toContain('NOTICE_PUBLISHED')
    expect(wrapper.get('[data-testid="notification-row-10"]').text()).toContain('已读')
    expect(wrapper.find('[data-testid="notification-mark-all-read"]').exists()).toBe(true)

    await wrapper.get('[data-testid="notification-mark-read-9"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith('/v1/engagement/notifications/9/read', {})
    expect(wrapper.get('[data-testid="notification-row-9"]').text()).toContain('已读')
  })

  it('marks every unread notification through the existing per-item API and shows the honest channel-preference gap', async () => {
    resource.get.mockResolvedValue([
      inboxNotification(),
      inboxNotification({ id: 10, type: 'APPROVAL_PENDING', payload: { case_id: 71 } }),
    ])
    resource.post
      .mockResolvedValueOnce(inboxNotification({ read: true }))
      .mockResolvedValueOnce(inboxNotification({ id: 10, type: 'APPROVAL_PENDING', payload: { case_id: 71 }, read: true }))

    const wrapper = await mountPage(NotificationsPage, ['notification.read'])
    await flushPromises()

    const channelNote = wrapper.get('[data-testid="notification-channel-unavailable"]')
    expect(channelNote.text()).toContain('暂不可用')
    expect(wrapper.find('[data-testid="notification-channel-toggle"]').exists()).toBe(false)

    await wrapper.get('[data-testid="notification-mark-all-read"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledTimes(2)
    expect(resource.post).toHaveBeenNthCalledWith(1, '/v1/engagement/notifications/9/read', {})
    expect(resource.post).toHaveBeenNthCalledWith(2, '/v1/engagement/notifications/10/read', {})
    expect(wrapper.find('[data-testid="notification-unread-9"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="notification-unread-10"]').exists()).toBe(false)
  })

  it('renders loading, empty, error, forbidden and partial states without fabricated rows', async () => {
    resource.get.mockReturnValue(new Promise(() => {}))
    const loading = await mountPage(NotificationsPage, ['notification.read'])
    await nextTick()
    expect(loading.find('[data-testid="notification-loading"]').exists()).toBe(true)
    expect(loading.find('[data-testid="notification-row-9"]').exists()).toBe(false)

    resource.get.mockResolvedValue([])
    const empty = await mountPage(NotificationsPage, ['notification.read'])
    await flushPromises()
    expect(empty.find('[data-testid="notification-empty"]').exists()).toBe(true)
    expect(empty.text()).not.toContain('通知编号')

    resource.get.mockRejectedValue(new ApiError('INTERNAL', 'Internal error.', { status: 500, requestId: 'req-notification-500' }))
    const error = await mountPage(NotificationsPage, ['notification.read'])
    await flushPromises()
    expect(error.find('[data-testid="notification-error"]').exists()).toBe(true)
    expect(error.find('[data-testid="notification-empty"]').exists()).toBe(false)

    resource.get.mockReturnValue(new Promise(() => {}))
    const forbidden = await mountPage(NotificationsPage, [])
    await nextTick()
    expect(forbidden.find('[data-testid="notification-forbidden"]').exists()).toBe(true)
    expect(forbidden.find('[data-testid="notification-loading"]').exists()).toBe(false)

    resource.get.mockResolvedValue([inboxNotification(), inboxNotification({ id: 10 })])
    resource.post
      .mockResolvedValueOnce(inboxNotification({ read: true }))
      .mockRejectedValueOnce(new ApiError('INTERNAL', 'Internal error.', { status: 500, requestId: 'req-mark-all-500' }))
    const partial = await mountPage(NotificationsPage, ['notification.read'])
    await flushPromises()
    await partial.get('[data-testid="notification-mark-all-read"]').trigger('click')
    await flushPromises()

    expect(partial.find('[data-testid="notification-partial"]').exists()).toBe(true)
    expect(partial.get('[data-testid="notification-partial"]').text()).toContain('剩余未读通知未能标记')
    expect(partial.get('[data-testid="notification-action-error"]').text()).toContain('系统暂时无法完成该操作')
    expect(partial.find('[data-testid="notification-mark-read-9"]').exists()).toBe(false)
    expect(partial.find('[data-testid="notification-mark-read-10"]').exists()).toBe(true)
  })
})

describe('audit log workspace', () => {
  it('requests the server-filtered audit page and renders readable actor, action, result and request id', async () => {
    resource.get.mockImplementation((path: string, query: Record<string, unknown>) => {
      if (path === '/v1/health/ready') {
        return Promise.resolve({ status: 'ready', version: '1.0.0', checks: { db: true, cache: true, storage: true }, time: '2026-11-27T01:00:00Z' })
      }
      return Promise.resolve(listResponse([
        auditEvent(),
        auditEvent({
          id: 2,
          user_id: 16,
          event_type: 'role.assigned',
          metadata: { role_code: 'SYNTHETIC_ROLE' },
          request_id: 'req-audit-2',
          occurred_at: '2026-11-26T01:00:00Z',
        }),
      ], { page: query.page ?? 1, page_size: query.page_size ?? 20, total: 47 }))
    })

    const wrapper = await mountPage(SystemAuditPage, ['iam.audit.read'])
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/audit/events', {
      page: 1,
      page_size: 20,
    })
    const row = wrapper.get('[data-testid="audit-row-1"]')
    expect(row.text()).toContain('登录成功')
    expect(row.text()).toContain('账号会话')
    expect(row.text()).toContain('成功')
    expect(row.text()).toContain('req-audit-1')
    expect(row.text()).toContain('用户 15')
    expect(row.text()).not.toContain('login.succeeded')

    const table = wrapper.findComponent(DataTable)
    expect(table.props('total')).toBe(47)
    table.vm.$emit('update:page', 2)
    await flushPromises()
    expect(resource.get).toHaveBeenLastCalledWith('/v1/audit/events', expect.objectContaining({ page: 2, page_size: 20 }))
  })

  it('applies actor, action, result and date filters on the server and exports the allow-listed fields', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/health/ready') {
        return Promise.resolve({ status: 'ready', version: '1.0.0', checks: { db: true, cache: true, storage: true }, time: '2026-11-27T01:00:00Z' })
      }
      return Promise.resolve(listResponse([auditEvent()], { total: 1 }))
    })
    resource.post.mockResolvedValue({ fields: ['event_type', 'result', 'user_id', 'request_id', 'occurred_at'], rows: [auditEvent()] })

    const wrapper = await mountPage(SystemAuditPage, ['iam.audit.read'])
    await flushPromises()

    await wrapper.get('[data-testid="audit-filter-actor"]').setValue('15')
    await wrapper.get('[data-testid="audit-filter-action"]').setValue('login')
    await wrapper.get('[data-testid="audit-filter-result-SUCCESS"]').trigger('click')
    const datePickers = wrapper.findAllComponents({ name: 'ElDatePicker' })
    datePickers[0]?.vm.$emit('update:modelValue', '2026-11-01')
    datePickers[1]?.vm.$emit('update:modelValue', '2026-11-30')
    await wrapper.get('[data-testid="audit-filter-apply"]').trigger('click')
    await flushPromises()

    expect(resource.get).toHaveBeenLastCalledWith('/v1/audit/events', expect.objectContaining({
      actor: '15',
      action: 'login',
      result: 'SUCCESS',
      from: '2026-11-01',
      to: '2026-11-30',
    }))

    await wrapper.get('[data-testid="audit-export"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/audit/events/export',
      expect.objectContaining({
        fields: ['event_type', 'result', 'user_id', 'request_id', 'occurred_at'],
        actor: '15',
        action: 'login',
        result: 'SUCCESS',
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.get('[data-testid="audit-export-message"]').text()).toContain('已导出 1 条')
  })

  it('shows real readiness and explicitly discloses that backup status has no API', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/health/ready') {
        return Promise.resolve({ status: 'ready', version: '1.0.0', checks: { db: true, cache: false, storage: true }, time: '2026-11-27T01:00:00Z' })
      }
      return Promise.resolve(listResponse([], { total: 0 }))
    })

    const wrapper = await mountPage(SystemAuditPage, ['iam.audit.read'])
    await flushPromises()

    expect(wrapper.get('[data-testid="audit-health-panel"]').text()).toContain('版本 1.0.0')
    expect(wrapper.get('[data-testid="audit-health-check-db"]').text()).toContain('正常')
    expect(wrapper.get('[data-testid="audit-health-check-cache"]').text()).toContain('异常')
    expect(wrapper.get('[data-testid="audit-backup-unavailable"]').text()).toContain('未提供备份状态 API')
  })

  it('keeps the raw event code inside the operator detail only', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/health/ready') {
        return Promise.resolve({ status: 'ready', version: '1.0.0', checks: { db: true, cache: true, storage: true }, time: '2026-11-27T01:00:00Z' })
      }
      return Promise.resolve(listResponse([auditEvent()]))
    })
    const wrapper = await mountPage(SystemAuditPage, ['iam.audit.read'])
    await flushPromises()

    expect(wrapper.get('[data-testid="audit-row-1"]').text()).not.toContain('login.succeeded')
    await wrapper.get('[data-testid="audit-row-1"]').findAll('button').at(-1)?.trigger('click')
    await flushPromises()

    const detail = wrapper.get('[data-testid="audit-detail-dialog"]')
    expect(detail.text()).toContain('事件代码')
    expect(detail.text()).toContain('login.succeeded')
    expect(detail.get('[data-testid="audit-detail-request-id"]').text()).toContain('req-audit-1')
  })

  it('renders loading, empty, error and forbidden states without fabricating events', async () => {
    resource.get.mockReturnValue(new Promise(() => {}))
    const loading = await mountPage(SystemAuditPage, ['iam.audit.read'])
    await nextTick()
    expect(loading.find('[data-testid="audit-loading"]').exists()).toBe(true)

    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/health/ready') {
        return Promise.resolve({ status: 'ready', version: '1.0.0', checks: { db: true, cache: true, storage: true }, time: '2026-11-27T01:00:00Z' })
      }
      return Promise.resolve(listResponse([], { total: 0 }))
    })
    const empty = await mountPage(SystemAuditPage, ['iam.audit.read'])
    await flushPromises()
    expect(empty.find('[data-testid="audit-empty"]').exists()).toBe(true)

    resource.get.mockRejectedValue(new ApiError('INTERNAL', 'Internal error.', { status: 500, requestId: 'req-audit-500' }))
    const error = await mountPage(SystemAuditPage, ['iam.audit.read'])
    await flushPromises()
    expect(error.find('[data-testid="audit-error"]').exists()).toBe(true)
    expect(error.get('[data-testid="audit-error"]').text()).toContain('req-audit-500')

    resource.get.mockReturnValue(new Promise(() => {}))
    const forbidden = await mountPage(SystemAuditPage, ['iam.user.read'])
    await nextTick()
    expect(forbidden.find('[data-testid="audit-forbidden"]').exists()).toBe(true)
    expect(forbidden.find('[data-testid="audit-loading"]').exists()).toBe(false)
  })
})

