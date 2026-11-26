import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SystemUsersPage from '@/modules/platform/pages/SystemUsersPage.vue'

const resourceGet = vi.fn()
const resourcePost = vi.fn()
const resourceDelete = vi.fn()

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resourceGet(...args),
  resourcePost: (...args: unknown[]) => resourcePost(...args),
  resourceDelete: (...args: unknown[]) => resourceDelete(...args),
}))

const users = [
  {
    id: 12,
    username: 'alice.synthetic',
    email: 'alice.synthetic@example.test',
    status: 'ACTIVE',
    is_privileged: false,
    mfa_enrolled: true,
    auth_version: 3,
    roles: [{ role_code: 'HR_SPECIALIST', valid_from: '2026-01-01T00:00:00Z', valid_to: '2027-01-01T00:00:00Z' }],
  },
]

const roles = [{ code: 'HR_SPECIALIST', name: '人事专员' }, { code: 'HR_ADMIN', name: '人事管理员' }]

const preview = {
  user: { id: 12, username: 'alice.synthetic' },
  roles: ['HR_SPECIALIST'],
  pages: [{ page_code: 'hr-employees', path: '/hr/employees', menu_label: '员工列表', required_capability: 'people.employee.read' }],
  actions: [{ capability: 'people.employee.read' }],
  denied: [{ page_code: 'payroll', path: '/payroll', reason: 'PAGE_NOT_GRANTED' }],
}

beforeEach(() => {
  resourceGet.mockReset()
  resourcePost.mockReset()
  resourceDelete.mockReset()
  resourceGet.mockImplementation((path: string) => {
    if (path === '/v1/users') return Promise.resolve({ items: users })
    if (path === '/v1/roles') return Promise.resolve({ roles })
    if (path === '/v1/users/12/permissions-preview') return Promise.resolve(preview)
    return Promise.resolve({})
  })
  resourcePost.mockResolvedValue({})
  resourceDelete.mockResolvedValue({})
})

async function mountPage() {
  const wrapper = mount(SystemUsersPage)
  await flushPromises()
  return wrapper
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
    await wrapper.find('[data-testid="permission-preview"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-testid="assign-role-select"]').setValue('HR_ADMIN')
    await wrapper.find('[data-testid="role-reason"]').setValue('Synthetic acceptance assignment')
    await wrapper.find('[data-testid="role-submit"]').trigger('click')
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

    await wrapper.find('[data-testid="revoke-role"]').trigger('click')
    await flushPromises()

    expect(resourceDelete).toHaveBeenCalledWith(
      '/v1/users/12/roles/HR_SPECIALIST',
      expect.objectContaining({ idempotencyKey: expect.stringContaining('role-revoke-12-HR_SPECIALIST') }),
    )
  })
})
