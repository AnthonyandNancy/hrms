import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SystemRolesPage from '@/modules/platform/pages/SystemRolesPage.vue'

const resourceGet = vi.fn()
const resourcePut = vi.fn()

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resourceGet(...args),
  resourcePut: (...args: unknown[]) => resourcePut(...args),
}))

const roles = [
  {
    code: 'CUSTOM_HR',
    name: '自定义 HR',
    is_template: false,
    purpose: 'synthetic role purpose',
    capabilities: [
      { capability: 'people.employee.read', anchor_type: 'COMPANY', field_profile: 'P1_INTERNAL' },
    ],
    page_count: 1,
    member_count: 1,
  },
]

const capabilities = [
  {
    code: 'people.employee.read',
    resource: 'people.employee',
    actions: ['read'],
    anchor_types: ['COMPANY'],
    field_profile_max: 'P2_SENSITIVE',
    risk: 'low',
    description: 'Read employees',
  },
  {
    code: 'iam.binding.manage',
    resource: 'iam.binding',
    actions: ['manage'],
    anchor_types: ['COMPANY'],
    field_profile_max: 'P3_RESTRICTED',
    risk: 'critical',
    description: 'Manage authority bindings',
  },
  {
    code: 'people.employee.update',
    resource: 'people.employee',
    actions: ['update'],
    anchor_types: ['COMPANY'],
    field_profile_max: 'P1_INTERNAL',
    risk: 'high',
    description: 'Update employees',
  },
]

const rolePages = {
  role_code: 'CUSTOM_HR',
  page_codes: ['hr-employees'],
  pages: [
    { page_code: 'hr-employees', path: '/hr/employees', menu_group: '员工档案', menu_label: '员工列表', required_capability: 'people.employee.read', actions: [
      { capability: 'people.employee.read', label: '查看员工' },
      { capability: 'iam.binding.manage', label: '管理授权绑定' },
    ] },
  ],
}

const cataloguePages = [
  { page_code: 'hr-employees', path: '/hr/employees', menu_group: '员工档案', menu_label: '员工列表', required_capability: 'people.employee.read' },
  {
    page_code: 'hr-contracts',
    path: '/hr/contracts',
    menu_group: '员工档案',
    menu_label: '合同',
    required_capability: 'people.contract.read',
    actions: [{ capability: 'iam.binding.manage', label: '管理授权绑定' }],
  },
]

beforeEach(() => {
  resourceGet.mockReset()
  resourcePut.mockReset()
  resourceGet.mockImplementation((path: string) => {
    if (path === '/v1/roles') return Promise.resolve({ roles })
    if (path === '/v1/capabilities') return Promise.resolve({ capabilities })
    if (path === '/v1/roles/CUSTOM_HR/pages') return Promise.resolve(rolePages)
    if (path === '/v1/pages/catalog') return Promise.resolve({ pages: cataloguePages, read_only: true })
    return Promise.resolve({})
  })
  resourcePut.mockResolvedValue({})
})

async function mountPage() {
  const wrapper = mount(SystemRolesPage, {
    global: {
      stubs: {
        'el-button': { template: '<button type="button"><slot /></button>' },
      },
    },
  })
  await flushPromises()
  return wrapper
}

describe('role permission matrix', () => {
  it('renders the four authorization tabs without auto-selecting parent menu children', async () => {
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="tab-pages"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-buttons"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-scopes"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-fields"]').exists()).toBe(true)

    const checked = wrapper.findAll('input[type="checkbox"][data-page-code]')
      .filter((input) => (input.element as HTMLInputElement).checked)
      .map((input) => input.attributes('data-page-code'))
    expect(checked).toEqual(['hr-employees'])
  })

  it('shows a diff preview and requires a reason before saving a high-risk addition', async () => {
    const wrapper = await mountPage()

    await wrapper.find('[data-page-code="hr-contracts"]').setValue(true)
    await wrapper.find('[data-testid="tab-buttons"]').trigger('click')
    await wrapper.find('select').setValue('hr-contracts')
    await wrapper.vm.$nextTick()
    await wrapper.find('[data-capability="iam.binding.manage"]').setValue(true)
    await wrapper.find('[data-testid="save-role"]').trigger('click')
    await flushPromises()

    const diff = wrapper.find('[data-testid="role-diff"]')
    expect(diff.exists()).toBe(true)
    expect(diff.text()).toContain('/hr/contracts')

    expect(wrapper.find('[data-testid="high-risk-reason"]').exists()).toBe(true)
    expect(resourcePut).not.toHaveBeenCalled()

    await wrapper.find('[data-testid="high-risk-reason"]').setValue('Synthetic acceptance reason')
    await wrapper.find('[data-testid="confirm-save-role"]').trigger('click')
    await flushPromises()

    expect(resourcePut).toHaveBeenCalledWith(
      '/v1/roles/CUSTOM_HR/pages',
      { pages: ['hr-employees', 'hr-contracts'] },
      expect.any(Object),
    )
  })
})
