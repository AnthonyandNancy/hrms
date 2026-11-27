import { expect, test, type Page } from '@playwright/test'

/**
 * Real-stack recruitment workspace.
 *
 * Skips unless the seeded demo password is exported. It asserts only live
 * routes and seeded rows: no requisition, candidate or offer is fabricated
 * when the API returns none. Creation is attempted only through the real
 * drawer; the list assertion then reads the server response instead of
 * assuming success.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? process.env.DEMO_SEED_PASSWORD ?? ''
const USERNAME = process.env.HRMS_DEMO_HR ?? 'demo.hr'

test.skip(PASSWORD === '', 'HRMS_DEMO_PASSWORD or DEMO_SEED_PASSWORD is required for the real recruitment workspace test.')

type RequisitionRow = {
  requisition_id?: number
  requisition_no?: string
  title?: string
  status?: string
  headcount?: number
  org_unit_name?: string
  opened_at?: string
}

type CandidateRow = {
  candidate_id?: number
  display_name?: string
  status?: string
  converted_employee_no?: string | null
}

async function login(page: Page): Promise<void> {
  await page.goto('/login', { waitUntil: 'load' })
  await page.getByRole('textbox', { name: '账号' }).fill(USERNAME)
  await page.getByRole('textbox', { name: '密码' }).fill(PASSWORD)
  const responsePromise = page.waitForResponse(
    (response) => response.url().endsWith('/v1/auth/login') && response.request().method() === 'POST',
  )
  await page.getByRole('button', { name: '登录', exact: true }).click()
  expect((await responsePromise).status(), 'real demo login must succeed').toBe(200)
  await page.waitForURL(/\/workspace/, { timeout: 30_000 })
}

test.describe('@real recruitment workspace', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  test('lists only real requisitions with Chinese status, org unit, headcount and opened date', async ({ page }) => {
    const listResponse = page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname === '/v1/recruitment/requisitions'
        && response.request().method() === 'GET',
    )

    await page.goto('/recruitment/requests', { waitUntil: 'load' })
    const response = await listResponse
    expect(response.status(), 'requisition list must be readable for demo.hr').toBe(200)

    const payload = (await response.json()) as { data?: { items?: RequisitionRow[] } }
    const rows = Array.isArray(payload.data?.items) ? payload.data.items : []

    await expect(page.getByRole('heading', { name: '招聘需求', level: 1 })).toBeVisible()
    await expect(page.locator('[data-testid="requisitions-forbidden"]')).toHaveCount(0)

    if (rows.length === 0) {
      await expect(page.getByText('还没有招聘需求')).toBeVisible()
      await expect(page.locator('[data-testid="requisition-table"] tbody tr')).toHaveCount(0)
      return
    }

    await expect(page.getByTestId('requisition-table')).toBeVisible()
    const first = rows[0]
    await expect(page.getByTestId(`requisition-row-${first.requisition_id ?? 0}`)).toContainText(first.title ?? '')
    const text = await page.getByTestId(`requisition-row-${first.requisition_id ?? 0}`).textContent()
    expect(text ?? '').toMatch(/草稿|招聘中|已暂停|已关闭/)
    expect(text ?? '').not.toContain('¥NaN')
  })

  test('creates a synthetic requisition in the real drawer and surfaces either a draft or approval status', async ({ page }) => {
    await page.goto('/recruitment/requests', { waitUntil: 'load' })

    const create = page.getByTestId('requisition-create')
    const visible = await create.isVisible().catch(() => false)
    test.skip(!visible, 'demo.hr has no recruitment.requisition.manage grant in this environment')

    await create.click()
    const drawer = page.getByTestId('requisition-drawer')
    await expect(drawer).toBeVisible()
    await expect(drawer.getByText('新建招聘需求')).toBeVisible()

    const suffix = String(Date.now()).slice(-6)
    const title = `合成招聘岗位 ${suffix}`
    await drawer.getByTestId('requisition-title').fill(title)
    await page.getByTestId('requisition-headcount').fill('1')
    await page.getByTestId('requisition-budget-minor').fill('2500050')

    const saveResponse = page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname === '/v1/recruitment/requisitions'
        && response.request().method() === 'POST',
    )
    await page.getByTestId('requisition-save').click()
    const response = await saveResponse
    expect([201, 409, 422]).toContain(response.status())

    if (response.status() !== 201) {
      await expect(drawer).toBeVisible()
      return
    }

    await expect(page.getByText(title)).toBeVisible()
    const row = page.locator('[data-testid^="requisition-row-"]').filter({ hasText: title })
    await expect(row).toBeVisible()
    await expect(row).toContainText('招聘中')
    await expect(row).toContainText('¥25,000.50')
  })

  test('renders the real candidate pipeline with requisition filter and no fabricated rows', async ({ page }) => {
    const candidateResponse = page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname === '/v1/recruitment/candidates'
        && response.request().method() === 'GET',
    )

    await page.goto('/recruitment/candidates', { waitUntil: 'load' })
    const response = await candidateResponse
    expect(response.status(), 'candidate list must be readable for demo.hr').toBe(200)

    const payload = (await response.json()) as { data?: { items?: CandidateRow[] } }
    const rows = Array.isArray(payload.data?.items) ? payload.data.items : []

    await expect(page.getByRole('heading', { name: '候选人管道', level: 1 })).toBeVisible()
    await expect(page.locator('[data-testid="candidates-forbidden"]')).toHaveCount(0)

    for (const stage of ['NEW', 'SCREENING', 'INTERVIEW', 'OFFER', 'HIRED', 'REJECTED']) {
      await expect(page.getByTestId(`pipeline-column-${stage}`)).toBeVisible()
    }

    if (rows.length === 0) {
      await expect(page.getByText('还没有候选人')).toBeVisible()
      return
    }

    const first = rows[0]
    await expect(page.getByTestId(`pipeline-card-${first.candidate_id ?? 0}`)).toContainText(first.display_name ?? '')
    await expect(page.getByTestId('candidate-requisition-filter')).toBeVisible()
  })

  test('opens a candidate detail and, when an accepted offer is present, exposes the conversion contract without sent tenant fields', async ({ page }) => {
    const listResponse = await page.request.get('/v1/recruitment/candidates?page=1&page_size=100', {
      headers: { Accept: 'application/json' },
    })
    expect(listResponse.status()).toBe(200)
    const payload = (await listResponse.json()) as { data?: { items?: CandidateRow[] } }
    const candidates = Array.isArray(payload.data?.items) ? payload.data.items : []
    const candidate = candidates.find((row) => row.status === 'OFFER_ACCEPTED') ?? candidates[0]
    test.skip(candidate?.candidate_id === undefined, 'the demo tenant has no candidate to open')

    await page.goto(`/recruitment/candidates/${candidate?.candidate_id ?? 0}`, { waitUntil: 'load' })
    await expect(page.getByRole('heading', { name: '候选人档案', level: 1 })).toBeVisible()
    await expect(page.getByTestId('candidate-profile')).toBeVisible()
    await expect(page.getByTestId('candidate-offers')).toBeVisible()
    await expect(page.getByTestId('candidate-interviews')).toBeVisible()
    await expect(page.locator('[data-testid="candidate-detail-forbidden"]')).toHaveCount(0)

    const convert = page.getByTestId('candidate-convert')
    const canConvert = await convert.isVisible().catch(() => false)
    if (!canConvert) return

    await convert.click()
    const dialog = page.getByTestId('convert-dialog')
    await expect(dialog).toBeVisible()
    await expect(dialog).toContainText('目标组织单元')
    await expect(dialog).toContainText('员工编号')
    await expect(dialog.getByTestId('convert-org-unit')).toBeVisible()
    await expect(dialog.getByTestId('convert-employee-no')).toBeVisible()

    const convertResponse = page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname.endsWith('/convert')
        && response.request().method() === 'POST',
      { timeout: 5_000 },
    ).catch(() => null)

    await dialog.getByTestId('convert-employee-no').fill('')
    await dialog.getByTestId('convert-submit').click()
    await expect(dialog).toContainText('请填写员工编号')
    expect(await convertResponse).toBeNull()
  })

  test('deep link to an unknown candidate shows the honest not-found state', async ({ page }) => {
    await page.goto('/recruitment/candidates/999999999', { waitUntil: 'load' })
    await expect(
      page.locator('[data-testid="candidate-detail-forbidden"]').or(page.getByText('未找到该候选人')),
    ).toBeVisible({ timeout: 20_000 })
    await expect(page.getByTestId('candidate-profile')).toHaveCount(0)
  })
})
