<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Contracts\WorkflowCasePort;
use App\Modules\Time\Services\LeaveAccountService;
use App\Modules\Time\Services\LeaveLedgerService;
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
        $this->artisan('workflow:seed-templates', ['--tenant-id' => 1])->assertSuccessful();
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
        $this->artisan('workflow:seed-templates', ['--tenant-id' => 1])->assertSuccessful();
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
        $this->artisan('workflow:seed-templates', ['--tenant-id' => 1])->assertSuccessful();
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
        $this->artisan('workflow:seed-templates', ['--tenant-id' => 1])->assertSuccessful();
        $this->bindWorkflowPrincipal();
        [$employmentId] = $this->seedManagerChain();
        $this->useProductionPeopleAdapters();

        // Use the production bindings for Time's workflow port.
        $this->app->forgetInstance(WorkflowCasePort::class);

        $type = \App\Modules\Time\Models\LeaveType::query()->create([
            'tenant_id' => 1,
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

        app(LeaveAccountService::class)->ensureAccount(1, $employmentId, (int) $type->id, '2026-01-01');
        app(LeaveLedgerService::class)->grant(1, $employmentId, (int) $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');

        $result = app(\App\Modules\Time\Services\LeaveRequestService::class)->request(
            tenantId: 1,
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
        $this->artisan('workflow:seed-templates', ['--tenant-id' => 1])->assertSuccessful();
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
        $scenario = new \Tests\Support\PeopleScenario(1, $this->clock);
        $entity = $scenario->legalEntity();
        $unit = $scenario->orgUnit($entity, 'WF-UNIT');

        $managerPerson = $scenario->person(['legal_name' => 'Synthetic Manager']);
        $managerEmployment = $scenario->employment($managerPerson, $entity, 'WF-0001');
        $managerAssignment = $scenario->assignment($managerEmployment, $unit);
        $scenario->userLink(20, $managerEmployment);

        $employeePerson = $scenario->person(['legal_name' => 'Synthetic Employee']);
        $employeeEmployment = $scenario->employment($employeePerson, $entity, 'WF-0002');
        $scenario->assignment($employeeEmployment, $unit, ['manager_assignment_id' => $managerAssignment->id]);
        $scenario->userLink(10, $employeeEmployment);

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
            tenantId: 1,
            userId: 10,
            employmentId: 1,
            authVersion: 1,
            isPrivileged: false,
            roles: ['EMPLOYEE'],
            capabilities: ['workflow.case.create', 'workflow.case.read'],
        );
    }
}
