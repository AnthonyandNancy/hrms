### Task 11: 加班与出差

**Files:**
- Create: `frontend/src/modules/time/overtime/pages/OvertimeWorkspacePage.vue`
- Create: `frontend/src/modules/time/overtime/components/OvertimeRequestDrawer.vue`
- Create: `frontend/src/modules/time/travel/pages/TravelWorkspacePage.vue`
- Create: `frontend/src/modules/time/travel/components/TravelRequestDrawer.vue`
- Modify: `backend/app/Modules/Time/Http/Controllers/OvertimeRequestController.php`
- Modify: `backend/app/Modules/Time/Http/Controllers/TravelController.php`
- Test: `backend/tests/Feature/Time/OvertimeTravelWorkspaceTest.php`
- Test: `frontend/tests/e2e/real/overtime-travel.spec.ts`

**Interfaces:**
- Consumes: 加班申请/确认/调休、出差申请/审批/费用、政策与期间 API。
- Produces: 加班工作台与出差工作台；申请抽屉；审批与费用入口；时间/政策按公司配置。

- [ ] **Step 1: 写加班调休与出差政策测试**

```php
public function test_overtime_settlement_creates_time_off_ledger_entry(): void
{
    $overtime = $this->approvedOvertime(hours: 2);
    $this->actingAs($this->hrAdmin())
        ->postJson("/v1/overtime/records/{$overtime->id}/settlements", ['mode' => 'TIME_OFF'])
        ->assertOk();

    $this->assertDatabaseHas('leave_ledger_entries', ['source' => 'OVERTIME', 'minutes' => 120]);
}
```

- [ ] **Step 2: 实现两个工作台**

- 加班：申请、确认、调休结算、政策查看；审批独立授权。
- 出差：申请、审批、行程/费用；政策中的法人字段删除，使用公司配置。
- 两者都不在页面内嵌入薪资或请假编辑器。

- [ ] **Step 3: E2E**

```ts
test('employee submits overtime and sees confirmation status', async ({ page }) => {
  await loginAs(page, 'demo.employee')
  await page.goto('/overtime')
  await page.getByRole('button', { name: '申请加班' }).click()
  await page.getByRole('button', { name: '提交' }).click()
  await expect(page.getByText('待确认')).toBeVisible()
})
```

- [ ] **Step 4: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=OvertimeTravelWorkspaceTest
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/overtime-travel.spec.ts
```

Expected: 加班调休账本、出差政策、审批权限通过；无租户/法人字段。

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/time/overtime/pages/OvertimeWorkspacePage.vue frontend/src/modules/time/overtime/components/OvertimeRequestDrawer.vue frontend/src/modules/time/travel/pages/TravelWorkspacePage.vue frontend/src/modules/time/travel/components/TravelRequestDrawer.vue backend/app/Modules/Time/Http/Controllers/OvertimeRequestController.php backend/app/Modules/Time/Http/Controllers/TravelController.php backend/tests/Feature/Time/OvertimeTravelWorkspaceTest.php frontend/tests/e2e/real/overtime-travel.spec.ts
git commit -m "feat(time): rebuild overtime and travel workspaces"
```

---

