import { expect, test, type Page } from '@playwright/test'

/**
 * Real-stack training workspace.
 *
 * Runs against the seeded synthetic tenant. The workspace is read/create only
 * here: publishing/closing a program and cancelling an enrollment have no
 * backend route yet, so the suite never asserts a fabricated transition.
 *
 * Requires HRMS_DEMO_PASSWORD (same value as DEMO_SEED_PASSWORD) and the demo
 * seed. The training pages live at `/training` and `/training/programs/:id`
 * once the lead-owned router registry points those keys at the module.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? process.env.DEMO_SEED_PASSWORD ?? ''
const HR_USER = process.env.HRMS_DEMO_HR ?? 'demo.hr'

test.skip(PASSWORD === '', 'HRMS_DEMO_PASSWORD is required for real training workspace E2E.')

type ProgramRow = {
  program_id?: number
  code?: string
  title?: string
  delivery_mode?: string | null
  capacity?: number
  enrolled_count?: number
  remaining_seats?: number
  status?: string
  training_cost_cents?: number
}

async function login(page: Page): Promise<void> {
  await page.goto('/login', { waitUntil: 'load' })
  await page.getByRole('textbox', { name: '账号' }).fill(HR_USER)
  await page.getByRole('textbox', { name: '密码' }).fill(PASSWORD)
  const responsePromise = page.waitForResponse(
    (response) => response.url().endsWith('/v1/auth/login') && response.request().method() === 'POST',
  )
  await page.getByRole('button', { name: '登录', exact: true }).click()
  expect((await responsePromise).status(), 'real HR demo login must succeed').toBe(200)
  await page.waitForURL(/\/workspace/, { timeout: 30_000 })
}

test.describe('@real training workspace', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  test('program projection carries live capacity and delivery facts', async ({ page }) => {
    const response = await page.request.get('/v1/training/programs', {
      headers: { Accept: 'application/json' },
    })
    expect(response.status(), 'training programs must be readable for demo.hr').toBe(200)

    const payload = (await response.json()) as {
      data?: { items?: ProgramRow[]; total?: number; freshness?: string }
    }
    const rows = Array.isArray(payload.data?.items) ? payload.data.items : []
    expect(payload.data?.freshness).toBe('LIVE')
    expect(payload.data?.total).toBe(rows.length)

    for (const row of rows) {
      expect(row.program_id ?? 0).toBeGreaterThan(0)
      expect(typeof row.title).toBe('string')
      expect(typeof row.capacity).toBe('number')
      expect(typeof row.enrolled_count).toBe('number')
      expect(typeof row.remaining_seats).toBe('number')
      expect(row.remaining_seats ?? -1).toBeGreaterThanOrEqual(0)
      if (row.delivery_mode !== null && row.delivery_mode !== undefined) {
        expect(['ONSITE', 'ONLINE', 'HYBRID']).toContain(row.delivery_mode)
      }
      if (row.training_cost_cents !== undefined) {
        expect(Number.isSafeInteger(row.training_cost_cents)).toBe(true)
      }
    }
  })

  test('renders the training workspace from real rows or an honest empty state', async ({ page }) => {
    const listResponse = page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname === '/v1/training/programs' &&
        response.request().method() === 'GET',
    )

    await page.goto('/training', { waitUntil: 'load' })
    const response = await listResponse
    expect(response.status()).toBe(200)
    const payload = (await response.json()) as { data?: { items?: ProgramRow[] } }
    const rows = Array.isArray(payload.data?.items) ? payload.data.items : []

    const pageRoot = page.locator('[data-page="training-workspace"]')
    test.skip(
      (await pageRoot.count()) === 0,
      'Router registry still points /training at the legacy wrapper; lead-owned wiring is required.',
    )

    await expect(page.getByRole('heading', { name: '培训工作台', level: 1 })).toBeVisible()
    await expect(page.locator('[data-testid="training-forbidden"]')).toHaveCount(0)

    if (rows.length === 0) {
      await expect(page.getByText('还没有培训项目')).toBeVisible()
      return
    }

    const table = page.getByTestId('training-program-table')
    await expect(table).toBeVisible()
    await expect(table.locator('tbody tr')).toHaveCount(rows.length)

    const first = rows[0]
    const row = page.getByTestId(`training-program-row-${first?.program_id ?? 0}`)
    await expect(row).toContainText(first?.title ?? '')
    await expect(row).toContainText(`${first?.enrolled_count ?? 0} / ${first?.capacity ?? 0}`)
    const text = await row.textContent()
    expect(text ?? '').toMatch(/线上|线下|混合|未设置/)
    expect(text ?? '').toMatch(/启用中|已归档/)
    expect(text ?? '').not.toContain('¥NaN')
  })

  test('opens a real program detail with sessions and enrollment states', async ({ page }) => {
    const listResponse = await page.request.get('/v1/training/programs', {
      headers: { Accept: 'application/json' },
    })
    expect(listResponse.status()).toBe(200)
    const payload = (await listResponse.json()) as { data?: { items?: ProgramRow[] } }
    const program = payload.data?.items?.[0]
    test.skip(program?.program_id === undefined, 'the demo tenant has no training program for demo.hr')

    const programId = program?.program_id ?? 0
    await page.goto(`/training/programs/${programId}`, { waitUntil: 'load' })

    test.skip(
      (await page.locator('[data-page="training-program-detail"]').count()) === 0,
      'Router registry has no /training/programs/:id route yet; lead-owned wiring is required.',
    )

    await expect(page.getByRole('heading', { level: 1 })).toContainText(program?.title ?? '')
    await expect(page.getByTestId('training-detail-forbidden')).toHaveCount(0)

    const enrollmentRows = page.locator('[data-testid^="training-enrollment-row-"]')
    if ((await enrollmentRows.count()) === 0) {
      await expect(page.getByTestId('training-enrollment-empty')).toBeVisible()
      return
    }

    const statusText = await page.getByTestId('training-enrollment-table').textContent()
    expect(statusText ?? '').toMatch(/已报名|已签到|已完成|已取消/)
  })
})
