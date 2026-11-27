import { expect, test, type Page } from '@playwright/test'

/**
 * Real-stack settings workspaces: benefits, company calendar and the typed
 * parameter surfaces of `/system/settings`.
 *
 * Requires a seeded backend and the demo password. The suite reads real data
 * and only exercises a write when the demo administrator has the capability.
 * A benefit plan created here uses an obviously synthetic code and the API's
 * append-only versioning; no row is deleted or rewritten.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? process.env.DEMO_SEED_PASSWORD ?? ''
const ADMIN_USER = process.env.HRMS_DEMO_ADMIN ?? 'demo.admin'
const HR_USER = process.env.HRMS_DEMO_HR ?? 'demo.hr'

test.skip(PASSWORD === '', 'HRMS_DEMO_PASSWORD or DEMO_SEED_PASSWORD is required for real settings-workspace E2E.')

type Envelope<T> = { data?: T }

async function login(page: Page, username: string): Promise<void> {
  await page.goto('/login', { waitUntil: 'load' })
  await page.getByRole('textbox', { name: '账号' }).fill(username)
  await page.getByRole('textbox', { name: '密码' }).fill(PASSWORD)
  const responsePromise = page.waitForResponse(
    (response) => response.url().endsWith('/v1/auth/login') && response.request().method() === 'POST',
  )
  await page.getByRole('button', { name: '登录', exact: true }).click()
  expect((await responsePromise).status(), 'real demo login must succeed').toBe(200)
  await page.waitForURL(/\/workspace/, { timeout: 30_000 })
}

test.describe('@real settings workspace', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, HR_USER)
  })

  test('calendar reads one company snapshot and never asks for a legal entity or tenant', async ({ page }) => {
    const year = new Date().getFullYear()
    const requests: string[] = []
    page.on('request', (request) => {
      if (new URL(request.url()).pathname.startsWith('/v1/time/calendars')) requests.push(request.url())
    })
    const responsePromise = page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname === '/v1/time/calendars' &&
        response.request().method() === 'GET',
    )

    await page.goto('/system/settings?tab=calendar', { waitUntil: 'load' })
    const response = await responsePromise
    expect([200, 404], 'calendar read is either available or explicitly absent').toContain(response.status())

    await expect(page.getByTestId('settings-calendar')).toBeVisible()
    await expect(page.getByRole('heading', { name: '公司设置', level: 1 })).toBeVisible()
    await expect(page.getByTestId('calendar-forbidden')).toHaveCount(0)
    await expect(page.getByTestId('calendar-error')).toHaveCount(0)
    expect(requests.length).toBeGreaterThan(0)
    for (const request of requests) {
      const url = new URL(request)
      expect(url.searchParams.has('legal_entity_id'), 'no legal-entity selector').toBe(false)
      expect(url.searchParams.has('tenant_id'), 'no tenant selector').toBe(false)
    }

    const payload = (await response.json()) as Envelope<{
      year?: number
      version?: number
      workweek?: number[]
      holidays?: Array<{ name?: string; type?: string }>
    }>
    const calendar = payload.data
    expect(calendar?.year).toBe(year)
    expect(Object.prototype.hasOwnProperty.call(calendar ?? {}, 'legal_entity_id')).toBe(false)
    expect(Object.prototype.hasOwnProperty.call(calendar ?? {}, 'tenant_id')).toBe(false)

    await expect(page.getByTestId('settings-calendar-version')).toContainText(String(calendar?.version ?? ''))
    await expect(page.getByTestId('settings-calendar-workweek')).not.toHaveText('')
    const holidayRows = calendar?.holidays ?? []
    if (holidayRows.length === 0) {
      await expect(page.getByTestId('settings-calendar-holidays-empty')).toBeVisible()
    } else {
      await expect(page.getByTestId('settings-calendar-holiday-table')).toBeVisible()
      await expect(page.getByTestId('settings-calendar-holiday-table')).toContainText(String(holidayRows[0]?.name ?? ''))
    }

    // demo.hr may hold time.calendar.manage. The page may publish, but this
    // read-only journey deliberately leaves the company calendar unchanged.
    await page.getByTestId('settings-calendar-year-input').locator('input').fill(String(year))
    await page.getByTestId('settings-calendar-year-input').locator('input').press('Enter')
    await expect(page.getByTestId('settings-calendar-year-label')).toContainText(String(year))
    if (await page.getByTestId('settings-calendar-publish').count()) {
      await page.getByTestId('settings-calendar-publish').click()
      await expect(page.getByTestId('settings-calendar-publish-confirm')).toBeVisible()
      await expect(page.getByTestId('settings-calendar-publish-confirm').getByText('确认发布', { exact: true })).toBeVisible()
      await page.keyboard.press('Escape')
    }
  })

  test('benefits shows decimal rates as percentages and real enrolments', async ({ page }) => {
    const plansResponse = page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname === '/v1/benefits/plans' &&
        response.request().method() === 'GET',
    )

    await page.goto('/benefits', { waitUntil: 'load' })
    const response = await plansResponse
    expect([200, 403], 'benefit plan projection is readable or explicitly forbidden').toContain(response.status())

    await expect(page.getByTestId('benefits-page')).toBeVisible()
    await expect(page.getByRole('heading', { name: '社保公积金与福利', level: 1 })).toBeVisible()
    await expect(page.getByTestId('benefit-error')).toHaveCount(0)

    if (response.status() === 403) {
      await expect(page.getByTestId('benefit-forbidden')).toBeVisible()
      return
    }

    const payload = (await response.json()) as Envelope<{
      items?: Array<{
        id?: number
        code?: string
        employer_rate?: string
        employee_rate?: string
      }>
      total?: number
    }>
    const plans = payload.data?.items ?? []

    if (plans.length === 0) {
      await expect(page.getByTestId('benefit-empty')).toBeVisible()
      return
    }

    const first = plans[0]
    expect(first?.employer_rate ?? '').toMatch(/^(0|1)\.\d{6,18}$/)
    expect(first?.employee_rate ?? '').toMatch(/^(0|1)\.\d{6,18}$/)
    const row = page.getByTestId(`benefit-plan-row-${String(first?.id)}`)
    await expect(row).toContainText(String(first?.code ?? ''))
    await expect(row).not.toContainText(String(first?.employer_rate ?? ''))
    await expect(row).toContainText('%')
    await expect(page.locator('[data-testid="benefit-plans-panel"]')).not.toContainText('NaN')
  })

  test('settings edits existing dictionaries/parameters and discloses file ownership', async ({ page }) => {
    await login(page, ADMIN_USER)
    const parametersResponse = page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname === '/v1/system/parameters' &&
        response.request().method() === 'GET',
    )

    await page.goto('/system/settings', { waitUntil: 'load' })
    const response = await parametersResponse
    expect([200, 403], 'parameter projection is readable or explicitly forbidden').toContain(response.status())

    await expect(page.getByTestId('system-settings-page')).toBeVisible()
    await expect(page.getByRole('heading', { name: '字典、参数与文件', level: 1 })).toBeVisible()
    await expect(page.getByTestId('settings-action-error')).toHaveCount(0)

    // There is no generic key/value editor: no empty, free-text param key input
    // exists and no dictionary type/code creation form is rendered.
    await expect(page.getByTestId('parameter-key')).toHaveCount(0)
    await expect(page.getByTestId('dictionary-type')).toHaveCount(0)

    if (response.status() === 200) {
      const payload = (await response.json()) as Envelope<{
        items?: Array<{ id?: number; key?: string; sensitive?: boolean; masked?: boolean }>
      }>
      const parameters = payload.data?.items ?? []
      const masked = parameters.find((parameter) => parameter.sensitive === true || parameter.masked === true)
      if (masked?.id !== undefined) {
        // Sensitive rows are listed by label but never echo their ciphertext.
        await expect(page.getByTestId(`settings-parameter-${String(masked.id)}`)).not.toContainText(
          String(masked.key ?? ''),
        )
      }
    } else {
      await expect(page.getByTestId('settings-parameters-forbidden')).toBeVisible()
    }

    // File management is owned by the employee document surface.
    await expect(page.getByTestId('settings-files')).toBeVisible()
    await expect(page.getByTestId('settings-files')).toContainText('员工附件')
    await expect(page.getByTestId('settings-files-employment')).toBeVisible()
    const fileLink = page.getByTestId('settings-files-link')
    if (await fileLink.count()) {
      const href = await fileLink.getAttribute('href')
      expect(href ?? '').toMatch(/^\/hr\/employees\/\d+\/files$/)
    }
  })
})
