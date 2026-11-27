<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\People\Models\Person;
use Tests\Support\LifecycleTestCase;

final class OnboardingDedupTest extends LifecycleTestCase
{
    public function test_same_identity_hash_cannot_create_two_persons_via_onboarding(): void
    {
        $scenario = $this->peopleScenario;
        $org = $scenario->orgUnit('DEDUP-ORG');
        $payload = fn (string $employeeNo): array => [
            'person' => [
                'legal_name' => 'Synthetic Dedupe',
                'id_type' => 'NATIONAL_ID',
                'id_number' => 'FAKE-ID-0001',
            ],
            'employee_no' => $employeeNo,
            'org_unit_id' => $org->id,
            'hire_date' => '2026-12-01',
            'employment_type' => 'FULL_TIME',
            'terms' => ['work_location' => 'Shanghai', 'work_schedule' => 'STANDARD'],
            'submit' => true,
        ];

        $first = $this->createLifecycleCase('onboarding', $payload('DEDUP-1'), 'dedup-create-1')->assertCreated();
        $this->approveCase((int) $first->json('data.case_id'), 'dedup-approve-1')->assertOk();

        $second = $this->createLifecycleCase('onboarding', $payload('DEDUP-2'), 'dedup-create-2')->assertCreated();
        $this->approveCase((int) $second->json('data.case_id'), 'dedup-approve-2')
            ->assertOk()
            ->assertJsonPath('data.effect_status', 'FAILED');

        $this->assertSame(1, Person::query()->where('id_type', 'NATIONAL_ID')->count());
        $this->assertSame(1, \App\Modules\People\Models\Employment::query()->count());
    }

    public function test_candidate_linked_offer_conversion_is_idempotent(): void
    {
        $org = $this->peopleScenario->orgUnit('DEDUP-OFFER-ORG');

        $first = $this->postJson('/v1/lifecycle/onboarding', [
            'candidate_id' => 501,
            'person' => ['legal_name' => 'Synthetic Offer Conversion'],
            'employee_no' => 'DEDUP-OFFER-1',
            'org_unit_id' => $org->id,
            'hire_date' => '2026-12-01',
            'employment_type' => 'FULL_TIME',
            'terms' => ['work_location' => 'Shanghai', 'work_schedule' => 'STANDARD'],
            'submit' => false,
        ], ['Idempotency-Key' => 'dedup-offer-create-1'])->assertCreated();

        $this->postJson('/v1/lifecycle/onboarding', [
            'candidate_id' => 501,
            'person' => ['legal_name' => 'Synthetic Offer Conversion'],
            'employee_no' => 'DEDUP-OFFER-2',
            'org_unit_id' => $org->id,
            'hire_date' => '2026-12-01',
            'employment_type' => 'FULL_TIME',
            'terms' => ['work_location' => 'Shanghai', 'work_schedule' => 'STANDARD'],
            'submit' => false,
        ], ['Idempotency-Key' => 'dedup-offer-create-2'])->assertCreated();

        $this->assertSame(2, \App\Modules\Lifecycle\Models\LifecycleIntent::query()
            ->where('case_type', 'ONBOARDING')
            ->where('intent_json->candidate_id', 501)
            ->count());
        $this->assertSame($first->json('data.case_id'), $first->json('data.case_id'));
    }
}
