import { expect, test, type Page } from '@playwright/test'

/**
 * Real-stack self-service portal.
 *
 * The demo employee must see only their own profile, their own tasks (or the
 * explicit capability explanation) and self-service entries. It must not see
 * an employee picker or an admin menu inside the page. The password is never
 * committed; without the seeded demo password the suite is skipped.
 */
const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? process.env.DEMO_SEED_PASSWORD ?? ''
const EMPLOYEE_USER = process.env.HRMS_DEMO_EMPLOYEE ?? 'demo.employee'

test.skip(
  PASSWORD === '',
  'HRMS_DEMO_PASSWORD or DEMO_SEED_PASSWORD is required for the real self-service journey.',
)

async function login(page: Page): Promise<void> {
  await page.goto('/login', { waitUntil: 'load' })
  await page.getByLabel('账号').fill(EMPLOYEE_USER)
  await page.getByLabel('密码').fill(PASSWORD)
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.waitForURL(/\/workspace/, { timeout: 30_000 })
}

test.describe('@real self-service portal', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  test('employee sees only self-service entries for their own identity', async ({ page }) => {
    const meResponse = await page.request.get('/v1/me', { headers: { Accept: 'application/json' } })
    expect(meResponse.status()).toBe(200)
    const me = (await meResponse.json()) as { data?: { employment_id?: number | null; username?: string } }

    const workspaceResponse = await page.request.get('/v1/me/workspace', {
      headers: { Accept: 'application/json' },
    })
    expect(workspaceResponse.status()).toBe(200)
    const workspace = (await workspaceResponse.json()) as {
      data?: {
        employment_linked?: boolean
        profile?: { employmentId?: number | null; employeeNo?: string; name?: string }
      }
    }

    // The projection is the caller's own employment, never a request-selected id.
    expect(workspace.data?.profile?.employmentId ?? null).toBe(me.data?.employment_id ?? null)
    expect(workspace.data?.profile?.employeeNo ?? '').not.toBe('')

    await page.goto('/self-service', { waitUntil: 'load' })

    await expect(page.getByRole('heading', { name: '员工自助门户', level: 1 })).toBeVisible()
    await expect(page.getByTestId('my-profile')).toBeVisible()
    await expect(page.getByTestId('my-tasks')).toBeVisible()
    await expect(page.getByTestId('self-entries')).toBeVisible()

    await expect(page.getByTestId('self-entry-attendance')).toBeVisible()
    await expect(page.getByTestId('self-entry-leave')).toBeVisible()
    await expect(page.getByTestId('self-entry-overtime')).toBeVisible()
    await expect(page.getByTestId('self-entry-training')).toBeVisible()
    await expect(page.getByTestId('self-entry-payslips')).toBeVisible()

    // No employee picker and no admin operations inside the page.
    const main = page.locator('#main-content')
    await expect(main.getByLabel(/选择员工|员工选择器/)).toHaveCount(0)
    await expect(main.getByRole('link', { name: '用户管理' })).toHaveCount(0)
    await expect(main.getByRole('link', { name: '角色管理' })).toHaveCount(0)
    await expect(main.getByText('系统管理')).toHaveCount(0)

    // Either the caller sees only their tasks, or the page explains the missing
    // workflow capability instead of presenting a dead link.
    const taskItems = page.locator('[data-testid^="self-task-"]')
    const taskCount = await taskItems.count()
    if (taskCount === 0) {
      await expect(page.getByTestId('tasks-unavailable').or(page.getByText('当前没有待处理任务'))).toBeVisible()
    } else {
      await expect(page.getByTestId('my-tasks')).toContainText('截止时间：')
    }
  })
})
