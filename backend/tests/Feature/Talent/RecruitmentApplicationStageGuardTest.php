<?php

declare(strict_types=1);

namespace Tests\Feature\Talent;

use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\CandidateApplication;
use App\Modules\Talent\Models\Requisition;
use Illuminate\Support\Facades\Route;
use Tests\Support\TalentTestCase;

/**
 * State-machine regression: guard rejection must not mutate candidate or
 * application, and replays must not create a second application.
 */
final class RecruitmentApplicationStageGuardTest extends TalentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth.session'])->prefix('v1/recruitment')->group(function (): void {
            Route::post('/candidates/{id}/applications', [\App\Modules\Talent\Http\Controllers\RecruitmentController::class, 'storeApplication'])->whereNumber('id');
            Route::patch('/candidates/{id}/stage', [\App\Modules\Talent\Http\Controllers\RecruitmentController::class, 'moveCandidateStage'])->whereNumber('id');
        });
    }

    public function test_rejected_stage_transition_is_atomic(): void
    {
        $application = $this->application('GUARD-ATOMIC');

        $this->patchJson("/v1/recruitment/candidates/{$application->candidate_id}/stage", [
            'status' => 'CONVERTED',
            'application_id' => (int) $application->id,
        ], ['Idempotency-Key' => 'guard-atomic-1'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'INVALID_APPLICATION_TRANSITION');

        $this->assertSame('NEW', (string) $application->fresh()->stage);
        $this->assertSame('NEW', (string) Candidate::query()->findOrFail($application->candidate_id)->status);

        $this->patchJson("/v1/recruitment/candidates/{$application->candidate_id}/stage", [
            'status' => 'HIRED',
            'application_id' => (int) $application->id,
        ], ['Idempotency-Key' => 'guard-atomic-2'])
            ->assertConflict();
    }

    public function test_same_stage_submission_is_a_business_replay_not_a_second_write(): void
    {
        $application = $this->application('GUARD-REPLAY');

        $this->patchJson("/v1/recruitment/candidates/{$application->candidate_id}/stage", [
            'status' => 'NEW',
            'application_id' => (int) $application->id,
        ], ['Idempotency-Key' => 'guard-replay-1'])
            ->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.application_id', (int) $application->id);

        $this->assertSame(1, CandidateApplication::query()->where('candidate_id', $application->candidate_id)->count());
    }

    private function application(string $suffix): CandidateApplication
    {
        $org = $this->peopleScenario->orgUnit('GUARD-'.$suffix);
        $requisition = Requisition::query()->create([
            'org_unit_id' => (int) $org->id,
            'requisition_no' => 'REQ-GUARD-'.$suffix,
            'title' => '合成守卫岗位 '.$suffix,
            'status' => 'APPROVED',
            'headcount' => 1,
            'recorded_at' => $this->clock->now(),
        ]);
        $candidate = Candidate::query()->create([
            'display_name' => '合成守卫候选人 '.$suffix,
            'status' => 'NEW',
            'recorded_at' => $this->clock->now(),
        ]);

        return CandidateApplication::query()->create([
            'candidate_id' => (int) $candidate->id,
            'requisition_id' => (int) $requisition->id,
            'stage' => 'NEW',
            'status' => 'ACTIVE',
            'recorded_at' => $this->clock->now(),
        ]);
    }
}
