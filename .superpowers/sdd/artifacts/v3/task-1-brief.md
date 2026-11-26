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
     * approvedOvertime(int), annualLeave(), userWithRole(string), deptManager(),
     * manager(), menuAdmin(), auditAdmin(), activeCycle(), trainingSession(),
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

