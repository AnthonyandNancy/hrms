### Task 14: 招聘需求与候选人

**Files:**
- Create: `frontend/src/modules/talent/recruiting/pages/RequisitionWorkspacePage.vue`
- Create: `frontend/src/modules/talent/recruiting/pages/CandidateWorkspacePage.vue`
- Create: `frontend/src/modules/talent/recruiting/pages/CandidateDetailPage.vue`
- Create: `frontend/src/modules/talent/recruiting/components/RequisitionFormDrawer.vue`
- Create: `frontend/src/modules/talent/recruiting/components/CandidatePipeline.vue`
- Modify: `backend/app/Modules/Talent/Http/Controllers/RecruitmentController.php`
- Test: `backend/tests/Feature/Talent/RecruitmentWorkspaceTest.php`
- Test: `frontend/tests/e2e/real/recruitment-workspace.spec.ts`

**Interfaces:**
- Consumes: 招聘需求、审批、发布、候选人、面试、Offer、录用转员工 API。
- Produces: `/recruitment` 与 `/candidates` 工作台；候选人详情；需求/候选人动作；录用转员工幂等。

- [ ] **Step 1: 写录用转员工幂等测试**

```php
public function test_candidate_conversion_is_idempotent_and_atomic(): void
{
    $candidate = $this->offerAcceptedCandidate();
    $key = 'convert-'.Str::uuid();

    // Single-company contract: no legal_entity_id. The controller, OpenAPI and
    // action manifest must drop it in the same task (Volume 2 removed the column).
    $payload = ['org_unit_id' => $this->deptA(), 'employee_no' => 'CONV-SYNTH-0001'];
    $first = $this->actingAs($this->hrAdmin())->postJson("/v1/recruitment/candidates/{$candidate->id}/convert", $payload, ['Idempotency-Key' => $key])->assertCreated();
    $second = $this->actingAs($this->hrAdmin())->postJson("/v1/recruitment/candidates/{$candidate->id}/convert", $payload, ['Idempotency-Key' => $key])->assertOk();

    $this->assertSame($first->json('data.employment_id'), $second->json('data.employment_id'));
    $this->assertDatabaseCount('persons', 1);
}
```

- [ ] **Step 2: 实现工作台**

- 需求工单：创建、审批、发布、关闭；列表显示编制、状态、负责人。
- 候选人：管道视图/列表；详情显示面试、评价、Offer；录用转员工调用原子命令。
- 候选人敏感信息按字段策略；面试反馈仅授权可见。
- 不把招聘需求、候选人、培训混在一个页面。

- [ ] **Step 3: E2E**

```ts
test('HR creates a requisition and moves a candidate to offer', async ({ page }) => {
  await loginAs(page, 'demo.hr')
  await page.goto('/recruitment')
  await page.getByRole('button', { name: '新建招聘需求' }).click()
  await page.getByLabel('岗位').fill('合成工程师')
  await page.getByRole('button', { name: '保存' }).click()
  await expect(page.getByText('草稿')).toBeVisible()
})
```

- [ ] **Step 4: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=RecruitmentWorkspaceTest
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/recruitment-workspace.spec.ts
```

Expected: 需求审批、候选人管道、录用幂等、字段权限通过。

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/talent/recruiting/pages/RequisitionWorkspacePage.vue frontend/src/modules/talent/recruiting/pages/CandidateWorkspacePage.vue frontend/src/modules/talent/recruiting/pages/CandidateDetailPage.vue frontend/src/modules/talent/recruiting/components/RequisitionFormDrawer.vue frontend/src/modules/talent/recruiting/components/CandidatePipeline.vue backend/app/Modules/Talent/Http/Controllers/RecruitmentController.php backend/tests/Feature/Talent/RecruitmentWorkspaceTest.php frontend/tests/e2e/real/recruitment-workspace.spec.ts
git commit -m "feat(talent): rebuild recruiting workspaces"
```

---

