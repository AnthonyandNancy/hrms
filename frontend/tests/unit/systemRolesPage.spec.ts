import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import ElementPlus from 'element-plus'
import { useSessionStore } from '@/stores/session'
import SystemRolesPage from '@/modules/platform/pages/SystemRolesPage.vue'
import RolePageTree from '@/modules/platform/components/RolePageTree.vue'
import RoleScopeEditor from '@/modules/platform/components/RoleScopeEditor.vue'
import RoleFieldPolicyEditor from '@/modules/platform/components/RoleFieldPolicyEditor.vue'

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

const ROLE_CODE = 'CUSTOM_HR'

const capabilityRows = [
  {
    code: 'people.employee.manage',
    resource: 'people.employee',
    actions: ['manage'],
    anchor_types: ['ORG_UNIT', 'ORG_SUBTREE', 'COMPANY'],
    field_profile_max: 'P2_SENSITIVE',
    risk: 'high',
    description: 'Manage employees.',
  },
  {
    code: 'people.employee.archive',
    resource: 'people.employee',
    actions: ['archive'],
    anchor_types: ['ORG_UNIT', 'ORG_SUBTREE', 'COMPANY'],
    field_profile_max: 'P2_SENSITIVE',
    risk: 'high',
    description: 'Archive employees.',
  },
  {
    code: 'people.employee.restore',
    resource: 'people.employee',
    actions: ['restore'],
    anchor_types: ['ORG_UNIT', 'ORG_SUBTREE', 'COMPANY'],
    field_profile_max: 'P2_SENSITIVE',
    risk: 'high',
    description: 'Restore employees.',
  },
  {
    code: 'people.assignment.manage',
    resource: 'people.assignment',
    actions: ['manage'],
    anchor_types: ['ORG_UNIT', 'ORG_SUBTREE', 'COMPANY'],
    field_profile_max: 'P1_INTERNAL',
    risk: 'high',
    description: 'Manage assignments.',
  },
  {
    code: 'people.employee.read',
    resource: 'people.employee',
    actions: ['read'],
    anchor_types: ['SELF', 'MANAGED', 'ORG_UNIT', 'ORG_SUBTREE', 'COMPANY', 'LEGAL_ENTITY'],
    field_profile_max: 'P2_SENSITIVE',
    risk: 'medium',
    description: 'Read employees.',
  },
]

function roleFixtures() {
  return [
    {
      code: ROLE_CODE,
      name: '自定义 HR',
      is_template: false,
      purpose: '用于合成验收的角色',
      capabilities: [
        {
          capability: 'people.employee.manage',
          anchor_type: 'COMPANY',
          field_profile: 'P0_PUBLIC',
        },
      ],
      page_count: 1,
      member_count: 1,
    },
    {
      code: 'SYSTEM_ADMIN',
      name: '系统管理员',
      is_template: true,
      purpose: '代码拥有的系统管理员模板',
      capabilities: [],
      page_count: 0,
      member_count: 1,
    },
  ]
}

const orgUnits = [
  { id: 1, parent_id: null, code: 'HQ', name: '总公司', type: 'COMPANY' },
  { id: 2, parent_id: 1, code: 'HR', name: '人力资源部', type: 'DEPARTMENT' },
]

function defaultGet(path: string): Promise<unknown> {
  if (path === '/v1/roles') return Promise.resolve({ roles: roleFixtures() })
  if (path === '/v1/capabilities') return Promise.resolve({ capabilities: capabilityRows, read_only: true })
  if (path === `/v1/roles/${ROLE_CODE}/pages`) {
    return Promise.resolve({
      role_code: ROLE_CODE,
      page_codes: ['hr-employees'],
      pages: [
        {
          page_code: 'hr-employees',
          path: '/hr/employees',
          menu_group: '员工档案',
          menu_label: '员工列表',
          required_capability: 'people.employee.read',
        },
      ],
    })
  }
  if (path === '/v1/org-units/tree') return Promise.resolve({ items: orgUnits })
  return Promise.resolve({})
}

function piniaWithCapabilities(capabilities: string[]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })
  return pinia
}

async function mountPage(capabilities = ['iam.role.manage']) {
  const pinia = piniaWithCapabilities(capabilities)
  const wrapper = mount(SystemRolesPage, { global: { plugins: [pinia, ElementPlus] } })
  await flushPromises()
  return wrapper
}

function input(wrapper: Awaited<ReturnType<typeof mountPage>>, selector: string): HTMLInputElement {
  return wrapper.get(selector).element as HTMLInputElement
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
  resource.put.mockReset()
  resource.delete.mockReset()
  resource.get.mockImplementation(defaultGet)
  resource.post.mockResolvedValue({})
  resource.patch.mockResolvedValue({})
  resource.put.mockResolvedValue({})
  resource.delete.mockResolvedValue({})
})

describe('role authorization workbench', () => {
  it('renders a tri-state page tree and the page/action separation note', async () => {
    const pages = [
      { page_code: 'hr-employees', path: '/hr/employees', menu_group: '员工档案', menu_label: '员工列表', order: 16 },
      { page_code: 'employee-detail', path: '/hr/employees/:id', menu_group: '员工档案', menu_label: '员工详情', order: 18 },
    ]
    const wrapper = mount(RolePageTree, {
      props: { pages, modelValue: ['hr-employees'] },
    })

    const group = wrapper.get('input[data-group-toggle="员工档案"]')
    expect((group.element as HTMLInputElement).indeterminate).toBe(true)
    expect(group.attributes('data-tristate')).toBe('partial')
    expect((wrapper.get('input[data-page-code="hr-employees"]').element as HTMLInputElement).checked).toBe(true)
    expect(wrapper.text()).toContain('页面授权决定能否进入和查看；按钮动作必须单独授权')
    expect(wrapper.text()).toContain('hr-employees')
    expect(wrapper.text()).toContain('/hr/employees')

    await group.setValue(true)
    const emitted = wrapper.emitted('update:modelValue') ?? []
    expect(emitted.at(-1)?.[0]).toEqual(['employee-detail', 'hr-employees'])
  })

  it('auto-selects a page when an action is chosen and clears actions after page removal confirmation', async () => {
    const wrapper = await mountPage()

    await wrapper.get('[data-testid="role-tab-actions"]').trigger('click')
    await wrapper.findComponent('[data-testid="action-page-select"]').setValue('employee-detail')
    await wrapper.get('[data-testid="action-toggle-employee.detail.archive"]').setValue(true)
    await flushPromises()

    await wrapper.get('[data-testid="role-tab-pages"]').trigger('click')
    expect(input(wrapper, 'input[data-page-code="employee-detail"]').checked).toBe(true)

    await wrapper.get('input[data-page-code="employee-detail"]').setValue(false)
    const prompt = wrapper.get('[data-testid="page-action-removal-prompt"]')
    expect(prompt.text()).toContain('清除')
    expect(prompt.text()).toContain('动作')

    await wrapper.get('[data-testid="confirm-clear-page-actions"]').trigger('click')
    await flushPromises()
    expect(input(wrapper, 'input[data-page-code="employee-detail"]').checked).toBe(false)

    await wrapper.get('[data-testid="role-tab-actions"]').trigger('click')
    await wrapper.findComponent('[data-testid="action-page-select"]').setValue('employee-detail')
    expect(input(wrapper, '[data-testid="action-toggle-employee.detail.archive"]').checked).toBe(false)
  })

  it('offers only the seven declared anchors, shows an org picker and uses a CASE hint instead of a picker', async () => {
    const capability = {
      code: 'people.employee.archive',
      anchor_types: ['SELF', 'MANAGED', 'ORG_UNIT', 'ORG_SUBTREE', 'COMPANY', 'CASE', 'PAYROLL_RUN', 'LEGAL_ENTITY'],
      field_profile_max: 'P2_SENSITIVE',
    }
    const wrapper = mount(RoleScopeEditor, {
      props: {
        grants: [{ capability: 'people.employee.archive', anchor_type: 'ORG_UNIT', anchor_id: 2, field_profile: 'P0_PUBLIC' }],
        capabilities: [capability],
        orgUnits,
      },
    })

    const anchorOptions = wrapper
      .get('[data-testid="scope-anchor-people.employee.archive"]')
      .findAll('option')
      .map((option) => option.attributes('value'))
    expect(anchorOptions).toEqual(['SELF', 'MANAGED', 'ORG_UNIT', 'ORG_SUBTREE', 'COMPANY', 'CASE', 'PAYROLL_RUN'])
    expect(anchorOptions).not.toContain('LEGAL_ENTITY')
    expect(wrapper.find('[data-testid="org-unit-picker-people.employee.archive"]').exists()).toBe(true)

    await wrapper.setProps({
      grants: [{ capability: 'people.employee.archive', anchor_type: 'CASE', anchor_id: null, field_profile: 'P0_PUBLIC' }],
    })
    expect(wrapper.find('[data-testid="org-unit-picker-people.employee.archive"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="case-anchor-hint-people.employee.archive"]').text()).toContain('案例')
  })

  it('warns when a field profile exceeds the capability ceiling and emits only the changed row', async () => {
    const wrapper = mount(RoleFieldPolicyEditor, {
      props: {
        grants: [
          { capability: 'people.employee.read', field_profile: 'P1_INTERNAL' },
          { capability: 'people.employee.manage', field_profile: 'P2_SENSITIVE' },
        ],
        capabilities: [
          { code: 'people.employee.read', field_profile_max: 'P2_SENSITIVE' },
          { code: 'people.employee.manage', field_profile_max: 'P1_INTERNAL' },
        ],
      },
    })

    await wrapper.get('[data-testid="field-profile-people.employee.read"]').setValue('P3_RESTRICTED')
    expect(wrapper.text()).toContain('超过能力上限')
    expect(wrapper.emitted('update-field-profile')?.[0]?.[0]).toEqual({
      capability: 'people.employee.read',
      field_key: 'people.employee.read',
      field_profile: 'P3_RESTRICTED',
    })
    expect(wrapper.text()).not.toContain('LEGAL_ENTITY')
  })

  it('sends one atomic save request carrying the visible diff and disables save while in flight', async () => {
    const wrapper = await mountPage()

    await wrapper.get('input[data-page-code="employee-detail"]').setValue(true)
    await wrapper.get('[data-testid="role-tab-actions"]').trigger('click')
    await wrapper.findComponent('[data-testid="action-page-select"]').setValue('employee-detail')
    await wrapper.get('[data-testid="action-toggle-employee.detail.archive"]').setValue(true)

    await wrapper.get('[data-testid="role-tab-scopes"]').trigger('click')
    await wrapper.get('[data-testid="scope-anchor-people.employee.manage"]').setValue('ORG_UNIT')
    await wrapper.get('[data-testid="org-unit-picker-people.employee.manage"]').setValue('2')
    await wrapper.get('[data-testid="scope-anchor-people.employee.archive"]').setValue('ORG_UNIT')
    await wrapper.get('[data-testid="org-unit-picker-people.employee.archive"]').setValue('2')

    await wrapper.get('[data-testid="role-tab-fields"]').trigger('click')
    await wrapper.get('[data-testid="field-profile-people.employee.manage"]').setValue('P1_INTERNAL')
    await flushPromises()

    await wrapper.get('[data-testid="save-role"]').trigger('click')
    const diff = wrapper.get('[data-testid="role-diff"]')
    expect(diff.text()).toContain('新增页面：1')
    expect(diff.text()).toContain('新增动作：1')
    expect(diff.text()).toContain('范围变更：1')
    expect(diff.text()).toContain('字段变更：1')

    let resolveSave: ((value: unknown) => void) | undefined
    resource.put.mockImplementationOnce(
      () => new Promise((resolve) => {
        resolveSave = resolve
      }),
    )

    await wrapper.get('[data-testid="confirm-save-role"]').trigger('click')
    await wrapper.vm.$nextTick()
    expect(wrapper.get('[data-testid="confirm-save-role"]').attributes('disabled')).toBeDefined()
    expect(resource.put).toHaveBeenCalledTimes(1)

    resolveSave?.({})
    await flushPromises()

    const [path, body, options] = resource.put.mock.calls[0] as [
      string,
      Record<string, unknown> & { pages: string[]; capabilities: Array<Record<string, unknown>> },
      Record<string, unknown>,
    ]
    expect(path).toBe(`/v1/roles/${ROLE_CODE}/authorization`)
    expect(options.idempotencyKey).toEqual(expect.any(String))
    expect(body.pages).toEqual(expect.arrayContaining(['hr-employees', 'employee-detail']))
    expect(body.capabilities).toEqual(expect.arrayContaining([
      expect.objectContaining({
        capability: 'people.employee.manage',
        anchor_type: 'ORG_UNIT',
        anchor_id: 2,
        field_profile: 'P1_INTERNAL',
      }),
      expect.objectContaining({
        capability: 'people.employee.archive',
        anchor_type: 'ORG_UNIT',
        anchor_id: 2,
      }),
    ]))
    expect(resource.post).not.toHaveBeenCalled()
    expect(resource.patch).not.toHaveBeenCalled()
  })

  it('refetches the role and shows Chinese copy after a 409 conflict', async () => {
    const wrapper = await mountPage()
    const rolesReads = resource.get.mock.calls.filter(([path]) => path === '/v1/roles').length
    const pageReads = resource.get.mock.calls.filter(([path]) => path === `/v1/roles/${ROLE_CODE}/pages`).length

    await wrapper.get('input[data-page-code="employee-detail"]').setValue(true)
    await wrapper.get('[data-testid="save-role"]').trigger('click')
    resource.put.mockRejectedValueOnce(
      new ApiError('CONFLICT', 'The role authorization changed.', { status: 409 }),
    )
    await wrapper.get('[data-testid="confirm-save-role"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="role-conflict-message"]').text()).toContain('重新加载')
    expect(resource.get.mock.calls.filter(([path]) => path === '/v1/roles').length).toBeGreaterThan(rolesReads)
    expect(resource.get.mock.calls.filter(([path]) => path === `/v1/roles/${ROLE_CODE}/pages`).length).toBeGreaterThan(pageReads)
    expect(resource.put).toHaveBeenCalledTimes(1)
  })

  it('renders create/rename/copy/delete actions and disables delete for built-in roles with a Chinese hint', async () => {
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="role-create"]').exists()).toBe(true)
    expect(wrapper.find(`[data-testid="role-rename-${ROLE_CODE}"]`).exists()).toBe(true)
    expect(wrapper.find(`[data-testid="role-copy-${ROLE_CODE}"]`).exists()).toBe(true)
    expect(wrapper.find(`[data-testid="role-delete-${ROLE_CODE}"]`).exists()).toBe(true)

    expect(wrapper.find('[data-testid="role-delete-SYSTEM_ADMIN"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="role-rename-SYSTEM_ADMIN"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="role-copy-SYSTEM_ADMIN"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="built-in-role-hint-SYSTEM_ADMIN"]').text()).toContain('内置角色')

    await wrapper.get('[data-testid="role-create"]').trigger('click')
    expect(wrapper.find('[data-testid="role-create-form"]').exists()).toBe(true)
  })

  it('shows a forbidden state and no mutating controls without iam.role.manage', async () => {
    const wrapper = await mountPage([])

    expect(wrapper.get('[data-testid="roles-forbidden"]').text()).toContain('无权')
    expect(wrapper.find('[data-testid="role-create"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="save-role"]').exists()).toBe(false)
    expect(resource.get).not.toHaveBeenCalled()
  })
})
