<?php

declare(strict_types=1);

namespace Tests\Feature\Talent;

use App\Modules\Lifecycle\Models\LifecycleIntent;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\Person;
use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\CandidateConversion;
use App\Modules\Talent\Models\Offer;
use App\Modules\Talent\Models\Requisition;
use Tests\Support\TalentTestCase;

/**
 * Offer acceptance -> Lifecycle onboarding draft -> conversion is one path.
 * Acceptance must create exactly one draft per offer; conversion must never
 * create a Person/Employment and must return 202 until Lifecycle applied it.
 */
final class OfferOnboardingSinglePathTest extends TalentTestCase
{
    public function test_offer_acceptance_replays_the_same_onboarding_case(): void
    {
        [, $offer] = $this->offerScenario('ACCEPT-REPLAY');
        $org = $this->peopleScenario->orgUnit('ACCEPT-REPLAY-DEST');

        $payload = [
            'org_unit_id' => (int) $org->id,
            'employee_no' => 'SYN-ACCEPT-REPLAY-1',
            'hire_date' => '2026-12-01',
        ];

        $first = $this->postJson("/v1/recruitment/offers/{$offer->id}/accept", $payload, ['Idempotency-Key' => 'accept-replay-1'])
            ->assertCreated()
            ->assertJsonPath('data.replayed', false);
        $caseId = (int) $first->json('data.onboarding_case_id');
        $this->assertGreaterThan(0, $caseId);

        $this->postJson("/v1/recruitment/offers/{$offer->id}/accept", $payload, ['Idempotency-Key' => 'accept-replay-2'])
            ->assertOk()
            ->assertJsonPath('data.onboarding_case_id', $caseId)
            ->assertJsonPath('data.replayed', true);

        $this->assertSame(1, LifecycleIntent::query()
            ->where('case_type', 'ONBOARDING')
            ->where('offer_id', (int) $offer->id)
            ->count());
        $this->assertSame(0, Employment::query()->count());
        $this->assertSame(0, Person::query()->count());
    }

    public function test_conversion_is_202_until_onboarding_is_applied_and_then_records_the_same_employee(): void
    {
        [$candidate, $offer] = $this->offerScenario('CONVERT-PENDING');
        $org = $this->peopleScenario->orgUnit('CONVERT-PENDING-DEST');

        $payload = [
            'org_unit_id' => (int) $org->id,
            'employee_no' => 'SYN-CONVERT-PENDING-1',
            'hire_date' => '2026-11-23',
        ];

        $this->postJson("/v1/recruitment/offers/{$offer->id}/accept", $payload, ['Idempotency-Key' => 'convert-pending-accept'])
            ->assertCreated()
            ->assertJsonPath('data.replayed', false);

        $this->postJson("/v1/recruitment/candidates/{$candidate->id}/convert", $payload, ['Idempotency-Key' => 'convert-pending-1'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'PENDING_ONBOARDING')
            ->assertJsonPath('data.employment_id', null);

        $this->assertSame(0, CandidateConversion::query()->count());
        $this->assertSame(0, Employment::query()->count());
        $this->assertSame(0, Person::query()->count());

        $intent = LifecycleIntent::query()->where('offer_id', (int) $offer->id)->firstOrFail();
        $this->postJson("/v1/cases/{$intent->case_id}/submit", [], [
            'Idempotency-Key' => 'convert-pending-submit',
        ])->assertOk();
        $this->approveCase((int) $intent->case_id, 'convert-pending-approve')->assertOk();

        $employment = Employment::query()->where('employee_no', 'SYN-CONVERT-PENDING-1')->firstOrFail();
        $conversion = $this->postJson("/v1/recruitment/candidates/{$candidate->id}/convert", $payload, ['Idempotency-Key' => 'convert-pending-2'])
            ->assertCreated()
            ->assertJsonPath('data.employment_id', (int) $employment->id)
            ->assertJsonPath('data.person_id', (int) $employment->person_id)
            ->assertJsonPath('data.onboarding_case_id', (int) $intent->case_id)
            ->assertJsonPath('data.business_replayed', false);

        $conversionId = (int) $conversion->json('data.conversion_id');
        $this->postJson("/v1/recruitment/candidates/{$candidate->id}/convert", $payload, ['Idempotency-Key' => 'convert-pending-3'])
            ->assertOk()
            ->assertJsonPath('data.conversion_id', $conversionId)
            ->assertJsonPath('data.business_replayed', true);

        $this->assertSame(1, CandidateConversion::query()->count());
        $this->assertSame(1, Employment::query()->count());
        $this->assertSame(1, Person::query()->count());
    }

    public function test_closed_requisition_rejects_offer_creation_and_acceptance_with_conflict(): void
    {
        [$candidate, $offer] = $this->offerScenario('CLOSED-OFFER');
        $org = $this->peopleScenario->orgUnit('CLOSED-OFFER-DEST');
        Requisition::query()->whereKey((int) $offer->requisition_id)->update(['status' => 'CLOSED']);

        $this->postJson("/v1/recruitment/offers/{$offer->id}/accept", [
            'org_unit_id' => (int) $org->id,
            'employee_no' => 'SYN-CLOSED-OFFER-1',
        ], ['Idempotency-Key' => 'closed-offer-accept'])->assertConflict();

        $this->postJson('/v1/recruitment/offers', [
            'candidate_id' => (int) $candidate->id,
            'requisition_id' => (int) $offer->requisition_id,
            'base_salary_minor' => 1_000_000,
            'salary_currency' => 'CNY',
            'start_date' => '2026-12-01',
        ], ['Idempotency-Key' => 'closed-offer-create'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'REQUISITION_NOT_APPROVED');
    }

    /**
     * @return array{0:Candidate,1:Offer}
     */
    private function offerScenario(string $suffix): array
    {
        $org = $this->peopleScenario->orgUnit('OFFER-'.$suffix);
        $requisition = Requisition::query()->create([
            'org_unit_id' => (int) $org->id,
            'requisition_no' => 'REQ-'.$suffix,
            'title' => '合成 Offer 岗位 '.$suffix,
            'status' => 'APPROVED',
            'headcount' => 1,
            'recorded_at' => $this->clock->now(),
        ]);
        $candidate = Candidate::query()->create([
            'display_name' => '合成 Offer 候选人 '.$suffix,
            'status' => 'OFFER_ACCEPTED',
            'recorded_at' => $this->clock->now(),
        ]);
        $offer = Offer::query()->create([
            'candidate_id' => (int) $candidate->id,
            'requisition_id' => (int) $requisition->id,
            'version' => 1,
            'status' => 'APPROVED',
            'base_salary_minor' => 2_000_000,
            'salary_currency' => 'CNY',
            'start_date' => '2026-12-01',
            'recorded_at' => $this->clock->now(),
        ]);

        return [$candidate, $offer];
    }
}
