import { expect, test, type Page, type Response } from '@playwright/test'

/**
 * Real-backend journeys. These run against the seeded synthetic demo tenant
 * with the actual Laravel authorization engine, session cookie and CSRF
 * middleware. They are the evidence that the UI is wired to the real contract,
 * not only to the mock adapter.
 */
// The seeded demo password is never committed. The operator exports
// HRMS_DEMO_PASSWORD (the same value passed to DEMO_SEED_PASSWORD) before
// running this suite; without it the suite skips with an explicit reason.
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? ''
const MISSING_PASSWORD = PASSWORD === ''

test.beforeEach(() => {
  test.skip(
    MISSING_PASSWORD,
    'HRMS_DEMO_PASSWORD is not set; export the password used to seed the demo tenant (DEMO_SEED_PASSWORD).',
  )
})

async function login(page: Page, username: string): Promise<Response> {
  for (let attempt = 0; attempt < 2; attempt += 1) {
    await page.goto('/login')
    await page.getByLabel('账号').fill(username)
    await page.getByLabel('密码').fill(PASSWORD)

    const responsePromise = page.waitForResponse(
      (response) => response.url().endsWith('/v1/auth/login') && response.request().method() === 'POST',
    )
    await page.getByRole('button', { name: '登录', exact: true }).click()
    const response = await responsePromise

    if (response.status() !== 429) {
      return response
    }

    // The backend enforces five login attempts per username+IP per minute.
    // The real suite has more journeys than that, so pace only the throttled
    // attempt rather than weakening the production rate limit. Extend this
    // test's own budget; the wait is explicit and cannot hide a 429.
    test.setTimeout(150_000)
    await page.waitForTimeout(61_000)
  }

  throw new Error('Login still throttled after one retry window.')
}

test.describe('@real real-backend journeys', () => {
  test('HR logs in, lands on the workspace and sees real data', async ({ page }) => {
    await login(page, 'demo.hr')
    await expect(page).toHaveURL(/\/workspace/)
    await expect(page.locator('.workspace-hero__title')).toContainText('下午好')
    // The workspace projection comes from /v1/me/workspace, so an employee
    // number proves the request was authenticated and scoped.
    await expect(page.getByText(/工号\s*DEMO-0001/)).toBeVisible()
  })

  test('unauthenticated visitor is redirected to login', async ({ page }) => {
    await page.goto('/workspace')
    await expect(page).toHaveURL(/\/login/)
  })

  test('HR can list employees from the real directory', async ({ page }) => {
    await login(page, 'demo.hr')
    await expect(page).toHaveURL(/\/workspace/)
    await page.getByRole('link', { name: /员工目录/ }).click()
    await expect(page).toHaveURL(/\/employees/)
    await expect(page.locator('.employee-table tbody tr, table tbody tr').first()).toBeVisible()
    await expect(page.getByText('DEMO-0002')).toBeVisible()
  })

  test('employee role cannot read the HR directory', async ({ page }) => {
    await login(page, 'demo.employee')
    await expect(page).toHaveURL(/\/workspace/)
    const resp = await page.request.get('/v1/employees?as_of=2026-11-23', {
      headers: { 'X-Legal-Entity-Id': '1' },
    })
    expect(resp.status()).toBe(403)
  })

  test('session cookie is HttpOnly and the CSRF token is not in localStorage', async ({ page, context }) => {
    await login(page, 'demo.hr')
    await expect(page).toHaveURL(/\/workspace/)

    const cookies = await context.cookies()
    const session = cookies.find((c) => c.name === 'hrms_session')
    expect(session, 'session cookie must exist').toBeTruthy()
    expect(session?.httpOnly, 'session cookie must be HttpOnly').toBe(true)

    const storage = await page.evaluate(() => ({
      local: Object.keys(localStorage),
      session: Object.keys(sessionStorage),
    }))
    expect(storage.local.join(',')).not.toMatch(/token|csrf/i)
    expect(storage.session.join(',')).not.toMatch(/token|csrf/i)
  })


  test('HR can open dashboard, org tree, leave, attendance, notices and reports', async ({ page }) => {
    await login(page, 'demo.hr')
    await expect(page).toHaveURL(/\/workspace/)

    for (const [path, heading] of [
      ['/dashboard', /人力仪表盘|Welcome back/],
      ['/organization', /组织架构/],
      ['/leave', /假期与余额/],
      ['/attendance', /考勤日历/],
      ['/notices', /公告与通知/],
      ['/reports', /报表中心/],
    ] as const) {
      await page.goto(path)
      await expect(page.locator('h1').first()).toContainText(heading)
      // The error boundary is never an acceptable page state.
      await expect(page.getByText('页面暂时无法渲染')).toHaveCount(0)
    }

    await page.goto('/dashboard')
    await expect(page.getByRole('button', { name: /Headcount/ }).first()).toBeVisible()
    await page.goto('/organization')
    await expect(page.getByText('Demo Headquarters').first()).toBeVisible()
    await page.goto('/reports')
    await expect(page.getByText('Contract expiry').first()).toBeVisible()
  })

  test('dashboard notice panel is populated from a real published notice', async ({ page }) => {
    // Log in once through the UI and capture the session-bound CSRF token from
    // the successful login response. Reusing this session avoids tripping the
    // login throttle, while the notice published below is real backend data
    // visible to ALL.
    const loginResponse = await login(page, 'demo.hr')
    await expect(page).toHaveURL(/\/workspace/)

    expect(loginResponse.status()).toBe(200)
    const loginBody = await loginResponse.json()
    const csrf = String(loginBody.data.csrf_token)

    const title = `Synthetic dashboard notice ${Date.now()}`
    const publishResponse = await page.request.post('/v1/engagement/notices', {
      data: {
        title,
        body: 'Synthetic notice created by the real-backend dashboard journey.',
        audience: 'ALL',
        audienceLabel: 'All employees',
        requiresAck: false,
      },
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf,
        'X-Legal-Entity-Id': '1',
      },
    })
    expect(publishResponse.status()).toBe(201)

    await page.goto('/dashboard')
    await expect(page.locator('h1').first()).toContainText(/人力仪表盘|Welcome back/)
    await expect(page.locator('.dashboard-notices')).toContainText(title)
    // An empty panel would be honest, but it would not prove the panel is fed
    // from the real notice read seam. The published row above proves it is.
    await expect(page.getByText('当前没有可见的已发布公告。')).toHaveCount(0)
    await expect(page.getByText('页面暂时无法渲染')).toHaveCount(0)
  })

  test('login page is accessible with no critical axe violations', async ({ page }) => {
    await page.goto('/login')
    const { default: AxeBuilder } = await import('@axe-core/playwright')
    const results = await new AxeBuilder({ page }).analyze()
    const critical = results.violations.filter((v) => v.impact === 'critical')
    expect(critical, JSON.stringify(critical.map((v) => v.id))).toHaveLength(0)
  })
})
