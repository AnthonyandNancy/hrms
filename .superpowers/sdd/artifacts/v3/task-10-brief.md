### Task 10: 请假管理

**Files:**
- Create: `frontend/src/modules/time/leave/pages/LeaveWorkspacePage.vue`
- Create: `frontend/src/modules/time/leave/components/LeaveRequestDrawer.vue`
- Create: `frontend/src/modules/time/leave/components/LeaveBalancePanel.vue`
- Modify: `backend/app/Modules/Time/Http/Controllers/LeaveController.php`
- Test: `backend/tests/Feature/Time/LeaveWorkspaceTest.php`
- Test: `frontend/tests/e2e/real/leave-workspace.spec.ts`

**Interfaces:**
- Consumes: 假勤类型、余额、申请、撤回、审批、日历、报表 API。
- Produces: 我的请假/团队请假工作台；申请抽屉；余额面板；审批状态；无跨模块 Tab。

- [ ] **Step 1: 写余额与日期冲突测试**

```php
public function test_leave_request_rejects_insufficient_balance(): void
{
    $this->actingAs($this->employee())
        ->postJson('/v1/leave/requests', [
            'leave_type_id' => $this->annualLeave()->id,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-31',
        ])
        ->assertStatus(422);
}
```

- [ ] **Step 2: 实现工作台**

- 我的请假：申请、撤回、查看余额与审批进度。
- 团队请假：按数据范围查看，审批动作独立授权。
- 日期按公司日历计算工作日；半天/小时假按既有规则。
- 余额不足、日期冲突、跨关账期返回可读中文错误。

- [ ] **Step 3: E2E**

```ts
test('employee submits leave and sees pending status', async ({ page }) => {
  await loginAs(page, 'demo.employee')
  await page.goto('/leave')
  await page.getByRole('button', { name: '申请请假' }).click()
  await page.getByLabel('假期类型').click()
  await page.getByRole('option', { name: '年假' }).click()
  await page.getByRole('button', { name: '提交' }).click()
  await expect(page.getByText('待审批')).toBeVisible()
})
```

- [ ] **Step 4: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=LeaveWorkspaceTest
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/leave-workspace.spec.ts
```

Expected: 余额校验、日期冲突、审批流程、自助范围通过。

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/time/leave/pages/LeaveWorkspacePage.vue frontend/src/modules/time/leave/components/LeaveRequestDrawer.vue frontend/src/modules/time/leave/components/LeaveBalancePanel.vue backend/app/Modules/Time/Http/Controllers/LeaveController.php backend/tests/Feature/Time/LeaveWorkspaceTest.php frontend/tests/e2e/real/leave-workspace.spec.ts
git commit -m "feat(time): rebuild leave workspace"
```

---

