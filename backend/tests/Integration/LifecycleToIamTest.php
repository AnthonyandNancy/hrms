<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;
use App\Modules\Lifecycle\Models\LifecycleTask;
use Tests\Support\LifecycleTestCase;

final class LifecycleToIamTest extends LifecycleTestCase
{
    public function test_offboarding_revokes_sessions_and_auth_version_via_iam_contract(): void
    {
        $scenario = $this->peopleScenario;
        $org = $scenario->orgUnit('IAM-INT-ORG');
        $person = $scenario->person();
        $employment = $scenario->employment($person, 'IAM-INT-1');
        $scenario->assignment($employment, $org);

        $user = User::factory()->create(['auth_version' => 4]);
        $user->forceFill(['person_id' => $person->id])->save();
        $scenario->userLink((int) $user->id, $employment);
        $session = Session::factory()->create(['user_id' => $user->id, 'auth_version' => 4]);

        $created = $this->createLifecycleCase('offboardings', [
            'employment_id' => $employment->id,
            'last_working_date' => '2026-11-30',
            'termination_type' => 'RESIGNATION',
            'reason_code' => 'PERSONAL',
            'submit' => true,
        ], 'iam-int-offboard')->assertCreated();
        $caseId = (int) $created->json('data.case_id');

        foreach (['ACCOUNT_PROVISION', 'CONTRACT_SIGNATURE', 'PAYROLL_ENROLLMENT', 'PROBATION_END_DATE'] as $code) {
            LifecycleTask::query()->create([
                'case_id' => $caseId,
                'employment_id' => $employment->id,
                'task_code' => $code,
                'title' => $code,
                'status' => 'WAIVED',
                'required' => true,
                'waiver_reason' => 'Integration test waiver.',
                'recorded_at' => $this->clock->now(),
                'source_type' => 'CASE',
                'source_id' => $caseId,
            ]);
        }

        $this->approveCase($caseId, 'iam-int-offboard-approve')->assertOk()->assertJsonPath('data.effect_status', 'APPLIED');

        $this->assertNotNull(Session::query()->findOrFail($session->id)->revoked_at);
        $this->assertSame(5, (int) $user->fresh()?->auth_version);
    }
}
