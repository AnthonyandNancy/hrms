import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import ResourcePage from '@/components/ResourcePage.vue'
import type { PageSpec } from '@/router/registry'
import { ApiError } from '@/api/errors'

const resourceGet = vi.fn()

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resourceGet(...args),
}))

const page: PageSpec = {
  path: '/hr/departments/list',
  name: 'hr-departments-list',
  componentKey: 'hr/departments/list',
  capability: 'org.unit.read',
  kind: 'workspace',
  menu: { group: '部门管理', label: '部门列表', order: 28, icon: 'Menu' },
  layer: 'business',
  wave: 'Wave 2',
  requiredApis: ['GET /v1/departments'],
  actions: [],
  states: ['loading', 'empty', 'error', 'forbidden', 'partial'],
  e2e: ['28-hr-departments-list'],
  negativeTests: ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
}

async function mountPage(spec: PageSpec = page) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/hr/departments/list', name: 'page', component: { template: '<div />' } },
      { path: '/login', name: 'login', component: { template: '<div />' } },
      { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
    ],
  })
  await router.push('/hr/departments/list')
  await router.isReady()

  return mount(ResourcePage, {
    props: { page: spec },
    global: { plugins: [pinia, router, ElementPlus] },
  })
}

describe('ResourcePage', () => {
  beforeEach(() => {
    resourceGet.mockReset()
  })

  it('calls the first GET operation declared in the registry and renders a readable table', async () => {
    resourceGet.mockResolvedValue({
      items: [{ id: 1, code: 'DEP-001', name: 'Synthetic Department', headcount: 3 }],
      page: 1,
      page_size: 20,
      total: 1,
    })

    const wrapper = await mountPage()
    await flushPromises()

    expect(resourceGet).toHaveBeenCalledWith('/v1/departments', expect.any(Object))
    expect(wrapper.text()).toContain('部门列表')
    expect(wrapper.text()).toContain('DEP-001')
    expect(wrapper.text()).toContain('Synthetic Department')
    expect(wrapper.text()).toContain('共 1 条记录')
    expect(wrapper.find('table').exists()).toBe(true)
  })

  it('shows an explicit empty state when the API returns an empty page', async () => {
    resourceGet.mockResolvedValue({ items: [], page: 1, page_size: 20, total: 0 })

    const wrapper = await mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('当前没有数据')
    expect(wrapper.find('table').exists()).toBe(false)
  })

  it('renders the dedicated no-active-employment state for a 409 business conflict', async () => {
    resourceGet.mockRejectedValue(
      new ApiError('NO_ACTIVE_EMPLOYMENT', 'No active employment', {
        status: 409,
        requestId: 'req-empty-self',
      }),
    )

    const wrapper = await mountPage()
    await flushPromises()

    expect(wrapper.find('[data-testid="no-active-employment"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('当前账号未绑定员工')
    expect(wrapper.text()).toContain('req-empty-self')
  })

  it('keeps the request id on a 5xx page error', async () => {
    resourceGet.mockRejectedValue(new ApiError('INTERNAL', 'boom', { status: 500, requestId: 'req-500-page' }))

    const wrapper = await mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('暂时无法加载')
    expect(wrapper.text()).toContain('req-500-page')
    expect(wrapper.text()).not.toContain('当前账号未绑定员工')
  })
})
