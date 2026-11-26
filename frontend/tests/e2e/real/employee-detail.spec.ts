import { expect, test, type Page } from '@playwright/test'

/**
 * Real-stack employee detail workspace.
 *
 * This spec only runs when the operator exported the demo password used by the
 * seeded tenant. It deliberately asserts the shell + same-object tab switch
 * rather than seeded row text: the server owns data scoping, and an empty tab
 * must stay an honest empty state.
 *
 * Both environment names are accepted because the local demo runner and the
 * verify_real_stack script historically used different variables.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? process.env.DEMO_SEED_PASSWORD ?? ''
const USERNAME = process.env.HRMS_DEMO_HR ?? 'demo.hr'
const EMPLOYMENT_ID = process.env.HRMS_DEMO_EMPLOYMENT_ID ?? '1'

test.skip(PASSWORD === '', 'HRMS_DEMO_PASSWORD or DEMO_SEED_PASSWORD is required for the real employee-detail test.')

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

test('@real employee detail deep link shows the summary and switches same-object tabs', async ({ page }) => {
  await login(page)

  await page.goto(`/hr/employees/${EMPLOYMENT_ID}?tab=employment`, { waitUntil: 'load' })

  await expect(page).toHaveURL(new RegExp(`/hr/employees/${EMPLOYMENT_ID}\\?tab=employment`))
  await expect(page.getByTestId('employee-summary')).toBeVisible({ timeout: 20_000 })
  await expect(page.locator('.page-shell__header h1')).toBeVisible()
  await expect(page.getByTestId('employee-tabs')).toBeVisible()
  await expect(page.getByTestId('employee-not-found')).toHaveCount(0)
  await expect(page.getByTestId('employee-forbidden')).toHaveCount(0)

  await page.getByTestId('tab-contracts').click()
  await expect(page).toHaveURL(new RegExp(`/hr/employees/${EMPLOYMENT_ID}\\?tab=contracts`))
  await expect(page.getByTestId('tab-contracts-panel')).toBeVisible()

  await page.getByTestId('tab-education').click()
  await expect(page.getByTestId('tab-education-panel')).toBeVisible()

  // The same-object workspace never exposes cross-domain tabs.
  await expect(page.getByRole('tab', { name: /薪资|考勤|绩效/ })).toHaveCount(0)
})
