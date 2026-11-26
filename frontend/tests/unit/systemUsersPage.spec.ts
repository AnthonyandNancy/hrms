import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import { defineComponent } from 'vue'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import SystemUsersPage from '@/modules/platform/pages/SystemUsersPage.vue'

const resourceGet = vi.fn()
const resourcePost = vi.fn()
const resourcePatch = vi.fn()
const resourceDelete = vi.fn()

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resourceGet(...args),
  resourcePost: (...args: unknown[]) => resourcePost(...args),
  resourcePatch: (...args: unknown[]) => resourcePatch(...args),
  resourceDelete: (...args: unknown[]) => resourceDelete(...args),
}))

const users = [
  {
    id: 12,
    username: 'alice.synthetic',
    display_name: '爱丽丝（合成）',
    email: 'alice.synthetic@example.test',
    status: 'ACTIVE',
    is_privileged: false,
    mfa_enrolled: true,
    auth_version: 3,
    last_login_at: '2026-11-25T02:10:00Z',
    roles: [{ role_code: 'HR_SPECIALIST', valid_from: '2026-01-01T00:00:00Z', valid_to: '2027-01-01T00:00:00Z' }],
    employment_binding: {
      employment_id: 88,
      employee_no: 'E1001',
      person_name: '爱丽丝（合成）',
    },
  },
]

const roles = [
  { code: 'HR_SPECIALIST', name: '人事专员' },
  { code: 'HR_ADMIN', name: '人事管理员' },
]

const preview = {
  user: { id: 12, username: 'alice.synthetic' },
  roles: ['HR_SPECIALIST'],
  pages: [
    {
      page_code: 'hr-employees',
      path: '/hr/employees',
      menu_label: '员工列表',
      required_capability: 'people.employee.read',
      field_profile_max: 'P2_SENSITIVE',
    },
  ],
  actions: [
    { capability: 'people.employee.read', anchor_type: 'COMPANY', field_profile: 'P1_INTERNAL' },
    { capability: 'people.employee.manage', anchor_type: 'COMPANY', field_profile: 'P2_SENSITIVE' },
    { capability: 'time.leave.read', anchor_type: 'SELF', field_profile: 'P1_INTERNAL' },
  ],
  action_codes: ['people.employee.read', 'people.employee.manage', 'time.leave.read'],
  field_capabilities: ['P1_INTERNAL', 'P2_SENSITIVE'],
  scope_labels: [
    { anchor_type: 'COMPANY', label: '本公司' },
    { anchor_type: 'SELF', label: '仅本人' },
  ],
  denied: [{ page_code: 'payroll', path: '/payroll', reason: 'PAGE_NOT_GRANTED' }],
  wildcard: false,
}

const ALL_CAPABILITIES = ['iam.user.read', 'iam.user.manage', 'iam.role.manage', 'people.employee.read']

// The two Element Plus overlays need a teleport-free DOM in jsdom. A single
// stub component handles both because their slot contract is identical.
const OverlayStub = defineComponent({
  props: { modelValue: Boolean, title: String },
  emits: ['update:modelValue', 'close'],
  template: `<div v-if="modelValue" class="el-overlay-stub" role="dialog">
    <h2>{{ title }}</h2>
    <slot />
    <slot name="footer" />
  </div>`,
})

function sessionWith(capabilities: string[]): void {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })
}

beforeEach(() => {
  resourceGet.mockReset()
  resourcePost.mockReset()
  resourcePatch.mockReset()
  resourceDelete.mockReset()
  resourceGet.mockImplementation((path: string) => {
    if (path === '/v1/users') return Promise.resolve({ items: users, page: 1, page_size: 20, total: 1 })
    if (path === '/v1/roles') return Promise.resolve({ roles })
    if (path === '/v1/users/12/permissions-preview') return Promise.resolve(preview)
    if (path === '/v1/employees') {
      return Promise.resolve({ items: [{ employment_id: 88, employee_no: 'E1001', person_name: '爱丽丝（合成）' }] })
    }
    return Promise.resolve({})
  })
  resourcePost.mockResolvedValue({})
  resourcePatch.mockResolvedValue({})
  resourceDelete.mockResolvedValue({})
})

async function mountPage(capabilities = ALL_CAPABILITIES) {
  sessionWith(capabilities)
  const wrapper = mount(SystemUsersPage, {
    global: {
      plugins: [ElementPlus],
      stubs: {
        ElDialog: OverlayStub,
        ElDrawer: OverlayStub,
        ElPagination: true,
        teleport: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

function buttonByText(wrapper: Awaited<ReturnType<typeof mountPage>>, text: string) {
  const button = wrapper.findAll('button').find((candidate) => candidate.text().trim() === text)
  if (button === undefined) throw new Error(`Button not found: ${text}`)
  return button
}

describe('system users permissions management', () => {
  it('shows real roles from the user list instead of the privileged flag', async () => {
    const wrapper = await mountPage()
    expect(wrapper.find('[data-testid="user-roles"]').text()).toBe('HR_SPECIALIST')
    expect(wrapper.text()).not.toContain('特权：是')
    expect(wrapper.find('[data-testid="user-roles"]').text()).not.toBe('未分配')
  })

  it('assigns a selected role with a reason and idempotency key', async () => {
    const wrapper = await mountPage()
    await wrapper.find('[data-testid="assign-roles"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-testid="role-add-HR_ADMIN"]').setValue(true)
    await wrapper.find('[data-testid="role-reason"]').setValue('Synthetic acceptance assignment')
    await buttonByText(wrapper, '保存').trigger('click')
    await flushPromises()

    expect(resourcePost).toHaveBeenCalledWith(
      '/v1/users/12/roles',
      expect.objectContaining({ role_code: 'HR_ADMIN', reason: 'Synthetic acceptance assignment' }),
      expect.objectContaining({ idempotencyKey: expect.stringContaining('role-assign-12-HR_ADMIN') }),
    )
  })

  it('revokes a role and shows immediate denial reasons in the preview', async () => {
    const wrapper = await mountPage()
    await wrapper.find('[data-testid="permission-preview"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="permission-preview-panel"]').text()).toContain('员工列表')
    expect(wrapper.find('[data-testid="denied-list"]').text()).toContain('角色未授予该页面')

    await wrapper.find('[data-testid="permission-preview"]').trigger('click')
    await wrapper.find('[data-testid="assign-roles"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-testid="role-reason"]').setValue('Synthetic revocation')
    await wrapper.find('[data-testid="role-remove-HR_SPECIALIST"]').trigger('click')
    await buttonByText(wrapper, '保存').trigger('click')
    await flushPromises()

    expect(resourceDelete).toHaveBeenCalledWith(
      expect.stringContaining('/v1/users/12/roles/HR_SPECIALIST'),
      expect.objectContaining({ idempotencyKey: expect.stringContaining('role-revoke-12-HR_SPECIALIST') }),
    )
  })

  it('shows Chinese status, display name, employee binding and last login without tenant or legal-entity columns', async () => {
    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('在职')
    expect(wrapper.text()).not.toContain('ACTIVE')
    expect(wrapper.find('[data-testid="user-display-name"]').text()).toBe('爱丽丝（合成）')
    expect(wrapper.find('[data-testid="user-employment"]').text()).toContain('E1001')
    expect(wrapper.find('[data-testid="user-last-login"]').text()).toContain('2026-11-25')
    expect(wrapper.text()).not.toContain('租户')
    expect(wrapper.text()).not.toContain('法人')
  })

  it('opens the create dialog and refetches the list after a successful create', async () => {
    const wrapper = await mountPage()
    await wrapper.find('[data-testid="user-create"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-testid="user-username"]').setValue('demo.hr')
    await wrapper.find('[data-testid="user-email"]').setValue('demo.hr@example.test')
    await wrapper.find('[data-testid="user-display-name-input"]').setValue('演示人事')
    await wrapper.find('[data-testid="user-password"]').setValue('Synthetic-Password-9!')
    await wrapper.find('[data-testid="user-employment-input"]').setValue('88')
    await buttonByText(wrapper, '保存').trigger('click')
    await flushPromises()

    expect(resourcePost).toHaveBeenCalledWith(
      '/v1/users',
      expect.objectContaining({
        username: 'demo.hr',
        email: 'demo.hr@example.test',
        display_name: '演示人事',
        password: 'Synthetic-Password-9!',
        employment_id: 88,
      }),
      expect.objectContaining({ idempotencyKey: expect.stringContaining('user-create-demo.hr') }),
    )
    expect(resourceGet.mock.calls.filter(([path]) => path === '/v1/users')).toHaveLength(2)
    expect(wrapper.find('[data-testid="user-username"]').exists()).toBe(false)
  })

  it('maps server-side validation errors to Chinese field messages', async () => {
    resourcePost.mockRejectedValueOnce(
      new ApiError('VALIDATION_FAILED', 'The given data was invalid.', {
        status: 422,
        fieldErrors: {
          username: ['The username has already been taken.'],
          password: ['The password field must be at least 12 characters.'],
        },
      }),
    )
    const wrapper = await mountPage()
    await wrapper.find('[data-testid="user-create"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-testid="user-username"]').setValue('demo.hr')
    await wrapper.find('[data-testid="user-display-name-input"]').setValue('演示人事')
    await wrapper.find('[data-testid="user-password"]').setValue('Synthetic-Password-9!')
    await buttonByText(wrapper, '保存').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="field-error-username"]').text()).toContain('用户名')
    expect(wrapper.find('[data-testid="field-error-password"]').text()).toContain('密码')
    expect(wrapper.text()).not.toContain('The username has already been taken')
    expect(wrapper.find('[data-testid="user-username"]').exists()).toBe(true)
  })

  it('shows a clear role diff before saving additions and removals', async () => {
    const wrapper = await mountPage()
    await wrapper.find('[data-testid="assign-roles"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-testid="role-add-HR_ADMIN"]').setValue(true)
    await wrapper.find('[data-testid="role-remove-HR_SPECIALIST"]').trigger('click')
    await flushPromises()

    const diff = wrapper.find('[data-testid="role-diff"]').text()
    expect(diff).toContain('新增')
    expect(diff).toContain('HR_ADMIN')
    expect(diff).toContain('移除')
    expect(diff).toContain('HR_SPECIALIST')

    await wrapper.find('[data-testid="role-reason"]').setValue('Synthetic role diff')
    await buttonByText(wrapper, '保存').trigger('click')
    await flushPromises()

    expect(resourcePost).toHaveBeenCalledWith(
      '/v1/users/12/roles',
      expect.objectContaining({ role_code: 'HR_ADMIN', reason: 'Synthetic role diff' }),
      expect.objectContaining({ idempotencyKey: expect.stringContaining('role-assign-12-HR_ADMIN') }),
    )
    expect(resourceDelete).toHaveBeenCalledWith(
      expect.stringContaining('/v1/users/12/roles/HR_SPECIALIST'),
      expect.objectContaining({ idempotencyKey: expect.stringContaining('role-revoke-12-HR_SPECIALIST') }),
    )
    expect(resourceGet.mock.calls.filter(([path]) => path === '/v1/users')).toHaveLength(2)
  })

  it('groups effective capabilities by module and summarises anchors and field profiles', async () => {
    const wrapper = await mountPage()
    await wrapper.find('[data-testid="permission-preview"]').trigger('click')
    await flushPromises()

    const panel = wrapper.find('[data-testid="permission-preview-panel"]')
    expect(panel.text()).toContain('人事')
    expect(panel.text()).toContain('考勤与假勤')
    expect(panel.text()).toContain('本公司')
    expect(panel.text()).toContain('仅本人')
    expect(panel.text()).toContain('内部')
    expect(panel.text()).toContain('敏感')
    expect(panel.text()).not.toContain('people.employee.read')
    expect(panel.text()).not.toContain('time.leave.read')
  })

  it('confirms disabling a user by display name and surfaces last-admin protection', async () => {
    resourcePatch.mockRejectedValueOnce(
      new ApiError('VALIDATION_FAILED', 'The given data was invalid.', {
        status: 422,
        details: { reason: 'LAST_ENABLED_ADMIN_PROTECTED' },
      }),
    )
    const wrapper = await mountPage()
    await wrapper.find('[data-testid="user-disable"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="confirm-message"]').text()).toContain('爱丽丝（合成）')

    await wrapper.find('[data-testid="confirm-reason"]').setValue('Synthetic disable')
    await wrapper.find('[data-testid="confirm-ok"]').trigger('click')
    await flushPromises()

    expect(resourcePatch).toHaveBeenCalledWith(
      '/v1/users/12',
      expect.objectContaining({ status: 'DISABLED' }),
      expect.objectContaining({ idempotencyKey: expect.stringContaining('user-status-12') }),
    )
    expect(wrapper.text()).toContain('最后一名可用系统管理员')
    expect(resourceGet.mock.calls.filter(([path]) => path === '/v1/users')).toHaveLength(2)
  })

  it('shows a Chinese role conflict and refreshes the list after a conflict', async () => {
    resourcePost.mockRejectedValueOnce(
      new ApiError('VALIDATION_FAILED', 'The given data was invalid.', {
        status: 422,
        details: { reason: 'LAST_ENABLED_ADMIN_PROTECTED' },
      }),
    )
    const wrapper = await mountPage()
    await wrapper.find('[data-testid="assign-roles"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-testid="role-add-HR_ADMIN"]').setValue(true)
    await wrapper.find('[data-testid="role-reason"]').setValue('Synthetic conflict')
    await buttonByText(wrapper, '保存').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="role-error"]').text()).toContain('最后一名可用系统管理员')
    expect(wrapper.text()).not.toContain('Conflict.')
    expect(resourceGet.mock.calls.filter(([path]) => path === '/v1/users')).toHaveLength(2)
  })

  it('confirms revoking every session by display name and uses the audited status command', async () => {
    const wrapper = await mountPage()
    await wrapper.find('[data-testid="user-revoke-sessions"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="confirm-message"]').text()).toContain('爱丽丝（合成）')

    await wrapper.find('[data-testid="confirm-reason"]').setValue('Synthetic session reset')
    await wrapper.find('[data-testid="confirm-ok"]').trigger('click')
    await flushPromises()

    expect(resourcePatch).toHaveBeenCalledWith(
      '/v1/users/12',
      { status: 'ACTIVE', reason: 'Synthetic session reset' },
      expect.objectContaining({ idempotencyKey: expect.stringContaining('user-status-12') }),
    )
    expect(wrapper.find('[data-testid="users-action-message"]').text()).toContain('全部登录会话')
  })

  it('sends active filters and renders a dedicated empty state', async () => {
    resourceGet.mockImplementation((path: string, params?: Record<string, unknown>) => {
      if (path === '/v1/users') {
        if (params?.status === 'DISABLED') {
          return Promise.resolve({ items: [], page: 1, page_size: 20, total: 0 })
        }
        return Promise.resolve({ items: users, page: 1, page_size: 20, total: 1 })
      }
      if (path === '/v1/roles') return Promise.resolve({ roles })
      return Promise.resolve({})
    })
    const wrapper = await mountPage()

    await wrapper.find('[data-testid="filter-status"]').setValue('DISABLED')
    await flushPromises()

    expect(resourceGet).toHaveBeenLastCalledWith(
      '/v1/users',
      expect.objectContaining({ page: 1, page_size: 20, status: 'DISABLED' }),
    )
    expect(wrapper.text()).toContain('当前筛选下没有用户')
  })

  it('explains that the role catalog is unavailable instead of offering empty choices', async () => {
    sessionWith(ALL_CAPABILITIES)
    resourceGet.mockImplementation((path: string) => {
      if (path === '/v1/users') return Promise.resolve({ items: users, page: 1, page_size: 20, total: 1 })
      if (path === '/v1/roles') {
        return Promise.reject(new ApiError('FORBIDDEN', 'Forbidden.', { status: 403 }))
      }
      return Promise.resolve({})
    })
    const wrapper = await mountPage()
    await wrapper.find('[data-testid="assign-roles"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="user-role-drawer"]').text()).toContain('没有读取角色目录的权限')
  })

  it('hides every state-changing action when the caller only has read capability', async () => {
    const wrapper = await mountPage(['iam.user.read'])

    expect(wrapper.find('[data-testid="user-create"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="assign-roles"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="user-disable"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="user-revoke-sessions"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="permission-preview"]').exists()).toBe(true)
  })

  it('keeps tenant and legal-entity vocabulary out of the user workbench sources', () => {
    const root = resolve(__dirname, '../..')
    const files = [
      'src/modules/platform/pages/SystemUsersPage.vue',
      'src/modules/platform/components/UserCreateDialog.vue',
      'src/modules/platform/components/UserRoleDrawer.vue',
      'src/modules/platform/components/UserPermissionPreview.vue',
    ]
    for (const file of files) {
      const source = readFileSync(resolve(root, file), 'utf8')
      expect(source, file).not.toMatch(/tenant|legal[_ -]?entit|租户|法人/i)
    }
  })

  it('renders a dedicated Chinese forbidden state when the list is denied', async () => {
    resourceGet.mockRejectedValueOnce(new ApiError('FORBIDDEN', 'Forbidden.', { status: 403, requestId: 'req-1' }))
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="users-forbidden"]').text()).toContain('无权访问用户管理')
    expect(wrapper.text()).not.toContain('Forbidden')
  })
})
