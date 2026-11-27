import { expect, test, type Page } from '@playwright/test'

/**
 * Real-stack notification and audit workspaces.
 *
 * These specs read only real endpoints. They never create synthetic rows on the
 * client and they do not claim server-side filters or channel preferences that
 * the API does not expose.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? process.env.DEMO_SEED_PASSWORD ?? ''
const ADMIN_USER = process.env.HRMS_DEMO_ADMIN ?? 'demo.admin'

test.skip(PASSWORD === '', 'HRMS_DEMO_PASSWORD or DEMO_SEED_PASSWORD is required for real notification/audit E2E.')

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

test.describe('@real notification center and audit log', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ADMIN_USER)
  })

  test('notification center reads the caller inbox, discloses the channel-preference gap and marks a real unread item read', async ({ page }) => {
    const inboxResponse = page.waitForResponse(
      (response) => pathOf(response.url()) === '/v1/engagement/notifications' && response.request().method() === 'GET',
    )

    await page.goto('/notifications', { waitUntil: 'load' })
    const response = await inboxResponse
    expect(response.status(), 'the demo administrator can read its own inbox').toBe(200)
    const payload = (await response.json()) as Envelope<Array<Record<string, unknown>>>
    const items = Array.isArray(payload.data) ? payload.data : []

    await expect(page.getByTestId('notifications-page')).toBeVisible()
    await expect(page.getByRole('heading', { name: '消息与通知中心', level: 1 })).toBeVisible()
    await expect(page.getByTestId('notification-forbidden')).toHaveCount(0)
    await expect(page.getByTestId('notification-error')).toHaveCount(0)
    await expect(page.getByTestId('notification-channel-unavailable')).toContainText('该功能暂不可用')
    await expect(page.getByTestId('notification-channel-unavailable')).toContainText('渠道偏好')
    await expect(page.getByTestId('notification-channel-toggle')).toHaveCount(0)

    if (items.length === 0) {
      await expect(page.getByTestId('notification-empty')).toBeVisible()
      return
    }

    for (const item of items.slice(0, 5)) {
      await expect(page.getByTestId(`notification-row-${String(item.id)}`)).toBeVisible()
    }
    await expect(page.getByTestId('notification-filters')).toBeVisible()
    await expect(page.getByTestId('notification-filter-all')).toBeVisible()
    await expect(page.getByTestId('notification-filter-unread')).toBeVisible()

    const unread = items.find((item) => item.read === false)
    if (unread === undefined) return
    const unreadId = String(unread.id)
    const markReadResponse = page.waitForResponse(
      (entry) =>
        pathOf(entry.url()) === `/v1/engagement/notifications/${unreadId}/read` &&
        entry.request().method() === 'POST',
    )
    await page.getByTestId(`notification-mark-read-${unreadId}`).click()
    expect((await markReadResponse).status()).toBe(200)
    await expect(page.getByTestId(`notification-unread-${unreadId}`)).toHaveCount(0)
    await expect(page.getByTestId(`notification-row-${unreadId}`)).toContainText('已读')
  })

  test('audit workspace shows real actor/action/resource/result/request-id fields, limits filters to the loaded page and keeps the event code in the detail only', async ({ page }) => {
    const auditResponse = page.waitForResponse(
      (response) => pathOf(response.url()) === '/v1/auth/events' && response.request().method() === 'GET',
    )

    await page.goto('/system/audit', { waitUntil: 'load' })
    const response = await auditResponse
    expect(response.status(), 'the demo administrator can read audit events').toBe(200)
    const payload = (await response.json()) as Envelope<{
      items?: Array<Record<string, unknown>>
      page?: number
      page_size?: number
      total?: number
    }>
    const items = payload.data?.items ?? []

    await expect(page.getByTestId('system-audit-page')).toBeVisible()
    await expect(page.getByRole('heading', { name: '日志审计与运维', level: 1 })).toBeVisible()
    await expect(page.getByTestId('audit-forbidden')).toHaveCount(0)
    await expect(page.getByTestId('audit-error')).toHaveCount(0)
    await expect(page.getByTestId('audit-partial')).toContainText('仅作用于已加载')
    await expect(page.getByTestId('audit-partial')).toContainText('审计导出暂未开放')
    await expect(page.getByTestId('audit-export')).toHaveCount(0)
    await expect(page.getByTestId('audit-filter-action')).toHaveAttribute('placeholder', '按中文动作名称筛选')

    if (items.length === 0) {
      await expect(page.getByTestId('audit-empty')).toBeVisible()
      return
    }

    const first = items[0] as { id?: number; event_type?: string; request_id?: string | null }
    const firstId = String(first.id)
    const row = page.getByTestId(`audit-row-${firstId}`)
    await expect(page.getByTestId('audit-table')).toBeVisible()
    await expect(row).toBeVisible()
    await expect(row).toContainText('查看明细')
    await expect(row).not.toContainText(String(first.event_type ?? ''))
    if (typeof first.request_id === 'string' && first.request_id !== '') {
      await expect(row).toContainText(first.request_id)
    }

    await page.getByTestId('audit-filter-actor').fill('999999')
    await expect(page.getByTestId('audit-empty')).toBeVisible()
    await page.getByTestId('audit-filter-actor').fill('')
    await expect(row).toBeVisible()

    await row.getByRole('button', { name: '查看明细' }).click()
    const detail = page.getByTestId('audit-detail-dialog')
    await expect(detail).toBeVisible()
    await expect(detail.getByTestId('audit-detail-event-code')).toHaveText(String(first.event_type ?? ''))
    if (typeof first.request_id === 'string' && first.request_id !== '') {
      await expect(detail.getByTestId('audit-detail-request-id')).toHaveText(first.request_id)
    }
  })
})
