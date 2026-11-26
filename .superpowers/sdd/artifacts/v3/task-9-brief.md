### Task 9: 考勤打卡

**Files:**
- Create: `frontend/src/modules/time/attendance/pages/AttendanceWorkspacePage.vue`
- Create: `frontend/src/modules/time/attendance/components/AttendanceFilter.vue`
- Create: `frontend/src/modules/time/attendance/components/AttendanceTable.vue`
- Create: `frontend/src/modules/time/attendance/components/CorrectionDialog.vue`
- Modify: `backend/app/Modules/Time/Http/Controllers/AttendanceController.php`
- Test: `backend/tests/Feature/Time/AttendanceWorkspaceTest.php`
- Test: `frontend/tests/e2e/real/attendance-workspace.spec.ts`

**Interfaces:**
- Consumes: 打卡记录、考勤规则、班次、异常、更正、关账 API。
- Produces: 我的打卡/团队打卡/异常/更正工作台；规则与班次在治理页；页面内不混入请假/加班。

- [ ] **Step 1: 写更正权限与关账测试**

```php
public function test_correction_is_rejected_after_period_close(): void
{
    $this->closePeriod('2026-11');
    $this->actingAs($this->hrAdmin())
        ->postJson('/v1/time/attendance/corrections', ['employee_id' => $this->employee()->id, 'date' => '2026-11-15'])
        ->assertStatus(409);
}
```

- [ ] **Step 2: 实现工作台**

- 顶部筛选：日期范围、员工/部门（按范围）、异常类型。
- 表格显示日期、上班/下班、工时、异常、状态；时间按公司时区。
- 更正使用确认弹窗，必须填原因；关账后禁止更正。
- 班次/规则为独立治理页，不在考勤列表内以 Tab 混入。

- [ ] **Step 3: E2E**

```ts
test('HR corrects an attendance exception and sees Chinese status', async ({ page }) => {
  await loginAs(page, 'demo.hr')
  await page.goto('/attendance')
  await page.getByRole('button', { name: '补卡' }).first().click()
  await page.getByLabel('原因').fill('合成测试补卡')
  await page.getByRole('button', { name: '提交' }).click()
  await expect(page.getByText('已更正')).toBeVisible()
})
```

- [ ] **Step 4: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=AttendanceWorkspaceTest
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/attendance-workspace.spec.ts
```

Expected: 关账后更正 409；时区正确；异常中文显示。

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/time/attendance/pages/AttendanceWorkspacePage.vue frontend/src/modules/time/attendance/components/AttendanceFilter.vue frontend/src/modules/time/attendance/components/AttendanceTable.vue frontend/src/modules/time/attendance/components/CorrectionDialog.vue backend/app/Modules/Time/Http/Controllers/AttendanceController.php backend/tests/Feature/Time/AttendanceWorkspaceTest.php frontend/tests/e2e/real/attendance-workspace.spec.ts
git commit -m "feat(time): rebuild attendance workspace"
```

---

