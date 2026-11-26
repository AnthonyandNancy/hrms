### Task 2: 员工列表工作区

**Files:**
- Create: `frontend/src/modules/people/employees/pages/EmployeeListPage.vue`
- Create: `frontend/src/modules/people/employees/components/EmployeeFilter.vue`
- Create: `frontend/src/modules/people/employees/components/EmployeeTable.vue`
- Create: `frontend/src/modules/people/employees/components/EmployeeCreateDrawer.vue`
- Create: `frontend/src/modules/people/employees/api.ts`
- Modify: `frontend/src/modules/people/pages/EmployeeListPage.vue`（改为 re-export 或删除）
- Test: `frontend/tests/unit/EmployeeListPage.spec.ts`
- Test: `frontend/tests/e2e/real/employee-list.spec.ts`

**Interfaces:**
- Consumes: `GET /v1/employees`、`POST /v1/employees/onboard`、`DELETE /v1/employees/{employment_id}`（归档）、`POST /v1/employees/{employment_id}/restore`、动作清单 `employees.*`。
- Produces: 列表筛选、分页、创建抽屉、行操作、归档/恢复确认、详情入口。

- [ ] **Step 1: 写前端失败测试**

```ts
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import EmployeeListPage from '@/modules/people/employees/pages/EmployeeListPage.vue'

vi.mock('@/modules/people/employees/api', () => ({
  listEmployees: vi.fn(async () => ({ items: [{ id: 1, employee_no: 'E0001', name: '合成员工', status: 'ACTIVE' }], total: 1 })),
}))

describe('EmployeeListPage', () => {
  it('renders Chinese status and a detail link', async () => {
    const wrapper = mount(EmployeeListPage, { global: { stubs: { ElTable: true, ElButton: true } } })
    await new Promise((resolve) => setTimeout(resolve))
    expect(wrapper.text()).toContain('在职')
    expect(wrapper.find('a[href="/hr/employees/1"]').exists()).toBe(true)
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

```bash
cd frontend && pnpm exec vitest run tests/unit/EmployeeListPage.spec.ts
```

Expected: FAIL，页面仍由旧 `PeopleWorkspace` 渲染或缺少详情链接。

- [ ] **Step 3: 实现员工 API 客户端**

`api.ts` 只暴露纯函数：

```ts
export type EmployeeRow = { id: number; employee_no: string; name: string; status: string; org_unit?: string; position?: string }
export type EmployeeListQuery = { page: number; page_size: number; keyword?: string; org_unit_id?: number; status?: string }

export async function listEmployees(query: EmployeeListQuery): Promise<{ items: EmployeeRow[]; total: number }> {
  return resourceGet('/v1/employees', query)
}

export async function onboardEmployee(payload: OnboardPayload, idempotencyKey: string): Promise<{ employment_id: number }> {
  return resourcePost('/v1/employees/onboard', payload, { idempotencyKey })
}
```

- [ ] **Step 4: 实现列表骨架**

`EmployeeListPage.vue`：

- `PageShell` + `EmployeeFilter` + `EmployeeTable`。
- 页头“新增员工”受 `ActionGate` 控制。
- 行操作“查看详情”“编辑”“归档/恢复”；归档使用 `ElMessageBox.confirm`。
- 表格列显示中文状态、部门、岗位；金额/日期用 formatter。
- 空态有“新增员工”；错误态显示可重试与 traceId。

- [ ] **Step 5: 实现创建抽屉**

`EmployeeCreateDrawer.vue` 使用 `FormDrawer`，字段分组：主档案、入职信息、岗位任职、雇佣条款。提交调用 `onboardEmployee`，成功后关闭抽屉、刷新列表、跳转详情或显示成功提示。

- [ ] **Step 6: 写 E2E**

```ts
test('HR creates an employee and sees detail', async ({ page }) => {
  await loginAs(page, 'demo.hr')
  await page.goto('/hr/employees')
  await page.getByRole('button', { name: '新增员工' }).click()
  await page.getByLabel('姓名').fill('合成测试员工')
  await page.getByLabel('工号').fill(`E2E-${Date.now()}`)
  await page.getByLabel('部门').click()
  await page.getByRole('option', { name: '研发部' }).click()
  await page.getByLabel('岗位').click()
  await page.getByRole('option', { name: '工程师' }).click()
  await page.getByRole('button', { name: '保存' }).click()
  await expect(page).toHaveURL(/\/hr\/employees\/\d+/)
  await expect(page.getByText('合成测试员工')).toBeVisible()
})
```

- [ ] **Step 7: 运行测试**

```bash
cd frontend
pnpm exec vitest run tests/unit/EmployeeListPage.spec.ts
HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/employee-list.spec.ts
```

Expected: 单测与 E2E 通过；列表无跨模块 Tab；创建后详情可达。

- [ ] **Step 8: Commit**

```bash
git add frontend/src/modules/people/employees/pages/EmployeeListPage.vue frontend/src/modules/people/employees/components/EmployeeFilter.vue frontend/src/modules/people/employees/components/EmployeeTable.vue frontend/src/modules/people/employees/components/EmployeeCreateDrawer.vue frontend/src/modules/people/employees/api.ts frontend/src/modules/people/pages/EmployeeListPage.vue frontend/tests/unit/EmployeeListPage.spec.ts frontend/tests/e2e/real/employee-list.spec.ts
git commit -m "feat(people): rebuild employee list workspace"
```

---

