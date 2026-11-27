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
  it('requests the server page and size, then renders actor, action, resource, result, request id and timestamp without raw codes', async () => {
    resource.get.mockImplementation((_path: string, query: { page?: number; page_size?: number }) =>
      Promise.resolve(listResponse([
        auditEvent(),
        auditEvent({
          id: 2,
          user_id: 16,
          event_type: 'role.assigned',
          metadata: { role_code: 'SYNTHETIC_ROLE' },
          request_id: 'req-audit-2',
          occurred_at: '2026-11-26T01:00:00Z',
        }),
      ], { page: query.page ?? 1, page_size: query.page_size ?? 20, total: 47 })),
    )

    const wrapper = await mountPage(SystemAuditPage, ['iam.audit.read'])
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/auth/events', { page: 1, page_size: 20 })
    const row = wrapper.get('[data-testid="audit-row-1"]')
    expect(row.text()).toContain('登录成功')
    expect(row.text()).toContain('账号会话')
    expect(row.text()).toContain('成功')
    expect(row.text()).toContain('req-audit-1')
    expect(row.text()).toContain('2026-11-27')
    expect(row.text()).toContain('用户 15')
    expect(row.text()).not.toContain('login.succeeded')
    expect(row.text()).not.toContain('SUCCESS')

    const table = wrapper.findComponent(DataTable)
    expect(table.props('total')).toBe(47)
    expect(table.props('page')).toBe(1)
    table.vm.$emit('update:page', 2)
    await flushPromises()
    expect(resource.get).toHaveBeenLastCalledWith('/v1/auth/events', { page: 2, page_size: 20 })

    const reloadedTable = wrapper.findComponent(DataTable)
    reloadedTable.vm.$emit('update:pageSize', 50)
    await flushPromises()
    expect(resource.get).toHaveBeenLastCalledWith('/v1/auth/events', { page: 1, page_size: 50 })
  })

  it('keeps the raw technical event code inside the operator detail only', async () => {
    resource.get.mockResolvedValue(listResponse([auditEvent()]))
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

  it('discloses client-side-only filtering and pagination, and filters the loaded page', async () => {
    resource.get.mockResolvedValue(listResponse([
      auditEvent(),
      auditEvent({ id: 2, user_id: 16, event_type: 'user.status.changed', request_id: 'req-audit-2', occurred_at: '2026-11-26T01:00:00Z' }),
    ], { total: 2 }))
    const wrapper = await mountPage(SystemAuditPage, ['iam.audit.read'])
    await flushPromises()

    const note = wrapper.get('[data-testid="audit-partial"]')
    expect(note.text()).toContain('仅作用于已加载')
    expect(note.text()).toContain('服务端')
    expect(note.text()).toContain('导出暂未开放')
    expect(wrapper.find('[data-testid="audit-export"]').exists()).toBe(false)

    const actionInput = wrapper.get('[data-testid="audit-filter-action"]')
    expect(actionInput.attributes('placeholder')).not.toMatch(/[a-z]+\.[a-z]+/)

    await wrapper.get('[data-testid="audit-filter-actor"]').setValue('16')
    await flushPromises()
    expect(wrapper.find('[data-testid="audit-row-1"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="audit-row-2"]').exists()).toBe(true)

    await wrapper.get('[data-testid="audit-filter-actor"]').setValue('')
    await actionInput.setValue('登录成功')
    await flushPromises()
    expect(wrapper.find('[data-testid="audit-row-1"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="audit-row-2"]').exists()).toBe(false)

    await actionInput.setValue('不存在的动作')
    await flushPromises()
    expect(wrapper.find('[data-testid="audit-empty"]').exists()).toBe(true)
  })

  it('renders loading, empty, error, forbidden and partial states without fabricating events', async () => {
    resource.get.mockReturnValue(new Promise(() => {}))
    const loading = await mountPage(SystemAuditPage, ['iam.audit.read'])
    await nextTick()
    expect(loading.find('[data-testid="audit-loading"]').exists()).toBe(true)
    expect(loading.find('[data-testid="audit-table"]').exists()).toBe(false)

    resource.get.mockResolvedValue(listResponse([], { total: 0 }))
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

    resource.get.mockResolvedValue(listResponse([auditEvent()], { total: 1 }))
    const partial = await mountPage(SystemAuditPage, ['iam.audit.read'])
    await flushPromises()
    expect(partial.find('[data-testid="audit-partial"]').exists()).toBe(true)
    expect(partial.find('[data-testid="audit-table"]').exists()).toBe(true)
  })
})
