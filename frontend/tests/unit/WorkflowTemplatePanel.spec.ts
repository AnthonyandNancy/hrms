import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import { useSessionStore } from '@/stores/session'
import WorkflowTemplatePanel from '@/modules/platform/workflow/components/WorkflowTemplatePanel.vue'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
  resourcePut: vi.fn(),
  resourcePatch: vi.fn(),
  resourceDelete: vi.fn(),
}))

function useCapabilities(capabilities: string[]): void {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })
}


const SelectStub = {
  props: { modelValue: [String, Number] },
  emits: ['update:modelValue'],
  template: `<select :value="modelValue" @change="$emit('update:modelValue', $event.target.value)"><slot /></select>`,
}

const OptionStub = {
  props: { value: [String, Number], label: String },
  template: `<option :value="value">{{ label }}</option>`,
}

function page(items: unknown[]) {
  return { items, page: 1, page_size: items.length, total: items.length }
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
})

describe('WorkflowTemplatePanel', () => {
  it('renders versions from the real endpoint and hides creation for readers', async () => {
    useCapabilities(['workflow.case.read'])
    resource.get.mockResolvedValue(page([{
      id: 1,
      code: 'talent.offer',
      name: 'Offer 审批',
      version: 1,
      status: 'PUBLISHED',
      node_count: 3,
      effect_count: 1,
      definition: { nodes: [], effects: [] },
      created_at: '2026-11-27T01:00:00Z',
    }]))

    const wrapper = mount(WorkflowTemplatePanel, {
      global: { plugins: [ElementPlus], stubs: { teleport: true, 'el-select': SelectStub, 'el-option': OptionStub } },
    })
    await flushPromises()

    expect(wrapper.get('[data-testid="workflow-template-version"]').text()).toContain('Offer 审批')
    expect(wrapper.text()).toContain('第 1 版')
    expect(wrapper.text()).not.toContain('{"nodes"')
    expect(wrapper.find('[data-testid="workflow-template-create"]').exists()).toBe(false)
  })

  it('creates a new version with an idempotency key for admins', async () => {
    useCapabilities(['workflow.case.read', 'workflow.case.admin'])
    resource.get.mockResolvedValue(page([{
      id: 1,
      code: 'talent.offer',
      name: 'Offer 审批',
      version: 1,
      status: 'PUBLISHED',
      definition: {},
    }]))
    resource.post.mockResolvedValue({
      id: 2,
      code: 'talent.offer',
      name: 'Offer 审批',
      version: 2,
      status: 'DRAFT',
      created_at: '2026-11-27T02:00:00Z',
    })

    const wrapper = mount(WorkflowTemplatePanel, {
      global: { plugins: [ElementPlus], stubs: { teleport: true, 'el-select': SelectStub, 'el-option': OptionStub } },
    })
    await flushPromises()

    await wrapper.get('[data-testid="workflow-template-create"]').trigger('click')
    await wrapper.get('[data-testid="workflow-template-code"]').setValue('talent.offer')
    await wrapper.get('[data-testid="workflow-template-name"]').setValue('Offer 审批')
    await wrapper.findAllComponents({ name: 'ElInputNumber' })[0]!.vm.$emit('update:modelValue', 2)
    await wrapper.get('[data-testid="workflow-template-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/workflow/templates',
      expect.objectContaining({
        code: 'talent.offer',
        version: 2,
        status: 'DRAFT',
        definition: expect.objectContaining({ nodes: expect.any(Array) }),
      }),
      expect.objectContaining({ idempotencyKey: expect.stringContaining('workflow-template') }),
    )
    // A definition is submitted as a bounded declarative object, never as JSON text.
    const body = (resource.post.mock.calls[0]?.[1] ?? {}) as Record<string, unknown>
    expect(typeof body.definition).toBe('object')
  })

  it('shows a forbidden state without inventing template rows', async () => {
    useCapabilities(['workflow.case.read'])
    const { ApiError } = await import('@/api/errors')
    resource.get.mockRejectedValue(new ApiError('FORBIDDEN', 'forbidden', { status: 403 }))

    const wrapper = mount(WorkflowTemplatePanel, {
      global: { plugins: [ElementPlus], stubs: { teleport: true, 'el-select': SelectStub, 'el-option': OptionStub } },
    })
    await flushPromises()

    expect(wrapper.find('[data-testid="workflow-templates-forbidden"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-testid="workflow-template-version"]')).toHaveLength(0)
  })
})
