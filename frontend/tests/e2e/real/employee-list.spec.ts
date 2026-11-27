import { expect, test, type Page } from '@playwright/test'

/**
 * Real-stack employee list workspace.
 *
 * The demo password is never committed. Both operator-facing variable names
 * are honoured because `docker_up.sh --demo` and `verify_real_stack.sh` use
 * different names for the same seeded password.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? process.env.DEMO_SEED_PASSWORD ?? ''
const USERNAME = process.env.HRMS_DEMO_HR ?? 'demo.hr'

test.skip(
  PASSWORD === '',
  'HRMS_DEMO_PASSWORD or DEMO_SEED_PASSWORD is required for the real employee list journey.',
)

async function login(page: Page): Promise<void> {
  await page.goto('/login', { waitUntil: 'load' })
  await page.getByLabel('账号').fill(USERNAME)
  await page.getByLabel('密码').fill(PASSWORD)
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.waitForURL(/\/workspace/, { timeout: 30_000 })
}

test.describe('@real employee list workspace', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  test('renders the real employee directory and narrows it with the filter', async ({ page }) => {
    await page.goto('/hr/employees', { waitUntil: 'load' })

    await expect(page.getByRole('heading', { name: '员工列表', level: 1 })).toBeVisible()
    const rows = page.locator('[data-testid^="employee-row-"]')
    await expect(rows.first()).toBeVisible({ timeout: 20_000 })

    // Seed rows are synthetic; at least two are expected on the first page.
    expect(await rows.count()).toBeGreaterThan(1)

    await page.getByLabel('搜索员工').fill('DEMO-0002')
    await expect(rows).toHaveCount(1, { timeout: 15_000 })
    await expect(rows.first()).toContainText('DEMO-0002')
    await expect(page.getByTestId('employee-table')).toBeVisible()

    // The row action and row click both target the detail route.
    await expect(rows.first().getByRole('link', { name: '查看' })).toHaveAttribute(
      'href',
      /\/hr\/employees\/\d+$/,
    )
  })

  test('opens the create drawer for a manager with employee.manage', async ({ page }) => {
    await page.goto('/hr/employees', { waitUntil: 'load' })
    await expect(page.getByRole('heading', { name: '员工列表', level: 1 })).toBeVisible()

    const create = page.getByTestId('employee-create')
    await expect(create).toBeVisible()
    await create.click()

    const drawer = page.getByRole('dialog')
    await expect(drawer).toBeVisible()
    await expect(drawer.getByText('新建员工')).toBeVisible()
    await expect(drawer.getByLabel('员工编号')).toBeVisible()
    await expect(drawer.getByLabel('姓')).toBeVisible()
    await expect(drawer.getByRole('button', { name: '保存' })).toBeEnabled()
  })
})
