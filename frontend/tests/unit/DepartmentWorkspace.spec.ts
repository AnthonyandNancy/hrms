import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
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

import DepartmentWorkspacePage from '@/modules/people/departments/pages/DepartmentWorkspacePage.vue'
import { canUseAsParent, type Department } from '@/modules/people/departments/api'

const DEPARTMENTS: Department[] = [
  {
    id: 1,
    parent_id: null,
    code: 'HQ',
    name: '总公司',
    type: 'COMPANY',
    status: 'ACTIVE',
    headcount: 2,
    headcount_limit: 10,
    valid_from: '2026-01-01',
    valid_to: '9999-12-31',
  },
  {
    id: 2,
    parent_id: 1,
    code: 'HR',
    name: '人力资源部',
    type: 'DEPARTMENT',
    status: 'ACTIVE',
    headcount: 3,
    headcount_limit: 5,
    valid_from: '2026-01-01',
    valid_to: '9999-12-31',
  },
  {
    id: 3,
    parent_id: 2,
    code: 'TA',
    name: '招聘团队',
    type: 'TEAM',
    status: 'ACTIVE',
    headcount: 1,
    headcount_limit: 2,
    valid_from: '2026-01-01',
    valid_to: '9999-12-31',
  },
  {
    id: 4,
    parent_id: null,
    code: 'OPS',
    name: '运营中心',
    type: 'DIVISION',
    status: 'ACTIVE',
    headcount: 4,
    headcount_limit: 8,
    valid_from: '2026-01-01',
    valid_to: '9999-12-31',
  },
]

const TREE_ROWS = DEPARTMENTS.map(({ id, parent_id, code, name, type }) => ({
  id,
  parent_id,
  code,
  name,
  type,
}))

// Element Plus overlays teleport outside the wrapper in jsdom. The stub keeps
// the same slot contract and preserves the data-testid attributes under test.
const OverlayStub = defineComponent({
  inheritAttrs: false,
  props: { modelValue: Boolean, title: String },
  emits: ['update:modelValue', 'close'],
  template: `<div v-if="modelValue" v-bind="$attrs" class="el-overlay-stub" role="dialog">
    <h2>{{ title }}</h2>
    <slot />
    <slot name="footer" />
  </div>`,
})

const MEMBER_ROWS = [
  {
    employment_id: 44,
    assignment_id: 301,
    assignment_type: 'PRIMARY',
    is_primary: true,
    valid_from: '2026-01-01',
    valid_to: '9999-12-31',
  },
]

function listResponse(items: unknown[]) {
  return {
    items,
    page: 1,
    page_size: 100,
    total: items.length,
    partial: false,
    partial_reason: null,
  }
}

function mockWorkspaceGet(): void {
  resource.get.mockImplementation((path: string) => {
    if (path === '/v1/departments') return Promise.resolve(listResponse(DEPARTMENTS))
    if (path === '/v1/org-units/tree') return Promise.resolve(listResponse(TREE_ROWS))
    if (/^\/v1\/departments\/\d+\/members$/.test(path)) {
      return Promise.resolve(listResponse(MEMBER_ROWS))
    }
    return Promise.reject(new Error(`Unexpected GET ${path}`))
  })
}

function activePiniaWith(capabilities: string[]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSessionStore()
  session.capabilities = [...capabilities]
  session.status = 'authenticated'
  return pinia
}

async function mountWorkspace(
  capabilities: string[] = ['org.unit.read', 'org.unit.manage'],
): Promise<VueWrapper> {
  const pinia = activePiniaWith(capabilities)
  const wrapper = mount(DepartmentWorkspacePage, {
    global: {
      plugins: [pinia, ElementPlus],
      stubs: { ElDrawer: OverlayStub, ElDialog: OverlayStub, teleport: true },
    },
  })
  await flushPromises()
  return wrapper
}

async function selectDepartment(wrapper: VueWrapper, id: number): Promise<void> {
  await wrapper.get(`[data-testid="dept-node-${id}"]`).trigger('click')
  await flushPromises()
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
  resource.delete.mockReset()
  resource.post.mockResolvedValue({})
  resource.patch.mockResolvedValue({})
  mockWorkspaceGet()
})

describe('department workspace', () => {
  it('renders per-node headcount, expands children lazily and searches the whole tree', async () => {
    const wrapper = await mountWorkspace()

    expect(wrapper.get('[data-testid="dept-node-1"]').text()).toContain('总公司')
    expect(wrapper.get('[data-testid="dept-node-1"]').text()).toContain('在编 2 人')
    expect(wrapper.find('[data-testid="dept-node-2"]').exists()).toBe(false)

    await wrapper.get('[data-testid="dept-toggle-1"]').trigger('click')

    expect(wrapper.find('[data-testid="dept-node-2"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="dept-node-2"]').text()).toContain('在编 3 人')

    const search = wrapper.get('[data-testid="dept-search"]')
    await search.setValue('TA')
    expect(wrapper.find('[data-testid="dept-node-3"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="dept-node-3"]').text()).toContain('在编 1 人')
    expect(wrapper.find('[data-testid="dept-node-1"]').exists()).toBe(false)

    await search.setValue('运营')
    expect(wrapper.find('[data-testid="dept-node-4"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="dept-node-3"]').exists()).toBe(false)
  })

  it('shows the selected node in Chinese with headcount, member count and the manager gap', async () => {
    const wrapper = await mountWorkspace()
    await selectDepartment(wrapper, 1)

    const detail = wrapper.get('[data-testid="department-detail"]')
    expect(detail.text()).toContain('总公司')
    expect(detail.text()).toContain('公司')
    expect(detail.text()).toContain('启用')
    expect(detail.text()).toContain('在编人数')
    expect(detail.text()).toContain('2 人')
    expect(detail.text()).toContain('成员数')
    expect(detail.text()).toContain('1 人')
    expect(detail.get('[data-testid="department-manager-gap"]').text()).toContain('负责人')
    expect(detail.get('[data-testid="department-manager-gap"]').text()).toContain('接口未返回')
    expect(resource.get).toHaveBeenCalledWith(
      '/v1/departments/1/members',
      expect.objectContaining({ as_of: expect.any(String) }),
    )
  })

  it('validates a half-open date range locally and maps create 422 field errors inline', async () => {
    resource.post.mockRejectedValueOnce(
      new ApiError('VALIDATION_FAILED', '提交内容未通过校验', {
        status: 422,
        fieldErrors: { code: ['该部门编码已存在。'] },
      }),
    )
    const wrapper = await mountWorkspace()
    await wrapper.get('[data-testid="department-create"]').trigger('click')
    await nextTick()

    await wrapper.get('[data-testid="department-name"]').setValue('合成测试部')
    await wrapper.get('[data-testid="department-code"]').setValue('SYN-001')
    await wrapper.get('[data-testid="department-valid-from"]').setValue('2026-12-01')
    await wrapper.get('[data-testid="department-valid-to"]').setValue('2026-12-01')
    await wrapper.get('[data-testid="department-manager"]').setValue('88')
    await wrapper.get('[data-testid="department-save"]').trigger('click')

    expect(wrapper.get('[data-testid="department-date-range-error"]').text()).toContain('晚于')
    expect(resource.post).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="department-valid-to"]').setValue('2026-12-31')
    await wrapper.get('[data-testid="department-save"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/departments',
      expect.objectContaining({
        parent_id: null,
        name: '合成测试部',
        code: 'SYN-001',
        type: 'DEPARTMENT',
        valid_from: '2026-12-01',
        valid_to: '2026-12-31',
        manager_employment_id: 88,
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.get('[data-testid="field-error-code"]').text()).toContain('已存在')
    expect(wrapper.get('[data-testid="department-form-error"]').text()).toContain('未通过校验')
    expect(wrapper.find('[data-testid="department-form-drawer"]').exists()).toBe(true)
  })

  it('edits a department without offering itself or descendants as the parent', async () => {
    const wrapper = await mountWorkspace()
    await wrapper.get('[data-testid="dept-toggle-1"]').trigger('click')
    await selectDepartment(wrapper, 2)

    await wrapper.get('[data-testid="department-edit"]').trigger('click')
    await nextTick()
    const drawer = wrapper.get('[data-testid="department-form-drawer"]')
    expect((drawer.get('[data-testid="department-name"]').element as HTMLInputElement).value).toBe('人力资源部')

    const parentOptions = drawer
      .findAll('[data-testid="department-parent"] option')
      .map((option) => option.attributes('value'))
    expect(parentOptions).not.toContain('2')
    expect(parentOptions).not.toContain('3')
    expect(parentOptions).toContain('1')
    expect(parentOptions).toContain('4')

    await drawer.get('[data-testid="department-name"]').setValue('人力资源与行政部')
    await drawer.get('[data-testid="department-parent"]').setValue('1')
    await drawer.get('[data-testid="department-manager"]').setValue('88')
    await drawer.get('[data-testid="department-save"]').trigger('click')
    await flushPromises()

    expect(resource.patch).toHaveBeenCalledWith(
      '/v1/departments/2',
      expect.objectContaining({
        parent_id: 1,
        name: '人力资源与行政部',
        code: 'HR',
        type: 'DEPARTMENT',
        valid_from: '2026-01-01',
        valid_to: null,
        manager_employment_id: 88,
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('never offers the current department or its descendants as a move target', async () => {
    const wrapper = await mountWorkspace()
    await selectDepartment(wrapper, 1)
    await wrapper.get('[data-testid="department-move"]').trigger('click')
    await nextTick()

    const dialog = wrapper.get('[data-testid="department-move-dialog"]')
    const optionValues = dialog
      .findAll('[data-testid="move-parent"] option')
      .map((option) => option.attributes('value'))
    expect(optionValues).toContain('4')
    expect(optionValues).not.toContain('1')
    expect(optionValues).not.toContain('2')
    expect(optionValues).not.toContain('3')
    expect(dialog.text()).toContain('下级部门')
    expect(dialog.text()).toContain('拒绝')

    expect(canUseAsParent(DEPARTMENTS, 2, 1)).toBe(false)
    expect(canUseAsParent(DEPARTMENTS, 3, 1)).toBe(false)
    expect(canUseAsParent(DEPARTMENTS, 4, 1)).toBe(true)
  })

  it('names both departments in the move confirmation and posts only after final confirmation', async () => {
    const wrapper = await mountWorkspace()
    await selectDepartment(wrapper, 1)
    await wrapper.get('[data-testid="department-move"]').trigger('click')
    await nextTick()

    await wrapper.get('[data-testid="move-parent"]').setValue('4')
    await wrapper.get('[data-testid="move-effective-date"]').setValue('2026-12-01')
    await wrapper.get('[data-testid="move-confirm"]').trigger('click')
    await nextTick()

    const confirmation = wrapper.get('[data-testid="move-confirmation"]')
    expect(confirmation.text()).toContain('总公司')
    expect(confirmation.text()).toContain('运营中心')
    expect(resource.post).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="move-confirm-final"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/org-units/1/move',
      { new_parent_id: 4, effective_from: '2026-12-01' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('adds a member with an effective date, reloads members, and confirms before a blocked removal', async () => {
    const wrapper = await mountWorkspace()
    await wrapper.get('[data-testid="dept-toggle-1"]').trigger('click')
    await selectDepartment(wrapper, 2)

    const panel = wrapper.get('[data-testid="department-members"]')
    expect(panel.text()).toContain('员工关系 #44')
    expect(panel.text()).toContain('主任职')

    await panel.get('[data-testid="member-employment-id"]').setValue('44')
    await panel.get('[data-testid="member-effective-date"]').setValue('2026-12-01')
    await panel.get('[data-testid="member-reason"]').setValue('合成部门调整')
    await panel.get('[data-testid="member-add"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/departments/2/members',
      { employment_id: 44, effective_from: '2026-12-01', reason: '合成部门调整' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(
      resource.get.mock.calls.filter(([path]) => path === '/v1/departments/2/members').length,
    ).toBeGreaterThanOrEqual(2)

    await panel.get('[data-testid="member-remove-301"]').trigger('click')
    await nextTick()

    const dialog = wrapper.get('[data-testid="member-remove-dialog"]')
    expect(dialog.text()).toContain('员工关系 #44')
    expect(resource.post).toHaveBeenCalledTimes(1)
    expect(resource.delete).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="member-remove-effective-date"]').setValue('2026-12-01')
    await wrapper.get('[data-testid="member-remove-confirm-final"]').trigger('click')
    await nextTick()

    const blocker = wrapper.get('[data-testid="member-remove-blocker"]')
    expect(blocker.text()).toContain('缺少后端接口')
    expect(blocker.text()).toContain('移除部门成员')
    expect(resource.post).toHaveBeenCalledTimes(1)
    expect(resource.delete).not.toHaveBeenCalled()
  })

  it('renders a forbidden state and hides every mutating control from read-only callers', async () => {
    resource.get.mockRejectedValue(
      new ApiError('FORBIDDEN', 'Forbidden.', { status: 403, requestId: 'req-dept-forbidden' }),
    )
    const forbidden = await mountWorkspace()
    expect(forbidden.get('[data-testid="departments-forbidden"]').text()).toContain('无权限')
    expect(forbidden.find('[data-testid="department-create"]').exists()).toBe(false)
    forbidden.unmount()

    mockWorkspaceGet()
    const readOnly = await mountWorkspace(['org.unit.read'])
    expect(readOnly.find('[data-testid="department-create"]').exists()).toBe(false)
    await selectDepartment(readOnly, 1)
    expect(readOnly.find('[data-testid="department-edit"]').exists()).toBe(false)
    expect(readOnly.find('[data-testid="department-move"]').exists()).toBe(false)
    expect(readOnly.find('[data-testid="member-add"]').exists()).toBe(false)
    expect(readOnly.find('[data-testid="member-remove-301"]').exists()).toBe(false)
  })

  it('covers loading, empty, partial and error states without inventing rows', async () => {
    let resolveDepartments: (value: unknown) => void = () => undefined
    let resolveTree: (value: unknown) => void = () => undefined
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/departments') return new Promise((resolve) => (resolveDepartments = resolve))
      if (path === '/v1/org-units/tree') return new Promise((resolve) => (resolveTree = resolve))
      return Promise.reject(new Error(`Unexpected GET ${path}`))
    })

    const loading = mount(DepartmentWorkspacePage, {
      global: {
        plugins: [activePiniaWith(['org.unit.read', 'org.unit.manage']), ElementPlus],
        stubs: { ElDrawer: OverlayStub, ElDialog: OverlayStub, teleport: true },
      },
    })
    await nextTick()
    expect(loading.text()).toContain('正在加载')
    resolveDepartments(listResponse([]))
    resolveTree(listResponse([]))
    await flushPromises()
    expect(loading.text()).toContain('暂无部门')
    loading.unmount()

    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/departments') return Promise.reject(new Error('department projection unavailable'))
      if (path === '/v1/org-units/tree') return Promise.resolve(listResponse(TREE_ROWS))
      return Promise.reject(new Error(`Unexpected GET ${path}`))
    })
    const partial = await mountWorkspace()
    expect(partial.get('[data-testid="departments-partial"]').text()).toContain('部分数据')
    expect(partial.get('[data-testid="dept-node-1"]').text()).toContain('在编 —')
    partial.unmount()

    resource.get.mockRejectedValue(new ApiError('INTERNAL', '系统暂时不可用', { status: 500 }))
    const failed = await mountWorkspace()
    expect(failed.get('[data-testid="departments-error"]').text()).toContain('暂时无法加载')
    expect(failed.text()).toContain('重试')
  })
})
