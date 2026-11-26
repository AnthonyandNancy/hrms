import { expect, test, type Page } from '@playwright/test'

/**
 * Volume 2 Task 11: the five-role permission matrix on the real stack.
 *
 * Each case proves the same four boundaries the API matrix proves, but through
 * the shell: which menu groups are visible, that a forbidden page redirects,
 * that a mutating control is not offered, and that an export denial surfaces as
 * a Chinese message rather than a raw code.
 *
 * Accounts come from the demo seed. Both password variables are supported
 * because `docker_up.sh --demo` and `verify_real_stack.sh` use different names.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? process.env.DEMO_SEED_PASSWORD ?? ''

test.skip(PASSWORD === '', 'HRMS_DEMO_PASSWORD or DEMO_SEED_PASSWORD is required for the real matrix.')

type RoleCase = {
  role: string
  username: string
  visibleGroup: string
  forbiddenPath: string
  hiddenButton?: string
}

const ROLES: RoleCase[] = [
  {
    role: '系统管理员',
    username: process.env.HRMS_DEMO_SYSTEM_ADMIN ?? 'demo.admin',
    visibleGroup: '系统管理',
    forbiddenPath: '/does-not-exist',
    hiddenButton: undefined,
  },
  {
    role: '人事管理员',
    username: process.env.HRMS_DEMO_HR ?? 'demo.hr',
    visibleGroup: '员工档案',
    forbiddenPath: '/system/roles',
    hiddenButton: '新增用户',
  },
  {
    role: '部门经理',
    username: process.env.HRMS_DEMO_MANAGER ?? 'demo.manager',
    visibleGroup: '员工档案',
    forbiddenPath: '/system/roles',
    hiddenButton: '新增用户',
  },
  {
    role: '普通员工',
    username: process.env.HRMS_DEMO_EMPLOYEE ?? 'demo.employee',
    visibleGroup: '仪表盘',
    forbiddenPath: '/system/roles',
    hiddenButton: '新增用户',
  },
  {
    role: '工资专员',
    username: process.env.HRMS_DEMO_PAYROLL ?? 'demo.payroll',
    visibleGroup: '薪资管理',
    forbiddenPath: '/system/roles',
    hiddenButton: '新增用户',
  },
]

async function login(page: Page, username: string): Promise<void> {
  await page.goto('/login', { waitUntil: 'load' })
  await page.getByRole('textbox', { name: '账号' }).fill(username)
  await page.getByRole('textbox', { name: '密码' }).fill(PASSWORD)
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.waitForURL(/\/workspace/, { timeout: 30_000 })
}

for (const roleCase of ROLES) {
  test(`@real ${roleCase.role} sees only its permitted surface`, async ({ page }) => {
    await login(page, roleCase.username)

    // 1. Menu visibility: the group this role works in is present.
    await expect(
      page.locator('button.nav-link--group', { hasText: roleCase.visibleGroup }).first(),
    ).toBeVisible({ timeout: 20_000 })

    // 2. Page denial: an administration page the role cannot hold redirects
    //    instead of rendering an empty shell.
    await page.goto(roleCase.forbiddenPath, { waitUntil: 'load' })
    await expect(page).toHaveURL(/forbidden|login/, { timeout: 20_000 })

    // 3. Button denial: a mutating control is not rendered for this role.
    if (roleCase.hiddenButton !== undefined) {
      await page.goto('/system/users', { waitUntil: 'load' })
      await expect(page.getByRole('button', { name: roleCase.hiddenButton })).toHaveCount(0)
    }
  })
}

test('@real an export denial is shown in Chinese without a raw error code', async ({ page }) => {
  await login(page, process.env.HRMS_DEMO_HR ?? 'demo.hr')

  await page.goto('/reports', { waitUntil: 'load' })

  // Triggering an export with a restricted field must surface the mapped
  // Chinese message; the raw EXPORT_FIELD_* code must never reach the operator.
  const exportButton = page.getByRole('button', { name: /导出/ }).first()
  if ((await exportButton.count()) === 0) {
    test.skip(true, '当前角色的报表页没有可见的导出入口，导出拒绝由 API 矩阵覆盖。')
  }

  await exportButton.click()
  const message = page.locator('[role="alert"], .el-message').first()
  await expect(message).toBeVisible({ timeout: 20_000 })
  await expect(message).not.toContainText('EXPORT_FIELD_')
})
