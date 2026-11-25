<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\Offer;
use App\Modules\Talent\Models\Requisition;
use Tests\Support\TalentTestCase;

final class OfferListApiTest extends TalentTestCase
{
    public function test_offers_can_be_listed_and_filtered_by_candidate_and_status(): void
    {
        $candidate = Candidate::query()->create([
            'tenant_id' => 1,
            'display_name' => 'Synthetic Candidate',
            'email' => 'candidate@example.test',
            'status' => 'OFFER',
            'recorded_at' => $this->clock->now(),
        ]);
        $requisition = Requisition::query()->create([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'org_unit_id' => 1,
            'requisition_no' => 'REQ-OFFER-1',
            'title' => 'Synthetic role',
            'status' => 'APPROVED',
            'headcount' => 1,
            'recorded_at' => $this->clock->now(),
        ]);

        $offer = Offer::query()->create([
            'tenant_id' => 1,
            'candidate_id' => $candidate->id,
            'requisition_id' => $requisition->id,
            'version' => 1,
            'status' => 'PENDING_APPROVAL',
            'base_salary_minor' => 1000000,
            'salary_currency' => 'CNY',
            'start_date' => '2026-12-01',
            'recorded_at' => $this->clock->now(),
        ]);

        $this->getJson('/v1/recruitment/offers')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.offer_id', $offer->id)
            ->assertJsonPath('data.items.0.candidate_id', $candidate->id)
            ->assertJsonPath('data.items.0.status', 'PENDING_APPROVAL');

        $this->getJson('/v1/recruitment/offers?status=ACCEPTED')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }
}
