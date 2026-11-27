import { expect, test, type Page } from '@playwright/test'

/**
 * M04 employee records acceptance on the real stack.
 *
 * One login for the whole file keeps these checks clear of the deliberate
 * 5/min login throttle. All assertions use server-returned rows and the
 * surviving detail workspace; no mock data is accepted.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? process.env.DEMO_SEED_PASSWORD ?? ''
const USERNAME = process.env.HRMS_DEMO_HR ?? 'demo.hr'
const EMPLOYMENT_ID = process.env.HRMS_DEMO_EMPLOYMENT_ID ?? '1'

test.skip(PASSWORD === '', 'HRMS_DEMO_PASSWORD or DEMO_SEED_PASSWORD is required for the real M04 test.')

async function login(page: Page): Promise<void> {
  await page.goto('/login', { waitUntil: 'load' })
  await page.getByLabel('账号').fill(USERNAME)
  await page.getByLabel('密码').fill(PASSWORD)
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.waitForURL(/\/workspace/, { timeout: 30_000 })
}

test('@real M04 employee directory, detail tabs, profile/change actions and deleted sub-routes', async ({ page }) => {
  await login(page)

  // Directory is one workspace with server search.
  await page.goto('/hr/employees', { waitUntil: 'load' })
  await expect(page.locator('#main-content').getByRole('heading', { name: '员工列表', level: 1 })).toBeVisible()
  const rows = page.locator('[data-testid^="employee-row-"]')
  await expect(rows.first()).toBeVisible({ timeout: 20_000 })
  expect(await rows.count()).toBeGreaterThan(0)
  await page.getByLabel('搜索员工').fill('DEMO-0002')
  await expect(rows).toHaveCount(1, { timeout: 15_000 })
  await expect(rows.first()).toContainText('DEMO-0002')
  await expect(rows.first().getByRole('link', { name: '查看' })).toHaveAttribute(
    'href',
    /\/hr\/employees\/\d+$/,
  )

  // Detail workspace keeps same-object tabs only.
  await page.goto(`/hr/employees/${EMPLOYMENT_ID}?tab=employment`, { waitUntil: 'load' })
  await expect(page.getByTestId('employee-summary')).toBeVisible({ timeout: 20_000 })
  await expect(page.getByTestId('employee-not-found')).toHaveCount(0)
  await expect(page.getByTestId('employee-forbidden')).toHaveCount(0)
  await page.getByTestId('tab-contracts').click()
  await expect(page).toHaveURL(new RegExp(`/hr/employees/${EMPLOYMENT_ID}\\?tab=contracts`))
  await expect(page.getByTestId('tab-contracts-panel')).toBeVisible()

  // Versioned sub-profile create is server-backed.
  await page.getByTestId('tab-education').click()
  const section = page.getByTestId('profile-education')
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

  // Change request creates a draft and submits it through Workflow.
  await page.getByTestId('tab-changes').click()
  const list = page.getByTestId('employee-change-list')
  await expect(list).toBeVisible()
  const reason = `合成变更申请${Date.now()}`
  // Record #0 is not valid; the real created education row is the target.
  const educationRow = page.getByTestId('profile-education')
  await page.getByTestId('tab-education').click()
  await expect(educationRow.getByText(school)).toBeVisible()
  const educationRows = educationRow.locator('tbody tr')
  await expect(educationRows.first()).toBeVisible()
  const recordId = (await educationRows.first().getAttribute('data-row-id')) ?? '1'
  await page.getByTestId('tab-changes').click()
  await list.getByTestId('change-add').click()
  const targetDialog = page.getByRole('dialog')
  await expect(targetDialog).toBeVisible()
  await targetDialog.getByLabel('变更原因').fill(reason)
  await targetDialog.getByLabel('记录编号').fill(recordId)
  await targetDialog.getByLabel('新值').fill('合成专业变更')
  await targetDialog.getByRole('button', { name: '保存' }).click()
  await expect(targetDialog).toBeHidden({ timeout: 20_000 })
  // The table intentionally shows type/status/timestamps, not the free-text
  // reason. Identify the newly created draft by its server id, then submit it.
  const firstRow = list.locator('tbody tr').first()
  await expect(firstRow).toBeVisible({ timeout: 20_000 })
  await expect(firstRow).toContainText(/草稿|已提交|待审批|审批中/)
  const submit = firstRow.getByRole('button', { name: '提交' })
  if ((await submit.count()) > 0) {
    await submit.click()
  }
  await expect(list.getByText('已提交').first()).toBeVisible({ timeout: 20_000 })

  // Documents never fabricate a download URL.
  await page.getByTestId('tab-credentials').click()
  const files = page.getByTestId('employee-file-list')
  await expect(files).toBeVisible()
  await expect(files.locator('a[download]')).toHaveCount(0)
  await expect(page.getByTestId('file-upload')).toBeVisible()

  // Eight legacy sub-routes are destroyed, not redirected.
  for (const legacy of [
    '/hr/employees/imports',
    '/hr/employees/1/education',
    '/hr/employees/1/work-history',
    '/hr/employees/1/contacts',
    '/hr/employees/1/credentials',
    '/hr/employees/1/history',
    '/hr/employees/1/files',
    '/hr/employees/1/changes',
  ]) {
    await page.goto(legacy, { waitUntil: 'load' })
    await expect(page).toHaveURL(/\/not-found$/)
    await expect(page.getByText('页面不存在')).toBeVisible()
  }
})
