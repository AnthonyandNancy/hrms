import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import { defineComponent } from 'vue'
import { ApiError } from '@/api/errors'
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
import ContractDetailPage from '@/modules/people/contracts/pages/ContractDetailPage.vue'

// eslint-disable-next-line vue/one-component-per-file
const OverlayStub = defineComponent({
  inheritAttrs: false,
  props: { modelValue: Boolean, title: String },
  emits: ['update:modelValue', 'close'],
  template: `<div v-if="modelValue" v-bind="$attrs" class="el-overlay-stub" role="dialog">
    <h2 v-if="title">{{ title }}</h2>
    <slot />
    <slot name="footer" />
  </div>`,
})

// Element Plus inputs are rendered as native controls here so v-model updates
// can be exercised without the real overlay/teleport internals in jsdom.
// eslint-disable-next-line vue/one-component-per-file
const NativeInputStub = defineComponent({
  inheritAttrs: false,
  props: { modelValue: { type: [String, Number], default: '' } },
  emits: ['update:modelValue'],
  setup(_props, { emit }) {
    return {
      onInput: (event: Event) => {
        emit('update:modelValue', (event.target as HTMLInputElement).value)
      },
    }
  },
  template: `<input v-bind="$attrs" :value="modelValue" @input="onInput" />`,
})

const CONTRACTS = [
  {
    id: 31,
    employment_id: 1,
    employee_no: 'DEMO-0001',
    employee_name: '合成员工甲',
    contract_no: 'CT-2026-0031',
    contract_type: 'FIXED_TERM',
    start_date: '2026-01-01',
    end_date: '2026-11-30',
    signed_on: '2025-12-20',
    status: 'ACTIVE',
    version: 2,
    previous_contract_id: 30,
    renewed_by_id: null,
    days_to_expiry: 7,
  },
  {
    id: 32,
    employment_id: 2,
    employee_no: 'DEMO-0002',
    employee_name: '合成员工乙',
    contract_no: 'CT-2026-0032',
    contract_type: 'OPEN_ENDED',
    start_date: '2026-01-01',
    end_date: null,
    signed_on: '2025-12-20',
    status: 'ACTIVE',
    version: 1,
    previous_contract_id: null,
    renewed_by_id: null,
    days_to_expiry: null,
  },
  {
    id: 33,
    employment_id: 3,
    employee_no: 'DEMO-0003',
    employee_name: '合成员工丙',
    contract_no: 'CT-2025-0033',
    contract_type: 'INTERNSHIP',
    start_date: '2025-01-01',
    end_date: '2025-12-31',
    signed_on: '2024-12-20',
    status: 'TERMINATED',
    version: 1,
    previous_contract_id: null,
    renewed_by_id: null,
    days_to_expiry: -331,
  },
]

const EMPLOYEES = [
  { employment_id: 1, person_name: '合成员工甲', employee_no: 'DEMO-0001', status: 'ACTIVE' },
  { employment_id: 2, person_name: '合成员工乙', employee_no: 'DEMO-0002', status: 'ACTIVE' },
]

const VERSIONS = [
  {
    id: 101,
    contract_id: 31,
    employment_id: 1,
    change_type: 'CREATION',
    terms_version: 1,
    document_version: 1,
    effective_from: '2026-01-01',
    effective_to: '2026-11-30',
    reason: '首次签订',
    recorded_at: '2025-12-20T08:00:00Z',
    snapshot: {
      contract_no: 'CT-2026-0031',
      signed_on: '2025-12-20',
      signer: '合成签署人',
      work_location: '上海合成办公室',
      probation_end_date: '2026-04-01',
    },
  },
  {
    id: 102,
    contract_id: 31,
    employment_id: 1,
    change_type: 'RENEWAL',
    terms_version: 2,
    document_version: 1,
    effective_from: '2026-11-01',
    effective_to: '2026-11-30',
    reason: '续签合成测试',
    recorded_at: '2026-10-20T08:00:00Z',
    snapshot: {
      contract_no: 'CT-2026-0031',
      signed_on: '2026-10-20',
      signer: '合成签署人',
      previous_contract_id: 30,
    },
  },
]

const ATTACHMENTS = [
  {
    id: 501,
    employment_id: 1,
    category: 'CONTRACT',
    version: 1,
    original_name: '合成劳动合同.pdf',
    mime: 'application/pdf',
    size_bytes: 1024,
    signed_at: '2025-12-20T00:00:00Z',
    expires_at: null,
    retention_class: 'CONTRACT_7Y',
    legal_hold: true,
    created_at: '2025-12-20T08:00:00Z',
  },
]

function listEnvelope(items: unknown[]) {
  return { items, page: 1, page_size: 100, total: items.length, partial: false, partial_reason: null }
}

function projectionEnvelope(items: unknown[], extra: Record<string, unknown> = {}) {
  return {
    items,
    page: 1,
    page_size: 100,
    total: items.length,
    as_of: '2026-11-23',
    freshness: 'LIVE',
    source_hash: 'a'.repeat(64),
    partial: false,
    partial_reason: null,
    drilldown: null,
    ...extra,
  }
}

function mockWorkspaceGet(): void {
  resource.get.mockImplementation((path: string) => {
    if (path === '/v1/employments/contracts') return Promise.resolve(projectionEnvelope(CONTRACTS))
    if (path === '/v1/employees') return Promise.resolve(listEnvelope(EMPLOYEES))
    return Promise.reject(new Error(`Unexpected GET ${path}`))
  })
}

function piniaWith(capabilities: string[]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSessionStore()
  session.capabilities = capabilities
  session.status = 'authenticated'
  return pinia
}

async function mountPage(
  component: typeof ContractWorkspacePage | typeof ContractDetailPage,
  path: string,
  capabilities: string[] = [
    'people.contract.read',
    'people.contract.manage',
    'people.contract.amend',
    'people.contract.terminate',
    'people.document.read',
  ],
): Promise<VueWrapper> {
  const pinia = piniaWith(capabilities)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/hr/contracts', name: 'hr-contracts', component: { template: '<div />' } },
      { path: '/hr/contracts/:id', name: 'hr-contracts-by-id', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()

  const wrapper = mount(component, {
    global: {
      plugins: [pinia, router, ElementPlus],
      stubs: {
        ElDrawer: OverlayStub,
        ElDialog: OverlayStub,
        ElPagination: true,
        ElInput: NativeInputStub,
        ElDatePicker: NativeInputStub,
        teleport: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
  resource.delete.mockReset()
  resource.post.mockResolvedValue({})
  mockWorkspaceGet()
})

describe('contract workspace', () => {
  it('renders the live ledger with Chinese types, statuses, dates, renewal count and a server-side expiry tab', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/employments/contracts') return Promise.resolve(projectionEnvelope(CONTRACTS))
      if (path === '/v1/employments/contracts/expiring') return Promise.resolve(projectionEnvelope([CONTRACTS[0]]))
      if (path === '/v1/employees') return Promise.resolve(listEnvelope(EMPLOYEES))
      return Promise.reject(new Error(`Unexpected GET ${path}`))
    })
    const wrapper = await mountPage(ContractWorkspacePage, '/hr/contracts')

    expect(resource.get).toHaveBeenCalledWith(
      '/v1/employments/contracts',
      expect.objectContaining({ as_of: expect.any(String), page: 1, page_size: 20 }),
    )
    expect(wrapper.get('[data-testid="contract-table"]').text()).toContain('合成员工甲')
    expect(wrapper.text()).toContain('CT-2026-0031')
    expect(wrapper.text()).toContain('固定期限')
    expect(wrapper.text()).toContain('即将到期')
    expect(wrapper.text()).toContain('生效中')
    expect(wrapper.text()).toContain('已终止')
    expect(wrapper.text()).toContain('续签 1 次')
    expect(wrapper.text()).not.toContain('FIXED_TERM')
    expect(wrapper.text()).not.toContain('TERMINATED')

    await wrapper.get('[data-testid="contract-tab-expiry"]').trigger('click')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith(
      '/v1/employments/contracts/expiring',
      expect.objectContaining({ within_days: 60, as_of: expect.any(String) }),
    )
    expect(wrapper.get('[data-testid="contract-table"]').text()).toContain('CT-2026-0031')
    expect(wrapper.get('[data-testid="contract-table"]').text()).not.toContain('CT-2026-0032')
    expect(wrapper.get('[data-testid="contract-table"]').text()).not.toContain('CT-2025-0033')
  })

  it('validates contract dates and probation locally and maps a 422 for the contract number inline', async () => {
    const wrapper = await mountPage(ContractWorkspacePage, '/hr/contracts')

    await wrapper.get('[data-testid="contract-create"]').trigger('click')
    await wrapper.get('[data-testid="contract-employee"]').setValue('1')
    await wrapper.get('[data-testid="contract-no"]').setValue('CT-2027-0099')
    await wrapper.get('[data-testid="contract-start"]').setValue('2027-01-01')
    await wrapper.get('[data-testid="contract-end"]').setValue('2026-12-31')
    await wrapper.get('[data-testid="contract-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('结束日期必须晚于开始日期')
    expect(resource.post).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="contract-end"]').setValue('2027-12-31')
    await wrapper.get('[data-testid="contract-probation"]').setValue('2027-08-01')
    await wrapper.get('[data-testid="contract-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('试用期不得超过六个月')
    expect(resource.post).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="contract-probation"]').setValue('2027-04-01')
    await wrapper.get('[data-testid="contract-location"]').setValue('上海合成办公室')
    resource.post.mockRejectedValueOnce(
      new ApiError('VALIDATION_FAILED', '提交内容未通过校验', {
        status: 422,
        fieldErrors: { contract_no: ['合同编号已存在。'] },
      }),
    )
    await wrapper.get('[data-testid="contract-save"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/employments/1/contracts',
      expect.objectContaining({
        contract_no: 'CT-2027-0099',
        start_date: '2027-01-01',
        end_date: '2027-12-31',
        probation_end_date: '2027-04-01',
        work_location: '上海合成办公室',
        status: 'DRAFT',
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('合同编号已存在。')
  })

  it('requires a renewal reason and shows the previous version before appending a new one', async () => {
    const wrapper = await mountPage(ContractWorkspacePage, '/hr/contracts')

    await wrapper.get('[data-testid="contract-renew-31"]').trigger('click')
    await flushPromises()

    const comparison = wrapper.get('[data-testid="renewal-comparison"]')
    expect(comparison.text()).toContain('当前版本')
    expect(comparison.text()).toContain('CT-2026-0031')
    expect(comparison.text()).toContain('2026-01-01')

    await wrapper.get('[data-testid="contract-no"]').setValue('CT-2027-0031')
    await wrapper.get('[data-testid="contract-save"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('请填写续签原因')
    expect(resource.post).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="contract-start"]').setValue('2026-12-01')
    await wrapper.get('[data-testid="contract-end"]').setValue('2027-11-30')
    await wrapper.get('[data-testid="contract-reason"]').setValue('合成续签原因')
    await wrapper.get('[data-testid="contract-save"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/employments/1/contracts',
      expect.objectContaining({
        contract_no: 'CT-2027-0031',
        renewal_of_contract_id: 31,
        reason: '合成续签原因',
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('shows the version timeline with signer, contract attachments and a termination confirmation naming the employee', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/employments/1/contracts/31') return Promise.resolve(CONTRACTS[0])
      if (path === '/v1/employment-contracts/31/amendments') return Promise.resolve(projectionEnvelope(VERSIONS))
      if (path === '/v1/employments/1/document-files') return Promise.resolve(projectionEnvelope(ATTACHMENTS))
      return Promise.reject(new Error(`Unexpected GET ${path}`))
    })

    const wrapper = await mountPage(ContractDetailPage, '/hr/contracts/31?employment_id=1')
    await flushPromises()

    const timeline = wrapper.get('[data-testid="contract-version-list"]')
    expect(timeline.text()).toContain('首次签订')
    expect(timeline.text()).toContain('续签合成测试')
    expect(timeline.text()).toContain('2026年1月1日')
    expect(timeline.text()).toContain('合成签署人')
    expect(wrapper.get('[data-testid="contract-attachments"]').text()).toContain('合成劳动合同.pdf')

    await wrapper.get('[data-testid="contract-terminate"]').trigger('click')
    await flushPromises()
    const dialog = wrapper.get('[data-testid="contract-terminate-dialog"]')
    expect(dialog.text()).toContain('合成员工甲')
    expect(dialog.text()).toContain('终止不可撤销')
    expect(dialog.text()).toContain('历史版本会保留')

    await wrapper.get('[data-testid="terminate-date"]').setValue('2026-11-30')
    await wrapper.get('[data-testid="terminate-reason"]').setValue('合成终止原因')
    resource.post.mockResolvedValueOnce({
      contract: { ...CONTRACTS[0], status: 'TERMINATED', end_date: '2026-11-30' },
      version: { id: 103, change_type: 'TERMINATION' },
    })
    await wrapper.get('[data-testid="terminate-confirm"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/employment-contracts/31/terminate',
      { effective_date: '2026-11-30', reason: '合成终止原因' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('activates a draft contract through an explicit effective date', async () => {
    const draft = { ...CONTRACTS[0], status: 'DRAFT' }
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/employments/1/contracts/31') return Promise.resolve(draft)
      if (path === '/v1/employment-contracts/31/amendments') return Promise.resolve(projectionEnvelope(VERSIONS))
      if (path === '/v1/employments/1/document-files') return Promise.resolve(projectionEnvelope(ATTACHMENTS))
      return Promise.reject(new Error(`Unexpected GET ${path}`))
    })

    const wrapper = await mountPage(ContractDetailPage, '/hr/contracts/31?employment_id=1')
    await flushPromises()

    await wrapper.get('[data-testid="contract-activate"]').trigger('click')
    const dialog = wrapper.get('[data-testid="contract-activate-dialog"]')
    expect(dialog.text()).toContain('合成员工甲')
    expect(dialog.text()).toContain('确认与签署起始日期一致')

    await wrapper.get('[data-testid="activate-date"]').setValue('2026-01-01')
    await wrapper.get('[data-testid="activate-reason"]').setValue('合成合同激活')
    resource.post.mockResolvedValueOnce({})
    await wrapper.get('[data-testid="activate-confirm"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/employment-contracts/31/activate',
      { effective_from: '2026-01-01', reason: '合成合同激活' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('renders forbidden, error, empty and partial states without fabricating rows', async () => {
    resource.get.mockRejectedValueOnce(
      new ApiError('FORBIDDEN', 'Forbidden', { status: 403, requestId: 'req-contract-403' }),
    )
    const forbidden = await mountPage(ContractWorkspacePage, '/hr/contracts', ['people.contract.read'])
    expect(forbidden.text()).toContain('无权限查看劳动合同')
    expect(forbidden.text()).toContain('req-contract-403')

    resource.get.mockRejectedValueOnce(
      new ApiError('INTERNAL', 'Synthetic outage', { status: 500, requestId: 'req-contract-500' }),
    )
    const failed = await mountPage(ContractWorkspacePage, '/hr/contracts')
    expect(failed.get('[data-testid="contracts-error"]').text()).toContain('req-contract-500')

    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/employments/contracts') {
        return Promise.resolve(projectionEnvelope([], { partial: true, partial_reason: '部分合同版本不可读。' }))
      }
      if (path === '/v1/employees') return Promise.resolve(listEnvelope(EMPLOYEES))
      return Promise.resolve(listEnvelope([]))
    })
    const empty = await mountPage(ContractWorkspacePage, '/hr/contracts')
    expect(empty.get('[data-testid="contracts-empty"]').text()).toContain('暂无劳动合同')
    expect(empty.get('[data-testid="contracts-partial"]').text()).toContain('部分合同版本不可读')
    expect(empty.find('[data-testid="contract-table"]').exists()).toBe(false)
  })

  it('shows the loading state before the ledger resolves and never renders placeholder rows', async () => {
    resource.get.mockReturnValue(new Promise(() => {}))
    const wrapper = await mountPage(ContractWorkspacePage, '/hr/contracts')
    expect(wrapper.get('[role="status"]').text()).toContain('正在加载劳动合同台账')
    expect(wrapper.find('[data-testid="contract-table"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('falls back to a manual employment id when the employee directory is not readable', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/employments/contracts') return Promise.resolve(projectionEnvelope(CONTRACTS))
      if (path === '/v1/employees') {
        return Promise.reject(new ApiError('FORBIDDEN', 'Forbidden', { status: 403 }))
      }
      return Promise.reject(new Error(`Unexpected GET ${path}`))
    })

    const wrapper = await mountPage(ContractWorkspacePage, '/hr/contracts')
    await wrapper.get('[data-testid="contract-create"]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-testid="contract-employee-manual"]').setValue('7')
    await wrapper.get('[data-testid="contract-no"]').setValue('CT-2027-0007')
    await wrapper.get('[data-testid="contract-start"]').setValue('2027-01-01')
    await wrapper.get('[data-testid="contract-save"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/employments/7/contracts',
      expect.objectContaining({ contract_no: 'CT-2027-0007' }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('hides write actions from a read-only role', async () => {
    const wrapper = await mountPage(ContractWorkspacePage, '/hr/contracts', ['people.contract.read'])
    expect(wrapper.find('[data-testid="contract-create"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="contract-renew-31"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="contract-terminate-31"]').exists()).toBe(false)
  })
})
