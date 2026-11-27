import { expect, test, type Page, type Response } from '@playwright/test'

/**
 * Real-stack workflow center consistency.
 *
 * Verifies the center against the seeded synthetic tenant and the actual
 * Laravel capability/decision engine:
 *   - the list reads GET /v1/cases with the real server-side view filter;
 *   - a case detail reads GET /v1/cases/{id} and the timeline endpoint;
 *   - timeline items expose actor and timestamp, and a decided case is
 *     read-only with no decision controls;
 *   - the decision action only appears for the current assignee, and a
 *     non-assignee cannot post a decision through the API.
 *
 * Requires HRMS_DEMO_PASSWORD (same value as DEMO_SEED_PASSWORD), the
 * documented demo seed and a running backend/frontend pair. Collection is
 * verified with `--list`; execution is a real-stack step.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? process.env.DEMO_SEED_PASSWORD ?? ''
const MANAGER_USER = process.env.HRMS_DEMO_MANAGER ?? 'demo.manager'
const HR_USER = process.env.HRMS_DEMO_HR ?? 'demo.hr'

test.skip(PASSWORD === '', 'HRMS_DEMO_PASSWORD or DEMO_SEED_PASSWORD is required for real workflow center E2E.')

type Envelope<T> = { data?: T }

function pathOf(responseUrl: string): string {
  return new URL(responseUrl).pathname
}

async function login(page: Page, username: string): Promise<Response> {
  await page.goto('/login', { waitUntil: 'load' })
  await page.getByRole('textbox', { name: '账号' }).fill(username)
  await page.getByRole('textbox', { name: '密码' }).fill(PASSWORD)
  const responsePromise = page.waitForResponse(
    (response) => response.url().endsWith('/v1/auth/login') && response.request().method() === 'POST',
  )
  await page.getByRole('button', { name: '登录', exact: true }).click()
  const response = await responsePromise
  expect(response.status(), `${username} login must succeed against the real backend`).toBe(200)
  await page.waitForURL(/\/workspace/, { timeout: 30_000 })
  return response
}

test.describe('@real workflow center', () => {
  test('manager lists tasks with the real view filter and opens a case detail with timeline', async ({ page }) => {
    const loginResponse = await login(page, MANAGER_USER)
    const loginBody = (await loginResponse.json()) as Envelope<{ csrf_token?: string }>
    const csrf = loginBody.data?.csrf_token ?? ''
    expect(csrf, 'login must return a CSRF token').not.toBe('')

    const casesResponse = page.waitForResponse(
      (response) => pathOf(response.url()) === '/v1/cases' && response.request().method() === 'GET',
    )
    await page.goto('/workflow', { waitUntil: 'load' })
    const response = await casesResponse
    expect(response.status(), 'demo.manager can read its workflow cases').toBe(200)

    const payload = (await response.json()) as Envelope<{
      items?: Array<Record<string, unknown>>
      total?: number
    }>
    const rows = payload.data?.items ?? []

    await expect(page.getByTestId('workflow-center-page')).toBeVisible()
    await expect(page.getByRole('heading', { name: '审批流程中心', level: 1 })).toBeVisible()
    await expect(page.getByTestId('workflow-forbidden')).toHaveCount(0)
    await expect(page.getByTestId('workflow-error')).toHaveCount(0)

    if (rows.length === 0) {
      await expect(page.getByTestId('workflow-empty')).toBeVisible()
      return
    }

    const first = rows[0] as { id?: number; case_no?: string }
    expect(first.id).toBeGreaterThan(0)
    await expect(page.getByTestId(`workflow-case-row-${String(first.id)}`)).toBeVisible()

    const detailResponse = page.waitForResponse(
      (entry) => pathOf(entry.url()) === `/v1/cases/${String(first.id)}` && entry.request().method() === 'GET',
    )
    const timelineResponse = page.waitForResponse(
      (entry) =>
        pathOf(entry.url()) === `/v1/cases/${String(first.id)}/timeline` &&
        entry.request().method() === 'GET',
    )
    await page.getByTestId(`workflow-case-link-${String(first.id)}`).click()
    expect((await detailResponse).status()).toBe(200)
    const timeline = (await timelineResponse).json() as Promise<Envelope<{
      items?: Array<{ type?: string; actor_user_id?: number | null; occurred_at?: string | null }>
    }>>

    await expect(page.getByTestId('workflow-case-detail-page')).toBeVisible()
    await expect(page.getByTestId('workflow-case-summary')).toContainText(first.case_no ?? '')
    await expect(page.getByTestId('workflow-timeline-panel')).toBeVisible()
    await expect(page.getByTestId('workflow-detail-forbidden')).toHaveCount(0)
    await expect(page.getByTestId('workflow-detail-error')).toHaveCount(0)

    const items = (await timeline).data?.items ?? []
    if (items.length > 0) {
      const timelineText = await page.getByTestId('workflow-timeline').textContent()
      // Every rendered event must carry an actor (user id or 系统) and a
      // timestamp; the page must not expose raw node keys or capability codes.
      expect(timelineText ?? '').toMatch(/操作人：/)
      expect(timelineText ?? '').toMatch(/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/)
      expect(timelineText ?? '').not.toMatch(/(WORKFLOW_EVENT|DECISION|manager_approval|hr_review)/)
    }
  })

  test('decided cases are read-only and a non-assignee decision is rejected by the backend', async ({ page }) => {
    const loginResponse = await login(page, HR_USER)
    const loginBody = (await loginResponse.json()) as Envelope<{ csrf_token?: string }>
    const csrf = loginBody.data?.csrf_token ?? ''
    expect(csrf).not.toBe('')

    const decided = await page.request.get('/v1/cases?status=APPROVED&page=1&page_size=10', {
      headers: { Accept: 'application/json' },
    })
    expect([200, 403], 'approved cases are readable or explicitly forbidden').toContain(decided.status())

    if (decided.status() === 200) {
      const body = (await decided.json()) as Envelope<{
        items?: Array<{ id?: number; application_status?: string }>
      }>
      const decidedCaseId = body.data?.items?.[0]?.id ?? 0

      if (decidedCaseId > 0) {
        await page.goto(`/workflow/cases/${decidedCaseId}`, { waitUntil: 'load' })
        await expect(page.getByTestId('workflow-case-detail-page')).toBeVisible()
        await expect(page.getByTestId('workflow-decision-actions')).toHaveCount(0)
        await expect(page.getByTestId('workflow-readonly')).toBeVisible()

        // A decided case must reject any further decision regardless of the
        // button having been removed from the UI. The exact refusal depends on
        // the caller's capability: 409 when they hold workflow.case.decide,
        // 403 when they do not, 404 when no pending task exists.
        const conflict = await page.request.post(`/v1/cases/${decidedCaseId}/decisions`, {
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'Idempotency-Key': `e2e-decided-${Date.now()}`,
            'X-CSRF-Token': csrf,
          },
          data: { action: 'APPROVE', comment: 'Synthetic E2E attempt on a decided case.' },
        })
        expect([403, 404, 409], 'decided case must not accept another decision').toContain(conflict.status())
        if (conflict.status() === 409) {
          const error = (await conflict.json()) as { code?: string }
          expect(error.code).toBe('CONFLICT')
        }
      }
    }

    // A visible case with no pending task for the caller must not yield a
    // decision action in the UI, and the backend must refuse the write.
    const visible = await page.request.get('/v1/cases?page=1&page_size=5', {
      headers: { Accept: 'application/json' },
    })
    expect([200, 403]).toContain(visible.status())

    if (visible.status() === 200) {
      const body = (await visible.json()) as Envelope<{
        items?: Array<{ id?: number; is_awaiting_me?: boolean; application_status?: string }>
      }>
      const other = (body.data?.items ?? []).find(
        (row) => row.is_awaiting_me !== true && row.application_status !== 'APPROVED',
      )
      if (other?.id !== undefined && other.id > 0) {
        await page.goto(`/workflow/cases/${other.id}`, { waitUntil: 'load' })
        await expect(page.getByTestId('workflow-case-detail-page')).toBeVisible()
        await expect(page.getByTestId('workflow-decision-actions')).toHaveCount(0)
        await expect(page.getByTestId('workflow-readonly')).toBeVisible()

        const denied = await page.request.post(`/v1/cases/${other.id}/decisions`, {
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'Idempotency-Key': `e2e-non-assignee-${Date.now()}`,
            'X-CSRF-Token': csrf,
          },
          data: { action: 'APPROVE', comment: 'Synthetic E2E non-assignee attempt.' },
        })
        expect([403, 404], 'non-assignee decision must not be accepted').toContain(denied.status())
      }
    }
  })
})
