<?php

declare(strict_types=1);

namespace Tests\Feature\Talent;

use App\Modules\Talent\Http\Controllers\RecruitmentController;
use App\Modules\Talent\Http\Controllers\RecruitmentFunnelController;
use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\CandidateApplication;
use App\Modules\Talent\Models\Requisition;
use Illuminate\Support\Facades\Route;
use Tests\Support\TalentTestCase;

/**
 * Candidate applications are the fact row binding candidate, requisition and
 * opening. These tests use a test-only route fixture because routes/api.php is
 * owned by the lead agent in this workspace.
 */
final class CandidateApplicationTest extends TalentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth.session'])->prefix('v1/recruitment')->group(function (): void {
            Route::get('/candidates/{id}/applications', [RecruitmentController::class, 'applications'])->whereNumber('id');
            Route::post('/candidates/{id}/applications', [RecruitmentController::class, 'storeApplication'])->whereNumber('id');
            Route::patch('/candidates/{id}/stage', [RecruitmentController::class, 'moveCandidateStage'])->whereNumber('id');
            Route::get('/candidates', [RecruitmentFunnelController::class, 'candidates']);
        });
    }

    public function test_application_is_unique_per_candidate_and_requisition_and_is_read_back(): void
    {
        [$candidate, $requisition] = $this->candidateAndApprovedRequisition('APP-UNIQUE');

        $first = $this->postJson("/v1/recruitment/candidates/{$candidate->id}/applications", [
            'requisition_id' => (int) $requisition->id,
            'source' => 'REFERRAL',
        ], ['Idempotency-Key' => 'application-unique-1'])
            ->assertCreated()
            ->assertJsonPath('data.candidate_id', (int) $candidate->id)
            ->assertJsonPath('data.requisition_id', (int) $requisition->id)
            ->assertJsonPath('data.stage', 'NEW')
            ->assertJsonPath('data.replayed', false);

        $applicationId = (int) $first->json('data.application_id');
        $this->assertGreaterThan(0, $applicationId);

        $this->postJson("/v1/recruitment/candidates/{$candidate->id}/applications", [
            'requisition_id' => (int) $requisition->id,
            'source' => 'REFERRAL',
        ], ['Idempotency-Key' => 'application-unique-2'])
            ->assertOk()
            ->assertJsonPath('data.application_id', $applicationId)
            ->assertJsonPath('data.replayed', true);

        $this->assertSame(1, CandidateApplication::query()->where('candidate_id', $candidate->id)->count());

        $this->getJson("/v1/recruitment/candidates/{$candidate->id}/applications")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.application_id', $applicationId)
            ->assertJsonPath('data.items.0.requisition_no', (string) $requisition->requisition_no);
    }

    public function test_closed_requisition_rejects_new_application_with_409(): void
    {
        $candidate = Candidate::query()->create([
            'display_name' => '合成关闭需求候选人',
            'status' => 'NEW',
            'recorded_at' => $this->clock->now(),
        ]);
        $org = $this->peopleScenario->orgUnit('APP-CLOSED');
        $requisition = Requisition::query()->create([
            'org_unit_id' => (int) $org->id,
            'requisition_no' => 'APP-CLOSED-REQ',
            'title' => '合成关闭岗位',
            'status' => 'CLOSED',
            'headcount' => 1,
            'recorded_at' => $this->clock->now(),
        ]);

        $this->postJson("/v1/recruitment/candidates/{$candidate->id}/applications", [
            'requisition_id' => (int) $requisition->id,
        ], ['Idempotency-Key' => 'application-closed-1'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'REQUISITION_CLOSED');

        $this->assertSame(0, CandidateApplication::query()->count());
    }

    public function test_stage_move_rejects_skipping_to_hired_and_never_fakes_conversion(): void
    {
        [$candidate, $requisition] = $this->candidateAndApprovedRequisition('APP-STAGE');
        $application = CandidateApplication::query()->create([
            'candidate_id' => (int) $candidate->id,
            'requisition_id' => (int) $requisition->id,
            'stage' => 'NEW',
            'status' => 'ACTIVE',
            'recorded_at' => $this->clock->now(),
        ]);

        $this->patchJson("/v1/recruitment/candidates/{$candidate->id}/stage", [
            'status' => 'HIRED',
            'application_id' => (int) $application->id,
        ], ['Idempotency-Key' => 'application-stage-skip-1'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'INVALID_APPLICATION_TRANSITION');

        $this->assertSame('NEW', (string) $application->fresh()->stage);
    }

    public function test_candidate_projection_uses_application_requisition_and_exposes_application_stage(): void
    {
        [$candidate, $requisition] = $this->candidateAndApprovedRequisition('APP-PROJECTION');
        CandidateApplication::query()->create([
            'candidate_id' => (int) $candidate->id,
            'requisition_id' => (int) $requisition->id,
            'stage' => 'SCREENING',
            'status' => 'ACTIVE',
            'recorded_at' => $this->clock->now(),
        ]);

        $this->getJson('/v1/recruitment/candidates?candidate_id='.(int) $candidate->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.requisition_id', (int) $requisition->id)
            ->assertJsonPath('data.items.0.application_stage', 'SCREENING');

        $this->getJson('/v1/recruitment/candidates?requisition_id='.(int) $requisition->id)
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    /**
     * @return array{0:Candidate,1:Requisition}
     */
    private function candidateAndApprovedRequisition(string $suffix): array
    {
        $org = $this->peopleScenario->orgUnit($suffix);
        $requisition = Requisition::query()->create([
            'org_unit_id' => (int) $org->id,
            'requisition_no' => 'REQ-'.$suffix,
            'title' => '合成申请岗位 '.$suffix,
            'status' => 'APPROVED',
            'headcount' => 1,
            'recorded_at' => $this->clock->now(),
        ]);
        $candidate = Candidate::query()->create([
            'display_name' => '合成申请候选人 '.$suffix,
            'status' => 'NEW',
            'recorded_at' => $this->clock->now(),
        ]);

        return [$candidate, $requisition];
    }
}
