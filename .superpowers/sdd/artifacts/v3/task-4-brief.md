### Task 4: 员工子档案、附件、变更与导出

**Files:**
- Create: `backend/app/Modules/People/Http/Controllers/EmployeeProfileController.php` 的补充方法
- Create: `backend/tests/Feature/People/EmployeeProfileApiTest.php`
- Create: `frontend/src/modules/people/employees/components/EmployeeProfileSection.vue`
- Create: `frontend/src/modules/people/employees/components/EmployeeFileList.vue`
- Create: `frontend/src/modules/people/employees/components/EmployeeChangeList.vue`
- Test: `frontend/tests/e2e/real/employee-profile.spec.ts`

**Interfaces:**
- Consumes: 员工教育、经历、联系人、证照、附件、变更审批 API；字段策略；附件下载能力。
- Produces: 详情页内可维护子档案、上传/下载附件（有权限）、查看变更；导出按能力与字段策略。

- [ ] **Step 1: 写子档案 API 测试**

```php
public function test_hr_can_update_education_and_contact_but_read_only_cannot(): void
{
    $employee = $this->employee();
    $this->actingAs($this->hrAdmin())
        ->postJson("/v1/employees/{$employee->employment_id}/education", ['school' => '合成大学', 'degree' => 'BACHELOR'])
        ->assertCreated();

    $this->actingAs($this->hrReadOnly())
        ->postJson("/v1/employees/{$employee->employment_id}/education", ['school' => '合成大学', 'degree' => 'BACHELOR'])
        ->assertStatus(403);
}
```

- [ ] **Step 2: 实现与测试附件权限**

附件上传/下载必须校验：

- `people.document.read` / `people.document.download`；
- 对象范围；
- 文件属于该员工；
- 被归档员工仍可按权限查看历史附件。

- [ ] **Step 3: 实现导出**

若已有员工导出端点，按当前能力与字段策略接入；若没有，Task 4 必须补：

```http
GET /v1/employees/export
x-capability: people.employee.export
```

导出服务端应用数据范围与字段裁剪；无权限返回 403；不得返回未授权字段。

- [ ] **Step 4: 实现前端子档案组件**

`EmployeeProfileSection.vue` 按 tab 渲染教育/经历/联系人/证照；每类使用 `DataTable` + `FormDialog`；删除/归档有确认；错误回显到字段。

`EmployeeFileList.vue` 只在 `ActionGate` 允许时显示上传/下载；下载走短期签名 URL。

`EmployeeChangeList.vue` 显示变更类型、时间、状态中文与审批入口。

- [ ] **Step 5: E2E 覆盖**

```ts
test('HR adds education and downloads an authorized file', async ({ page }) => {
  await loginAs(page, 'demo.hr')
  await page.goto('/hr/employees/1?tab=experience')
  await page.getByRole('button', { name: '新增教育经历' }).click()
  await page.getByLabel('学校').fill('合成大学')
  await page.getByRole('button', { name: '保存' }).click()
  await expect(page.getByText('合成大学')).toBeVisible()
})
```

- [ ] **Step 6: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter='EmployeeProfileApiTest|DocumentTest|EmployeeDocument'
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/employee-profile.spec.ts
```

Expected: 子档案写权限正确；附件下载仅授权可见；导出不泄露字段。

- [ ] **Step 7: Commit**

```bash
git add backend/app/Modules/People/Http/Controllers/EmployeeProfileController.php backend/tests/Feature/People/EmployeeProfileApiTest.php frontend/src/modules/people/employees/components/EmployeeProfileSection.vue frontend/src/modules/people/employees/components/EmployeeFileList.vue frontend/src/modules/people/employees/components/EmployeeChangeList.vue frontend/tests/e2e/real/employee-profile.spec.ts
git commit -m "feat(people): complete employee profile, files, changes and export"
```

---

