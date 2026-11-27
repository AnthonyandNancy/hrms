### Task 15: 培训管理

**Files:**
- Create: `frontend/src/modules/talent/training/pages/TrainingWorkspacePage.vue`
- Create: `frontend/src/modules/talent/training/pages/TrainingProgramDetailPage.vue`
- Create: `frontend/src/modules/talent/training/components/TrainingProgramDrawer.vue`
- Create: `frontend/src/modules/talent/training/components/TrainingEnrollmentTable.vue`
- Modify: `backend/app/Modules/Talent/Http/Controllers/TrainingController.php`
- Test: `backend/tests/Feature/Talent/TrainingWorkspaceTest.php`
- Test: `frontend/tests/e2e/real/training-workspace.spec.ts`

**Interfaces:**
- Consumes: 培训课程、场次、报名、签到、考试、反馈、档案、报表 API。
- Produces: `/training` 工作台 + 课程详情；报名/签到/完成动作；培训档案。

- [ ] **Step 1: 写签到与完成测试**

```php
public function test_completion_requires_an_enrollment(): void
{
    $session = $this->trainingSession();
    $employment = $this->employee();

    // No enrollment -> 404/403, never a silent completion.
    $this->actingAs($this->hrAdmin())
        ->postJson("/v1/training/enrollments/999999/complete", [
            'attended' => true,
            'completion_result' => 'PASS',
        ], ['Idempotency-Key' => 'complete-unenrolled'])
        ->assertStatus(404);

    $enrollment = $this->enroll($employment, $session);
    $this->actingAs($this->hrAdmin())
        ->postJson("/v1/training/enrollments/{$enrollment->id}/complete", [
            'attended' => true,
            'completion_result' => 'PASS',
            'score' => 88,
        ], ['Idempotency-Key' => 'complete-enrolled'])
        ->assertOk();

    $this->assertDatabaseHas('training_enrollments', [
        'id' => $enrollment->id,
        'status' => 'COMPLETED',
        'completion_result' => 'PASS',
    ]);
}
```

- [ ] **Step 2: 实现工作台**

- 课程列表 + 详情；场次、报名、签到、考试、反馈在详情内区域。
- 报名/签到/完成分别受能力控制；未报名不能完成。
- 培训档案可从详情跳转员工，但不在本页嵌员工编辑器。

- [ ] **Step 3: E2E**

```ts
test('employee enrols in a session and signs in', async ({ page }) => {
  await loginAs(page, 'demo.employee')
  await page.goto('/training')
  await page.getByRole('button', { name: '报名' }).first().click()
  await expect(page.getByText('已报名')).toBeVisible()
})
```

- [ ] **Step 4: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=TrainingWorkspaceTest
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/training-workspace.spec.ts
```

Expected: 报名/签到/完成/档案通过；未报名完成被拒。

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/talent/training/pages/TrainingWorkspacePage.vue frontend/src/modules/talent/training/pages/TrainingProgramDetailPage.vue frontend/src/modules/talent/training/components/TrainingProgramDrawer.vue frontend/src/modules/talent/training/components/TrainingEnrollmentTable.vue backend/app/Modules/Talent/Http/Controllers/TrainingController.php backend/tests/Feature/Talent/TrainingWorkspaceTest.php frontend/tests/e2e/real/training-workspace.spec.ts
git commit -m "feat(talent): rebuild training workspace"
```

---

