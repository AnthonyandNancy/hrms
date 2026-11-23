<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\People\Models\Employment;
use App\Modules\Talent\Models\Requisition;
use Tests\Support\TalentTestCase;

final class RecruitmentToHireTest extends TalentTestCase
{
    public function test_requisition_offer_acceptance_creates_exactly_one_onboarding_draft(): void
    {
        $scenario = $this->peopleScenario;
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'REC-ORG');

        $requisition = $this->postJson('/v1/recruitment/requisitions', [
            'legal_entity_id' => $entity->id,
            'org_unit_id' => $org->id,
            'requisition_no' => 'REQ-0001',
            'title' => 'Platform Engineer',
            'headcount' => 1,
            'target_start_date' => '2026-12-01',
        ], ['Idempotency-Key' => 'req-create-1'])->assertCreated();

        $requisitionId = (int) $requisition->json('data.requisition_id');
        $requisitionCase = (int) $requisition->json('data.case_id');
        $this->approveCase($requisitionCase, 'req-approve-1')->assertOk()->assertJsonPath('data.effect_status', 'APPLIED');
        $this->assertSame('APPROVED', Requisition::query()->findOrFail($requisitionId)->status);

        $candidate = $this->postJson('/v1/recruitment/candidates', [
            'display_name' => 'Synthetic Candidate',
            'email' => 'candidate@example.test',
        ], ['Idempotency-Key' => 'cand-create-1'])->assertCreated();
        $candidateId = (int) $candidate->json('data.candidate_id');

        $offer = $this->postJson('/v1/recruitment/offers', [
            'candidate_id' => $candidateId,
            'requisition_id' => $requisitionId,
            'base_salary_minor' => 2_000_000,
            'salary_currency' => 'CNY',
            'start_date' => '2026-12-01',
        ], ['Idempotency-Key' => 'offer-create-1'])->assertCreated();

        $offerId = (int) $offer->json('data.offer_id');
        $this->approveCase((int) $offer->json('data.case_id'), 'offer-approve-1')->assertOk();

        $accepted = $this->postJson("/v1/recruitment/offers/{$offerId}/accept", [
            'legal_entity_id' => $entity->id,
            'org_unit_id' => $org->id,
            'employee_no' => 'HIRE-0001',
            'hire_date' => '2026-12-01',
        ], ['Idempotency-Key' => 'offer-accept-1'])->assertCreated();

        $onboardingCaseId = (int) $accepted->json('data.onboarding_case_id');
        $this->assertGreaterThan(0, $onboardingCaseId);

        $replay = $this->postJson("/v1/recruitment/offers/{$offerId}/accept", [
            'legal_entity_id' => $entity->id,
            'org_unit_id' => $org->id,
            'employee_no' => 'HIRE-0001',
            'hire_date' => '2026-12-01',
        ], ['Idempotency-Key' => 'offer-accept-2'])->assertOk();

        $this->assertSame($onboardingCaseId, (int) $replay->json('data.onboarding_case_id'));

        $this->assertSame(1, \App\Modules\Lifecycle\Models\LifecycleIntent::query()
            ->where('case_type', 'ONBOARDING')
            ->where('intent_json->offer_id', $offerId)
            ->count());
        $this->assertSame(0, Employment::query()->count(), 'Acceptance creates a draft intent, not an employment.');
    }
}
