### Task 7: 员工异动

**Files:**
- Create: `frontend/src/modules/people/lifecycle/pages/MovementWorkspacePage.vue`
- Create: `frontend/src/modules/people/lifecycle/pages/MovementDetailPage.vue`
- Create: `frontend/src/modules/people/lifecycle/components/MovementFormDrawer.vue`
- Create: `frontend/src/modules/people/lifecycle/components/MovementTimeline.vue`
- Modify: `backend/app/Modules/Lifecycle/Http/Controllers/LifecycleController.php`
- Test: `backend/tests/Feature/Lifecycle/MovementWorkspaceTest.php`
- Test: `frontend/tests/e2e/real/movement-workspace.spec.ts`

**Interfaces:**
- Consumes: 异动类型（入职、转正、调岗、调部门、调薪、借调、离职）、审批 Case、生效 Effect。
- Produces: `/hr/movements` 工作台 + `/hr/movements/:id` 详情；创建抽屉；审批与生效时间线。

- [ ] **Step 1: 写审批与生效测试**

```php
public function test_movement_effect_only_applies_after_approval(): void
{
    $movement = $this->draftTransfer();
    $this->actingAs($this->hrAdmin())
        ->postJson("/v1/lifecycle/cases/{$movement->case_id}/decision", ['decision' => 'APPROVE'])
        ->assertOk();

    $this->assertDatabaseHas('assignments', ['id' => $movement->new_assignment_id]);
    $this->assertNotNull($movement->fresh()->effective_at);
}
```

- [ ] **Step 2: 实现工作台**

- 列表按类型/状态筛选；创建抽屉选择异动类型与员工。
- 详情显示申请人、审批链、决策、生效时间与影响对象。
- 页面内不嵌入部门/岗位/合同编辑器；需要时导航到对应模块。
- 调薪动作必须受独立能力与字段策略约束。

- [ ] **Step 3: E2E**

```ts
test('HR creates a transfer and sees approval timeline', async ({ page }) => {
  await loginAs(page, 'demo.hr')
  await page.goto('/hr/movements')
  await page.getByRole('button', { name: '发起异动' }).click()
  await page.getByLabel('异动类型').click()
  await page.getByRole('option', { name: '调岗' }).click()
  await page.getByRole('button', { name: '保存草稿' }).click()
  await expect(page.getByText('草稿')).toBeVisible()
})
```

- [ ] **Step 4: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=MovementWorkspaceTest
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/movement-workspace.spec.ts
```

Expected: 未审批不生效；审批后生成正确 Effect；时间线可读。

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/people/lifecycle/pages/MovementWorkspacePage.vue frontend/src/modules/people/lifecycle/pages/MovementDetailPage.vue frontend/src/modules/people/lifecycle/components/MovementFormDrawer.vue frontend/src/modules/people/lifecycle/components/MovementTimeline.vue backend/app/Modules/Lifecycle/Http/Controllers/LifecycleController.php backend/tests/Feature/Lifecycle/MovementWorkspaceTest.php frontend/tests/e2e/real/movement-workspace.spec.ts
git commit -m "feat(lifecycle): rebuild movement workspace and detail"
```

---

