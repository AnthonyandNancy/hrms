import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ElementPlus from 'element-plus'
import { useSessionStore } from '@/stores/session'
import SystemPagesPage from '@/modules/platform/pages/SystemPagesPage.vue'
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

const catalogue = {
  read_only: true,
  pages: [
    {
      page_code: 'hr-employees',
      path: '/hr/employees',
      menu_group: '员工档案',
      menu_label: '员工档案',
      required_capability: 'people.employee.read',
      actions: ['people.employee.manage'],
      order: 16,
    },
  ],
}

const menuOverrides = {
  items: [
    { page_code: 'hr-employees', label: '员工档案（覆盖）', visible: true, sort_order: 8 },
  ],
  page: 1,
  page_size: 100,
  total: 1,
}

const capabilities = {
  capabilities: [
    {
      code: 'people.employee.manage',
      anchor_types: ['COMPANY', 'ORG_UNIT'],
      field_profile_max: 'P2_SENSITIVE',
      risk: 'high',
    },
  ],
  read_only: true,
}

function mountWith(capabilitiesGranted: string[]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities: capabilitiesGranted })
  return mount(SystemPagesPage, { global: { plugins: [pinia, ElementPlus] } })
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
  resource.put.mockReset()
  resource.delete.mockReset()
  resource.get.mockImplementation((path: string, params?: Record<string, unknown>) => {
    if (path === '/v1/pages/catalog') return Promise.resolve(catalogue)
    if (path === '/v1/system/menus') return Promise.resolve(menuOverrides)
    if (path === '/v1/capabilities') return Promise.resolve(capabilities)
    return Promise.reject(new Error(`Unexpected path ${path} ${JSON.stringify(params ?? {})}`))
  })
  resource.patch.mockResolvedValue({ page_code: 'hr-employees' })
  resource.post.mockResolvedValue({ page_code: 'hr-employees' })
  resource.delete.mockResolvedValue({ page_code: 'hr-employees', deleted: true })
})

describe('page catalogue and menu override workspace', () => {
  it('loads the code-owned catalogue and updates an existing override only once', async () => {
    const wrapper = mountWith(['iam.permission.read', 'iam.menu.manage'])
    await flushPromises()

    expect(wrapper.get('[data-testid="page-catalog-table"]').text()).toContain('员工档案')
    expect(wrapper.text()).toContain('能力动作矩阵')
    expect(wrapper.text()).not.toContain('/v1/')
    expect(wrapper.text()).not.toContain('hr-employees')

    await wrapper.findComponent('[data-testid="menu-visible-hr-employees"]').setValue(false)
    await wrapper.get('[data-testid="menu-override-save-hr-employees"]').trigger('click')
    await flushPromises()

    expect(resource.patch).toHaveBeenCalledTimes(1)
    expect(resource.patch).toHaveBeenCalledWith(
      '/v1/system/menus/hr-employees',
      expect.objectContaining({ visible: false }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(resource.post).not.toHaveBeenCalledWith('/v1/pages/catalog', expect.anything(), expect.anything())
  })

  it('creates a missing override and resets it to the code-owned default', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/pages/catalog') return Promise.resolve(catalogue)
      if (path === '/v1/system/menus') return Promise.resolve({ items: [] })
      if (path === '/v1/capabilities') return Promise.resolve(capabilities)
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })
    const wrapper = mountWith(['iam.permission.read', 'iam.menu.manage'])
    await flushPromises()

    await wrapper.get('[data-testid="menu-label-hr-employees"]').setValue('员工名册')
    await wrapper.get('[data-testid="menu-override-save-hr-employees"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/system/menus',
      expect.objectContaining({ page_code: 'hr-employees', label: '员工名册' }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )

    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/pages/catalog') return Promise.resolve(catalogue)
      if (path === '/v1/system/menus') return Promise.resolve(menuOverrides)
      if (path === '/v1/capabilities') return Promise.resolve(capabilities)
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })
    await wrapper.get('[data-testid="menu-override-save-hr-employees"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="menu-override-reset-hr-employees"]').trigger('click')
    await flushPromises()

    expect(resource.delete).toHaveBeenCalledWith(
      '/v1/system/menus/hr-employees',
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('answers a missing menu permission with a read-only catalogue and no write controls', async () => {
    const wrapper = mountWith(['iam.permission.read'])
    await flushPromises()

    expect(wrapper.find('[data-testid="system-pages-readonly"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="menu-override-save-hr-employees"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="page-catalog-table"]').text()).toContain('员工档案')
  })

  it('shows the forbidden state without catalogue read access', async () => {
    const wrapper = mountWith(['iam.user.read'])
    await flushPromises()

    expect(wrapper.get('[data-testid="system-pages-forbidden"]').text()).toContain('无权')
    expect(resource.get).not.toHaveBeenCalled()
  })

  it('registers /system/pages and removes the four legacy IAM routes', () => {
    const paths = PAGE_REGISTRY.map((page) => page.path)
    expect(paths).toContain('/system/pages')
    for (const retired of ['/system/menus', '/system/permissions', '/system/data-scopes', '/system/field-policies']) {
      expect(paths).not.toContain(retired)
    }
    expect(PAGE_REGISTRY).toHaveLength(37)
  })
})
