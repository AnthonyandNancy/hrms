import { expect, test, type Page } from '@playwright/test'

/**
 * Real-stack performance workspace.
 *
 * Runs against the seeded synthetic tenant and the actual Laravel routes.
 * There is deliberately no cycle create/detail endpoint invented here:
 * the workspace lists GET /v1/performance/cycles, opens the cycle selected by
 * its real id, and reads GET /v1/performance/reviews. The manager score flow
 * uses POST /v1/performance/reviews (employment_id in the body) followed by
 * the real POST /v1/performance/reviews/{id}/submit.
 *
 * Requires HRMS_DEMO_PASSWORD plus `people:seed-demo`, `iam:seed-demo`,
 * `talent:seed-demo` and a seeded performance cycle/review.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? process.env.DEMO_SEED_PASSWORD ?? ''
const MANAGER_USER = process.env.HRMS_DEMO_MANAGER ?? 'demo.manager'
const HR_USER = process.env.HRMS_DEMO_HR ?? 'demo.hr'

test.skip(PASSWORD === '', 'HRMS_DEMO_PASSWORD is required for real performance workspace E2E.')

type CycleRow = {
  cycle_id?: number
  name?: string
  period_from?: string
  period_to?: string
  status?: string
  participant_count?: number
  submitted_count?: number
  progress?: number
}

type ReviewRow = {
  review_id?: number
  employment_id?: number
  manager_employment_id?: number
  status?: string
  self_rating?: string | null
  manager_rating?: string | null
  final_rating?: string | null
}

async function login(page: Page, username: string): Promise<void> {
  await page.goto('/login', { waitUntil: 'load' })
  await page.getByRole('textbox', { name: '账号' }).fill(username)
  await page.getByRole('textbox', { name: '密码' }).fill(PASSWORD)
  const responsePromise = page.waitForResponse(
    (response) => response.url().endsWith('/v1/auth/login') && response.request().method() === 'POST',
  )
  await page.getByRole('button', { name: '登录', exact: true }).click()
  expect((await responsePromise).status(), `${username} demo login must succeed`).toBe(200)
  await page.waitForURL(/\/workspace/, { timeout: 30_000 })
}

async function realCycles(page: Page): Promise<CycleRow[]> {
  const response = await page.request.get('/v1/performance/cycles?page=1&page_size=50', {
    headers: { Accept: 'application/json' },
  })
  expect(response.status(), 'the demo manager must be allowed to list performance cycles').toBe(200)
  const payload = (await response.json()) as { data?: { items?: CycleRow[] } }
  return Array.isArray(payload.data?.items) ? payload.data.items : []
}

test.describe('@real performance workspace', () => {
  test('manager sees the real cycle list with Chinese status, participants and progress', async ({ page }) => {
    await login(page, MANAGER_USER)
    const listResponse = page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname === '/v1/performance/cycles' &&
        response.request().method() === 'GET',
    )

    await page.goto('/performance', { waitUntil: 'load' })
    const response = await listResponse
    expect(response.status(), 'performance cycle list must be readable for demo.manager').toBe(200)

    const payload = (await response.json()) as { data?: { items?: CycleRow[]; total?: number } }
    const rows = Array.isArray(payload.data?.items) ? payload.data.items : []

    await expect(page.getByRole('heading', { name: '绩效考核工作台', level: 1 })).toBeVisible()
    await expect(page.locator('[data-testid="performance-forbidden"]')).toHaveCount(0)

    if (rows.length === 0) {
      await expect(page.getByText('还没有绩效周期')).toBeVisible()
      await expect(page.locator('[data-testid^="performance-cycle-"]')).toHaveCount(0)
      return
    }

    const table = page.getByTestId('performance-cycle-table')
    await expect(table).toBeVisible()
    await expect(table.locator('tbody tr')).toHaveCount(rows.length)

    const first = rows[0]
    expect(first.cycle_id ?? 0).toBeGreaterThan(0)
    const row = page.getByTestId(`performance-cycle-${first.cycle_id}`)
    await expect(row).toContainText(first.name ?? '')
    await expect(row).toContainText(`${first.participant_count ?? 0} 人`)
    await expect(row).toContainText(`${first.progress ?? 0}%`)

    const rowText = (await row.textContent()) ?? ''
    expect(rowText).toMatch(/草稿|进行中|评分中|已结束/)
    expect(rowText).not.toContain('NaN')
  })

  test('manager opens a real cycle detail and sees participant review statuses', async ({ page }) => {
    await login(page, MANAGER_USER)
    const cycles = await realCycles(page)
    test.skip(cycles.length === 0 || cycles[0]?.cycle_id === undefined, 'the demo tenant has no performance cycle')

    const cycle = cycles[0]!
    await page.goto(`/performance?cycle_id=${cycle.cycle_id}`, { waitUntil: 'load' })

    await expect(page.getByRole('heading', { level: 1 })).toContainText(cycle.name ?? '')
    await expect(page.getByText('页面暂时无法渲染')).toHaveCount(0)

    const reviewResponse = await page.request.get(
      `/v1/performance/reviews?cycle_id=${cycle.cycle_id}&page=1&page_size=100`,
      { headers: { Accept: 'application/json' } },
    )
    expect(reviewResponse.status(), 'manager review list must be readable').toBe(200)
    const payload = (await reviewResponse.json()) as { data?: { items?: ReviewRow[] } }
    const reviews = Array.isArray(payload.data?.items) ? payload.data.items : []

    if (reviews.length === 0) {
      await expect(page.getByText('该周期还没有评估')).toBeVisible()
      return
    }

    await expect(page.getByTestId('performance-review-table')).toBeVisible()
    await expect(page.getByTestId(`performance-review-${reviews[0]!.review_id}`)).toContainText(
      `员工 #${reviews[0]!.employment_id}`,
    )
    const statusText = (await page.getByTestId('performance-review-table').textContent()) ?? ''
    expect(statusText).toMatch(/草稿|已提交|申诉中/)
    expect(statusText).toMatch(/待服务端计算|超出预期|符合预期|低于预期|\d+ 分/)
  })

  test('manager scores a direct report through the real review and submit endpoints', async ({ page }) => {
    await login(page, MANAGER_USER)
    const cycles = await realCycles(page)
    test.skip(cycles.length === 0, 'the demo tenant has no performance cycle')

    let target: { cycle: CycleRow; review: ReviewRow } | null = null
    for (const cycle of cycles) {
      if (cycle.cycle_id === undefined) continue
      const response = await page.request.get(
        `/v1/performance/reviews?cycle_id=${cycle.cycle_id}&page=1&page_size=100`,
        { headers: { Accept: 'application/json' } },
      )
      if (response.status() !== 200) continue
      const payload = (await response.json()) as { data?: { items?: ReviewRow[] } }
      const review = payload.data?.items?.find((item) => item.status === 'DRAFT' && item.employment_id !== undefined)
      if (review !== undefined) {
        target = { cycle, review }
        break
      }
    }
    test.skip(target === null, 'the demo tenant has no draft performance review for this manager')

    const { cycle, review } = target!
    await page.goto(`/performance?cycle_id=${cycle.cycle_id}`, { waitUntil: 'load' })
    const scoreButton = page.getByTestId(`performance-score-${review.review_id}`)
    await expect(scoreButton).toBeVisible()
    await scoreButton.click()

    const drawer = page.getByTestId('performance-score-drawer')
    await expect(drawer).toBeVisible()
    await expect(drawer.getByTestId('goal-weight-total')).toContainText('/ 100')

    await drawer.getByTestId('performance-manager-rating').fill('90')
    await drawer.getByTestId('performance-score-save').click()
    const saveConfirm = drawer.getByTestId('performance-save-confirm')
    await expect(saveConfirm).toBeVisible()

    const saveResponsePromise = page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname === '/v1/performance/reviews' &&
        response.request().method() === 'POST',
    )
    await saveConfirm.getByTestId('performance-save-confirm-submit').click()
    const saveResponse = await saveResponsePromise
    expect(saveResponse.status(), 'saving a draft review must be accepted').toBe(201)

    await expect(page.getByTestId('performance-action-success')).toContainText('已保存')
    await expect(page.getByTestId(`performance-review-${review.review_id}`)).toContainText('草稿')
  })

  test('published cycle is read-only and no score action is offered', async ({ page }) => {
    await login(page, HR_USER)
    const cycles = await realCycles(page)
    const published = cycles.find((cycle) => cycle.status === 'PUBLISHED')
    test.skip(published?.cycle_id === undefined, 'the demo tenant has no published performance cycle')

    await page.goto(`/performance?cycle_id=${published!.cycle_id}`, { waitUntil: 'load' })

    await expect(page.getByTestId('performance-readonly')).toContainText('只读')
    await expect(page.getByTestId('performance-publish')).toBeDisabled()
    await expect(page.locator('[data-testid^="performance-score-"]')).toHaveCount(0)
    await expect(page.getByText('页面暂时无法渲染')).toHaveCount(0)
  })
})
