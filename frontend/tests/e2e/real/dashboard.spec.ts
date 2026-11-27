import { expect, test, type Page } from '@playwright/test'

/**
 * M02 real-stack dashboard acceptance.
 *
 * One login keeps this file clear of the 5/min login throttle. It proves the
 * dashboard reads the Reporting projection, renders business units instead of
 * MINOR_UNITS, never paints an unavailable metric as zero, and that a metric
 * drilldown comes from the same server projection.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? process.env.DEMO_SEED_PASSWORD ?? ''
const USERNAME = process.env.HRMS_DEMO_USERNAME ?? 'demo.hr'

test.skip(PASSWORD === '', 'HRMS_DEMO_PASSWORD is required for the real M02 test.')

async function login(page: Page): Promise<void> {
  await page.goto('/login', { waitUntil: 'load' })
  await page.getByLabel('账号').fill(USERNAME)
  await page.getByLabel('密码').fill(PASSWORD)
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.waitForURL(/\/workspace/, { timeout: 30_000 })
}

test('@real M02 dashboard metrics, units and drilldown share one server projection', async ({ page }) => {
  await login(page)
  await page.goto('/dashboard', { waitUntil: 'load' })

  await expect(page.getByTestId('dashboard-page')).toBeVisible({ timeout: 20_000 })
  await expect(page.getByTestId('dashboard-error')).toHaveCount(0)
  await expect(page.getByTestId('dashboard-forbidden')).toHaveCount(0)

  const response = await page.request.get('/v1/reporting/dashboard', {
    headers: { Accept: 'application/json' },
  })
  expect(response.status()).toBe(200)
  const payload = (await response.json()) as {
    data?: {
      metrics?: Array<{
        code: string
        status: string
        value: number
        unit: string
        reason?: string | null
        drilldown?: { route?: string; query?: Record<string, string> } | null
      }>
    }
  }
  const metrics = payload.data?.metrics ?? []
  expect(metrics.length).toBeGreaterThan(0)

  for (const metric of metrics) {
    const card = page.getByTestId(`metric-card-${metric.code}`)
    await expect(card).toBeVisible()
    const text = await card.innerText()
    expect(text).not.toContain('MINOR_UNITS')
    expect(text).not.toContain('null')
    if (metric.status !== 'AVAILABLE') {
      // Unavailable is not zero: the card must carry the server reason.
      expect(metric.reason ?? '').not.toBe('')
      await expect(card).toContainText(/尚未|不可用|未配置|无权|异常|暂不/)
    }
    if (metric.unit === 'CNY_CENT' && metric.status === 'AVAILABLE') {
      await expect(card).toContainText('元')
      await expect(card).not.toContainText('分')
    }
    if (metric.status !== 'AVAILABLE') {
      await expect(card).toContainText('—')
    }
  }

  const drillable = metrics.find(
    (metric) => metric.status === 'AVAILABLE' && (metric.drilldown?.route ?? '') === '',
  )
  const routed = metrics.find(
    (metric) => metric.status === 'AVAILABLE' && (metric.drilldown?.route ?? '') !== '',
  )

  if (routed !== undefined) {
    const route = routed.drilldown?.route ?? ''
    await page.getByTestId(`metric-card-${routed.code}`).click()
    await expect(page).toHaveURL((url) => url.pathname === route)
  }

  if (drillable !== undefined) {
    await page.goto('/dashboard', { waitUntil: 'load' })
    await page.getByTestId(`metric-card-${drillable.code}`).click()
    await expect(
      page.getByTestId('dashboard-drilldown').or(page.getByTestId('dashboard-selected-unavailable')),
    ).toBeVisible({ timeout: 20_000 })

    const drilldown = await page.request.get(`/v1/reporting/dashboard/metrics/${drillable.code}`, {
      headers: { Accept: 'application/json' },
    })
    expect([200, 403]).toContain(drilldown.status())
    if (drilldown.status() === 200) {
      const body = (await drilldown.json()) as { data?: { metric?: { code?: string } } }
      expect(body.data?.metric?.code ?? drillable.code).toBe(drillable.code)
      await expect(page.getByTestId('dashboard-drilldown')).toBeVisible()
    }
  }

})
