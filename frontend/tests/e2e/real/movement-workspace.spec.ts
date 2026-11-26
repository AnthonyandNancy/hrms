import { expect, test, type Page, type Response } from '@playwright/test'

/**
 * Real-backend employee movement workspace journeys.
 *
 * These run against the seeded synthetic demo tenant and the actual Laravel
 * capability engine. The purpose is to prove that the workspace is wired to
 * real Case / Lifecycle endpoints: a draft transfer created in the drawer must
 * come back from GET /v1/cases with DRAFT status, and the detail view must show
 * the effective date and the workflow timeline.
 *
 * Requires HRMS_DEMO_PASSWORD (same value as DEMO_SEED_PASSWORD) plus the
 * documented demo seed and lifecycle template seed.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? ''
const MISSING_PASSWORD = PASSWORD === ''

test.skip(MISSING_PASSWORD, 'HRMS_DEMO_PASSWORD is required for real movement workspace E2E.')

async function login(page: Page): Promise<Response> {
  await page.goto('/login')
  await page.getByLabel('账号').fill('demo.hr')
  await page.getByLabel('密码').fill(PASSWORD)
  const responsePromise = page.waitForResponse(
    (response) => response.url().endsWith('/v1/auth/login') && response.request().method() === 'POST',
  )
  await page.getByRole('button', { name: '登录', exact: true }).click()
  const response = await responsePromise
  expect(response.status(), 'demo.hr login must succeed against the real backend').toBe(200)
  await expect(page).toHaveURL(/\/workspace/)
  return response
}

function futureDate(daysFromNow: number): string {
  const date = new Date()
  date.setUTCDate(date.getUTCDate() + daysFromNow)
  return date.toISOString().slice(0, 10)
}

async function firstRealOption(page: Page, testId: string): Promise<string> {
  const select = page.locator(`[data-testid="${testId}"]`)
  await expect(select).toBeVisible()
  const options = select.locator('option')
  const count = await options.count()

  for (let index = 1; index < count; index += 1) {
    const value = await options.nth(index).getAttribute('value')
    if (value !== null && value !== '') return value
  }

  throw new Error(`${testId} has no real seeded option`)
}

test.describe('@real employee movement workspace', () => {
  test('HR creates a transfer draft and sees it in the real movement list', async ({ page }) => {
    await login(page)
    await page.goto('/hr/movements')

    await expect(page.locator('h1').first()).toContainText('员工异动工作台')
    await expect(page.getByText('页面暂时无法渲染')).toHaveCount(0)
    await expect(page.getByTestId('open-movement-drawer')).toBeVisible()

    await page.getByTestId('open-movement-drawer').click()
    await expect(page.getByTestId('movement-form')).toBeVisible()

    await page.getByTestId('movement-kind').selectOption('transfer')
    const employeeId = await firstRealOption(page, 'movement-employment')
    const targetOrgId = await firstRealOption(page, 'movement-target-org')
    const effectiveDate = futureDate(60)
    const reason = `Synthetic workspace transfer ${Date.now()}`

    await page.getByTestId('movement-employment').selectOption(employeeId)
    await page.getByTestId('movement-target-org').selectOption(targetOrgId)
    await page.getByTestId('movement-effective-date').fill(effectiveDate)
    await page.getByTestId('movement-reason').fill(reason)

    const createResponsePromise = page.waitForResponse(
      (response) =>
        response.url().endsWith('/v1/lifecycle/transfers') &&
        response.request().method() === 'POST',
    )
    await page.getByTestId('movement-save-draft').click()
    const createResponse = await createResponsePromise
    expect(createResponse.status(), 'draft transfer creation must be accepted').toBe(201)

    const body = (await createResponse.json()) as { data?: { case_no?: string; case_id?: number } }
    const caseNo = body.data?.case_no ?? ''
    expect(caseNo).not.toBe('')

    await expect(page.getByTestId('movement-form')).toHaveCount(0)
    await expect(page.getByTestId('movement-table')).toBeVisible()
    await expect(page.getByText(caseNo)).toBeVisible()
    await expect(page.getByText(reason)).toHaveCount(0)
    await expect(page.getByText('草稿').first()).toBeVisible()
  })

  test('HR opens a real movement case and sees effective date plus timeline', async ({ page }) => {
    const loginResponse = await login(page)
    const loginBody = (await loginResponse.json()) as { data?: { csrf_token?: string } }
    const csrf = loginBody.data?.csrf_token ?? ''
    expect(csrf).not.toBe('')

    const employees = await page.request.get('/v1/employees?as_of=' + futureDate(0) + '&page=1&page_size=5', {
      headers: { Accept: 'application/json' },
    })
    expect(employees.status()).toBe(200)
    const employeeBody = (await employees.json()) as {
      data?: { items?: Array<{ employment_id?: number }> }
    }
    const employmentId = employeeBody.data?.items?.[0]?.employment_id ?? 0
    expect(employmentId).toBeGreaterThan(0)

    const orgs = await page.request.get('/v1/departments?as_of=' + futureDate(0), {
      headers: { Accept: 'application/json' },
    })
    expect(orgs.status()).toBe(200)
    const orgBody = (await orgs.json()) as { data?: { items?: Array<{ id?: number }> } }
    const orgUnitId = orgBody.data?.items?.[0]?.id ?? 0
    expect(orgUnitId).toBeGreaterThan(0)

    const effectiveDate = futureDate(30)
    const createResponse = await page.request.post('/v1/lifecycle/transfers', {
      data: {
        employment_id: employmentId,
        org_unit_id: orgUnitId,
        effective_date: effectiveDate,
        reason: `Synthetic detail transfer ${Date.now()}`,
        submit: false,
      },
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf,
        'Idempotency-Key': `movement-e2e-${Date.now()}`,
      },
    })
    expect(createResponse.status(), 'real lifecycle draft creation must succeed').toBe(201)
    const created = (await createResponse.json()) as { data?: { case_id?: number } }
    const caseId = created.data?.case_id ?? 0
    expect(caseId).toBeGreaterThan(0)

    await page.goto(`/hr/movements/${caseId}`)
    await expect(page.locator('h1').first()).toContainText('调岗/调部门详情')
    await expect(page.getByTestId('movement-effective-banner')).toContainText('生效日期')
    await expect(page.getByTestId('movement-effective-banner')).toContainText(
      `${effectiveDate.slice(0, 4)}年${Number(effectiveDate.slice(5, 7))}月${Number(effectiveDate.slice(8, 10))}日`,
    )
    await expect(page.getByTestId('movement-timeline')).toBeVisible()
    await expect(page.getByText('不会立即变更')).toBeVisible()
    await expect(page.getByText('页面暂时无法渲染')).toHaveCount(0)
  })
})
