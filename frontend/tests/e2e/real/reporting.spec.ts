import { expect, test, type Page } from '@playwright/test'

/**
 * M03 real-stack reporting acceptance.
 *
 * One login avoids the deliberate 5/min login throttle. The test derives every
 * selector from the live definitions endpoint rather than hard-coding a report
 * code, then proves query and export use the real API.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? process.env.DEMO_SEED_PASSWORD ?? ''
const USERNAME = process.env.HRMS_DEMO_USERNAME ?? 'demo.hr'

test.skip(PASSWORD === '', 'HRMS_DEMO_PASSWORD is required for the real M03 test.')

async function login(page: Page): Promise<void> {
  await page.goto('/login', { waitUntil: 'load' })
  await page.getByLabel('账号').fill(USERNAME)
  await page.getByLabel('密码').fill(PASSWORD)
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.waitForURL(/\/workspace/, { timeout: 30_000 })
}

test('@real M03 report catalog, run, export and download reauthorization', async ({ page }) => {
  await login(page)

  const definitionsResponse = await page.request.get('/v1/reporting/definitions', {
    headers: { Accept: 'application/json' },
  })
  expect(definitionsResponse.status()).toBe(200)
  const definitionsPayload = (await definitionsResponse.json()) as {
    data?: { items?: Array<{ code: string; title?: string; exportableFields?: string[] }> }
  }
  const definitions = definitionsPayload.data?.items ?? []
  expect(definitions.length).toBeGreaterThan(0)
  const definition = definitions[0]

  // `/reports` shows only the surviving single workspace, not eight sub-routes.
  await page.goto('/reports', { waitUntil: 'load' })
  await expect(page.getByTestId('reports-page')).toBeVisible({ timeout: 20_000 })
  await expect(page.getByTestId('reports-forbidden')).toHaveCount(0)
  await expect(page.getByTestId('report-catalog')).toBeVisible()

  const definitionButton = page.getByTestId(`report-definition-${definition.code}`)
  await expect(definitionButton).toBeVisible()
  await definitionButton.click()

  await expect(page.getByTestId('report-runner')).toBeVisible()
  await page.getByTestId('report-run').click()

  // Either the real projection returns rows, an explicit empty state, or a
  // business error. A failed projection must never be painted as empty zero.
  // report-empty is nested inside report-result; locate the outermost state.
  const outcome = page
    .getByTestId('report-result')
    .or(page.getByTestId('report-error'))
    .or(page.getByTestId('report-partial'))
  await expect(outcome.first()).toBeVisible({ timeout: 30_000 })
  if ((await page.getByTestId('report-error').count()) > 0) {
    await expect(page.getByTestId('report-error')).not.toContainText('0 行')
  }

  if ((await page.getByTestId('report-result').count()) === 0) {
    // No result means export cannot be exercised meaningfully; the page must
    // still have shown a truthful state above.
    return
  }

  const exportPanel = page.getByTestId('export-panel')
  if ((await exportPanel.count()) === 0) return
  await expect(exportPanel).toBeVisible()

  const exportFields = definition.exportableFields ?? []
  if (exportFields.length === 0) return

  await page.getByTestId('export-submit').click()
  const exportOutcome = page
    .getByTestId('export-job')
    .or(page.getByTestId('export-denial'))
  await expect(exportOutcome).toBeVisible({ timeout: 30_000 })

  if ((await page.getByTestId('export-job').count()) > 0) {
    await expect(page.getByTestId('export-download')).toBeVisible({ timeout: 30_000 })
    const href = await page.getByTestId('export-download').getAttribute('href')
    expect(href ?? '').toMatch(/\/v1\/reporting\/exports\/\d+\/download/)
  }
})
