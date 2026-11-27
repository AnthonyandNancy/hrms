<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Services\EmployeeProfileChangeEffectHandler;
use App\Modules\Workflow\Contracts\WorkflowPrincipalResolver;
use App\Modules\Workflow\Domain\EffectRegistry;
use App\Modules\Workflow\Services\DomainEffectHandlerAdapter;
use Tests\Support\FakeWorkflowPrincipalResolver;
use Tests\Support\PeopleTestCase;

/**
 * The People adapter opens a case without touching Workflow tables directly.
 * A real gateway-backed case is created, then the local effect is applied.
 */
final class EmployeeChangeWorkflowAdapterTest extends PeopleTestCase
{
    public function test_adapter_creates_a_real_case_and_effect_handler_registered(): void
    {
        $this->bindWorkflowPrincipal(10);

        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'CHG-ADAPTER-1');
        $scenario->assignment($employment, $scenario->orgUnit('CHG-ADAPTER-ORG'));

        $recordId = (int) $this->postJson("/v1/employees/{$employment->id}/education", [
            'school' => '合成大学',
            'degree' => 'BACHELOR',
            'start_date' => '2010-09-01',
        ], ['Idempotency-Key' => 'chg-adapter-education'])->assertCreated()->json('data.id');

        $id = (int) $this->postJson("/v1/employees/{$employment->id}/change_requests", [
            'reason' => '合成变更',
            'diff' => [
                'change_type' => 'PROFILE_CORRECTION',
                'resource' => 'education',
                'record_id' => $recordId,
                'changes' => ['major' => '合成新专业'],
            ],
        ], ['Idempotency-Key' => 'chg-adapter-create'])->assertCreated()->json('data.id');

        $submitted = $this->postJson(
            "/v1/employees/{$employment->id}/change_requests/{$id}/submit",
            [],
            ['Idempotency-Key' => 'chg-adapter-submit'],
        )->assertOk();

        $caseId = (int) $submitted->json('data.case_id');
        $this->assertGreaterThan(0, $caseId);

        $case = \App\Modules\Workflow\Models\CaseModel::query()->findOrFail($caseId);
        $this->assertSame('EMPLOYEE_PROFILE_CHANGE', (string) $case->type);
        $this->assertSame('employment', (string) $case->subject_type);
        $this->assertSame((int) $employment->id, (int) $case->subject_id);
        $this->assertSame('SUBMITTED', $case->status->value);
    }

    public function test_local_effect_applies_the_approved_change_to_the_profile(): void
    {
        $this->bindWorkflowPrincipal(10);
        $this->app->instance(EffectRegistry::class, new EffectRegistry(
            [new DomainEffectHandlerAdapter(app(EmployeeProfileChangeEffectHandler::class))],
            [EmployeeProfileChangeEffectHandler::EFFECT_TYPE],
        ));

        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'CHG-ADAPTER-2');
        $scenario->assignment($employment, $scenario->orgUnit('CHG-ADAPTER-ORG-2'));

        $recordId = (int) $this->postJson("/v1/employees/{$employment->id}/education", [
            'school' => '合成大学',
            'degree' => 'BACHELOR',
            'major' => '旧专业',
            'start_date' => '2010-09-01',
        ], ['Idempotency-Key' => 'chg-adapter-education-2'])->assertCreated()->json('data.id');

        $id = (int) $this->postJson("/v1/employees/{$employment->id}/change_requests", [
            'reason' => '合成变更',
            'diff' => [
                'change_type' => 'PROFILE_CORRECTION',
                'resource' => 'education',
                'record_id' => $recordId,
                'changes' => ['major' => '合成新专业'],
            ],
        ], ['Idempotency-Key' => 'chg-adapter-create-2'])->assertCreated()->json('data.id');

        $caseId = (int) $this->postJson(
            "/v1/employees/{$employment->id}/change_requests/{$id}/submit",
            [],
            ['Idempotency-Key' => 'chg-adapter-submit-2'],
        )->assertOk()->json('data.case_id');

        $this->postJson("/v1/cases/{$caseId}/decisions", [
            'action' => 'APPROVE',
            'comment' => '合成审批通过',
        ], ['Idempotency-Key' => 'chg-adapter-approve'])->assertOk();

        $this->assertDatabaseHas('employee_educations', [
            'employment_id' => $employment->id,
            'major' => '合成新专业',
            'superseded_at' => null,
        ]);
        $this->assertDatabaseHas('employee_change_requests', [
            'id' => $id,
            'status' => 'APPROVED',
            'case_id' => $caseId,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'people.employee.change.applied']);
    }

    private function bindWorkflowPrincipal(int $userId): void
    {
        $resolver = new FakeWorkflowPrincipalResolver;
        $resolver->setPrincipal(new \App\Modules\Workflow\Contracts\WorkflowPrincipal(
            userId: $userId,
            employmentId: null,
            authVersion: 1,
            isPrivileged: false,
            capabilities: [
                'workflow.case.read',
                'workflow.case.create',
                'workflow.case.decide',
                'workflow.case.admin',
            ],
        ));
        $this->app->instance(WorkflowPrincipalResolver::class, $resolver);
        $this->app->instance(
            \App\Modules\Workflow\Contracts\AssigneeDirectory::class,
            new class implements \App\Modules\Workflow\Contracts\AssigneeDirectory
            {
                public function managerUserIdForSubject(string $subjectType, int $subjectId, string $businessDate): ?int
                {
                    return null;
                }

                public function orgHeadUserIdsForSubject(string $subjectType, int $subjectId, string $businessDate): array
                {
                    return [];
                }

                public function userIdsWithRole(string $roleCode, string $businessDate): array
                {
                    return [10];
                }

                public function reassignmentCandidates(int $caseId, int $currentAssigneeId, string $businessDate): array
                {
                    return [];
                }
            },
        );
        $this->app->instance(
            \App\Modules\Workflow\Contracts\AssigneeEligibility::class,
            new \Tests\Support\FakeAssigneeEligibility,
        );
        $this->app->instance(
            \App\Modules\Workflow\Contracts\ActorDirectory::class,
            new \Tests\Support\FakeActorDirectory,
        );
    }
}
