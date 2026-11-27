### Task 16: 仪表盘、报表与公告

**Files:**
- Create: `frontend/src/modules/reporting/pages/DashboardPage.vue`
- Create: `frontend/src/modules/reporting/pages/ReportWorkspacePage.vue`
- Create: `frontend/src/modules/reporting/pages/ReportDetailPage.vue`
- Create: `frontend/src/modules/engagement/pages/NoticeWorkspacePage.vue`
- Create: `frontend/src/modules/engagement/pages/NoticeDetailPage.vue`
- Modify: `backend/app/Modules/Reporting/Http/Controllers/DashboardController.php`
- Modify: `backend/app/Modules/Reporting/Http/Controllers/ReportingController.php`
- Modify: `backend/app/Modules/Engagement/Http/Controllers/NoticeController.php`
- Test: `backend/tests/Feature/Reporting/ReportingWorkspaceTest.php`
- Test: `backend/tests/Feature/Engagement/NoticeWorkspaceTest.php`
- Test: `frontend/tests/e2e/real/reporting-notice.spec.ts`

**Interfaces:**
- Consumes: 仪表盘快照、报表定义/导出/订阅、公告创建/审批/发布/阅读 API。
- Produces: 仪表盘主工作台；报表目录 + 详情/导出；公告工作台 + 详情；无跨模块 Tab。

- [ ] **Step 1: 写报表导出字段/范围测试**

```php
public function test_report_export_respects_scope_and_field_policy(): void
{
    // A permitted export capability with a forbidden field is a validation
    // failure (422). 403 is reserved for a missing reporting.export grant; the
    // capability-denial case is covered separately in Volume 2's role matrix.
    $this->actingAs($this->deptManager())
        ->postJson('/v1/reporting/exports', [
            'reportCode' => 'WORKFORCE_HEADCOUNT',
            'purpose' => 'BUSINESS_OPERATION',
            'fields' => ['employee_no', 'org_path'],
            'filters' => ['org_unit_id' => $this->deptB()],
        ], ['Idempotency-Key' => 'export-scope-negative'])
        ->assertStatus(422)
        ->assertJsonPath('data.reason', 'EXPORT_FIELD_NOT_ALLOWED');
}
```

- [ ] **Step 2: 写公告可见范围测试**

```php
public function test_notice_is_visible_only_to_target_audience(): void
{
    $notice = $this->publishedNoticeFor($this->deptA());
    $this->actingAs($this->employeeIn($this->deptB()))
        ->getJson("/v1/engagement/notices/{$notice->id}")
        ->assertStatus(404);
}
```

- [ ] **Step 3: 实现三个工作台**

- 仪表盘：真实快照、时间范围、下钻；不用静态占位数字。
- 报表：目录、参数、图表/明细切换、导出任务；导出独立能力。
- 公告：列表、创建/编辑、审核、发布、阅读记录；发布范围用组织树，不使用租户。
- 三者各自独立入口，不共享巨型 `PlatformWorkspace`。

- [ ] **Step 4: E2E**

```ts
test('HR views dashboard and exports an authorized report', async ({ page }) => {
  await loginAs(page, 'demo.hr')
  await page.goto('/dashboard')
  await expect(page.getByText('员工总数')).toBeVisible()
  await page.goto('/reports')
  await page.getByRole('button', { name: '导出' }).first().click()
  await expect(page.getByText('导出任务已创建')).toBeVisible()
})
```

- [ ] **Step 5: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter='ReportingWorkspaceTest|NoticeWorkspaceTest'
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/reporting-notice.spec.ts
```

Expected: 报表范围/字段/导出正确；公告范围正确；无技术文案。

- [ ] **Step 6: Commit**

```bash
git add frontend/src/modules/reporting/pages/DashboardPage.vue frontend/src/modules/reporting/pages/ReportWorkspacePage.vue frontend/src/modules/reporting/pages/ReportDetailPage.vue frontend/src/modules/engagement/pages/NoticeWorkspacePage.vue frontend/src/modules/engagement/pages/NoticeDetailPage.vue backend/app/Modules/Reporting/Http/Controllers/DashboardController.php backend/app/Modules/Reporting/Http/Controllers/ReportingController.php backend/app/Modules/Engagement/Http/Controllers/NoticeController.php backend/tests/Feature/Reporting/ReportingWorkspaceTest.php backend/tests/Feature/Engagement/NoticeWorkspaceTest.php frontend/tests/e2e/real/reporting-notice.spec.ts
git commit -m "feat(reporting): rebuild dashboard, reports and notices"
```

---

