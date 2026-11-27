<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;
use App\Modules\Lifecycle\Models\LifecycleTask;
use App\Modules\People\Models\Assignment;
use App\Modules\Workflow\Models\WorkflowDelegation;
use Tests\Support\LifecycleTestCase;

final class OffboardingOffboardTest extends LifecycleTestCase
{
    public function test_offboarding_requires_checklist_resolution_and_revokes_access_in_same_effect(): void
    {
        $scenario = $this->peopleScenario;
        $org = $scenario->orgUnit('OFF-ORG');
        $person = $scenario->person();
        $employment = $scenario->employment($person, 'OFF-0001');
        $scenario->assignment($employment, $org);
        $user = User::factory()->create(['auth_version' => 1]);
        $user->forceFill(['person_id' => $person->id])->save();
        $scenario->userLink((int) $user->id, $employment);
        $session = Session::factory()->create(['user_id' => $user->id, 'auth_version' => 1]);
        $delegation = WorkflowDelegation::query()->create([
            'proxy_user_id' => 202,
            'principal_user_id' => $user->id,
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'scope_json' => [],
            'active' => true,
            'created_by' => 10,
        ]);

        $created = $this->createLifecycleCase('offboardings', [
            'employment_id' => $employment->id,
            'last_working_date' => '2026-11-30',
            'termination_type' => 'RESIGNATION',
            'reason_code' => 'PERSONAL',
            'submit' => true,
        ], 'offboard-create')->assertCreated();
        $caseId = (int) $created->json('data.case_id');

        foreach (['ACCOUNT_PROVISION', 'CONTRACT_SIGNATURE', 'PAYROLL_ENROLLMENT', 'PROBATION_END_DATE'] as $index => $code) {
            $task = LifecycleTask::query()->create([
                'case_id' => $caseId,
                'employment_id' => $employment->id,
                'task_code' => $code,
                'title' => $code,
                'status' => 'PENDING',
                'required' => true,
                'recorded_at' => $this->clock->now(),
                'source_type' => 'CASE',
                'source_id' => $caseId,
            ]);

            $this->patchJson("/v1/onboarding-tasks/{$task->id}", [
                'status' => $index === 3 ? 'WAIVED' : 'DONE',
                'waiver_reason' => $index === 3 ? 'No probation configured.' : null,
            ], ['Idempotency-Key' => "offboard-task-{$index}"])->assertOk();
        }

        $this->approveCase($caseId, 'offboard-approve')
            ->assertOk()
            ->assertJsonPath('data.effect_status', 'APPLIED');

        $employment->refresh();
        $this->assertSame('TERMINATED', $employment->status);
        $this->assertSame('2026-11-30', (string) $employment->termination_date);
        $this->assertSame('2026-12-01', (string) $employment->valid_to);
        $this->assertSame('2026-12-01', (string) Assignment::query()->where('employment_id', $employment->id)->firstOrFail()->valid_to);

        $this->assertNotNull(Session::query()->findOrFail($session->id)->revoked_at);
        $this->assertSame(2, (int) $user->fresh()?->auth_version);
        $this->assertFalse((bool) WorkflowDelegation::query()->findOrFail($delegation->id)->active);
    }
}
