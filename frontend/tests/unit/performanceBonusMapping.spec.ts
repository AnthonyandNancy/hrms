import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import { defineComponent } from 'vue'
import { useSessionStore } from '@/stores/session'
import PerformanceBonusMappingDialog from '@/modules/talent/performance/components/PerformanceBonusMappingDialog.vue'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resource.get(...args),
  resourcePost: (...args: unknown[]) => resource.post(...args),
}))

const OverlayStub = defineComponent({
  inheritAttrs: false,
  props: { modelValue: Boolean, title: String },
  emits: ['update:modelValue'],
  template: `<div v-if="modelValue" class="dialog-stub" role="dialog">
    <h2>{{ title }}</h2><slot /><slot name="footer" />
  </div>`,
})

function mappingRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 41,
    performance_snapshot_id: 71,
    cycle_id: 9,
    employment_id: 1001,
    payroll_item_code: 'PERFORMANCE_BONUS',
    period: '2026-01',
    amount_cents: 50000,
    mapping_version: 'V1',
    status: 'DRAFT',
    approved_by: null,
    approved_at: null,
    source_revision: 'a'.repeat(64),
    ...overrides,
  }
}

function page(items: unknown[]): Record<string, unknown> {
  return { items, page: 1, page_size: 20, total: items.length }
}

function mountDialog(): ReturnType<typeof mount> {
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSessionStore()
  session.capabilities = ['performance.result.publish']
  session.status = 'authenticated'

  return mount(PerformanceBonusMappingDialog, {
    props: { modelValue: true },
    global: {
      plugins: [pinia, ElementPlus],
      stubs: { ElDialog: OverlayStub },
    },
  })
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.get.mockResolvedValue(page([mappingRaw()]))
  resource.post.mockImplementation((path: string) => {
    if (path.endsWith('/approve')) return Promise.resolve(mappingRaw({ status: 'APPROVED', approved_by: 20 }))
    return Promise.resolve(mappingRaw())
  })
})

describe('performance bonus mapping dialog', () => {
  it('lists real mappings and creates an integer-cent draft through the API', async () => {
    const wrapper = mountDialog()
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith(
      '/v1/performance/bonus-mappings',
      expect.objectContaining({ page: 1, page_size: 100 }),
    )
    expect(wrapper.get('[data-testid="bonus-mapping-table"]').text()).toContain('¥500.00')

    await wrapper.get('[data-testid="bonus-mapping-snapshot"]').setValue('71')
    await wrapper.get('[data-testid="bonus-mapping-period"]').setValue('2026-01')
    await wrapper.get('[data-testid="bonus-mapping-amount"]').setValue('500.00')
    await wrapper.get('[data-testid="bonus-mapping-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/performance/bonus-mappings',
      expect.objectContaining({
        snapshot_id: 71,
        payroll_item_code: 'PERFORMANCE_BONUS',
        period: '2026-01',
        amount_cents: 50000,
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('approves an existing draft and renders the approved state', async () => {
    const wrapper = mountDialog()
    await flushPromises()

    await wrapper.get('[data-testid="bonus-mapping-approve-41"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/performance/bonus-mappings/41/approve',
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.get('[data-testid="bonus-mapping-table"]').text()).toContain('已批准')
  })
})
