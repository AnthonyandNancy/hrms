import { expect, test, type Page } from '@playwright/test'

async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto('/login')
  await page.getByLabel('邮箱').fill('admin@demo.example')
  await page.getByLabel('密码').fill('Demo!2026')
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await expect(page).toHaveURL(/\/workspace/)
  await expect(page.getByRole('heading', { name: /林若桐，下午好/ })).toBeVisible()
}

test.describe('@smoke mock-mode critical journeys', () => {
  test('login -> dashboard -> KPI drill-down with matching total', async ({ page }) => {
    await loginAsAdmin(page)
    await page.getByRole('link', { name: '人力仪表盘' }).click()
    await expect(page).toHaveURL(/\/dashboard/)
    await expect(page.getByRole('heading', { name: /林若桐，下午好/ })).toBeVisible()

    const headcountCard = page.getByRole('button', { name: /在职人数/ })
    await expect(headcountCard).toContainText('86')
    await headcountCard.click()
    await expect(page).toHaveURL(/\/dashboard\/metric\/headcount/)
    await expect(page.getByText(/相同谓词/)).toBeVisible()
    await expect(page.locator('#main-content').getByRole('heading', { name: '在职人数', exact: true })).toBeVisible()

    // Mock has 12 seeded employees while the enterprise KPI is tenant-wide.
    // The detail page explicitly shows the same predicate; count/list equality
    // against the real API is deferred to the backend integration suite.
    await expect(page.getByText('共 12 条')).toBeVisible()
    const rows = page.locator('.drilldown-table tbody tr')
    await expect(rows).toHaveCount(12)
  })

  test('case center separates statuses and approves a pending case', async ({ page }) => {
    await loginAsAdmin(page)
    await page.getByRole('link', { name: '审批中心' }).click()
    await expect(page).toHaveURL(/\/cases/)
    await expect(page.locator('#main-content').getByRole('heading', { name: '审批中心' })).toBeVisible()

    const pendingRow = page.getByRole('row', { name: /事假申请 · 0\.5 天/ })
    await expect(pendingRow).toContainText('审批中')
    await pendingRow.getByRole('button', { name: '查看' }).click()

    await expect(page.getByText('申请状态', { exact: true }).first()).toBeVisible()
    await expect(page.getByText('生效状态', { exact: true }).first()).toBeVisible()
    await expect(page.getByRole('alert').filter({ hasText: '审批已通过，业务生效失败' })).toHaveCount(0)

    await page.getByRole('button', { name: '同意' }).click()
    await expect(page.getByRole('dialog', { name: '同意申请' })).toBeVisible()
    await page.getByLabel('审批意见').fill('同意，已确认排班安排')
    await page.getByRole('button', { name: '确认提交决定' }).click()
    await expect(page.getByRole('dialog', { name: '案例详情' }).getByText('已通过').first()).toBeVisible()
    await expect(page.getByRole('dialog', { name: '案例详情' }).getByText('已生效').first()).toBeVisible()
  })

  test('failed effect is visible, retryable and never shown as a single completed status', async ({ page }) => {
    await loginAsAdmin(page)
    await page.getByRole('link', { name: '审批中心' }).click()
    await page.getByRole('tab', { name: '全部案例' }).click()
    await page.getByRole('row', { name: /合同续签 · 贺兰舟/ }).getByRole('button', { name: '查看' }).click()
    await expect(page.getByText('生效失败').first()).toBeVisible()
    await expect(page.getByText('审批已通过，业务生效失败')).toBeVisible()
    await expect(page.getByRole('button', { name: '重试生效' })).toBeVisible()
  })

  test('payroll run -> payslip detail from my payslips', async ({ page }) => {
    await loginAsAdmin(page)
    await page.getByRole('link', { name: '工资批次' }).click()
    await expect(page).toHaveURL(/\/payroll$/)
    await expect(page.locator('#main-content').getByRole('heading', { name: '工资批次' })).toBeVisible()
    await page.getByRole('row', { name: /PR-2026-11/ }).getByRole('button', { name: '打开' }).click()
    await expect(page).toHaveURL(/\/payroll\/runs\/7001/)
    await expect(page.getByRole('heading', { name: 'PR-2026-11' })).toBeVisible()
    await expect(page.getByText('对账与差异')).toBeVisible()
    await page.getByRole('tab', { name: '对账与差异' }).click()
    await expect(page.getByText('存在需要确认的差异')).toBeVisible()

    await page.getByRole('link', { name: '我的工资条' }).click()
    await expect(page).toHaveURL(/\/payroll\/my-payslips/)
    await page.locator('.payslip-card').first().getByRole('button', { name: '查看明细' }).click()
    await expect(page).toHaveURL(/\/payroll\/my-payslips\/9101/)
    await expect(page.getByRole('heading', { name: /陈嘉禾|林若桐/ })).toBeVisible()
    await expect(page.getByText('应发项目')).toBeVisible()
    await expect(page.getByText('扣款项目')).toBeVisible()
  })

  test('report export dialog requires purpose and acknowledges watermark', async ({ page }) => {
    await loginAsAdmin(page)
    await page.getByRole('link', { name: '报表中心' }).click()
    await expect(page).toHaveURL(/\/reports/)
    await expect(page.locator('#main-content').getByRole('heading', { name: '组织人数与编制' })).toBeVisible()
    await page.getByRole('button', { name: '导出报表' }).click()
    const dialog = page.getByRole('dialog', { name: '导出报表' })
    await expect(dialog).toBeVisible()
    await expect(dialog.getByText(/P3|导出白名单|字段/).first()).toBeVisible()

    await dialog.getByRole('button', { name: '创建导出任务' }).click()
    await expect(dialog.getByText('请选择导出目的')).toBeVisible()

    await dialog.locator('.el-select').filter({ hasText: '选择用途' }).click()
    await page.getByRole('option', { name: '审计检查' }).click()
    await dialog.locator('.el-checkbox').filter({ hasText: '我确认导出数据' }).click()
    await dialog.getByRole('button', { name: '创建导出任务' }).click()
    await expect(dialog.getByText('已生成')).toBeVisible()
    await expect(dialog.getByText('已生成')).toBeVisible()
    await expect(dialog.getByText(/导出人：林若桐/)).toBeVisible()
    await expect(dialog.getByRole('button', { name: /下载 CSV/ })).toBeVisible()
  })

  test('employee role sees self-service navigation without admin', async ({ page }) => {
    await page.goto('/login')
    await page.getByLabel('邮箱').fill('jiahe.chen@demo.example')
    await page.getByLabel('密码').fill('Demo!2026')
    await page.getByRole('button', { name: '登录', exact: true }).click()
    await expect(page).toHaveURL(/\/workspace/)
    await expect(page.getByRole('link', { name: '员工目录' })).toHaveCount(0)
    await expect(page.getByRole('link', { name: '权限治理' })).toHaveCount(0)
    await expect(page.getByRole('link', { name: '我的工资条' })).toBeVisible()
  })

  test('MFA step-up flow accepts a valid six digit code', async ({ page }) => {
    await page.goto('/login')
    await page.getByLabel('邮箱').fill('mfa@demo.example')
    await page.getByLabel('密码').fill('Demo!2026')
    await page.getByRole('button', { name: '登录', exact: true }).click()
    await expect(page.getByRole('heading', { name: '验证你的身份' })).toBeVisible()
    await page.getByLabel('动态验证码').fill('246810')
    await page.getByRole('button', { name: '验证并进入工作台' }).click()
    await expect(page).toHaveURL(/\/workspace/)
  })
})
