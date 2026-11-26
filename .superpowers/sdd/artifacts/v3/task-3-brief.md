### Task 3: 员工详情与同对象页签

**Files:**
- Create: `frontend/src/modules/people/employees/pages/EmployeeDetailPage.vue`
- Create: `frontend/src/modules/people/employees/components/EmployeeSummary.vue`
- Create: `frontend/src/modules/people/employees/components/EmployeeTabs.vue`
- Create: `frontend/src/modules/people/employees/detailApi.ts`
- Modify: `frontend/src/router/registry.ts`
- Test: `frontend/tests/unit/EmployeeDetailPage.spec.ts`
- Test: `frontend/tests/e2e/real/employee-detail.spec.ts`

**Interfaces:**
- Consumes: `GET /v1/employees/{employment_id}`、`GET /v1/employees/{employmentId}/education|experiences|contacts|certificates|change_requests|imports`、动作清单、字段遮罩。
- Produces: `/hr/employees/:id` 详情页；`?tab=overview|employment|experience|contacts|credentials|files|changes` 深链；返回列表。

- [ ] **Step 1: 写详情页失败测试**

```ts
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import EmployeeDetailPage from '@/modules/people/employees/pages/EmployeeDetailPage.vue'

vi.mock('@/modules/people/employees/detailApi', () => ({
  getEmployee: vi.fn(async () => ({ id: 1, employee_no: 'E0001', name: '合成员工', status: 'ACTIVE', tabs: [] })),
}))

describe('EmployeeDetailPage', () => {
  it('uses a hidden context route and renders summary', async () => {
    const wrapper = mount(EmployeeDetailPage, { props: { id: '1' }, global: { stubs: { RouterLink: true } } })
    await new Promise((resolve) => setTimeout(resolve))
    expect(wrapper.text()).toContain('合成员工')
    expect(wrapper.text()).toContain('在职')
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

```bash
cd frontend && pnpm exec vitest run tests/unit/EmployeeDetailPage.spec.ts
```

Expected: FAIL，页面不存在或仍为旧 `PeopleWorkspace` 分支。

- [ ] **Step 3: 实现详情页**

- `PageShell` 标题为员工姓名 + 工号；页头有返回列表、编辑、归档/恢复。
- 概览显示部门、岗位、入职日期、状态、联系方式（按字段权限遮罩）。
- `EmployeeTabs` 仅包含同对象页签：概览、任职、经历、联系人、证照、附件、变更。
- `?tab=` 非法值回退概览，不报错。
- 无权限时显示 `ForbiddenState`，不泄露记录存在性。

- [ ] **Step 4: 更新注册表**

```ts
{
  path: '/hr/employees/:id',
  name: 'employee-detail',
  componentKey: 'people/employees/detail',
  capability: 'people.employee.read',
  kind: 'detail',
  menu: null,
  detailOf: 'employees',
  primaryObject: 'employee',
}
```

删除旧 `EmployeeEducationPage`、`EmployeeWorkHistoryPage` 等作为独立页面的注册；保留为详情页签组件。

- [ ] **Step 5: 写 E2E 深链与返回**

```ts
test('detail deep link and tab return context', async ({ page }) => {
  await loginAs(page, 'demo.hr')
  await page.goto('/hr/employees/1?tab=employment')
  await expect(page.getByRole('heading', { name: /合成/ })).toBeVisible()
  await page.getByRole('link', { name: '返回员工列表' }).click()
  await expect(page).toHaveURL('/hr/employees')
})
```

- [ ] **Step 6: 运行测试**

```bash
cd frontend
pnpm exec vitest run tests/unit/EmployeeDetailPage.spec.ts
HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/employee-detail.spec.ts
```

Expected: 详情与深链通过；详情不出现在侧栏。

- [ ] **Step 7: Commit**

```bash
git add frontend/src/modules/people/employees/pages/EmployeeDetailPage.vue frontend/src/modules/people/employees/components/EmployeeSummary.vue frontend/src/modules/people/employees/components/EmployeeTabs.vue frontend/src/modules/people/employees/detailApi.ts frontend/src/router/registry.ts frontend/tests/unit/EmployeeDetailPage.spec.ts frontend/tests/e2e/real/employee-detail.spec.ts
git commit -m "feat(people): add employee detail workspace with same-object tabs"
```

---

