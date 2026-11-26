### Task 8: 劳动合同

**Files:**
- Create: `frontend/src/modules/people/contracts/pages/ContractWorkspacePage.vue`
- Create: `frontend/src/modules/people/contracts/pages/ContractDetailPage.vue`
- Create: `frontend/src/modules/people/contracts/components/ContractFormDrawer.vue`
- Create: `frontend/src/modules/people/contracts/components/ContractVersionList.vue`
- Modify: `backend/app/Modules/People/Http/Controllers/EmploymentContractController.php`
- Test: `backend/tests/Feature/People/ContractWorkspaceTest.php`
- Test: `frontend/tests/e2e/real/contract-workspace.spec.ts`

**Interfaces:**
- Consumes: 合同草稿、版本、续签、变更、终止、到期预警、模板 API。
- Produces: `/hr/contracts` 工作台 + `/hr/contracts/:id` 详情；草稿/续签/终止动作；到期筛选。

- [ ] **Step 1: 写合同版本与终止测试**

```php
public function test_terminating_contract_keeps_versions_and_sets_end_date(): void
{
    $contract = $this->activeContract();
    $this->actingAs($this->hrAdmin())
        ->postJson("/v1/employment-contracts/{$contract->id}/terminate", ['end_date' => '2026-12-31', 'reason' => '合成测试'])
        ->assertOk();

    $this->assertDatabaseCount('employment_contract_versions', 2);
    $this->assertSame('2026-12-31', $contract->fresh()->end_date->toDateString());
}
```

- [ ] **Step 2: 实现工作台**

- 列表显示员工、合同类型、起止日、状态、到期提醒。
- 新增/续签/变更/终止使用抽屉；详情显示版本历史与附件。
- 到期预警作为筛选或详情提示，不单独注册菜单。
- 合同模板放在治理区，由 `people.contract.template.manage` 控制。

- [ ] **Step 3: E2E**

```ts
test('HR creates a contract draft and renews it', async ({ page }) => {
  await loginAs(page, 'demo.hr')
  await page.goto('/hr/contracts')
  await page.getByRole('button', { name: '新建合同' }).click()
  await page.getByLabel('员工').fill('合成员工')
  await page.getByRole('button', { name: '保存草稿' }).click()
  await expect(page.getByText('草稿')).toBeVisible()
})
```

- [ ] **Step 4: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=ContractWorkspaceTest
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/contract-workspace.spec.ts
```

Expected: 版本保留、终止日期、到期筛选、权限控制通过。

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/people/contracts/pages/ContractWorkspacePage.vue frontend/src/modules/people/contracts/pages/ContractDetailPage.vue frontend/src/modules/people/contracts/components/ContractFormDrawer.vue frontend/src/modules/people/contracts/components/ContractVersionList.vue backend/app/Modules/People/Http/Controllers/EmploymentContractController.php backend/tests/Feature/People/ContractWorkspaceTest.php frontend/tests/e2e/real/contract-workspace.spec.ts
git commit -m "feat(people): rebuild contract workspace"
```

---

