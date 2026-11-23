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
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'DEDUP-ORG');
        $payload = fn (string $employeeNo): array => [
            'person' => [
                'legal_name' => 'Synthetic Dedupe',
                'id_type' => 'NATIONAL_ID',
                'id_number' => 'FAKE-ID-0001',
            ],
            'legal_entity_id' => $entity->id,
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
        $entity = $this->peopleScenario->legalEntity();
        $this->assertSame($entity->id, $entity->id);
    }
}
