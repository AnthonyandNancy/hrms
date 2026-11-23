import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'

async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto('/login')
  await page.getByLabel('账号').fill('admin@demo.example')
  await page.getByLabel('密码').fill('Demo!2026')
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await expect(page).toHaveURL(/\/workspace/)
}

async function expectNoSeriousViolations(page: Page, name: string): Promise<void> {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa'])
    .disableRules(['color-contrast'])
    .analyze()
  const serious = results.violations.filter(
    (violation) => violation.impact === 'critical' || violation.impact === 'serious',
  )
  expect(serious, `${name}: ${serious.map((item) => `${item.id} (${item.nodes.length})`).join(', ')}`).toEqual([])
}

test.describe('@a11y mock-mode accessibility smoke', () => {
  test('login page has no critical or serious axe violations', async ({ page }) => {
    await page.goto('/login')
    await expect(page.getByRole('heading', { name: '欢迎回来' })).toBeVisible()
    await expectNoSeriousViolations(page, 'login')
  })

  test('dashboard has no critical or serious axe violations', async ({ page }) => {
    await loginAsAdmin(page)
    await page.getByRole('link', { name: '人力仪表盘' }).click()
    await expect(page.getByRole('heading', { name: /林若桐，下午好/ })).toBeVisible()
    await expectNoSeriousViolations(page, 'dashboard')
  })

  test('case detail has no critical or serious axe violations', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/cases/8204')
    await expect(page.locator('#main-content').getByRole('heading', { name: /事假申请/ })).toBeVisible()
    await expectNoSeriousViolations(page, 'case-detail')
  })

  test('payroll run detail has no critical or serious axe violations', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/payroll/runs/7001')
    await expect(page.locator('#main-content').getByRole('heading', { name: 'PR-2026-11' })).toBeVisible()
    await expectNoSeriousViolations(page, 'payroll-run')
  })
})
