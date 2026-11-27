import { expect, test, type Page } from '@playwright/test'

/**
 * M01 real-stack identity acceptance.
 *
 * Covers, in one authenticated session:
 *  1. the unauthenticated redirect contract;
 *  2. login landing on the workspace, not /forbidden;
 *  3. the workspace as the self-service portal;
 *  4. destructive removal of /self-service, /account/mfa and /account/sessions;
 *  5. the single /account profile + sessions + optional MFA page.
 *
 * The backend throttles login to five attempts per minute. Splitting these
 * checks into separately-logged-in tests would exercise that throttle rather
 * than the identity contract, so the file performs exactly one real login.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? process.env.DEMO_SEED_PASSWORD ?? ''
const USERNAME = process.env.HRMS_DEMO_USERNAME ?? 'demo.employee'

test.skip(PASSWORD === '', 'HRMS_DEMO_PASSWORD is required for the real M01 test.')

async function login(page: Page): Promise<void> {
  await page.getByLabel('账号').fill(USERNAME)
  await page.getByLabel('密码').fill(PASSWORD)
  await page.getByRole('button', { name: '登录', exact: true }).click()
}

test('@real M01 login, workspace self-service and account security', async ({ page }) => {
  const pageErrors: string[] = []
  page.on('pageerror', (error) => pageErrors.push(error.message))

  // 1. Unauthenticated access redirects to the login page.
  await page.goto('/workspace', { waitUntil: 'load' })
  await expect(page).toHaveURL(/\/login/, { timeout: 20_000 })
  await expect(page.getByRole('textbox', { name: '账号' })).toBeVisible()

  // 2. One real login lands on the workspace shell.
  await login(page)
  await page.waitForURL(/\/workspace/, { timeout: 30_000 })
  await expect(page.locator('h1').first()).toBeVisible({ timeout: 20_000 })
  await expect(page).not.toHaveURL(/forbidden/)
  await expect(page.locator('button.nav-link--group').first()).toBeVisible()

  // 3. The projection is the caller's own employment, never a selected id.
  const meResponse = await page.request.get('/v1/me', { headers: { Accept: 'application/json' } })
  expect(meResponse.status()).toBe(200)
  const me = (await meResponse.json()) as { data?: { employment_id?: number | null } }

  const workspaceResponse = await page.request.get('/v1/me/workspace', {
    headers: { Accept: 'application/json' },
  })
  expect(workspaceResponse.status()).toBe(200)
  const workspace = (await workspaceResponse.json()) as {
    data?: {
      employment_linked?: boolean
      profile?: { employmentId?: number | null; employeeNo?: string }
    }
  }
  expect(workspace.data?.employment_linked).toBe(true)
  expect(workspace.data?.profile?.employmentId ?? null).toBe(me.data?.employment_id ?? null)
  expect(workspace.data?.profile?.employeeNo ?? '').not.toBe('')

  await expect(page.getByTestId('workspace-quick-actions')).toBeVisible()
  await expect(page.getByTestId('quick-account')).toBeVisible()
  await expect(page.getByTestId('quick-overtime')).toBeVisible()
  // No workflow.case.read => no task panel and no task request.
  await expect(page.getByTestId('my-tasks')).toHaveCount(0)

  const main = page.locator('#main-content')
  await expect(main.getByLabel(/选择员工|员工选择器/)).toHaveCount(0)
  await expect(main.getByRole('link', { name: '用户管理' })).toHaveCount(0)
  await expect(main.getByRole('link', { name: '角色管理' })).toHaveCount(0)

  // 4. /account is one page with three sections.
  await page.getByTestId('quick-account').click()
  await expect(page).toHaveURL(/\/account$/)
  await expect(page.getByTestId('account-profile')).toBeVisible()
  await expect(page.getByTestId('account-sessions')).toBeVisible()
  await expect(page.getByTestId('account-mfa')).toBeVisible()
  await expect(page.getByTestId('account-mfa')).toContainText(/多因素认证|双因素认证|已启用|未启用/)

  // 5. Retired routes are destroyed, not redirected to a compatibility shell.
  for (const retired of ['/self-service', '/account/mfa', '/account/sessions']) {
    await page.goto(retired, { waitUntil: 'load' })
    await expect(page).toHaveURL(/\/not-found$/)
    await expect(page.getByText('页面不存在')).toBeVisible()
  }

  expect(pageErrors, pageErrors.join(' | ')).toEqual([])
})
