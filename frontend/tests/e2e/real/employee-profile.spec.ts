import { expect, test, type Page } from '@playwright/test'

/**
 * Real-stack employee sub-profile, attachments and change-request journey.
 *
 * The demo password is never committed. Both operator-facing variable names
 * are honoured because the local demo runner and verify_real_stack historically
 * used different names for the same seeded password.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? process.env.DEMO_SEED_PASSWORD ?? ''
const USERNAME = process.env.HRMS_DEMO_HR ?? 'demo.hr'
const EMPLOYMENT_ID = process.env.HRMS_DEMO_EMPLOYMENT_ID ?? '1'

test.skip(
  PASSWORD === '',
  'HRMS_DEMO_PASSWORD or DEMO_SEED_PASSWORD is required for the real employee profile journey.',
)

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

test.describe('@real employee profile workspace', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  test('HR adds an education record with effective dates and sees it in the real list', async ({ page }) => {
    await page.goto(`/hr/employees/${EMPLOYMENT_ID}?tab=education`, { waitUntil: 'load' })
    await expect(page.getByTestId('employee-summary')).toBeVisible({ timeout: 20_000 })
    await expect(page.getByTestId('employee-not-found')).toHaveCount(0)
    await expect(page.getByTestId('employee-forbidden')).toHaveCount(0)

    const section = page.getByTestId('profile-section-education')
    await expect(section).toBeVisible()

    const school = `合成大学${Date.now()}`
    await section.getByTestId('profile-add').click()
    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()
    await dialog.getByLabel('学校').fill(school)
    await dialog.getByLabel('学历').selectOption('BACHELOR')
    await dialog.getByLabel('开始日期').fill('2010-09-01')
    await dialog.getByRole('button', { name: '保存' }).click()

    await expect(dialog).toBeHidden({ timeout: 20_000 })
    await expect(section.getByText(school)).toBeVisible({ timeout: 20_000 })
  })

  test('HR creates a change-request draft and submits it; submitted requests are read-only', async ({ page }) => {
    await page.goto(`/hr/employees/${EMPLOYMENT_ID}?tab=changes`, { waitUntil: 'load' })
    await expect(page.getByTestId('employee-summary')).toBeVisible({ timeout: 20_000 })

    const list = page.getByTestId('employee-change-list')
    await expect(list).toBeVisible()
    await list.getByTestId('change-add').click()

    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()
    const reason = `合成变更申请${Date.now()}`
    await dialog.getByLabel('变更原因').fill(reason)
    await dialog.getByRole('button', { name: '保存' }).click()
    await expect(dialog).toBeHidden({ timeout: 20_000 })

    // The draft row is real server data, and its submit action is offered only
    // while the status is DRAFT.
    const draftRow = list.locator('tr', { hasText: reason })
    await expect(draftRow).toBeVisible({ timeout: 20_000 })
    const submit = draftRow.getByRole('button', { name: '提交' })
    await expect(submit).toBeVisible()
    await submit.click()

    await expect(list.getByText('已提交').first()).toBeVisible({ timeout: 20_000 })
    await expect(draftRow.getByRole('button', { name: '提交' })).toHaveCount(0)
  })

  test('file workspace never fabricates a download URL and uploads only when allowed', async ({ page }) => {
    await page.goto(`/hr/employees/${EMPLOYMENT_ID}?tab=credentials`, { waitUntil: 'load' })
    await expect(page.getByTestId('employee-summary')).toBeVisible({ timeout: 20_000 })

    const files = page.getByTestId('employee-file-list')
    await expect(files).toBeVisible()

    // The UI never renders a synthetic download anchor; an authorized download
    // is started from the short-lived URL returned by the signed-download API.
    await expect(files.locator('a[download]')).toHaveCount(0)

    // The upload control is present only behind people.document.read; this
    // seeded HR operator holds that capability.
    const upload = page.getByTestId('file-upload')
    await expect(upload).toBeVisible()
    await expect(page.getByTestId('file-upload-input')).toBeVisible()
    await expect(page.getByTestId('file-upload-submit')).toBeVisible()
  })
})
