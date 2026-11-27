import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import { useSessionStore } from '@/stores/session'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  patch: vi.fn(),
  delete: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
  resourcePatch: resource.patch,
  resourceDelete: resource.delete,
}))

import ContractWorkspacePage from '@/modules/people/contracts/pages/ContractWorkspacePage.vue'

const CONTRACTS = [
  {
    id: 41,
    employment_id: 7,
    employee_no: 'LEDGER-0007',
    employee_name: '合成台账员工',
    contract_no: 'HT-0007',
    contract_type: 'FIXED_TERM',
    start_date: '2026-01-01',
    end_date: '2026-12-20',
    signed_on: '2025-12-20',
    status: 'ACTIVE',
    version: 1,
    previous_contract_id: null,
    renewed_by_id: null,
    days_to_expiry: 23,
    display_status: 'EXPIRING',
    display_status_label: '即将到期',
  },
]

const TEMPLATES = [
  {
    id: 82,
    code: 'CN-FIXED',
    name: '合成固定期限模板',
    version: 3,
    status: 'DRAFT',
    body: 'Synthetic template body',
    published_at: null,
  },
]

function listEnvelope(items: unknown[]) {
  return { items, page: 1, page_size: 20, total: items.length, partial: false, partial_reason: null }
}

function projectionEnvelope(items: unknown[]) {
  return {
    items,
    page: 1,
    page_size: 20,
    total: items.length,
    as_of: '2026-11-27',
    freshness: 'LIVE',
    source_hash: 'a'.repeat(64),
    partial: false,
    partial_reason: null,
    drilldown: null,
  }
}

function mountAt(path: string, capabilities: string[] = ['people.contract.read', 'people.contract.manage']): Promise<VueWrapper> {
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSessionStore()
  session.capabilities = capabilities
  session.status = 'authenticated'

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/hr/contracts', name: 'hr-contracts', component: { template: '<div />' } },
      { path: '/hr/contracts/:id', name: 'hr-contracts-by-id', component: { template: '<div />' } },
    ],
  })

  return router.push(path).then(async () => {
    await router.isReady()
    const wrapper = mount(ContractWorkspacePage, {
      global: {
        plugins: [pinia, router, ElementPlus],
        stubs: { ElPagination: true, teleport: true },
      },
    })
    await flushPromises()
    return wrapper
  })
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
  resource.delete.mockReset()
  resource.post.mockResolvedValue({})
  resource.get.mockImplementation((path: string) => {
    if (path === '/v1/employments/contracts') return Promise.resolve(projectionEnvelope(CONTRACTS))
    if (path === '/v1/employments/contracts/expiring') return Promise.resolve(projectionEnvelope(CONTRACTS))
    if (path === '/v1/contract-templates') return Promise.resolve(projectionEnvelope(TEMPLATES))
    if (path === '/v1/employees') return Promise.resolve(listEnvelope([]))
    return Promise.reject(new Error(`Unexpected GET ${path}`))
  })
})

describe('contract ledger filtering and workspace tabs', () => {
  it('sends keyword and employment id to the server ledger instead of filtering the first page', async () => {
    const wrapper = await mountAt('/hr/contracts')

    await wrapper.get('[data-testid="contract-search"]').setValue('HT-0007')
    await wrapper.get('[data-testid="contract-search-submit"]').trigger('submit')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith(
      '/v1/employments/contracts',
      expect.objectContaining({ q: 'HT-0007', page: 1, page_size: 20 }),
    )
    expect(wrapper.get('[data-testid="contract-table"]').text()).toContain('HT-0007')
  })

  it('renders ledger, expiry and templates as query-addressable tabs backed by real endpoints', async () => {
    const expiry = await mountAt('/hr/contracts?tab=expiry')
    expect(resource.get).toHaveBeenCalledWith(
      '/v1/employments/contracts/expiring',
      expect.objectContaining({ within_days: 60 }),
    )
    expect(expiry.get('[data-testid="contract-table"]').text()).toContain('HT-0007')

    await expiry.get('[data-testid="contract-expiry-7"]').trigger('click')
    await flushPromises()
    expect(resource.get).toHaveBeenCalledWith(
      '/v1/employments/contracts/expiring',
      expect.objectContaining({ within_days: 7 }),
    )

    const templates = await mountAt('/hr/contracts?tab=templates')
    expect(resource.get).toHaveBeenCalledWith(
      '/v1/contract-templates',
      expect.objectContaining({ page: 1, page_size: 50 }),
    )
    expect(templates.get('[data-testid="contract-template-table"]').text()).toContain('CN-FIXED')
    expect(templates.get('[data-testid="contract-template-table"]').text()).toContain('草稿')
  })

  it('hides template create and publish when the caller only has contract read', async () => {
    const readOnly = await mountAt('/hr/contracts?tab=templates', ['people.contract.read'])

    expect(readOnly.find('[data-testid="contract-template-create"]').exists()).toBe(false)
    expect(readOnly.find('[data-testid="contract-template-publish-82"]').exists()).toBe(false)
    expect(readOnly.get('[data-testid="contract-template-table"]').text()).toContain('只读')
  })

  it('creates and publishes templates only through idempotent real API calls', async () => {
    const writable = await mountAt('/hr/contracts?tab=templates', [
      'people.contract.read',
      'people.contract.template.manage',
    ])

    await writable.get('[data-testid="contract-template-create"]').trigger('click')
    await flushPromises()

    await writable.get('[data-testid="contract-template-code"]').setValue('CN-OPEN')
    await writable.get('[data-testid="contract-template-name"]').setValue('合成无固定期限模板')
    await writable.get('[data-testid="contract-template-body"]').setValue('Synthetic template body')
    await writable.get('[data-testid="contract-template-save"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/contract-templates',
      { code: 'CN-OPEN', name: '合成无固定期限模板', body: 'Synthetic template body' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )

    await writable.get('[data-testid="contract-template-publish-82"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/contract-templates/82/publish',
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('deeplinks every ledger row with its real employment id', async () => {
    const wrapper = await mountAt('/hr/contracts')
    const router = wrapper.vm.$router

    const link = wrapper.findAll('.contract-link')
    expect(link.length).toBeGreaterThan(0)
    await link[0]!.trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.fullPath).toBe('/hr/contracts/41?employment_id=7')
  })
})
