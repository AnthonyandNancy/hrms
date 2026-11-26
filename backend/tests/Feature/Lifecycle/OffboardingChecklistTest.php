<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\Lifecycle\Models\LifecycleTask;
use Tests\Support\LifecycleTestCase;

final class OffboardingChecklistTest extends LifecycleTestCase
{
    public function test_offboarding_rejects_unresolved_required_checklist_at_effect_time(): void
    {
        $scenario = $this->peopleScenario;
        $org = $scenario->orgUnit('OFF-REJECT-ORG');
        $employment = $scenario->employment($scenario->person(), 'OFF-0002');
        $scenario->assignment($employment, $org);

        $created = $this->createLifecycleCase('offboardings', [
            'employment_id' => $employment->id,
            'last_working_date' => '2026-11-30',
            'termination_type' => 'RESIGNATION',
            'reason_code' => 'PERSONAL',
            'submit' => true,
        ], 'offboard-unresolved-create')->assertCreated();

        $caseId = (int) $created->json('data.case_id');

        LifecycleTask::query()->create([
            'case_id' => $caseId,
            'employment_id' => $employment->id,
            'task_code' => 'ACCOUNT_PROVISION',
            'title' => 'Provision',
            'status' => 'PENDING',
            'required' => true,
            'recorded_at' => $this->clock->now(),
            'source_type' => 'CASE',
            'source_id' => $caseId,
        ]);

        $this->approveCase($caseId, 'offboard-unresolved-approve')
            ->assertOk()
            ->assertJsonPath('data.effect_status', 'FAILED');

        $this->assertSame('ACTIVE', $employment->fresh()?->status);
    }
}
