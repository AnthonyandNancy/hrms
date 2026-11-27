import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import { createMemoryHistory, createRouter } from 'vue-router'
import { defineComponent } from 'vue'
import { useSessionStore } from '@/stores/session'
import NoticesPage from '@/modules/reporting/pages/NoticesPage.vue'
import type { Notice } from '@/modules/reporting/components/noticeApi'

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

const OverlayStub = defineComponent({
  props: { modelValue: Boolean, title: String },
  emits: ['update:modelValue', 'close'],
  template: `<div v-if="modelValue" data-testid="overlay-stub"><slot /><slot name="footer" /></div>`,
})

const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/notices', name: 'notices', component: { template: '<div />' } },
    { path: '/workflow/cases/:id', name: 'workflow-case-detail', component: { template: '<div />' } },
  ],
})

function notice(overrides: Partial<Notice> = {}): Notice {
  return {
    id: 7,
    title: '合成公告标题',
    summary: '合成公告摘要。',
    body: ['合成公告摘要。', '合成公告正文。'],
    status: 'DRAFT',
    audience: 'ALL',
    audienceLabel: '全体员工',
    orgUnitIds: [],
    roleCodes: [],
    publishedAt: '2026-11-27T01:00:00Z',
    retractedAt: null,
    authorName: null,
    requiresAck: true,
    acknowledged: false,
    acknowledgementCount: 0,
    audienceCount: 20,
    approvalCaseId: 31,
    approvedAt: null,
    archivedAt: null,
    templateCode: null,
    templateVersion: null,
    ...overrides,
  }
}

async function mountNotices(capabilities: string[], path = '/notices?notice=7&tab=approval') {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })
  await router.push(path)
  await router.isReady()
  return mount(NoticesPage, {
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

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
})

describe('notice detail five tabs', () => {
  it('submits a draft and links its workflow case', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/engagement/notices') return Promise.resolve({ items: [notice()], total: 1, partial: false, partial_reason: null })
      if (path === '/v1/engagement/notices/7') return Promise.resolve(notice())
      if (path === '/v1/cases/31') return Promise.resolve({ id: 31, is_awaiting_me: true, decision_context: { is_awaiting_me: true } })
      if (path === '/v1/org-units/tree') return Promise.resolve({ items: [] })
      if (path === '/v1/roles') return Promise.resolve({ roles: [] })
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })
    resource.post.mockResolvedValue(notice({
      status: 'PENDING_APPROVAL',
      approvalCaseId: 31,
    }))

    const wrapper = await mountNotices(['engagement.notice.read', 'engagement.notice.manage'])
    await flushPromises()

    await wrapper.get('[data-testid="notice-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/engagement/notices/7/submit',
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.get('[data-testid="notice-case-link"]').attributes('href')).toBe('/workflow/cases/31')
  })

  it('renders reads and template versions from real endpoints', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/engagement/notices') return Promise.resolve({ items: [notice({ status: 'PUBLISHED' })], total: 1, partial: false, partial_reason: null })
      if (path === '/v1/engagement/notices/7') return Promise.resolve(notice({ status: 'PUBLISHED' }))
      if (path === '/v1/engagement/notices/7/reads') {
        return Promise.resolve({
          items: [{ user_id: 11, acknowledged_at: '2026-11-27T02:00:00Z', employment_id: 1, employee_no: 'E-A', display_name: '合成员工' }],
          page: 1,
          page_size: 20,
          total: 1,
          as_of: '2026-11-27',
          read_user_ids: [11],
          unread_user_ids: [12],
          read_count: 1,
          unread_count: 1,
        })
      }
      if (path === '/v1/engagement/notice-templates') {
        return Promise.resolve({
          items: [
            {
              id: 3,
              code: 'PAYROLL_REMINDER',
              name: '薪酬提醒',
              version: 2,
              body_template: 'Payroll {{period}}',
              variables: ['period'],
              status: 'PUBLISHED',
            },
            {
              id: 2,
              code: 'PAYROLL_REMINDER',
              name: '薪酬提醒',
              version: 1,
              body_template: 'Payroll {{period}}',
              variables: ['period'],
              status: 'PUBLISHED',
            },
          ],
          page: 1,
          page_size: 100,
          total: 2,
          as_of: '2026-11-27',
        })
      }
      if (path === '/v1/cases/31') return Promise.resolve({ id: 31, is_awaiting_me: false })
      if (path === '/v1/org-units/tree') return Promise.resolve({ items: [] })
      if (path === '/v1/roles') return Promise.resolve({ roles: [] })
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })

    const wrapper = await mountNotices(['engagement.notice.read'], '/notices?notice=7&tab=reads')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/engagement/notices/7/reads', { page: 1, page_size: 20 })
    expect(wrapper.get('[data-testid="notice-reads-unread"]').text()).toContain('未读 1 人')

    await wrapper.get('[data-testid="notice-detail-tab-templates"]').trigger('click')
    await flushPromises()
    expect(resource.get).toHaveBeenCalledWith('/v1/engagement/notice-templates')
    expect(wrapper.findAll('[data-testid="notice-template-version"]').length).toBeGreaterThanOrEqual(2)
  })
})
