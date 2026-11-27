### Task 5: 部门工作台

**Files:**
- Create: `frontend/src/modules/people/organization/pages/DepartmentWorkspacePage.vue`
- Create: `frontend/src/modules/people/organization/components/DepartmentTree.vue`
- Create: `frontend/src/modules/people/organization/components/DepartmentDetail.vue`
- Create: `frontend/src/modules/people/organization/components/DepartmentFormDialog.vue`
- Modify: `backend/app/Modules/Organization/Http/Controllers/DepartmentController.php`
- Test: `backend/tests/Feature/Organization/DepartmentWorkspaceTest.php`
- Test: `frontend/tests/e2e/real/department-workspace.spec.ts`

**Interfaces:**
- Consumes: 组织树、部门 CRUD、部门成员、编制、负责人 API。
- Produces: `/hr/departments` 单工作台：左树右详情；新增/编辑弹窗；成员/编制/负责人上下文；停用与恢复。

- [ ] **Step 1: 写树与防环测试**

```php
public function test_department_cannot_be_moved_under_its_descendant(): void
{
    [$root, $child, $grandchild] = $this->departmentChain();
    $this->actingAs($this->orgAdmin())
        ->postJson("/v1/org-units/{$root->id}/move", ['parent_id' => $grandchild->id])
        ->assertStatus(422);
}
```

- [ ] **Step 2: 实现工作台**

- 左树支持搜索、展开、选中；右详情显示基础信息、负责人、成员数、编制。
- 新增/编辑使用 `DepartmentFormDialog`；删除改为停用，停用前显示员工影响。
- 成员调整、部门迁移作为详情内动作/抽屉，不注册新页面。
- 不出现法人编号、租户选择器。

- [ ] **Step 3: 权限与字段**

- 部门查看：`org.unit.read`；编辑：`org.unit.manage`；成员查看：`org.unit.read` + 数据范围。
- 部门经理只能查看授权子树；越权返回 403/404。
- 负责人姓名与联系方式按字段策略裁剪。

- [ ] **Step 4: E2E**

```ts
test('org admin creates and edits a department in one workspace', async ({ page }) => {
  await loginAs(page, 'demo.admin')
  await page.goto('/hr/departments')
  await page.getByRole('button', { name: '新增部门' }).click()
  await page.getByLabel('部门名称').fill('合成测试部')
  await page.getByLabel('部门编码').fill(`D-${Date.now()}`)
  await page.getByRole('button', { name: '保存' }).click()
  await expect(page.getByText('合成测试部')).toBeVisible()
})
```

- [ ] **Step 5: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=DepartmentWorkspaceTest
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/department-workspace.spec.ts
```

Expected: 建/改/停用闭环；防环 422；越权 403。

- [ ] **Step 6: Commit**

```bash
git add frontend/src/modules/people/organization/pages/DepartmentWorkspacePage.vue frontend/src/modules/people/organization/components/DepartmentTree.vue frontend/src/modules/people/organization/components/DepartmentDetail.vue frontend/src/modules/people/organization/components/DepartmentFormDialog.vue backend/app/Modules/Organization/Http/Controllers/DepartmentController.php backend/tests/Feature/Organization/DepartmentWorkspaceTest.php frontend/tests/e2e/real/department-workspace.spec.ts
git commit -m "feat(org): rebuild department workspace"
```

---

