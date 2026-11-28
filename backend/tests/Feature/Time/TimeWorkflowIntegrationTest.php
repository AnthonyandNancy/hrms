<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Contracts\WorkflowCasePort;
use App\Modules\Time\Services\LeaveAccountService;
use App\Modules\Time\Services\LeaveLedgerService;
use App\Modules\Time\Services\LeavePolicyService;
use App\Modules\Workflow\Contracts\CaseCommandPort;
use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Contracts\WorkflowPrincipalResolver;
use App\Modules\Workflow\Models\CaseModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FrozenClock;
use Tests\TestCase;

/**
 * Proves the REAL Time -> Workflow path, not the FakeWorkflowCasePort used by
 * TimeTestCase. Time opens Cases with an internal type such as LEAVE_REQUEST;
 * the published template catalog names the same flow leave.request. Without an
 * explicit mapping the submit fails with "No published workflow template
 * exists for this case type." in every real environment.
 */
final class TimeWorkflowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(\Carbon\CarbonImmutable::parse('2026-11-23T09:00:00Z'));
        $this->app->instance(\App\Shared\Time\Clock::class, $this->clock);
    }

    public function test_real_adapter_open_resolves_a_published_template_for_a_leave_request_case_type(): void
    {
        $this->artisan('workflow:seed-templates')->assertSuccessful();
        $this->bindWorkflowPrincipal();
        [$employmentId] = $this->seedManagerChain();
        $this->useProductionPeopleAdapters();

        $case = app(CaseCommandPort::class)->open(
            principal: $this->principal(),
            type: 'LEAVE_REQUEST',
            subjectType: 'employment',
            subjectId: $employmentId,
            title: 'Leave request',
            payload: [
                'employment_id' => 1,
                'from_date' => '2026-11-24',
                'to_date' => '2026-11-25',
                'minutes' => 960,
                'reason' => 'Synthetic family trip',
            ],
            businessDate: '2026-11-24',
        );

        $this->assertGreaterThan(0, $case['id']);
        $this->assertNotNull($case['template_id']);

        $stored = CaseModel::query()->whereKey($case['id'])->firstOrFail();
        $this->assertSame('LEAVE_REQUEST', (string) $stored->type);
        $this->assertDatabaseHas('workflow_templates', [
            'id' => $case['template_id'],
            'code' => 'leave.request',
            'status' => 'PUBLISHED',
        ]);
    }

    public function test_real_adapter_open_resolves_a_published_template_for_an_attendance_correction_case_type(): void
    {
        $this->artisan('workflow:seed-templates')->assertSuccessful();
        $this->bindWorkflowPrincipal();
        [$employmentId] = $this->seedManagerChain();
        $this->useProductionPeopleAdapters();

        $case = app(CaseCommandPort::class)->open(
            principal: $this->principal(),
            type: 'ATTENDANCE_CORRECTION',
            subjectType: 'employment',
            subjectId: $employmentId,
            title: 'Attendance correction',
            payload: [
                'employment_id' => 1,
                'business_date' => '2026-11-23',
                'reason' => 'Device missed the punch',
                'corrected_punches' => ['2026-11-23T01:00:00Z', '2026-11-23T10:00:00Z'],
            ],
            businessDate: '2026-11-23',
        );

        $this->assertGreaterThan(0, $case['id']);

        $stored = CaseModel::query()->whereKey($case['id'])->firstOrFail();
        $this->assertSame('ATTENDANCE_CORRECTION', (string) $stored->type);
        $this->assertDatabaseHas('workflow_templates', [
            'id' => $case['template_id'],
            'code' => 'attendance.correction',
            'status' => 'PUBLISHED',
        ]);
    }

    public function test_an_unmapped_case_type_still_fails_closed(): void
    {
        $this->artisan('workflow:seed-templates')->assertSuccessful();
        $this->bindWorkflowPrincipal();

        $this->expectException(\App\Shared\Exceptions\ValidationException::class);

        app(CaseCommandPort::class)->open(
            principal: $this->principal(),
            type: 'NOT_A_REAL_CASE_TYPE',
            subjectType: 'employment',
            subjectId: 1,
            title: 'Unknown flow',
            payload: [],
            businessDate: '2026-11-23',
        );
    }

    public function test_time_submits_a_leave_request_through_the_real_workflow_adapter(): void
    {
        $this->artisan('workflow:seed-templates')->assertSuccessful();
        $this->bindWorkflowPrincipal();
        [$employmentId] = $this->seedManagerChain();
        $this->useProductionPeopleAdapters();

        // Use the production bindings for Time's workflow port.
        $this->app->forgetInstance(WorkflowCasePort::class);

        $type = \App\Modules\Time\Models\LeaveType::query()->create([
            'code' => 'ANNUAL',
            'name' => 'Annual leave',
            'is_statutory' => true,
            'is_annual' => true,
            'unit' => 'DAY',
            'annual_entitlement_days' => '15.00',
            'requires_attachment' => false,
            'allow_negative' => false,
            'expiry_rule_json' => null,
            'legal_basis' => 'State Council Order 514',
            'created_at' => $this->clock->now(),
        ]);

        app(LeavePolicyService::class)->append([
            'leave_type_id' => (int) $type->id,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'accrual' => ['mode' => 'ANNUAL_FIXED', 'minutes_per_year' => 4800],
            'payroll_treatment' => 'PAID',
            'min_notice_days' => 0,
            'allow_half_day' => true,
        ], 10);
        app(LeaveAccountService::class)->ensureAccount($employmentId, (int) $type->id, '2026-01-01');
        app(LeaveLedgerService::class)->grant($employmentId, (int) $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');

        $result = app(\App\Modules\Time\Services\LeaveRequestService::class)->request(
            employmentId: $employmentId,
            userId: 10,
            leaveTypeCode: 'ANNUAL',
            fromDate: '2026-11-24',
            toDate: '2026-11-25',
            halfDay: false,
            reason: 'Synthetic family trip',
        );

        $this->assertGreaterThan(0, $result['case_id']);
        $this->assertDatabaseHas('cases', [
            'id' => $result['case_id'],
            'type' => 'LEAVE_REQUEST',
        ]);
        $this->assertDatabaseHas('leave_ledger_entries', [
            'case_id' => $result['case_id'],
            'account' => 'RESERVE',
        ]);
    }

    public function test_manager_approval_resolves_the_subjects_real_manager(): void
    {
        $this->artisan('workflow:seed-templates')->assertSuccessful();
        $this->bindWorkflowPrincipal();

        [$employmentId] = $this->seedManagerChain();

        // Production manager resolution lives in People; bind its real adapter
        // because the testing environment otherwise installs an in-memory fake.
        $this->useProductionPeopleAdapters();

        $case = app(CaseCommandPort::class)->open(
            principal: $this->principal(),
            type: 'LEAVE_REQUEST',
            subjectType: 'employment',
            subjectId: $employmentId,
            title: 'Leave request',
            payload: ['employment_id' => $employmentId],
            businessDate: '2026-11-24',
        );

        $this->assertGreaterThan(0, $case['id']);
        $this->assertDatabaseHas('workflow_tasks', [
            'case_id' => $case['id'],
            'assignee_user_id' => 20,
            'status' => 'PENDING',
        ]);
    }

    /**
     * Build a real org/employment manager chain plus links so approval routing
     * can resolve an assignee.
     *
     * @return array{0: int, 1: int}
     */
    private function seedManagerChain(): array
    {
        // The cutover schema carries no company-entity columns, so this
        // Time-owned integration fixture seeds the company org directly.
        $unit = \App\Modules\Organization\Models\OrgUnit::query()->create([
            'code' => 'WF-UNIT',
            'name' => 'WF-UNIT',
            'type' => 'DEPARTMENT',
            'status' => 'ACTIVE',
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ]);

        \Illuminate\Support\Facades\DB::table('org_unit_closure')->insert([
            'ancestor_id' => $unit->id,
            'descendant_id' => $unit->id,
            'depth' => 0,
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ]);

        $managerPerson = \App\Modules\People\Models\Person::query()->create([
            'legal_name' => 'Synthetic Manager',
            'birth_date' => '1990-01-01',
            'gender' => 'FEMALE',
            'nationality' => 'CN',
            'email' => 'manager@example.test',
        ]);
        $managerEmployment = \App\Modules\People\Models\Employment::query()->create([
            'person_id' => $managerPerson->id,
            'employee_no' => 'WF-0001',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'hire_date' => '2026-01-01',
            'seniority_start_date' => '2026-01-01',
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ]);
        $managerAssignment = \App\Modules\People\Models\Assignment::query()->create([
            'employment_id' => $managerEmployment->id,
            'org_unit_id' => $unit->id,
            'manager_assignment_id' => null,
            'assignment_type' => 'PRIMARY',
            'is_primary' => true,
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ]);
        \App\Modules\People\Models\EmploymentUserLink::query()->create([
            'user_id' => 20,
            'employment_id' => $managerEmployment->id,
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ]);

        $employeePerson = \App\Modules\People\Models\Person::query()->create([
            'legal_name' => 'Synthetic Employee',
            'birth_date' => '1990-01-01',
            'gender' => 'FEMALE',
            'nationality' => 'CN',
            'email' => 'employee@example.test',
        ]);
        $employeeEmployment = \App\Modules\People\Models\Employment::query()->create([
            'person_id' => $employeePerson->id,
            'employee_no' => 'WF-0002',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'hire_date' => '2026-01-01',
            'seniority_start_date' => '2026-01-01',
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ]);
        \App\Modules\People\Models\Assignment::query()->create([
            'employment_id' => $employeeEmployment->id,
            'org_unit_id' => $unit->id,
            'manager_assignment_id' => $managerAssignment->id,
            'assignment_type' => 'PRIMARY',
            'is_primary' => true,
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ]);
        \App\Modules\People\Models\EmploymentUserLink::query()->create([
            'user_id' => 10,
            'employment_id' => $employeeEmployment->id,
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ]);

        return [(int) $employeeEmployment->id, 20];
    }

    private function useProductionPeopleAdapters(): void
    {
        $this->app->instance(
            \App\Shared\Authorization\Contracts\PersonEmploymentDirectory::class,
            new \App\Modules\People\Authorization\EloquentPersonEmploymentDirectory,
        );
        $this->app->instance(
            \App\Shared\Authorization\Contracts\EmploymentManagerDirectory::class,
            new \App\Modules\People\Authorization\EloquentEmploymentManagerDirectory,
        );
    }

    private function bindWorkflowPrincipal(): void
    {
        $resolver = new class implements WorkflowPrincipalResolver
        {
            public ?WorkflowPrincipal $principal = null;

            public function resolve(): ?WorkflowPrincipal
            {
                return $this->principal;
            }
        };

        $resolver->principal = $this->principal();
        $this->app->instance(WorkflowPrincipalResolver::class, $resolver);
    }

    private function principal(): WorkflowPrincipal
    {
        return new WorkflowPrincipal(
            userId: 10,
            employmentId: 1,
            authVersion: 1,
            isPrivileged: false,
            roles: ['EMPLOYEE'],
            capabilities: ['workflow.case.create', 'workflow.case.read'],
        );
    }
}
