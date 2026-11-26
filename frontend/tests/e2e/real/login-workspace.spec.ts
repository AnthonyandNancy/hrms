import { expect, test, type Page } from '@playwright/test'

/**
 * Real-stack smoke test for the post-login landing page.
 *
 * It covers the regression where a successful login landed on /forbidden
 * because the page projection had not been loaded for the new session.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? ''

test.skip(PASSWORD === '', 'HRMS_DEMO_PASSWORD is required for the real login test.')

async function login(page: Page): Promise<void> {
  await page.goto('/login', { waitUntil: 'load' })
  await page.getByRole('textbox', { name: '账号' }).fill(process.env.HRMS_DEMO_USERNAME ?? 'demo.hr')
  await page.getByRole('textbox', { name: '密码' }).fill(PASSWORD)
  await page.getByRole('button', { name: '登录', exact: true }).click()
}

test('@real a demo user reaches the workspace shell instead of /forbidden', async ({ page }) => {
  const pageErrors: string[] = []
  page.on('pageerror', (error) => pageErrors.push(error.message))

  await login(page)

  await page.waitForURL(/\/workspace/, { timeout: 30_000 })
  await expect(page.locator('h1').first()).toBeVisible({ timeout: 20_000 })
  await expect(page).not.toHaveURL(/forbidden/)
  await expect(page.locator('button.nav-link--group').first()).toBeVisible()
  expect(pageErrors, pageErrors.join(' | ')).toEqual([])
})

test('@real an unauthenticated visitor still lands on the login page', async ({ page }) => {
  await page.goto('/workspace', { waitUntil: 'load' })

  await expect(page).toHaveURL(/\/login/, { timeout: 20_000 })
  await expect(page.getByRole('textbox', { name: '账号' })).toBeVisible()
})
