<?php

declare(strict_types=1);

namespace Tests\Feature\Talent;

use App\Modules\People\Models\Person;
use App\Modules\Talent\Http\Controllers\RecruitmentController;
use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\CandidateApplication;
use App\Modules\Talent\Models\CandidateConversion;
use App\Modules\Talent\Models\Interview;
use App\Modules\Talent\Models\Offer;
use App\Modules\Talent\Models\Requisition;
use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\SpySensitiveAccessAuditor;
use Tests\Support\TalentTestCase;

/**
 * Volume 3 Task 14 recruitment workspace.
 *
 * These workspace actions have no production route yet:
 *   PATCH /v1/recruitment/requisitions/{id}
 *   POST  /v1/recruitment/requisitions/{id}/close
 *   PATCH /v1/recruitment/candidates/{id}/stage
 *   GET   /v1/recruitment/candidates/{id}/interviews
 *   GET   /v1/recruitment/candidates  (must serve the workspace projection)
 *
 * They are registered here as test-only fixtures so controller behaviour is
 * proven without editing the forbidden route file. The production route
 * additions are listed in the task report and must be wired by the lead.
 */
final class RecruitmentWorkspaceTest extends TalentTestCase
{
    private SpySensitiveAccessAuditor $auditor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditor = new SpySensitiveAccessAuditor;
        $this->app->instance(SensitiveAccessAuditor::class, $this->auditor);

        Route::middleware(['auth.session'])->prefix('v1/recruitment')->group(function (): void {
            Route::get('/workspace/candidates', [RecruitmentController::class, 'candidates']);
            Route::patch('/requisitions/{id}', [RecruitmentController::class, 'updateRequisition'])->whereNumber('id');
            Route::post('/requisitions/{id}/close', [RecruitmentController::class, 'closeRequisition'])->whereNumber('id');
            Route::patch('/candidates/{id}/stage', [RecruitmentController::class, 'moveCandidateStage'])->whereNumber('id');
            Route::get('/candidates/{id}/interviews', [RecruitmentController::class, 'interviews'])->whereNumber('id');
        });
    }

    public function test_requisition_workspace_exposes_org_unit_headcount_status_and_opened_date(): void
    {
        $org = $this->peopleScenario->orgUnit('WORKSPACE-REQ-ORG', null, '合成招聘部门');

        $created = $this->postJson('/v1/recruitment/requisitions', [
            'org_unit_id' => (int) $org->id,
            'requisition_no' => 'REQ-WORKSPACE-0001',
            'title' => '合成工程师',
            'headcount' => 3,
            'budget_minor' => 12_000_050,
            'budget_currency' => 'CNY',
            'target_start_date' => '2027-01-04',
        ], ['Idempotency-Key' => 'workspace-req-create-1'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING_APPROVAL')
            ->assertJsonPath('data.budget_minor', 12_000_050);

        $requisitionId = (int) $created->json('data.requisition_id');

        $response = $this->getJson('/v1/recruitment/requisitions')
            ->assertOk()
            ->assertJsonPath('data.items.0.requisition_id', $requisitionId)
            ->assertJsonPath('data.items.0.title', '合成工程师')
            ->assertJsonPath('data.items.0.org_unit_id', (int) $org->id)
            ->assertJsonPath('data.items.0.org_unit_name', '合成招聘部门')
            ->assertJsonPath('data.items.0.headcount', 3)
            ->assertJsonPath('data.items.0.status', 'PENDING_APPROVAL')
            ->assertJsonPath('data.items.0.budget_minor', 12_000_050)
            ->assertJsonStructure(['data' => ['items' => [[
                'opened_at', 'target_start_date',
            ]]]]);

        $this->assertIsString($response->json('data.items.0.opened_at'));
        $this->assertNotSame('', $response->json('data.items.0.opened_at'));
    }

    public function test_requisition_can_be_edited_then_closed_with_manage_capability(): void
    {
        $org = $this->peopleScenario->orgUnit('WORKSPACE-REQ-EDIT-ORG');

        $requisition = $this->postJson('/v1/recruitment/requisitions', [
            'org_unit_id' => (int) $org->id,
            'requisition_no' => 'REQ-WORKSPACE-EDIT',
            'title' => '合成编辑前岗位',
            'headcount' => 1,
        ], ['Idempotency-Key' => 'workspace-req-edit-create'])->assertCreated();

        $id = (int) $requisition->json('data.requisition_id');

        $this->patchJson("/v1/recruitment/requisitions/{$id}", [
            'title' => '合成编辑后岗位',
            'headcount' => 4,
            'target_start_date' => '2027-02-01',
        ], ['Idempotency-Key' => 'workspace-req-edit'])
            ->assertOk()
            ->assertJsonPath('data.title', '合成编辑后岗位')
            ->assertJsonPath('data.headcount', 4);

        $this->assertSame('合成编辑后岗位', (string) Requisition::query()->findOrFail($id)->title);

        $this->postJson("/v1/recruitment/requisitions/{$id}/close", [
            'reason' => '合成编制取消',
        ], ['Idempotency-Key' => 'workspace-req-close'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CLOSED')
            ->assertJsonPath('data.replayed', false);

        $this->postJson("/v1/recruitment/requisitions/{$id}/close", [
            'reason' => '合成编制取消',
        ], ['Idempotency-Key' => 'workspace-req-close-replay'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CLOSED')
            ->assertJsonPath('data.replayed', true);

        $this->assertSame('CLOSED', (string) Requisition::query()->findOrFail($id)->status);

        $this->authorizer->deniedCapabilities = ['recruitment.requisition.manage'];
        $this->postJson("/v1/recruitment/requisitions/{$id}/close", [
            'reason' => '未经授权',
        ], ['Idempotency-Key' => 'workspace-req-close-denied'])->assertForbidden();
    }

    public function test_candidate_stage_move_requires_manage_and_writes_the_stage(): void
    {
        $candidate = Candidate::query()->create([
            'display_name' => '合成管道候选人',
            'status' => 'NEW',
            'recorded_at' => $this->clock->now(),
        ]);
        $org = $this->peopleScenario->orgUnit('WORKSPACE-STAGE-ORG');
        $requisition = Requisition::query()->create([
            'org_unit_id' => (int) $org->id,
            'requisition_no' => 'REQ-WORKSPACE-STAGE',
            'title' => '合成管道岗位',
            'status' => 'APPROVED',
            'headcount' => 1,
            'recorded_at' => $this->clock->now(),
        ]);
        CandidateApplication::query()->create([
            'candidate_id' => (int) $candidate->id,
            'requisition_id' => (int) $requisition->id,
            'opening_id' => null,
            'stage' => 'NEW',
            'status' => 'ACTIVE',
            'source' => 'TEST',
            'recorded_at' => $this->clock->now(),
        ]);

        $this->patchJson("/v1/recruitment/candidates/{$candidate->id}/stage", [
            'status' => 'SCREENING',
            'reason' => '简历初筛通过',
        ], ['Idempotency-Key' => 'workspace-stage-screening'])
            ->assertOk()
            ->assertJsonPath('data.candidate_id', (int) $candidate->id)
            ->assertJsonPath('data.status', 'SCREENING')
            ->assertJsonPath('data.previous_status', 'NEW')
            ->assertJsonPath('data.replayed', false);

        $this->assertSame('SCREENING', (string) $candidate->fresh()->status);

        $this->patchJson("/v1/recruitment/candidates/{$candidate->id}/stage", [
            'status' => 'SCREENING',
        ], ['Idempotency-Key' => 'workspace-stage-screening-replay'])
            ->assertOk()
            ->assertJsonPath('data.status', 'SCREENING')
            ->assertJsonPath('data.replayed', true);

        // Accepted-offer / hired transitions are owned by the offer and
        // conversion commands; a generic stage move cannot fake them.
        $this->patchJson("/v1/recruitment/candidates/{$candidate->id}/stage", [
            'status' => 'CONVERTED',
        ], ['Idempotency-Key' => 'workspace-stage-converted'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'INVALID_APPLICATION_TRANSITION');

        $this->authorizer->deniedCapabilities = ['recruitment.candidate.manage'];
        $this->patchJson("/v1/recruitment/candidates/{$candidate->id}/stage", [
            'status' => 'INTERVIEW',
        ], ['Idempotency-Key' => 'workspace-stage-denied'])->assertForbidden();
    }

    public function test_candidate_list_filters_by_requisition_and_exposes_conversion_facts(): void
    {
        $org = $this->peopleScenario->orgUnit('WORKSPACE-FILTER-ORG');
        $requisition = Requisition::query()->create([
            'org_unit_id' => (int) $org->id,
            'requisition_no' => 'REQ-WORKSPACE-FILTER',
            'title' => '合成筛选岗位',
            'status' => 'APPROVED',
            'headcount' => 1,
            'recorded_at' => $this->clock->now(),
        ]);

        $linked = Candidate::query()->create([
            'display_name' => '已关联需求候选人',
            'status' => 'INTERVIEW',
            'recorded_at' => $this->clock->now(),
        ]);
        CandidateApplication::query()->create([
            'candidate_id' => (int) $linked->id,
            'requisition_id' => (int) $requisition->id,
            'opening_id' => null,
            'stage' => 'INTERVIEW',
            'status' => 'ACTIVE',
            'source' => 'TEST',
            'recorded_at' => $this->clock->now(),
        ]);
        Candidate::query()->create([
            'display_name' => '未关联需求候选人',
            'status' => 'NEW',
            'recorded_at' => $this->clock->now(),
        ]);

        Interview::query()->create([
            'candidate_id' => (int) $linked->id,
            'requisition_id' => (int) $requisition->id,
            'scheduled_at' => '2026-11-25 09:00:00',
            'duration_minutes' => 60,
            'interviewer' => '合成面试官',
            'mode' => 'VIDEO',
            'status' => 'SCHEDULED',
            'recorded_at' => $this->clock->now(),
        ]);

        $response = $this->getJson('/v1/recruitment/workspace/candidates?requisition_id='.$requisition->id)
            ->assertOk();

        $ids = array_map(
            static fn (array $row): int => (int) $row['candidate_id'],
            (array) $response->json('data.items'),
        );

        $this->assertSame([(int) $linked->id], $ids);
        $this->assertSame((int) $requisition->id, (int) $response->json('data.items.0.requisition_id'));
        $this->assertArrayHasKey('converted_employment_id', (array) $response->json('data.items.0'));
        $this->assertArrayHasKey('converted_employee_no', (array) $response->json('data.items.0'));
    }

    public function test_candidate_conversion_uses_only_org_unit_and_employee_no_and_is_idempotent(): void
    {
        $candidate = $this->acceptedCandidate('WORKSPACE-CONVERSION');
        $org = $this->peopleScenario->orgUnit('WORKSPACE-CONVERSION-ORG');

        $this->assertFalse(
            Schema::hasColumn('candidate_conversions', 'legal_entity_id'),
            'Volume 2 removed legal_entity_id; conversion must not resurrect it.',
        );

        $payload = [
            'org_unit_id' => (int) $org->id,
            'employee_no' => 'CONV-WORKSPACE-0001',
        ];

        $first = $this->postJson("/v1/recruitment/candidates/{$candidate->id}/convert", $payload, [
            'Idempotency-Key' => 'workspace-convert-1',
        ])->assertStatus(202)
            ->assertJsonPath('data.status', 'PENDING_ONBOARDING');

        $first->assertJsonPath('data.employee_no', 'CONV-WORKSPACE-0001');
        $caseId = (int) $first->json('data.onboarding_case_id');
        $this->assertGreaterThan(0, $caseId);
        $this->assertArrayNotHasKey('legal_entity_id', (array) $first->json('data'));
        $this->assertArrayNotHasKey('tenant_id', (array) $first->json('data'));

        $second = $this->postJson("/v1/recruitment/candidates/{$candidate->id}/convert", $payload, [
            'Idempotency-Key' => 'workspace-convert-1',
        ])->assertOk();

        $this->assertSame($caseId, (int) $second->json('data.onboarding_case_id'));
        $this->assertSame(0, CandidateConversion::query()->where('candidate_id', $candidate->id)->count());
        $this->assertSame(0, Person::query()->count());
    }

    public function test_duplicate_employee_no_returns_409_and_rolls_back_the_conversion(): void
    {
        $candidate = $this->acceptedCandidate('WORKSPACE-DUPLICATE');
        $org = $this->peopleScenario->orgUnit('WORKSPACE-DUPLICATE-ORG');
        $this->peopleScenario->employment(
            $this->peopleScenario->person(['legal_name' => '合成已存在员工']),
            'CONV-DUPLICATE-0001',
        );

        $personsBefore = Person::query()->count();

        $this->postJson("/v1/recruitment/candidates/{$candidate->id}/convert", [
            'org_unit_id' => (int) $org->id,
            'employee_no' => 'CONV-DUPLICATE-0001',
        ], ['Idempotency-Key' => 'workspace-convert-duplicate'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'EMPLOYEE_NO_DUPLICATE');

        $this->assertSame($personsBefore, Person::query()->count());
        $this->assertSame(0, CandidateConversion::query()->where('candidate_id', $candidate->id)->count());
        $this->assertNull($candidate->fresh()->linked_person_id);
    }

    public function test_interview_feedback_is_restricted_to_evaluation_manage(): void
    {
        $candidate = Candidate::query()->create([
            'display_name' => '合成面试候选人',
            'status' => 'INTERVIEW',
            'recorded_at' => $this->clock->now(),
        ]);
        $org = $this->peopleScenario->orgUnit('WORKSPACE-INTERVIEW-ORG');
        $requisition = Requisition::query()->create([
            'org_unit_id' => (int) $org->id,
            'requisition_no' => 'REQ-WORKSPACE-INTERVIEW',
            'title' => '合成面试岗位',
            'status' => 'APPROVED',
            'headcount' => 1,
            'recorded_at' => $this->clock->now(),
        ]);

        Interview::query()->create([
            'candidate_id' => (int) $candidate->id,
            'requisition_id' => (int) $requisition->id,
            'scheduled_at' => '2026-11-26 09:00:00',
            'duration_minutes' => 60,
            'interviewer' => '合成面试官',
            'mode' => 'ONSITE',
            'status' => 'COMPLETED',
            'recommendation' => 'YES',
            'feedback' => '合成内部面试反馈',
            'recorded_at' => $this->clock->now(),
        ]);

        $visible = $this->getJson("/v1/recruitment/candidates/{$candidate->id}/interviews")
            ->assertOk()
            ->assertJsonPath('data.items.0.feedback', '合成内部面试反馈')
            ->assertJsonPath('data.items.0.recommendation', 'YES');

        $this->assertArrayHasKey('feedback', (array) $visible->json('data.items.0'));
        $this->assertNotEmpty(array_filter(
            $this->auditor->records,
            static fn (array $record): bool => $record['resource_type'] === 'candidate_interview'
                && in_array('feedback', $record['fields'], true),
        ));

        $this->authorizer->deniedCapabilities = ['recruitment.evaluation.manage'];
        $restricted = $this->getJson("/v1/recruitment/candidates/{$candidate->id}/interviews")
            ->assertOk()
            ->assertJsonPath('data.items.0.feedback_restricted', true);

        $this->assertArrayNotHasKey('feedback', (array) $restricted->json('data.items.0'));
        $this->assertArrayNotHasKey('recommendation', (array) $restricted->json('data.items.0'));
    }

    public function test_candidate_conversion_is_capability_guarded_on_the_production_route(): void
    {
        $candidate = $this->acceptedCandidate('WORKSPACE-CONVERT-DENIED');
        $org = $this->peopleScenario->orgUnit('WORKSPACE-CONVERT-DENIED-ORG');

        $this->authorizer->deniedCapabilities = ['recruitment.candidate.convert'];

        $this->postJson("/v1/recruitment/candidates/{$candidate->id}/convert", [
            'org_unit_id' => (int) $org->id,
            'employee_no' => 'CONV-DENIED-0001',
        ], ['Idempotency-Key' => 'workspace-convert-denied'])->assertForbidden();

        $this->assertSame(0, CandidateConversion::query()->where('candidate_id', $candidate->id)->count());
        $this->assertSame(0, Person::query()->count());
    }

    public function test_requisition_and_candidate_reads_are_capability_guarded(): void
    {
        $this->authorizer->deniedCapabilities = ['recruitment.requisition.read'];
        $this->getJson('/v1/recruitment/requisitions')->assertForbidden();

        $this->authorizer->deniedCapabilities = ['recruitment.candidate.read'];
        $this->getJson('/v1/recruitment/workspace/candidates')->assertForbidden();
    }

    private function acceptedCandidate(string $suffix): Candidate
    {
        $org = $this->peopleScenario->orgUnit('REQ-ORG-'.$suffix);
        $requisition = Requisition::query()->create([
            'org_unit_id' => (int) $org->id,
            'requisition_no' => 'REQ-'.$suffix,
            'title' => '合成录用岗位',
            'status' => 'APPROVED',
            'headcount' => 1,
            'budget_minor' => 2_500_000,
            'budget_currency' => 'CNY',
            'recorded_at' => $this->clock->now(),
        ]);

        $email = mb_strtolower($suffix).'@example.test';
        $candidate = Candidate::query()->create([
            'display_name' => '合成待入职候选人',
            'email' => $email,
            'email_hash' => hash_hmac('sha256', $email, (string) config('hrms.pii_hmac_key')),
            'mobile_ciphertext' => '+8613900000001',
            'source' => 'REFERRAL',
            'status' => 'OFFER_ACCEPTED',
            'recorded_at' => $this->clock->now(),
        ]);

        Offer::query()->create([
            'candidate_id' => (int) $candidate->id,
            'requisition_id' => (int) $requisition->id,
            'version' => 1,
            'status' => 'ACCEPTED',
            'base_salary_minor' => 2_500_000,
            'salary_currency' => 'CNY',
            'start_date' => '2026-12-01',
            'accepted_at' => $this->clock->now(),
            'recorded_at' => $this->clock->now(),
        ]);

        Interview::query()->create([
            'candidate_id' => (int) $candidate->id,
            'requisition_id' => (int) $requisition->id,
            'scheduled_at' => '2026-11-20 09:00:00',
            'duration_minutes' => 45,
            'interviewer' => '合成面试官',
            'mode' => 'VIDEO',
            'status' => 'COMPLETED',
            'recommendation' => 'YES',
            'recorded_at' => $this->clock->now(),
        ]);

        return $candidate;
    }

    /**
     * NEW-P3 regression: a candidate beyond the first page must still be
     * reachable by id, so the detail route never depends on page position.
     */
    public function test_candidate_detail_lookup_by_id_works_beyond_the_first_page(): void
    {
        $target = null;

        for ($i = 0; $i < 105; $i++) {
            $candidate = Candidate::query()->create([
                'display_name' => '合成候选人 '.$i,
                'status' => 'NEW',
                'recorded_at' => $this->clock->now(),
            ]);

            $target = $candidate->getKey();
        }

        $this->assertNotNull($target);

        $firstPage = $this->getJson('/v1/recruitment/workspace/candidates?page=1&page_size=100')->assertOk();
        $firstPageIds = array_column($firstPage->json('data.items'), 'candidate_id');
        $this->assertNotContains($target, $firstPageIds, 'the target must be outside the first page');

        $found = $this->getJson('/v1/recruitment/workspace/candidates?candidate_id='.$target)->assertOk();
        $this->assertSame(1, $found->json('data.total'));
        $this->assertSame((int) $target, (int) $found->json('data.items.0.candidate_id'));
    }
}
