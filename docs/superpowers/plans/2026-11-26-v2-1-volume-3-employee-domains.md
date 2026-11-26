# FactoryHR V2.1 整改第 3 册：员工样板与全部业务域 — 详细实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 以员工管理为样板完成原子建档、列表/详情/动作闭环，再按业务域完成组织人事、考勤假勤、薪酬绩效、招聘培训、报表公告的全部真实读写、中文展示、数据/字段权限和 E2E。

**Architecture:** 每个业务域按“任务图 → 服务端读写契约 → 页面工作区/详情 → 动作权限与字段策略 → 测试证据”纵向交付；共享 UI 与权限链路复用第 1/2 册产物，不新建平行容器或第二套权限。

**Tech Stack:** Laravel 11 / PHP 8.4 / MySQL 8.4 / Vue 3.5 / TypeScript 5.9 / Element Plus 2.14 / Vitest / Playwright / OpenAPI 3.1。

## Global Constraints

- 规范优先级：用户裁决 > `04 专项补充书` > `01/02 V2.1` > `03 路由矩阵` > V1.1/历史 ADR。
- 每个业务域必须有真实读写 API；没有 API 的能力先补口，不得渲染可点击假入口。
- 页面按任务型模型：workspace/detail/governance/base；创建/编辑/归档是动作，不是页面。
- 页面内 Tab 只允许同一对象、同一任务、同一数据范围。
- 所有写/导出/附件/统计 API 必须服务端鉴权；按钮隐藏不是安全边界。
- 金额整数最小单位；费率十进制字符串；时间 UTC，业务日期按 `company_profile.timezone`。
- 用户界面禁止英文枚举、接口路径、表名、字段档位、开发缺口或演示文案。
- 迁移 forward-only；不修改已合并迁移；数据结构变更必须补契约、测试与回滚说明。
- 未执行或未通过的命令记录为 `NOT RUN`/`FAIL`，不得声称完成。
- 测试与种子只用合成数据；禁止提交 `.env`、凭据、SQLite 数据库或真实员工数据。

---

## 0. 执行顺序与依赖

```text
Task 1   员工原子建档命令与契约
Task 2   员工列表工作区
Task 3   员工详情与同对象页签
Task 4   员工子档案、附件、变更与导出
Task 5   部门工作台
Task 6   岗位工作台
Task 7   员工异动
Task 8   劳动合同
Task 9   考勤打卡
Task 10  请假管理
Task 11  加班与出差
Task 12  薪资管理
Task 13  绩效考核
Task 14  招聘需求与候选人
Task 15  培训管理
Task 16  仪表盘、报表与公告
Task 17  平台与系统管理工作台（功能实现）
Task 18  跨域回归与第 3 册验收
```

依赖关系：

- Task 1 是 Task 2–4 的前置。
- Task 2/3 建立的列表—详情—动作模式是 Task 5–16 的复用样板。
- Task 5/6 是 Task 7/8 的组织与岗位上下文前置。
- Task 9/10/11 共用时间与审批契约；Task 12 依赖考勤/假勤数据。
- Task 13 依赖员工与组织；Task 14 依赖岗位与员工；Task 15 依赖员工与培训目录。
- Task 16 依赖各域读模型；Task 17 依赖第 2 册权限工作台与各域 API；Task 18 必须使用全部域的真实 E2E。

---

### Task 1: 员工原子建档命令与契约

**Files:**
- Create: `backend/app/Modules/People/Commands/CreateEmployee.php`
- Create: `backend/app/Modules/People/Services/CreateEmployeeService.php`
- Create: `backend/app/Modules/People/Http/Requests/CreateEmployeeRequest.php`
- Modify: `backend/app/Modules/People/Http/Controllers/EmployeeController.php`
- Modify: `backend/routes/api.php`
- Modify: `docs/openapi/openapi.yaml`
- Create: `backend/tests/Feature/People/CreateEmployeeCommandTest.php`

**Interfaces:**
- Consumes: Person/Employment/Assignment/Terms 现有模型与服务、`company_profile`、组织/岗位、`audit_events`、`outbox_events`。
- Produces:

```http
POST /v1/employees/onboard
Idempotency-Key: <uuid>
{
  "person": { "given_name": "...", "family_name": "...", "national_id": "...", "mobile": "..." },
  "employment": { "employee_no": "...", "hire_date": "2026-12-01", "employment_type": "FULL_TIME" },
  "assignment": { "org_unit_id": 1, "position_id": 1, "valid_from": "2026-12-01" },
  "terms": { "salary_unit": "MONTH", "salary_minor": 1000000, "currency": "CNY" }
}
```

- [ ] **Step 1: 写事务失败测试**

创建 `backend/tests/Feature/People/CreateEmployeeCommandTest.php`：

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Illuminate\Support\Facades\DB;

final class CreateEmployeeCommandTest extends \Tests\Support\PeopleTestCase
{
    use \Tests\Support\EmployeeDomainScenario;

    /**
     * EmployeeDomainScenario provides real fixtures; this test class uses them.
     * Required: hrAdmin(), hrReadOnly(), employee(), employeeIn(int),
     * deptA(), deptB(), validOnboardPayload(), closePeriod(string),
     * approvedOvertime(int), annualLeave(), activeCycle(), trainingSession(),
     * enroll(Employment, object), publishedNoticeFor(int).
     */

    public function test_partial_failure_leaves_no_person_or_employment(): void
    {
        $before = [
            'persons' => DB::table('persons')->count(),
            'employments' => DB::table('employments')->count(),
            'assignments' => DB::table('assignments')->count(),
            'employment_terms' => DB::table('employment_terms')->count(),
        ];

        // Force the real injected writer's terms step to fail.
        $this->mock(\App\Modules\People\Contracts\PeopleEmploymentWriter::class)
            ->shouldReceive('createPerson')->once()->andReturn(9001)
            ->shouldReceive('createEmployment')->once()->andReturn(9002)
            ->shouldReceive('createAssignment')->once()->andReturn(9003)
            ->shouldReceive('appendTerms')->once()->andThrow(new \RuntimeException('forced terms failure'));

        $this->actingAs($this->hrAdmin())
            ->postJson('/v1/employees/onboard', $this->validOnboardPayload())
            ->assertStatus(500);

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "{$table} changed after rollback");
        }
    }
}
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=CreateEmployeeCommandTest
```

Expected: FAIL，路由 404 或服务不存在。

- [ ] **Step 3: 实现命令与事务**

创建 `CreateEmployeeService.php`：

```php
<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Contracts\PeopleEmploymentWriter;
use App\Modules\People\Commands\CreateEmployee;
use App\Shared\Authorization\PrincipalResolver;
use Illuminate\Support\Facades\DB;

final class CreateEmployeeService
{
    public function __construct(
        private readonly PeopleEmploymentWriter $writer,
        private readonly PrincipalResolver $principals,
    ) {}

    public function handle(CreateEmployee $command): array
    {
        return DB::transaction(function () use ($command): array {
            $principal = $this->principals->resolve();
            $tenantId = $principal->tenantId();

            // The single-company cutover removes tenantId in Volume 2; until then
            // the writer keeps its tenant-scoped signature.
            $personId = $this->writer->createPerson($tenantId, $command->person, $principal->userId());
            $employmentId = $this->writer->createEmployment($tenantId, $command->employment + ['person_id' => $personId], $principal->userId());
            $assignmentId = $this->writer->createAssignment($tenantId, $employmentId, $command->assignment, $principal->userId());
            $termsId = $this->writer->appendTerms($tenantId, $employmentId, $command->terms, $principal->userId());

            return [
                'person_id' => $personId,
                'employment_id' => $employmentId,
                'assignment_id' => $assignmentId,
                'terms_id' => $termsId,
            ];
        });
    }
}
```

所有写入必须通过 People 模块自己的 writer；不得跨模块直接写 Organization/Payroll 表。

- [ ] **Step 4: 实现请求校验与幂等**

`CreateEmployeeRequest` 校验：

- 姓名、证件号、手机号格式；
- `hire_date` 合法；
- `org_unit_id`、`position_id` 存在且属于本公司；
- `salary_minor` 为非负整数；`currency` 为 3 位大写；
- `Idempotency-Key` 必需。

控制器用 `IdempotentCommand` 包裹，重复键返回同一结果，不新增记录。

- [ ] **Step 5: 增加权限与路由**

```php
Route::post('/employees/onboard', [EmployeeController::class, 'onboard'])
    ->middleware(['mfa.step-up', 'capability:people.employee.manage,COMPANY'])
    ->name('employees.onboard');
```

在 OpenAPI 增加 operation，`x-capability: people.employee.manage`，并加入动作清单 `employees.create`。

- [ ] **Step 6: 补齐成功、幂等、越权测试**

```php
public function test_same_idempotency_key_does_not_create_duplicate_employee(): void
{
    $payload = $this->validOnboardPayload();
    $key = 'onboard-'.Str::uuid();

    $first = $this->actingAs($this->hrAdmin())->postJson('/v1/employees/onboard', $payload, ['Idempotency-Key' => $key])->assertCreated();
    $second = $this->actingAs($this->hrAdmin())->postJson('/v1/employees/onboard', $payload, ['Idempotency-Key' => $key])->assertCreated();

    $this->assertSame($first->json('data.employment_id'), $second->json('data.employment_id'));
    $this->assertSame(1, DB::table('employments')->where('employee_no', $payload['employment']['employee_no'])->count());
}

public function test_read_only_hr_cannot_onboard(): void
{
    $this->actingAs($this->hrReadOnly())->postJson('/v1/employees/onboard', $this->validOnboardPayload())->assertStatus(403);
}
```

- [ ] **Step 7: 运行测试**

Run:

```bash
cd backend
../.tools/php/php.exe artisan test --compact --filter=CreateEmployeeCommandTest
../.tools/php/php.exe artisan capability:audit
node ../deploy/scripts/openapi-lint.mjs
```

Expected: 全部通过；失败注入无部分记录；重复键只一笔；只读账号 403。

- [ ] **Step 8: Commit**

```bash
git add backend/app/Modules/People/Commands/CreateEmployee.php backend/app/Modules/People/Services/CreateEmployeeService.php backend/app/Modules/People/Http/Requests/CreateEmployeeRequest.php backend/app/Modules/People/Http/Controllers/EmployeeController.php backend/routes/api.php docs/openapi/openapi.yaml backend/tests/Feature/People/CreateEmployeeCommandTest.php
git commit -m "feat(people): add atomic employee onboarding command"
```

---

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

### Task 8: 劳动合同

**Files:**
- Create: `frontend/src/modules/people/contracts/pages/ContractWorkspacePage.vue`
- Create: `frontend/src/modules/people/contracts/pages/ContractDetailPage.vue`
- Create: `frontend/src/modules/people/contracts/components/ContractFormDrawer.vue`
- Create: `frontend/src/modules/people/contracts/components/ContractVersionList.vue`
- Modify: `backend/app/Modules/People/Http/Controllers/EmploymentContractController.php`
- Test: `backend/tests/Feature/People/ContractWorkspaceTest.php`
- Test: `frontend/tests/e2e/real/contract-workspace.spec.ts`

**Interfaces:**
- Consumes: 合同草稿、版本、续签、变更、终止、到期预警、模板 API。
- Produces: `/hr/contracts` 工作台 + `/hr/contracts/:id` 详情；草稿/续签/终止动作；到期筛选。

- [ ] **Step 1: 写合同版本与终止测试**

```php
public function test_terminating_contract_keeps_versions_and_sets_end_date(): void
{
    $contract = $this->activeContract();
    $this->actingAs($this->hrAdmin())
        ->postJson("/v1/employment-contracts/{$contract->id}/terminate", ['end_date' => '2026-12-31', 'reason' => '合成测试'])
        ->assertOk();

    $this->assertDatabaseCount('employment_contract_versions', 2);
    $this->assertSame('2026-12-31', $contract->fresh()->end_date->toDateString());
}
```

- [ ] **Step 2: 实现工作台**

- 列表显示员工、合同类型、起止日、状态、到期提醒。
- 新增/续签/变更/终止使用抽屉；详情显示版本历史与附件。
- 到期预警作为筛选或详情提示，不单独注册菜单。
- 合同模板放在治理区，由 `people.contract.template.manage` 控制。

- [ ] **Step 3: E2E**

```ts
test('HR creates a contract draft and renews it', async ({ page }) => {
  await loginAs(page, 'demo.hr')
  await page.goto('/hr/contracts')
  await page.getByRole('button', { name: '新建合同' }).click()
  await page.getByLabel('员工').fill('合成员工')
  await page.getByRole('button', { name: '保存草稿' }).click()
  await expect(page.getByText('草稿')).toBeVisible()
})
```

- [ ] **Step 4: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=ContractWorkspaceTest
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/contract-workspace.spec.ts
```

Expected: 版本保留、终止日期、到期筛选、权限控制通过。

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/people/contracts/pages/ContractWorkspacePage.vue frontend/src/modules/people/contracts/pages/ContractDetailPage.vue frontend/src/modules/people/contracts/components/ContractFormDrawer.vue frontend/src/modules/people/contracts/components/ContractVersionList.vue backend/app/Modules/People/Http/Controllers/EmploymentContractController.php backend/tests/Feature/People/ContractWorkspaceTest.php frontend/tests/e2e/real/contract-workspace.spec.ts
git commit -m "feat(people): rebuild contract workspace"
```

---

### Task 9: 考勤打卡

**Files:**
- Create: `frontend/src/modules/time/attendance/pages/AttendanceWorkspacePage.vue`
- Create: `frontend/src/modules/time/attendance/components/AttendanceFilter.vue`
- Create: `frontend/src/modules/time/attendance/components/AttendanceTable.vue`
- Create: `frontend/src/modules/time/attendance/components/CorrectionDialog.vue`
- Modify: `backend/app/Modules/Time/Http/Controllers/AttendanceController.php`
- Test: `backend/tests/Feature/Time/AttendanceWorkspaceTest.php`
- Test: `frontend/tests/e2e/real/attendance-workspace.spec.ts`

**Interfaces:**
- Consumes: 打卡记录、考勤规则、班次、异常、更正、关账 API。
- Produces: 我的打卡/团队打卡/异常/更正工作台；规则与班次在治理页；页面内不混入请假/加班。

- [ ] **Step 1: 写更正权限与关账测试**

```php
public function test_correction_is_rejected_after_period_close(): void
{
    $this->closePeriod('2026-11');
    $this->actingAs($this->hrAdmin())
        ->postJson('/v1/time/attendance/corrections', ['employee_id' => $this->employee()->id, 'date' => '2026-11-15'])
        ->assertStatus(409);
}
```

- [ ] **Step 2: 实现工作台**

- 顶部筛选：日期范围、员工/部门（按范围）、异常类型。
- 表格显示日期、上班/下班、工时、异常、状态；时间按公司时区。
- 更正使用确认弹窗，必须填原因；关账后禁止更正。
- 班次/规则为独立治理页，不在考勤列表内以 Tab 混入。

- [ ] **Step 3: E2E**

```ts
test('HR corrects an attendance exception and sees Chinese status', async ({ page }) => {
  await loginAs(page, 'demo.hr')
  await page.goto('/attendance')
  await page.getByRole('button', { name: '补卡' }).first().click()
  await page.getByLabel('原因').fill('合成测试补卡')
  await page.getByRole('button', { name: '提交' }).click()
  await expect(page.getByText('已更正')).toBeVisible()
})
```

- [ ] **Step 4: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=AttendanceWorkspaceTest
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/attendance-workspace.spec.ts
```

Expected: 关账后更正 409；时区正确；异常中文显示。

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/time/attendance/pages/AttendanceWorkspacePage.vue frontend/src/modules/time/attendance/components/AttendanceFilter.vue frontend/src/modules/time/attendance/components/AttendanceTable.vue frontend/src/modules/time/attendance/components/CorrectionDialog.vue backend/app/Modules/Time/Http/Controllers/AttendanceController.php backend/tests/Feature/Time/AttendanceWorkspaceTest.php frontend/tests/e2e/real/attendance-workspace.spec.ts
git commit -m "feat(time): rebuild attendance workspace"
```

---

### Task 10: 请假管理

**Files:**
- Create: `frontend/src/modules/time/leave/pages/LeaveWorkspacePage.vue`
- Create: `frontend/src/modules/time/leave/components/LeaveRequestDrawer.vue`
- Create: `frontend/src/modules/time/leave/components/LeaveBalancePanel.vue`
- Modify: `backend/app/Modules/Time/Http/Controllers/LeaveController.php`
- Test: `backend/tests/Feature/Time/LeaveWorkspaceTest.php`
- Test: `frontend/tests/e2e/real/leave-workspace.spec.ts`

**Interfaces:**
- Consumes: 假勤类型、余额、申请、撤回、审批、日历、报表 API。
- Produces: 我的请假/团队请假工作台；申请抽屉；余额面板；审批状态；无跨模块 Tab。

- [ ] **Step 1: 写余额与日期冲突测试**

```php
public function test_leave_request_rejects_insufficient_balance(): void
{
    $this->actingAs($this->employee())
        ->postJson('/v1/leave/requests', [
            'leave_type_id' => $this->annualLeave()->id,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-31',
        ])
        ->assertStatus(422);
}
```

- [ ] **Step 2: 实现工作台**

- 我的请假：申请、撤回、查看余额与审批进度。
- 团队请假：按数据范围查看，审批动作独立授权。
- 日期按公司日历计算工作日；半天/小时假按既有规则。
- 余额不足、日期冲突、跨关账期返回可读中文错误。

- [ ] **Step 3: E2E**

```ts
test('employee submits leave and sees pending status', async ({ page }) => {
  await loginAs(page, 'demo.employee')
  await page.goto('/leave')
  await page.getByRole('button', { name: '申请请假' }).click()
  await page.getByLabel('假期类型').click()
  await page.getByRole('option', { name: '年假' }).click()
  await page.getByRole('button', { name: '提交' }).click()
  await expect(page.getByText('待审批')).toBeVisible()
})
```

- [ ] **Step 4: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=LeaveWorkspaceTest
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/leave-workspace.spec.ts
```

Expected: 余额校验、日期冲突、审批流程、自助范围通过。

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/time/leave/pages/LeaveWorkspacePage.vue frontend/src/modules/time/leave/components/LeaveRequestDrawer.vue frontend/src/modules/time/leave/components/LeaveBalancePanel.vue backend/app/Modules/Time/Http/Controllers/LeaveController.php backend/tests/Feature/Time/LeaveWorkspaceTest.php frontend/tests/e2e/real/leave-workspace.spec.ts
git commit -m "feat(time): rebuild leave workspace"
```

---

### Task 11: 加班与出差

**Files:**
- Create: `frontend/src/modules/time/overtime/pages/OvertimeWorkspacePage.vue`
- Create: `frontend/src/modules/time/overtime/components/OvertimeRequestDrawer.vue`
- Create: `frontend/src/modules/time/travel/pages/TravelWorkspacePage.vue`
- Create: `frontend/src/modules/time/travel/components/TravelRequestDrawer.vue`
- Modify: `backend/app/Modules/Time/Http/Controllers/OvertimeRequestController.php`
- Modify: `backend/app/Modules/Time/Http/Controllers/TravelController.php`
- Test: `backend/tests/Feature/Time/OvertimeTravelWorkspaceTest.php`
- Test: `frontend/tests/e2e/real/overtime-travel.spec.ts`

**Interfaces:**
- Consumes: 加班申请/确认/调休、出差申请/审批/费用、政策与期间 API。
- Produces: 加班工作台与出差工作台；申请抽屉；审批与费用入口；时间/政策按公司配置。

- [ ] **Step 1: 写加班调休与出差政策测试**

```php
public function test_overtime_settlement_creates_time_off_ledger_entry(): void
{
    $overtime = $this->approvedOvertime(hours: 2);
    $this->actingAs($this->hrAdmin())
        ->postJson("/v1/overtime/records/{$overtime->id}/settlements", ['mode' => 'TIME_OFF'])
        ->assertOk();

    $this->assertDatabaseHas('leave_ledger_entries', ['source' => 'OVERTIME', 'minutes' => 120]);
}
```

- [ ] **Step 2: 实现两个工作台**

- 加班：申请、确认、调休结算、政策查看；审批独立授权。
- 出差：申请、审批、行程/费用；政策中的法人字段删除，使用公司配置。
- 两者都不在页面内嵌入薪资或请假编辑器。

- [ ] **Step 3: E2E**

```ts
test('employee submits overtime and sees confirmation status', async ({ page }) => {
  await loginAs(page, 'demo.employee')
  await page.goto('/overtime')
  await page.getByRole('button', { name: '申请加班' }).click()
  await page.getByRole('button', { name: '提交' }).click()
  await expect(page.getByText('待确认')).toBeVisible()
})
```

- [ ] **Step 4: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=OvertimeTravelWorkspaceTest
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/overtime-travel.spec.ts
```

Expected: 加班调休账本、出差政策、审批权限通过；无租户/法人字段。

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/time/overtime/pages/OvertimeWorkspacePage.vue frontend/src/modules/time/overtime/components/OvertimeRequestDrawer.vue frontend/src/modules/time/travel/pages/TravelWorkspacePage.vue frontend/src/modules/time/travel/components/TravelRequestDrawer.vue backend/app/Modules/Time/Http/Controllers/OvertimeRequestController.php backend/app/Modules/Time/Http/Controllers/TravelController.php backend/tests/Feature/Time/OvertimeTravelWorkspaceTest.php frontend/tests/e2e/real/overtime-travel.spec.ts
git commit -m "feat(time): rebuild overtime and travel workspaces"
```

---

### Task 12: 薪资管理

**Files:**
- Create: `frontend/src/modules/payroll/pages/PayrollWorkspacePage.vue`
- Create: `frontend/src/modules/payroll/pages/PayrollRunDetailPage.vue`
- Create: `frontend/src/modules/payroll/components/PayrollRunTable.vue`
- Create: `frontend/src/modules/payroll/components/PayrollActionBar.vue`
- Create: `frontend/src/modules/payroll/components/PayslipDrawer.vue`
- Modify: `backend/app/Modules/Payroll/Http/Controllers/PayrollRunController.php`
- Test: `backend/tests/Feature/Payroll/PayrollWorkspaceTest.php`
- Test: `frontend/tests/e2e/real/payroll-workspace.spec.ts`

**Interfaces:**
- Consumes: 工资批次、输入、试算、复核、发布、发放、导出、工资条 API。
- Produces: `/payroll` 工作台 + `/payroll/runs/:id` 详情；查看/核算/复核/发布/导出分别独立授权；金额仅整数最小单位。

- [ ] **Step 1: 写能力分离测试**

```php
public function test_view_only_payroll_user_cannot_prepare_or_publish(): void
{
    $run = $this->draftPayrollRun();
    $user = $this->userWithRole('PAYROLL_VIEWER');

    $this->actingAs($user)->getJson("/v1/payroll/runs/{$run->id}")->assertOk();
    $this->actingAs($user)->postJson("/v1/payroll/runs/{$run->id}/prepare")->assertStatus(403);
    $this->actingAs($user)->postJson("/v1/payroll/runs/{$run->id}/publish")->assertStatus(403);
}
```

- [ ] **Step 2: 写金额与字段测试**

```php
public function test_payslip_amounts_are_integer_minor_units_and_masked_without_p2(): void
{
    $run = $this->publishedPayrollRun();
    $response = $this->actingAs($this->payrollSpecialist())->getJson("/v1/payroll/runs/{$run->id}/payslips");

    $response->assertOk()->assertJsonStructure(['data' => ['items' => [['gross_minor', 'net_minor']]]]);
    $this->assertIsInt($response->json('data.items.0.gross_minor'));
}
```

- [ ] **Step 3: 实现工作台**

- 列表显示期间、批次、状态、人数、总额（中文金额格式）。
- 详情显示输入、计算、复核、发布、发放、工资条。
- 每个动作独立 `ActionGate`；发布/发放二次确认；导出走独立能力。
- 工资条抽屉按字段策略遮罩；无权限不返回金额。

- [ ] **Step 4: E2E**

```ts
test('payroll specialist prepares and reviews a run', async ({ page }) => {
  await loginAs(page, 'demo.payroll')
  await page.goto('/payroll')
  await page.getByRole('row').filter({ hasText: '2026-11' }).getByRole('button', { name: '试算' }).click()
  await expect(page.getByText('已试算')).toBeVisible()
})
```

- [ ] **Step 5: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter='PayrollWorkspaceTest|PayrollRunTest|Payslip'
cd ../frontend && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/payroll-workspace.spec.ts
```

Expected: 查看/试算/复核/发布/导出权限分离；金额为整数；字段遮罩正确。

- [ ] **Step 6: Commit**

```bash
git add frontend/src/modules/payroll/pages/PayrollWorkspacePage.vue frontend/src/modules/payroll/pages/PayrollRunDetailPage.vue frontend/src/modules/payroll/components/PayrollRunTable.vue frontend/src/modules/payroll/components/PayrollActionBar.vue frontend/src/modules/payroll/components/PayslipDrawer.vue backend/app/Modules/Payroll/Http/Controllers/PayrollRunController.php backend/tests/Feature/Payroll/PayrollWorkspaceTest.php frontend/tests/e2e/real/payroll-workspace.spec.ts
git commit -m "feat(payroll): rebuild payroll workspace with separated actions"
```

---

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

    $payload = ['legal_entity_id' => 1, 'org_unit_id' => $this->deptA(), 'employee_no' => 'CONV-SYNTH-0001'];
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
    $this->actingAs($this->deptManager())
        ->postJson('/v1/reporting/exports', [
            'reportCode' => 'WORKFORCE_HEADCOUNT',
            'purpose' => 'BUSINESS_OPERATION',
            'fields' => ['employee_no', 'org_path'],
            'filters' => ['org_unit_id' => $this->deptB()],
        ], ['Idempotency-Key' => 'export-scope-negative'])
        ->assertStatus(403);
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

### Task 17: 平台与系统管理工作台（功能实现）

**Files:**
- Create: `frontend/src/modules/platform/iam/pages/UserAdminPage.vue`
- Create: `frontend/src/modules/platform/iam/pages/RoleAdminPage.vue`
- Create: `frontend/src/modules/platform/iam/pages/MenuAdminPage.vue`
- Create: `frontend/src/modules/platform/iam/pages/PermissionCatalogPage.vue`
- Create: `frontend/src/modules/platform/iam/pages/DataScopeAdminPage.vue`
- Create: `frontend/src/modules/platform/iam/pages/FieldPolicyAdminPage.vue`
- Create: `frontend/src/modules/platform/workflow/pages/WorkflowCenterPage.vue`
- Create: `frontend/src/modules/platform/workflow/pages/WorkflowCaseDetailPage.vue`
- Create: `frontend/src/modules/platform/settings/pages/CalendarSettingsPage.vue`
- Create: `frontend/src/modules/platform/settings/pages/DictionarySettingsPage.vue`
- Create: `frontend/src/modules/platform/settings/pages/NotificationCenterPage.vue`
- Create: `frontend/src/modules/platform/settings/pages/AuditLogPage.vue`
- Test: `frontend/tests/e2e/real/platform-workspaces.spec.ts`
- Test: `backend/tests/Feature/IAM/PlatformWorkspaceApiTest.php`

**Interfaces:**
- Consumes: 第 2 册的用户/角色/菜单/数据范围/字段策略 API、Workflow Case/Decision API、日历/字典/参数/通知/审计 API、`PageShell`/`DataTable`/`FormDialog`/`ActionGate`。
- Produces: 12 个独立平台工作台页面；每页有真实读写、动作权限、中文展示和五态；不再依赖 `PlatformWorkspace` 的 `mode` 分支。

- [ ] **Step 1: 写平台页面清单测试**

创建 `frontend/tests/unit/platformWorkspaces.spec.ts`：

```ts
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const pages = [
  'src/modules/platform/iam/pages/UserAdminPage.vue',
  'src/modules/platform/iam/pages/RoleAdminPage.vue',
  'src/modules/platform/iam/pages/MenuAdminPage.vue',
  'src/modules/platform/iam/pages/PermissionCatalogPage.vue',
  'src/modules/platform/iam/pages/DataScopeAdminPage.vue',
  'src/modules/platform/iam/pages/FieldPolicyAdminPage.vue',
  'src/modules/platform/workflow/pages/WorkflowCenterPage.vue',
  'src/modules/platform/workflow/pages/WorkflowCaseDetailPage.vue',
  'src/modules/platform/settings/pages/CalendarSettingsPage.vue',
  'src/modules/platform/settings/pages/DictionarySettingsPage.vue',
  'src/modules/platform/settings/pages/NotificationCenterPage.vue',
  'src/modules/platform/settings/pages/AuditLogPage.vue',
]

describe('platform workspaces', () => {
  it('each page uses PageShell and contains no mode-based workspace switch', () => {
    const root = resolve(__dirname, '../..')
    for (const page of pages) {
      const source = readFileSync(resolve(root, page), 'utf8')
      expect(source, page).toContain('PageShell')
      expect(source, page).not.toMatch(/PlatformWorkspace|mode\s*===/)
    }
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

```bash
cd frontend && pnpm exec vitest run tests/unit/platformWorkspaces.spec.ts
```

Expected: FAIL，12 个页面尚不存在。

- [ ] **Step 3: 实现 12 个独立页面**

每个页面必须：

- 使用 `PageShell` + `DataTable`/`ElTree`/`ElDescriptions`。
- 页头动作通过 `ActionGate` 绑定动作清单；无权限时不渲染可点击按钮。
- 列表/表单/详情均处理加载、空、错误、无权、部分失败五态。
- 不回显能力码、接口路径、表名或字段档位；技术信息仅写入审计/日志。
- 以真实 API 驱动，不使用静态数组伪装数据。

重点页面要求：

| 页面 | 必须具备 | 独立能力 |
|---|---|---|
| `UserAdminPage` | 列表、创建、编辑、启停、重置密码、角色分配、权限预览 | `iam.user.read/manage`、`iam.role.manage` |
| `RoleAdminPage` | 角色列表、创建/复制/停用、四 Tab、差异保存、成员 | `iam.role.manage`、`iam.scope.manage`、`iam.field.manage` |
| `MenuAdminPage` | 菜单树、名称/图标/排序/显示隐藏、关联已有页面 | `iam.menu.manage` |
| `PermissionCatalogPage` | 页面—动作—API—能力核对，只读+跳转 | `iam.permission.read` |
| `DataScopeAdminPage` | 角色动作范围、组织树选择、影响预览 | `iam.scope.manage` |
| `FieldPolicyAdminPage` | 字段可见/编辑/导出、P0–P3 中文、差异 | `iam.field.manage` |
| `WorkflowCenterPage` | 待办/已办/我发起、类型筛选、批量处理 | `workflow.case.read`、`workflow.decision.manage` |
| `WorkflowCaseDetailPage` | 业务摘要、审批链、决策、时间线、附件 | `workflow.case.read`、`workflow.decision.manage` + 对象范围 |
| `CalendarSettingsPage` | 年份、工作日、节假日、发布 | `time.calendar.manage` |
| `DictionarySettingsPage` | 字典/参数列表与编辑、审计 | `system.dictionary.manage`、`system.parameter.manage` |
| `NotificationCenterPage` | 未读/全部、已读、业务跳转 | `notification.read` |
| `AuditLogPage` | 事件筛选、详情、导出 | `iam.audit.read` |

- [ ] **Step 4: 补后端平台 API 测试**

创建 `backend/tests/Feature/IAM/PlatformWorkspaceApiTest.php`，至少覆盖：

```php
public function test_menu_admin_cannot_create_unknown_page(): void
{
    $this->actingAs($this->menuAdmin())
        ->postJson('/v1/system/menus', ['page_code' => 'fake', 'path' => '/fake', 'label' => '伪造'])
        ->assertStatus(422);
}

public function test_audit_page_requires_audit_capability(): void
{
    $this->actingAs($this->hrAdmin())->getJson('/v1/auth/events')->assertStatus(403);
    $this->actingAs($this->auditAdmin())->getJson('/v1/auth/events')->assertOk();
}

public function test_workflow_decision_requires_object_scope(): void
{
    $case = $this->caseInOtherDepartment();
    $this->actingAs($this->manager())
        ->postJson("/v1/cases/{$case->id}/decisions", ['decision' => 'APPROVE'])
        ->assertStatus(403);
}
```

- [ ] **Step 5: 写 E2E 冒烟**

`platform-workspaces.spec.ts` 至少验证：

- 管理员可打开 12 个页面且无空白页；
- 用户/角色页可完成一次真实保存；
- 菜单页伪造 URL 被拒；
- 审计页对无权限角色不可见；
- 每个页面不出现 `GET /v1/`、`ACTIVE`、`P0_` 等内部文案。

```ts
test('admin can open every platform workspace', async ({ page }) => {
  await loginAs(page, 'demo.admin')
  for (const path of ['/system/users', '/system/roles', '/system/menus', '/system/permissions', '/system/data-scopes', '/system/field-policies', '/workflow', '/system/calendars', '/system/settings', '/notifications', '/system/audit']) {
    await page.goto(path)
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  }
})
```

- [ ] **Step 6: 运行测试**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=PlatformWorkspaceApiTest
cd ../frontend
pnpm exec vitest run tests/unit/platformWorkspaces.spec.ts
HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/platform-workspaces.spec.ts
```

Expected: 12 页面加载、真实保存、越权拒绝、文案门禁通过。若某 API 尚缺，补后端契约，不得用静态数据占位。

- [ ] **Step 7: Commit**

```bash
git add frontend/src/modules/platform/iam/pages frontend/src/modules/platform/workflow/pages frontend/src/modules/platform/settings/pages frontend/tests/unit/platformWorkspaces.spec.ts frontend/tests/e2e/real/platform-workspaces.spec.ts backend/tests/Feature/IAM/PlatformWorkspaceApiTest.php
git commit -m "feat(platform): implement independent workspace pages"
```

---

### Task 18: 跨域回归与第 3 册验收

**Files:**
- Create: `docs/audit/2026-11-26-v2-volume-3-acceptance.md`
- Create: `frontend/tests/e2e/real/cross-domain.spec.ts`
- Modify: `docs/superpowers/plans/2026-11-26-v2-1-remediation-master-plan.md`

**Interfaces:**
- Consumes: Task 1–16 全部业务域。
- Produces: 跨域 E2E、性能基线、权限回归与第 3 册退出结论。

- [ ] **Step 1: 写跨域 E2E**

覆盖：招聘候选人录用 → 员工建档 → 异动调岗 → 合同续签 → 请假 → 考勤影响 → 薪资试算 → 绩效评分 → 培训记录 → 报表导出。

```ts
test('candidate to employee to payroll cross-domain journey', async ({ page }) => {
  await loginAs(page, 'demo.hr')
  // 候选人录用、员工详情、异动、合同、请假、薪资、绩效、培训、报表
  // 每步断言真实业务状态，不使用固定 sleep
})
```

- [ ] **Step 2: 运行跨域 API 与 E2E**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact
cd ../frontend && pnpm typecheck && pnpm lint && pnpm test:unit && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/cross-domain.spec.ts
```

Expected: 后端全量通过；前端全量通过；跨域 E2E 通过；未通过项阻断第 4 册。

- [ ] **Step 3: 记录性能基线**

在 `docs/audit/2026-11-26-v2-volume-3-acceptance.md` 记录：

- 员工列表 1k/10k 数据分页响应；
- 报表导出 1k/10k 行耗时；
- 薪资批次 1k 人试算耗时；
- 数据库索引与慢查询证据。

- [ ] **Step 4: 更新总控并提交**

```bash
git add docs/audit/2026-11-26-v2-volume-3-acceptance.md frontend/tests/e2e/real/cross-domain.spec.ts docs/superpowers/plans/2026-11-26-v2-1-remediation-master-plan.md
git commit -m "docs(plan): accept V2.1 volume 3 business domains"
```

---

## 第 3 册退出条件

- 员工原子建档、列表、详情、子档案、附件、变更、导出全部闭环。
- 部门、岗位、异动、合同、考勤、假勤、加班、出差、薪资、绩效、招聘、培训、报表、公告均有真实读写与中文展示。
- 12 个平台与系统管理工作台已独立实现，具备真实 API、五态、动作权限与中文展示。
- 每个业务域至少一条 E2E 与一项权限负测；数据范围与字段策略在 API 层生效。
- 跨域旅程通过：候选人 → 员工 → 异动 → 合同 → 假勤 → 薪资 → 绩效 → 培训 → 报表。
- 无跨模块 Tab；无假入口；无英文枚举、接口路径、表名、字段档位或演示文案。
- 第 4 册所需的共享壳退役清单、真实栈脚本与验收账号已冻结；第 4 册只做收敛、清理与发布验收，不重复实现业务页。
