import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { ApiError } from '@/api/errors'
import ElementPlus from 'element-plus'
import { PAGE_REGISTRY } from '@/router/registry'
import { useSessionStore } from '@/stores/session'
import SystemMenusPage from '@/modules/platform/pages/SystemMenusPage.vue'
import SystemPermissionsPage from '@/modules/platform/pages/SystemPermissionsPage.vue'
import SystemDataScopesPage from '@/modules/platform/pages/SystemDataScopesPage.vue'
import SystemFieldPoliciesPage from '@/modules/platform/pages/SystemFieldPoliciesPage.vue'

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

function listResponse(items: unknown[]) {
  return {
    items,
    page: 1,
    page_size: 100,
    total: items.length,
    as_of: '2026-11-26',
    freshness: 'LIVE',
    source_hash: 'synthetic',
    partial: false,
    partial_reason: null,
    drilldown: null,
  }
}

function activePiniaWith(capabilities: string[]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSessionStore()
  session.capabilities = [...capabilities]
  session.status = 'authenticated'
  return pinia
}

async function mountWithCapabilities(component: object, capabilities: string[]): Promise<VueWrapper> {
  const pinia = activePiniaWith(capabilities)
  const wrapper = mount(component, {
    global: {
      plugins: [pinia, ElementPlus],
    },
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
  resource.put.mockReset()
  resource.delete.mockReset()
})

describe('system governance pages', () => {
  it('saves a per-company menu override and requires confirmation before reset', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/system/menus') {
        return Promise.resolve(
          listResponse([
            { page_code: 'system-menus', label: '菜单/页面管理', visible: true, sort_order: 104 },
          ]),
        )
      }
      return Promise.resolve({})
    })
    resource.patch.mockResolvedValue({ page_code: 'system-menus' })
    resource.delete.mockResolvedValue({ page_code: 'system-menus', deleted: true })

    const wrapper = await mountWithCapabilities(SystemMenusPage, ['iam.menu.manage'])

    expect(wrapper.text()).toContain('菜单/页面管理')
    expect(wrapper.text()).toContain('system-menus')
    expect(wrapper.text()).toContain('iam.menu.manage')
    expect(wrapper.text()).not.toContain('租户选择')

    await wrapper.findComponent('[data-testid="menu-visible-system-menus"]').setValue(false)
    await wrapper.findComponent('[data-testid="menu-order-system-menus"]').setValue(7)
    await wrapper.get('[data-testid="menu-save-system-menus"]').trigger('click')
    await flushPromises()

    expect(resource.patch).toHaveBeenCalledWith(
      '/v1/system/menus/system-menus',
      expect.objectContaining({ visible: false, sort_order: 7 }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )

    await wrapper.get('[data-testid="menu-reset-system-menus"]').trigger('click')
    expect(wrapper.find('[data-testid="menu-reset-confirm-system-menus"]').exists()).toBe(true)
    expect(resource.delete).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="menu-reset-confirm-button-system-menus"]').trigger('click')
    await flushPromises()

    expect(resource.delete).toHaveBeenCalledWith(
      '/v1/system/menus/system-menus',
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('filters the read-only capability catalogue by search and module and counts page/action usage', async () => {
    resource.get.mockResolvedValue({
      capabilities: [
        {
          code: 'iam.menu.manage',
          resource: 'menu',
          actions: ['create', 'update', 'delete', 'sort'],
          anchor_types: ['COMPANY'],
          field_profile_max: 'P1_INTERNAL',
          risk: 'high',
          sod_group: 'identity_governance',
          description: 'Manage the code-declared menu tree.',
        },
        {
          code: 'people.employee.read',
          resource: 'employee',
          actions: ['read'],
          anchor_types: ['SELF', 'ORG_UNIT', 'COMPANY'],
          field_profile_max: 'P2_SENSITIVE',
          risk: 'high',
          sod_group: null,
          description: 'Read employee records.',
        },
      ],
      read_only: true,
    })

    const wrapper = await mountWithCapabilities(SystemPermissionsPage, ['iam.permission.read', 'iam.role.manage'])

    const expectedPageCount = PAGE_REGISTRY.filter(
      (page) =>
        page.capability === 'iam.menu.manage' ||
        page.actions.some((action) => action.capability === 'iam.menu.manage'),
    ).length
    const expectedActionCount = PAGE_REGISTRY.flatMap((page) => page.actions).filter(
      (action) => action.capability === 'iam.menu.manage',
    ).length

    expect(wrapper.text()).toContain('iam.menu.manage')
    expect(wrapper.get('[data-testid="permission-usage-iam.menu.manage"]').text()).toContain(
      `${expectedPageCount} 个页面 / ${expectedActionCount} 个动作`,
    )
    expect(wrapper.text()).toContain('P1 内部')
    expect(wrapper.text()).toContain('本公司')

    await wrapper.get('[data-testid="permission-search"]').setValue('employee')
    expect(wrapper.text()).not.toContain('iam.menu.manage')
    expect(wrapper.text()).toContain('people.employee.read')

    await wrapper.get('[data-testid="permission-search"]').setValue('')
    await wrapper.findComponent('[data-testid="permission-module-filter"]').setValue('iam')
    expect(wrapper.text()).toContain('iam.menu.manage')
    expect(wrapper.text()).not.toContain('people.employee.read')
  })

  it('shows code-owned data scope inclusion rules and disables editing with an explanation', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/system/data-scopes') {
        return Promise.resolve(
          listResponse([
            {
              template: 'ORG_SUBTREE',
              anchor_type: 'ORG_SUBTREE',
              label: '本部门及子部门',
              active: true,
              sort_order: 3,
              description: 'An organization unit and its declared descendants.',
            },
            {
              template: 'COMPANY',
              anchor_type: 'COMPANY',
              label: '本公司/法人',
              active: true,
              sort_order: 4,
              description: 'One legal entity.',
            },
          ]),
        )
      }
      if (path === '/v1/org-units/tree') {
        return Promise.resolve(
          listResponse([
            { id: 1, parent_id: null, code: 'HQ', name: '总公司', type: 'COMPANY' },
            { id: 2, parent_id: 1, code: 'HR', name: '人力资源部', type: 'DEPARTMENT' },
          ]),
        )
      }
      return Promise.resolve({})
    })

    const wrapper = await mountWithCapabilities(SystemDataScopesPage, ['iam.scope.manage', 'org.tree.read', 'org.unit.read'])

    expect(wrapper.text()).toContain('ORG_SUBTREE')
    expect(wrapper.find('[data-testid="tenant-select"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="legal-entity-select"]').exists()).toBe(false)

    await wrapper.get('[data-testid="data-scope-item-ORG_SUBTREE"]').trigger('click')

    const detail = wrapper.get('[data-testid="data-scope-detail"]')
    expect(detail.text()).toContain('本部门及子部门')
    expect(detail.text()).toContain('组织单元及其全部下级')
    expect(detail.text()).toContain('总公司')
    expect(detail.text()).toContain('人力资源部')
    expect(detail.get('[data-testid="data-scope-edit-disabled"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="data-scope-code-owned-hint"]').text()).toContain('代码拥有')
    expect(resource.get).not.toHaveBeenCalledWith('/v1/system/data-scopes', expect.objectContaining({ legal_entity_id: expect.anything() }))
  })

  it('requires a before/after confirmation for field policy changes and maps 422 errors inline', async () => {
    resource.get.mockResolvedValue(
      listResponse([
        {
          field_key: 'employee.bank_account',
          profile: 'P2_SENSITIVE',
          declared_profile: 'P2_SENSITIVE',
          can_tighten: true,
          change_history: [
            { changed_at: '2026-11-20T08:00:00Z', from_profile: 'P2_SENSITIVE', to_profile: 'P2_SENSITIVE', actor: 'synthetic.admin' },
          ],
        },
        {
          field_key: 'employee.salary',
          profile: 'P3_RESTRICTED',
          declared_profile: 'P3_RESTRICTED',
          can_tighten: false,
        },
      ]),
    )
    resource.put.mockRejectedValue(
      new ApiError('VALIDATION_FAILED', 'The given data was invalid.', {
        status: 422,
        fieldErrors: { field_profile: ['The requested field profile is not accepted.'] },
      }),
    )

    const wrapper = await mountWithCapabilities(SystemFieldPoliciesPage, ['iam.field.manage'])

    expect(wrapper.text()).toContain('employee.bank_account')
    expect(wrapper.text()).toContain('P2 敏感')
    expect(wrapper.text()).toContain('影响')
    expect(wrapper.text()).toContain('2026-11-20')
    expect(wrapper.text()).toContain('已达声明档位')

    const profileSelect = wrapper.getComponent('[data-testid="field-policy-profile-employee.bank_account"]') as VueWrapper
    await profileSelect.setValue('P3_RESTRICTED')
    profileSelect.vm.$emit('change', 'P3_RESTRICTED')
    await flushPromises()
    await wrapper.get('[data-testid="field-policy-save-employee.bank_account"]').trigger('click')
    await flushPromises()

    expect(resource.put).not.toHaveBeenCalled()
    const diff = wrapper.get('[data-testid="field-policy-confirm-employee.bank_account"]')
    expect(diff.text()).toContain('变更前')
    expect(diff.text()).toContain('变更后')
    expect(diff.text()).toContain('P2 敏感')
    expect(diff.text()).toContain('P3 受限')
    expect(diff.text()).toContain('可见')
    expect(diff.text()).toContain('可编辑')
    expect(diff.text()).toContain('可导出')

    await wrapper.get('[data-testid="field-policy-confirm-button-employee.bank_account"]').trigger('click')
    await flushPromises()

    expect(resource.put).toHaveBeenCalledWith(
      '/v1/system/field-policies/employee.bank_account',
      { field_profile: 'P3_RESTRICTED' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.get('[data-testid="field-policy-field-error-employee.bank_account"]').text()).toContain('字段档位')
    expect(wrapper.text()).not.toContain('VALIDATION_FAILED')
    expect(wrapper.text()).not.toContain('The requested field profile is not accepted.')
  })
})
