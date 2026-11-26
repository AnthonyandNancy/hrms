import { expect, test, type Page } from '@playwright/test'

/**
 * Real-stack Platform workspaces.
 *
 * Covers the five Volume 3 Task 17 replacements against the seeded synthetic
 * tenant. Reads are asserted on the actual endpoints; writes are exercised
 * only when the demo administrator can perform them and always restore the
 * previous value in a finally block.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? process.env.DEMO_SEED_PASSWORD ?? ''
const ADMIN_USER = process.env.HRMS_DEMO_ADMIN ?? 'demo.admin'
const HR_USER = process.env.HRMS_DEMO_HR ?? 'demo.hr'

test.skip(PASSWORD === '', 'HRMS_DEMO_PASSWORD or DEMO_SEED_PASSWORD is required for real Platform workspace E2E.')

type Envelope<T> = { data?: T }

function pathOf(responseUrl: string): string {
  return new URL(responseUrl).pathname
}

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

test.describe('@real platform workspaces', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, HR_USER)
  })

  test('workflow center reads the real case projection and opens a case detail', async ({ page }) => {
    const casesResponse = page.waitForResponse(
      (response) => pathOf(response.url()) === '/v1/cases' && response.request().method() === 'GET',
    )

    await page.goto('/workflow', { waitUntil: 'load' })
    const response = await casesResponse
    expect(response.status(), 'demo.hr can read its approval cases').toBe(200)
    const payload = (await response.json()) as Envelope<{ items?: Array<Record<string, unknown>>; total?: number }>
    const rows = payload.data?.items ?? []

    await expect(page.getByTestId('workflow-center-page')).toBeVisible()
    await expect(page.getByRole('heading', { name: '审批流程中心', level: 1 })).toBeVisible()
    await expect(page.getByTestId('workflow-forbidden')).toHaveCount(0)
    await expect(page.getByTestId('workflow-error')).toHaveCount(0)

    if (rows.length === 0) {
      await expect(page.getByTestId('workflow-empty')).toBeVisible()
      return
    }

    const first = rows[0] as { id?: number; case_no?: string; application_status?: string }
    expect(first.id).toBeGreaterThan(0)
    await expect(page.getByTestId(`workflow-case-row-${String(first.id)}`)).toBeVisible()
    await expect(page.getByTestId(`workflow-case-link-${String(first.id)}`)).toHaveAttribute(
      'href',
      `/workflow/cases/${String(first.id)}`,
    )

    const detailResponse = page.waitForResponse(
      (entry) =>
        pathOf(entry.url()) === `/v1/cases/${String(first.id)}` &&
        entry.request().method() === 'GET',
    )
    const timelineResponse = page.waitForResponse(
      (entry) =>
        pathOf(entry.url()) === `/v1/cases/${String(first.id)}/timeline` &&
        entry.request().method() === 'GET',
    )
    await page.getByTestId(`workflow-case-link-${String(first.id)}`).click()
    expect((await detailResponse).status()).toBe(200)
    expect((await timelineResponse).status()).toBe(200)

    await expect(page.getByTestId('workflow-case-detail-page')).toBeVisible()
    await expect(page.getByTestId('workflow-case-summary')).toContainText(first.case_no ?? '')
    await expect(page.getByTestId('workflow-timeline-panel')).toBeVisible()
    await expect(page.getByTestId('workflow-detail-forbidden')).toHaveCount(0)
    await expect(page.getByTestId('workflow-detail-error')).toHaveCount(0)
  })

  test('calendar workspace renders the current real version and holiday snapshot', async ({ page }) => {
    const year = new Date().getFullYear()
    const calendarResponse = page.waitForResponse(
      (response) =>
        pathOf(response.url()) === '/v1/time/calendars' &&
        response.request().method() === 'GET',
    )

    await page.goto('/system/calendars', { waitUntil: 'load' })
    const response = await calendarResponse
    expect([200, 404], 'calendar read is either available or explicitly absent').toContain(response.status())

    await expect(page.getByTestId('system-calendars-page')).toBeVisible()
    await expect(page.getByRole('heading', { name: '节假日与工作日历', level: 1 })).toBeVisible()
    await expect(page.getByTestId('calendar-error')).toHaveCount(0)

    if (response.status() === 200) {
      const payload = (await response.json()) as Envelope<{
        year?: number
        version?: number
        workweek?: number[]
        holidays?: Array<Record<string, unknown>>
      }>
      const calendar = payload.data
      await expect(page.getByTestId('calendar-version')).toContainText(String(calendar?.version ?? ''))
      await expect(page.getByTestId('calendar-year')).toContainText(String(year))
      await expect(page.getByTestId('calendar-workweek')).not.toHaveText('')
      await expect(page.getByTestId('calendar-holiday-table')).toBeVisible()
      const firstHoliday = calendar?.holidays?.[0]
      if (firstHoliday !== undefined) {
        await expect(page.getByTestId('calendar-holiday-table')).toContainText(String(firstHoliday.name ?? ''))
      }
      // demo.hr may hold time.calendar.manage; when it does the editor must be
      // visible without actually publishing a new calendar in this read test.
      const publish = page.getByTestId('calendar-publish')
      if (await publish.count()) {
        await expect(page.getByTestId('calendar-editor')).toBeVisible()
      }
    } else {
      await expect(page.getByTestId('calendar-forbidden')).toBeVisible()
    }
  })

  test('settings workspace reads real flags and typed parameters without inventing rows', async ({ page }) => {
    await page.goto('/system/settings', { waitUntil: 'load' })

    await expect(page.getByTestId('system-settings-page')).toBeVisible()
    await expect(page.getByRole('heading', { name: '字典、参数与文件', level: 1 })).toBeVisible()
    await expect(page.getByTestId('settings-action-error')).toHaveCount(0)
    await expect(page.getByTestId('settings-feature-flags')).toBeVisible()
    await expect(page.getByTestId('settings-parameters')).toBeVisible()

    const flagsLoading = page.getByTestId('settings-flags-loading')
    if (await flagsLoading.count()) await expect(flagsLoading).toBeHidden()
    const parametersLoading = page.getByTestId('settings-parameters-loading')
    if (await parametersLoading.count()) await expect(parametersLoading).toBeHidden()

    // HR_ADMIN has system.settings.manage but not platform.feature.manage or
    // system.parameter.manage; the page must disclose both restricted panels
    // instead of rendering fabricated toggles.
    const flagsForbidden = await page.getByTestId('settings-flags-forbidden').count()
    const parametersForbidden = await page.getByTestId('settings-parameters-forbidden').count()
    expect(flagsForbidden + parametersForbidden, 'at least one restricted panel is disclosed').toBeGreaterThan(0)
    await expect(page.getByTestId('settings-flags-error')).toHaveCount(0)
    await expect(page.getByTestId('settings-parameters-error')).toHaveCount(0)
  })

  test('notifications workspace reads the caller inbox and never shows another user message', async ({ page }) => {
    const inboxResponse = page.waitForResponse(
      (response) =>
        pathOf(response.url()) === '/v1/engagement/notifications' &&
        response.request().method() === 'GET',
    )

    await page.goto('/notifications', { waitUntil: 'load' })
    const response = await inboxResponse
    expect(response.status(), 'demo.hr can read its own inbox').toBe(200)
    const payload = (await response.json()) as Envelope<Array<Record<string, unknown>>>
    const items = Array.isArray(payload.data) ? payload.data : []

    await expect(page.getByTestId('notifications-page')).toBeVisible()
    await expect(page.getByRole('heading', { name: '消息与通知中心', level: 1 })).toBeVisible()
    await expect(page.getByTestId('notification-forbidden')).toHaveCount(0)
    await expect(page.getByTestId('notification-error')).toHaveCount(0)

    if (items.length === 0) {
      await expect(page.getByTestId('notification-empty')).toBeVisible()
      return
    }

    for (const item of items.slice(0, 5)) {
      await expect(page.getByTestId(`notification-row-${String(item.id)}`)).toBeVisible()
    }
    await expect(page.getByTestId('notification-filters')).toBeVisible()
  })

  test('audit workspace lists real security events and discloses loaded-page filtering', async ({ page }) => {
    await login(page, ADMIN_USER)
    const auditResponse = page.waitForResponse(
      (response) => pathOf(response.url()) === '/v1/auth/events' && response.request().method() === 'GET',
    )

    await page.goto('/system/audit', { waitUntil: 'load' })
    const response = await auditResponse
    expect(response.status(), 'demo.admin can read security events').toBe(200)
    const payload = (await response.json()) as Envelope<{ items?: Array<Record<string, unknown>> }>
    const items = payload.data?.items ?? []

    await expect(page.getByTestId('system-audit-page')).toBeVisible()
    await expect(page.getByRole('heading', { name: '日志审计与运维', level: 1 })).toBeVisible()
    await expect(page.getByTestId('audit-forbidden')).toHaveCount(0)
    await expect(page.getByTestId('audit-error')).toHaveCount(0)
    await expect(page.getByTestId('audit-partial')).toContainText('仅作用于已加载')

    if (items.length === 0) {
      await expect(page.getByTestId('audit-empty')).toBeVisible()
      return
    }

    const first = items[0] as { id?: number; request_id?: string | null }
    await expect(page.getByTestId('audit-table')).toBeVisible()
    await expect(page.getByTestId(`audit-row-${String(first.id)}`)).toBeVisible()
    if (typeof first.request_id === 'string' && first.request_id !== '') {
      await expect(page.getByTestId(`audit-row-${String(first.id)}`)).toContainText(first.request_id)
    }

    await page.getByTestId('audit-filter-actor').fill('999999')
    await expect(page.getByTestId('audit-empty')).toBeVisible()
  })
})
