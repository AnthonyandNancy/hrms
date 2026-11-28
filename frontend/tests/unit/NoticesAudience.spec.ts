import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSessionStore } from '@/stores/session'
import NoticeComposer from '@/modules/reporting/components/NoticeComposer.vue'

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
    { path: '/notices', name: 'notices', component: { template: '<div />' } },
  ],
})

function mountComposer(capabilities: string[]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })

  return mount(NoticeComposer, {
    props: {
      orgUnits: [{ id: 12, parentId: null, name: '人力资源部', label: '人力资源部' }],
      roles: [
        { code: 'HR_ADMIN', name: '人力资源管理员' },
        { code: 'EMPLOYEE', name: '员工' },
      ],
    },
    global: {
      plugins: [ElementPlus, pinia, router],
      stubs: { teleport: true },
    },
  })
}

const publishedNotice = {
  id: 77,
  title: '角色公告',
  summary: '合成公告正文',
  body: ['合成公告正文至少十个字。'],
  status: 'PUBLISHED',
  audience: 'ROLE',
  audienceLabel: '人力资源管理员',
  orgUnitIds: [],
  roleCodes: ['HR_ADMIN'],
  publishedAt: '2026-11-27T01:00:00Z',
  retractedAt: null,
  expiresAt: null,
  authorName: null,
  requiresAck: true,
  acknowledged: false,
  acknowledgementCount: 0,
  audienceCount: 3,
  approvalCaseId: null,
  approvedAt: null,
  archivedAt: null,
  templateCode: null,
  templateVersion: null,
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
})

describe('notice role audience', () => {
  it('publishes a role audience and previews the real count', async () => {
    resource.post
      .mockResolvedValueOnce({
        audienceType: 'ROLE',
        audienceLabel: '人力资源管理员',
        orgUnitIds: [],
        roleCodes: ['HR_ADMIN'],
        recipientCount: 3,
        resolvedOn: '2026-11-27',
      })
      .mockResolvedValueOnce(publishedNotice)

    const wrapper = mountComposer(['engagement.notice.manage'])
    await wrapper.get('[data-testid="notice-title"]').setValue('角色公告')
    await wrapper.get('[data-testid="notice-body"]').setValue('合成公告正文至少十个字。')
    await wrapper.get('[data-testid="notice-audience"]').setValue('ROLE')
    const roleSelect = wrapper.get('[data-testid="notice-role-select"]')
    const select = roleSelect.element as HTMLSelectElement
    const option = Array.from(select.options).find((item) => item.value === 'HR_ADMIN')
    if (option === undefined) throw new Error('role option missing')
    option.selected = true
    await roleSelect.trigger('change')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/engagement/notices/audience-preview',
      expect.objectContaining({ audience: 'ROLE', roleCodes: ['HR_ADMIN'] }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.get('[data-testid="notice-audience-preview"]').text()).toContain('3 人')

    await wrapper.get('[data-testid="notice-publish"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="notice-confirm-publish"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/engagement/notices',
      expect.objectContaining({ audience: 'ROLE', roleCodes: ['HR_ADMIN'] }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('does not invent roles when the role directory is unavailable', async () => {
    const wrapper = mount(NoticeComposer, {
      props: { rolesUnavailable: true, roles: [] },
      global: {
        plugins: [ElementPlus, createPinia(), router],
        stubs: { teleport: true },
      },
    })

    await wrapper.get('[data-testid="notice-audience"]').setValue('ROLE')

    expect(wrapper.get('[data-testid="notice-roles-unavailable"]').text()).toContain('没有角色目录读取能力')
    expect(wrapper.get('[data-testid="notice-role-select"]').findAll('option')).toHaveLength(0)
  })
})
