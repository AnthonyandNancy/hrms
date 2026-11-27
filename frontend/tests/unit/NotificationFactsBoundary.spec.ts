import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import { createMemoryHistory, createRouter } from 'vue-router'
import { defineComponent } from 'vue'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import NotificationsPage from '@/modules/platform/pages/NotificationsPage.vue'

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

const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/notifications', name: 'notifications', component: { template: '<div />' } },
    { path: '/notices', name: 'notices', component: { template: '<div />' } },
    { path: '/workflow/cases/:id', name: 'workflow-case-detail', component: { template: '<div />' } },
  ],
})

const OverlayStub = defineComponent({
  props: { modelValue: Boolean, title: String },
  emits: ['update:modelValue', 'close'],
  template: `<div v-if="modelValue"><slot /><slot name="footer" /></div>`,
})

async function mountInbox() {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities: ['notification.read'] })
  await router.push('/notifications')
  await router.isReady()
  return mount(NotificationsPage, {
    global: {
      plugins: [ElementPlus, pinia, router],
      stubs: {
        'el-dialog': OverlayStub,
        'el-drawer': OverlayStub,
        teleport: true,
      },
    },
  })
}

function notification(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    type: 'NOTICE_PUBLISHED',
    payload: { notice_id: 7, requires_ack: true },
    read: false,
    createdAt: '2026-11-27T01:00:00Z',
    ...overrides,
  }
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
})

describe('notification notice facts boundary', () => {
  it('never renders a notice title from the notification payload', async () => {
    resource.get.mockResolvedValue([
      notification({
        // A malicious or accidental payload copy must not be rendered.
        payload: { notice_id: 7, requires_ack: true, title: '合成公告标题', body: '合成公告正文' },
      }),
    ])

    const wrapper = await mountInbox()
    await flushPromises()

    expect(wrapper.text()).not.toContain('合成公告标题')
    expect(wrapper.text()).not.toContain('合成公告正文')
    expect(wrapper.get('[data-testid="notification-open-notice-1"]').attributes('data-notice-id')).toBe('7')
  })

  it('loads the notice by id when opening and shows a stable Chinese message when it is revoked', async () => {
    resource.get
      .mockResolvedValueOnce([notification({
        type: 'NOTICE_REMINDER',
        payload: { notice_id: 7, requires_ack: true },
      })])
      .mockRejectedValueOnce(new ApiError('NOT_FOUND', 'Not found.', { status: 404 }))

    const wrapper = await mountInbox()
    await flushPromises()
    await wrapper.get('[data-testid="notification-open-notice-1"]').trigger('click')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/engagement/notices/7')
    expect(wrapper.get('[data-testid="notification-action-error"]').text()).toContain('该公告不可见或已撤回')
  })
})
