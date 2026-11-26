### Task 6: 岗位工作台

**Files:**
- Create: `frontend/src/modules/people/organization/pages/PositionWorkspacePage.vue`
- Create: `frontend/src/modules/people/organization/components/PositionTable.vue`
- Create: `frontend/src/modules/people/organization/components/PositionFormDialog.vue`
- Create: `frontend/src/modules/people/organization/components/PositionDetailDrawer.vue`
- Modify: `backend/app/Modules/Organization/Http/Controllers/PositionController.php`
- Test: `backend/tests/Feature/Organization/PositionWorkspaceTest.php`
- Test: `frontend/tests/e2e/real/position-workspace.spec.ts`

**Interfaces:**
- Consumes: 岗位目录、岗位编制、任职资格、职级、在岗人员 API。
- Produces: `/hr/positions` 列表 + 详情抽屉；新增/编辑弹窗；编制/资格/在岗为详情内区域。

- [ ] **Step 1: 写岗位占用与停用测试**

```php
public function test_position_with_active_occupancy_cannot_be_deleted_only_disabled(): void
{
    $position = $this->positionWithOccupant();
    $this->actingAs($this->orgAdmin())
        ->postJson("/v1/positions/{$position->id}/disable")
        ->assertOk();
    $this->assertNotNull($position->fresh()->disabled_at);
}
```

- [ ] **Step 2: 实现工作台**

- 列表显示岗位名称、编码、部门、职级、编制、在岗人数、状态。
- 新增/编辑弹窗；编制、资格、在岗人员放在详情抽屉。
- 岗位变更不得直接覆盖历史任职；影响现任岗位时提示走异动流程。
- 不出现法人编号。

- [ ] **Step 3: E2E**

```ts
test('org admin creates a position and views its occupants', async ({ page }) => {
  await loginAs(page, 'demo.admin')
  await page.goto('/hr/positions')
  await page.getByRole('button', { name: '新增岗位' }).click()
  await page.getByLabel('岗位名称').fill('合成测试岗')
  await page.getByLabel('岗位编码').fill(`P-${Date.now()}`)
  await page.getByRole('button', { name: '保存' }).click()
  await expect(page.getByText('合成测试岗')).toBeVisible()
})
```

- [ ] **Step 4: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=PositionWorkspaceTest
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/position-workspace.spec.ts
```

Expected: 岗位 CRUD、编制、在岗人员通过；占用岗位不可物理删除。

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/people/organization/pages/PositionWorkspacePage.vue frontend/src/modules/people/organization/components/PositionTable.vue frontend/src/modules/people/organization/components/PositionFormDialog.vue frontend/src/modules/people/organization/components/PositionDetailDrawer.vue backend/app/Modules/Organization/Http/Controllers/PositionController.php backend/tests/Feature/Organization/PositionWorkspaceTest.php frontend/tests/e2e/real/position-workspace.spec.ts
git commit -m "feat(org): rebuild position workspace"
```

---

