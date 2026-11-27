### Task 12: 薪资管理

**Files:**
- Create: `frontend/src/modules/payroll/pages/PayrollWorkspacePage.vue`
- Create: `frontend/src/modules/payroll/pages/PayrollRunDetailPage.vue`
- Create: `frontend/src/modules/payroll/components/PayrollRunTable.vue`
- Create: `frontend/src/modules/payroll/components/PayrollActionBar.vue`
- Create: `frontend/src/modules/payroll/components/PayslipDrawer.vue`
- Modify: `backend/app/Modules/Payroll/Http/Controllers/PayrollRunController.php`
- Test: `backend/tests/Feature/Payroll/PayrollWorkspaceTest.php`
- Test: `frontend/tests/e2e/real/payroll-workspace.spec.ts`

**Interfaces:**
- Consumes: 工资批次、输入、试算、复核、发布、发放、导出、工资条 API。
- Produces: `/payroll` 工作台 + `/payroll/runs/:id` 详情；查看/核算/复核/发布/导出分别独立授权；金额仅整数最小单位。

- [ ] **Step 1: 写能力分离测试**

```php
public function test_view_only_payroll_user_cannot_prepare_or_publish(): void
{
    $run = $this->draftPayrollRun();
    $user = $this->userWithRole('PAYROLL_VIEWER');

    $this->actingAs($user)->getJson("/v1/payroll/runs/{$run->id}")->assertOk();
    $this->actingAs($user)->postJson("/v1/payroll/runs/{$run->id}/prepare")->assertStatus(403);
    $this->actingAs($user)->postJson("/v1/payroll/runs/{$run->id}/publish")->assertStatus(403);
}
```

- [ ] **Step 2: 写金额与字段测试**

```php
public function test_payslip_amounts_are_integer_minor_units_and_masked_without_p2(): void
{
    $run = $this->publishedPayrollRun();
    $response = $this->actingAs($this->payrollSpecialist())->getJson("/v1/payroll/runs/{$run->id}/payslips");

    $response->assertOk()->assertJsonStructure(['data' => ['items' => [['gross_minor', 'net_minor']]]]);
    $this->assertIsInt($response->json('data.items.0.gross_minor'));
}
```

- [ ] **Step 3: 实现工作台**

- 列表显示期间、批次、状态、人数、总额（中文金额格式）。
- 详情显示输入、计算、复核、发布、发放、工资条。
- 每个动作独立 `ActionGate`；发布/发放二次确认；导出走独立能力。
- 工资条抽屉按字段策略遮罩；无权限不返回金额。

- [ ] **Step 4: E2E**

```ts
test('payroll specialist prepares and reviews a run', async ({ page }) => {
  await loginAs(page, 'demo.payroll')
  await page.goto('/payroll')
  await page.getByRole('row').filter({ hasText: '2026-11' }).getByRole('button', { name: '试算' }).click()
  await expect(page.getByText('已试算')).toBeVisible()
})
```

- [ ] **Step 5: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter='PayrollWorkspaceTest|PayrollRunTest|Payslip'
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/payroll-workspace.spec.ts
```

Expected: 查看/试算/复核/发布/导出权限分离；金额为整数；字段遮罩正确。

- [ ] **Step 6: Commit**

```bash
git add frontend/src/modules/payroll/pages/PayrollWorkspacePage.vue frontend/src/modules/payroll/pages/PayrollRunDetailPage.vue frontend/src/modules/payroll/components/PayrollRunTable.vue frontend/src/modules/payroll/components/PayrollActionBar.vue frontend/src/modules/payroll/components/PayslipDrawer.vue backend/app/Modules/Payroll/Http/Controllers/PayrollRunController.php backend/tests/Feature/Payroll/PayrollWorkspaceTest.php frontend/tests/e2e/real/payroll-workspace.spec.ts
git commit -m "feat(payroll): rebuild payroll workspace with separated actions"
```

---

