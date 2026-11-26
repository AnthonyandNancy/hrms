### Task 13: 绩效考核

**Files:**
- Create: `frontend/src/modules/talent/performance/pages/PerformanceWorkspacePage.vue`
- Create: `frontend/src/modules/talent/performance/pages/PerformanceCycleDetailPage.vue`
- Create: `frontend/src/modules/talent/performance/components/PerformanceGoalTable.vue`
- Create: `frontend/src/modules/talent/performance/components/PerformanceScoreDrawer.vue`
- Modify: `backend/app/Modules/Talent/Http/Controllers/PerformanceController.php`
- Test: `backend/tests/Feature/Talent/PerformanceWorkspaceTest.php`
- Test: `frontend/tests/e2e/real/performance-workspace.spec.ts`

**Interfaces:**
- Consumes: 绩效周期、模板、指标、目标、评分、校准、申诉、结果 API。
- Produces: `/performance` 工作台 + 周期详情；目标/评分/校准为详情内区域；结果按字段权限。

- [ ] **Step 1: 写评分锁定与越权测试**

```php
public function test_employee_cannot_score_other_employee(): void
{
    $cycle = $this->activeCycle();
    $other = $this->employeeIn($this->deptB());
    $this->actingAs($this->employee())
        ->postJson('/v1/performance/reviews', ['cycle_id' => $cycle->id, 'employment_id' => $other->employment_id, 'self_rating' => '90'])
        ->assertStatus(403);
}
```

- [ ] **Step 2: 实现工作台**

- 周期列表与状态中文；详情显示目标、评分、校准、申诉。
- 员工仅能看本人；主管看下属；HR 按范围。
- 校准/申诉为独立动作；结果发布后只读。
- 评分不得以浮点或字符串混用；按既有规则。

- [ ] **Step 3: E2E**

```ts
test('manager scores a direct report', async ({ page }) => {
  await loginAs(page, 'demo.manager')
  await page.goto('/performance')
  await page.getByRole('button', { name: '评分' }).first().click()
  await page.getByLabel('评分').fill('90')
  await page.getByRole('button', { name: '保存' }).click()
  await expect(page.getByText('已评分')).toBeVisible()
})
```

- [ ] **Step 4: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=PerformanceWorkspaceTest
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/performance-workspace.spec.ts
```

Expected: 越权评分 403；主管范围正确；结果发布后只读。

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/talent/performance/pages/PerformanceWorkspacePage.vue frontend/src/modules/talent/performance/pages/PerformanceCycleDetailPage.vue frontend/src/modules/talent/performance/components/PerformanceGoalTable.vue frontend/src/modules/talent/performance/components/PerformanceScoreDrawer.vue backend/app/Modules/Talent/Http/Controllers/PerformanceController.php backend/tests/Feature/Talent/PerformanceWorkspaceTest.php frontend/tests/e2e/real/performance-workspace.spec.ts
git commit -m "feat(talent): rebuild performance workspace"
```

---

