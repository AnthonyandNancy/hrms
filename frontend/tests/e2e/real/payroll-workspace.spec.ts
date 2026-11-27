import { expect, test, type Page } from '@playwright/test'

/**
 * Real-stack payroll workspace.
 *
 * Runs against the seeded synthetic tenant and the actual payroll lifecycle.
 * The suite never creates a payroll run (the demo scheme ids are environment
 * state) and never fabricates a batch: it asserts the live list/detail reads,
 * the read-only published presentation and the real export endpoint.
 *
 * Requires HRMS_DEMO_PASSWORD (same value as DEMO_SEED_PASSWORD) plus the demo
 * seed and payroll demo data.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? process.env.DEMO_SEED_PASSWORD ?? ''
const PAYROLL_USER = process.env.HRMS_DEMO_PAYROLL ?? 'demo.payroll'

test.skip(PASSWORD === '', 'HRMS_DEMO_PASSWORD is required for real payroll workspace E2E.')

type RunRow = {
  id?: number
  period?: string
  status?: string
  employee_count?: number
}

async function login(page: Page): Promise<void> {
  await page.goto('/login', { waitUntil: 'load' })
  await page.getByRole('textbox', { name: '账号' }).fill(PAYROLL_USER)
  await page.getByRole('textbox', { name: '密码' }).fill(PASSWORD)
  const responsePromise = page.waitForResponse(
    (response) => response.url().endsWith('/v1/auth/login') && response.request().method() === 'POST',
  )
  await page.getByRole('button', { name: '登录', exact: true }).click()
  expect((await responsePromise).status(), 'real payroll demo login must succeed').toBe(200)
  await page.waitForURL(/\/workspace/, { timeout: 30_000 })
}

test.describe('@real payroll workspace', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  test('renders only real payroll runs with Chinese stages and integer-cent money', async ({ page }) => {
    const listResponse = page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname === '/v1/payroll/runs' &&
        response.request().method() === 'GET',
    )

    await page.goto('/payroll', { waitUntil: 'load' })
    const response = await listResponse
    expect(response.status(), 'payroll run list must be readable for the demo payroll user').toBe(200)

    const payload = (await response.json()) as { data?: { items?: RunRow[]; total?: number } }
    const rows = Array.isArray(payload.data?.items) ? payload.data.items : []

    await expect(page.getByRole('heading', { name: '薪资工作台', level: 1 })).toBeVisible()
    await expect(page.locator('.page-error, [data-testid="payroll-forbidden"]')).toHaveCount(0)

    if (rows.length === 0) {
      await expect(page.getByText('还没有工资批次')).toBeVisible()
      await expect(page.locator('[data-testid^="payroll-run-"]')).toHaveCount(0)
      return
    }

    const table = page.getByTestId('payroll-run-table')
    await expect(table).toBeVisible()
    await expect(table.locator('tbody tr')).toHaveCount(rows.length)

    const first = rows[0]
    expect(first.period ?? '').toMatch(/^\d{4}-\d{2}$/)
    await expect(page.getByTestId(`payroll-run-${first.id}`)).toContainText(first.period ?? '')
    await expect(page.getByTestId(`payroll-run-${first.id}`)).toContainText('人')

    // The list must show only the four brief stages, never a fabricated one.
    const stageText = await table.locator('tbody tr').first().textContent()
    expect(stageText ?? '').toMatch(/草稿|已提交|已批准|已发布|已取消/)
    expect(stageText ?? '').not.toContain('¥NaN')
  })

  test('opens a real run detail and shows employee amounts or the permission explanation', async ({ page }) => {
    const listResponse = await page.request.get('/v1/payroll/runs?page=1&page_size=20', {
      headers: { Accept: 'application/json' },
    })
    expect(listResponse.status()).toBe(200)
    const payload = (await listResponse.json()) as { data?: { items?: RunRow[] } }
    const run = payload.data?.items?.[0]
    test.skip(run?.id === undefined, 'the demo tenant has no payroll run for this payroll user')

    const runId = run?.id ?? 0
    await page.goto(`/payroll/runs/${runId}`, { waitUntil: 'load' })

    await expect(page.getByRole('heading', { level: 1 })).toContainText('工资批次')
    await expect(page.getByText('页面暂时无法渲染')).toHaveCount(0)
    await expect(page.getByTestId('payroll-action-bar')).toBeVisible()

    const employeeRows = page.locator('[data-testid^="payroll-employee-"]')
    const count = await employeeRows.count()

    if (count === 0) {
      await expect(page.getByText('该批次没有员工行')).toBeVisible()
      return
    }

    await expect(page.getByTestId('payroll-employee-table')).toBeVisible()
    await employeeRows.first().getByRole('button', { name: '查看工资条' }).click()

    const drawer = page.getByTestId('payslip-drawer')
    await expect(drawer).toBeVisible()
    const masked = await drawer.getByTestId('payslip-masked').count()
    if (masked > 0) {
      await expect(drawer.getByTestId('payslip-masked')).toContainText('字段策略')
    } else {
      await expect(drawer.getByTestId('payslip-line-net')).toBeVisible()
    }
    await expect(drawer).not.toContainText('¥0.00')
  })

  test('shows a published run as read-only while exports keep their real endpoint', async ({ page }) => {
    const listResponse = await page.request.get('/v1/payroll/runs?page=1&page_size=50', {
      headers: { Accept: 'application/json' },
    })
    expect(listResponse.status()).toBe(200)
    const payload = (await listResponse.json()) as { data?: { items?: RunRow[] } }
    const published = payload.data?.items?.find((run) => run.status === 'PUBLISHED')
    test.skip(published?.id === undefined, 'the demo tenant has no published payroll run')

    const runId = published?.id ?? 0
    await page.goto(`/payroll/runs/${runId}`, { waitUntil: 'load' })

    await expect(page.getByTestId('payroll-readonly')).toContainText('只读')
    for (const action of ['lock-inputs', 'calculate', 'review', 'approve', 'publish']) {
      await expect(page.getByTestId(`payroll-action-${action}`)).toBeDisabled()
    }

    const exportResponse = page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname === `/v1/payroll/runs/${runId}/exports/bank` &&
        response.request().method() === 'GET',
    )
    await page.getByTestId('payroll-export-bank').click()
    const response = await exportResponse
    expect(response.status(), 'bank export must be available after publication').toBe(200)
    const body = (await response.json()) as { data?: { filename?: string; sha256?: string } }
    expect(body.data?.filename ?? '').not.toBe('')
    expect(body.data?.sha256 ?? '').toMatch(/^[0-9a-f]{64}$/)
  })
})
