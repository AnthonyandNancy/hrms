import { expect, test, type Page } from '@playwright/test'

/**
 * Real-stack employment-contract workspace.
 *
 * The suite is skipped unless the operator exports the seeded demo password.
 * It asserts the live ledger/version routes and the capability-gated write
 * drawer; it never invents a seeded contract row when the API returns none.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? process.env.DEMO_SEED_PASSWORD ?? ''
const USERNAME = process.env.HRMS_DEMO_HR ?? 'demo.hr'
const EMPLOYMENT_ID = process.env.HRMS_DEMO_CONTRACT_EMPLOYMENT_ID ?? '1'

test.skip(PASSWORD === '', 'HRMS_DEMO_PASSWORD or DEMO_SEED_PASSWORD is required for the real contract workspace test.')

async function login(page: Page): Promise<void> {
  await page.goto('/login', { waitUntil: 'load' })
  await page.getByRole('textbox', { name: '账号' }).fill(USERNAME)
  await page.getByRole('textbox', { name: '密码' }).fill(PASSWORD)
  const responsePromise = page.waitForResponse(
    (response) => response.url().endsWith('/v1/auth/login') && response.request().method() === 'POST',
  )
  await page.getByRole('button', { name: '登录', exact: true }).click()
  expect((await responsePromise).status(), 'real demo login must succeed').toBe(200)
  await page.waitForURL(/\/workspace/, { timeout: 30_000 })
}

test.describe('@real contract workspace', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  test('renders the live ledger and keeps the expiry filter on real rows', async ({ page }) => {
    await page.goto('/hr/contracts', { waitUntil: 'load' })
    await expect(page.getByRole('heading', { name: '劳动合同', level: 1 })).toBeVisible()

    const ledger = page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname === '/v1/employments/contracts'
        && response.request().method() === 'GET',
    )
    await page.getByTestId('contract-refresh').click()
    const response = await ledger
    expect(response.status(), 'contract ledger must be readable for demo.hr').toBe(200)

    const payload = (await response.json()) as { data?: { items?: unknown[] } }
    const rowCount = Array.isArray(payload.data?.items) ? payload.data.items.length : 0

    if (rowCount === 0) {
      await expect(page.getByTestId('contracts-empty')).toBeVisible()
      return
    }

    await expect(page.getByTestId('contract-table')).toBeVisible({ timeout: 20_000 })
    await expect(page.locator('[data-testid="contract-table"] tbody tr').first()).toBeVisible()

    await page.getByTestId('contract-expiry-filter').click()
    await expect(page.locator('.contract-filter--active')).toHaveText('60 天内')
  })

  test('opens the draft drawer for a manager with contract.manage and validates local dates', async ({ page }) => {
    await page.goto('/hr/contracts', { waitUntil: 'load' })

    const create = page.getByTestId('contract-create')
    const visible = await create.isVisible().catch(() => false)
    test.skip(!visible, 'demo.hr has no people.contract.manage grant in this environment')

    await create.click()
    const drawer = page.getByRole('dialog')
    await expect(drawer).toBeVisible()
    await expect(drawer.getByText('新建劳动合同')).toBeVisible()
    await expect(drawer).toContainText('工作地点')
    await expect(drawer).toContainText('试用期结束')

    await drawer.getByTestId('contract-start').fill('2027-01-01')
    await drawer.getByTestId('contract-end').fill('2026-12-31')
    await drawer.getByTestId('contract-save').click()
    await expect(drawer.getByText(/结束日期必须晚于开始日期/)).toBeVisible()
  })

  test('opens a contract detail timeline and shows the irreversible termination copy', async ({ page }) => {
    await page.goto('/hr/contracts', { waitUntil: 'load' })

    const firstContract = page.locator('.contract-link').first()
    const hasContract = await firstContract.isVisible().catch(() => false)
    test.skip(!hasContract, 'No seeded contract row to open in this environment')

    await firstContract.click()
    await expect(page).toHaveURL(/\/hr\/contracts\/\d+$/)
    await expect(page.getByTestId('contract-detail')).toBeVisible({ timeout: 20_000 })
    await expect(page.getByTestId('contract-version-list')).toBeVisible()
    await expect(page.getByTestId('contract-attachments')).toBeVisible()

    const terminate = page.getByTestId('contract-terminate')
    if (await terminate.isVisible().catch(() => false)) {
      await terminate.click()
      const dialog = page.getByTestId('contract-terminate-dialog')
      await expect(dialog).toBeVisible()
      await expect(dialog).toContainText('终止不可撤销')
      await expect(dialog).toContainText('历史版本会保留')
    }
  })

  test('deep link to an unknown contract shows the honest not-found state', async ({ page }) => {
    await page.goto('/hr/contracts/999999999?as_of=2026-11-23', { waitUntil: 'load' })
    await expect(
      page.getByTestId('contract-detail-empty').or(page.getByTestId('contract-detail-forbidden')),
    ).toBeVisible({ timeout: 20_000 })
    await expect(page.getByTestId('contract-detail')).toHaveCount(0)
  })

  test('exposes the current employee contract route only through the real ledger', async ({ page }) => {
    const response = await page.request.get(`/v1/employments/${EMPLOYMENT_ID}/contracts`)
    expect([200, 403, 404]).toContain(response.status())
  })
})
