import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import { defineComponent } from 'vue'
import { useSessionStore } from '@/stores/session'
import PayrollRuleVersionEditor from '@/modules/payroll/components/PayrollRuleVersionEditor.vue'

const resource = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

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

function ruleRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 41,
    scheme_item_id: 71,
    version: 1,
    ast: { type: 'const', value: '50000' },
    stage: 'EARNING',
    dependencies: [],
    rounding_mode: 'HALF_UP',
    unit: 'CENT',
    engine_version: 'PAYROLL_RULE_V1',
    hash: 'b'.repeat(64),
    status: 'DRAFT',
    published_by: null,
    published_at: null,
    retire_reason: null,
    ...overrides,
  }
}

function page(items: unknown[]): Record<string, unknown> {
  return { items, page: 1, page_size: 20, total: items.length }
}

function mountEditor(): ReturnType<typeof mount> {
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSessionStore()
  session.capabilities = ['payroll.run.prepare']
  session.status = 'authenticated'

  return mount(PayrollRuleVersionEditor, {
    props: { modelValue: true },
    global: { plugins: [pinia, ElementPlus], stubs: { ElDialog: OverlayStub } },
  })
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.get.mockResolvedValue(page([ruleRaw()]))
  resource.post.mockImplementation((path: string) => {
    if (path.endsWith('/publish')) return Promise.resolve(ruleRaw({ status: 'PUBLISHED', published_by: 10 }))
    if (path.endsWith('/retire')) return Promise.resolve(ruleRaw({ status: 'RETIRED', retire_reason: 'Synthetic retirement.' }))
    return Promise.resolve(ruleRaw({ id: 42, version: 2 }))
  })
})

describe('payroll restricted rule editor', () => {
  it('loads versions and creates a draft with JSON AST and dependency ids', async () => {
    const wrapper = mountEditor()
    await wrapper.get('[data-testid="payroll-rule-item-id"]').setValue('71')
    await wrapper.get('[data-testid="payroll-rule-load"]').trigger('click')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/payroll/rules', { scheme_item_id: 71 })
    expect(wrapper.get('[data-testid="payroll-rule-table"]').text()).toContain('草稿')

    await wrapper.get('[data-testid="payroll-rule-dependencies"]').setValue('42')
    await wrapper.get('[data-testid="payroll-rule-create"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/payroll/scheme-items/71/rules',
      expect.objectContaining({
        ast: expect.objectContaining({ type: 'if' }),
        dependencies: [42],
        stage: 'EARNING',
        rounding_mode: 'HALF_UP',
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('publishes and retires a rule version through the real commands', async () => {
    const wrapper = mountEditor()
    await wrapper.get('[data-testid="payroll-rule-item-id"]').setValue('71')
    await wrapper.get('[data-testid="payroll-rule-load"]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-testid="payroll-rule-publish-41"]').trigger('click')
    await flushPromises()
    expect(resource.post).toHaveBeenCalledWith(
      '/v1/payroll/rules/41/publish',
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.get('[data-testid="payroll-rule-table"]').text()).toContain('已发布')

    await wrapper.get('[data-testid="payroll-rule-retire-reason"]').setValue('Synthetic retirement.')
    await wrapper.get('[data-testid="payroll-rule-retire-41"]').trigger('click')
    await flushPromises()
    expect(resource.post).toHaveBeenCalledWith(
      '/v1/payroll/rules/41/retire',
      { reason: 'Synthetic retirement.' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.get('[data-testid="payroll-rule-table"]').text()).toContain('已退役')
  })
})
