<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use Tests\Support\TalentTestCase;

final class OfferIdempotencyTest extends TalentTestCase
{
    public function test_offer_creation_and_acceptance_replays_do_not_duplicate_rows(): void
    {
        $entity = $this->peopleScenario->legalEntity();
        $org = $this->peopleScenario->orgUnit($entity, 'OFFER-IDEM-ORG');
        $requisition = $this->postJson('/v1/recruitment/requisitions', [
            'legal_entity_id' => $entity->id,
            'org_unit_id' => $org->id,
            'requisition_no' => 'REQ-IDEM-1',
            'title' => 'Engineer',
            'headcount' => 1,
        ], ['Idempotency-Key' => 'req-idem-create'])->assertCreated();
        $this->approveCase((int) $requisition->json('data.case_id'), 'req-idem-approve')->assertOk();

        $candidate = $this->postJson('/v1/recruitment/candidates', [
            'display_name' => 'Synthetic Idem',
            'email' => 'idem@example.test',
        ], ['Idempotency-Key' => 'cand-idem-create'])->assertCreated();

        $payload = [
            'candidate_id' => (int) $candidate->json('data.candidate_id'),
            'requisition_id' => (int) $requisition->json('data.requisition_id'),
            'base_salary_minor' => 1_000_000,
            'salary_currency' => 'CNY',
            'start_date' => '2026-12-01',
        ];

        $first = $this->postJson('/v1/recruitment/offers', $payload, ['Idempotency-Key' => 'offer-idem-create'])->assertCreated();
        $second = $this->postJson('/v1/recruitment/offers', $payload, ['Idempotency-Key' => 'offer-idem-create'])->assertOk();
        $second->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json('data.offer_id'), $second->json('data.offer_id'));

        $this->assertSame(1, \App\Modules\Talent\Models\Offer::query()->count());
    }
}
