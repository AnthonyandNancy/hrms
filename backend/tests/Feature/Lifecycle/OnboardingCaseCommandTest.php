<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\Lifecycle\Contracts\OnboardingCaseCommand;
use App\Modules\Lifecycle\Models\LifecycleIntent;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentTerms;
use App\Modules\People\Models\Person;
use Tests\Support\LifecycleTestCase;

final class OnboardingCaseCommandTest extends LifecycleTestCase
{
    public function test_people_can_submit_the_canonical_onboarding_case_without_domain_rows(): void
    {
        $org = $this->peopleScenario->orgUnit('CONTRACT-ONB-ORG');
        $command = app(OnboardingCaseCommand::class);

        $created = $command->submit(
            actorUserId: 10,
            payload: [
                'person' => ['legal_name' => 'Synthetic Contract Onboarding'],
                'employee_no' => 'CONTRACT-ONB-1',
                'org_unit_id' => (int) $org->id,
                'hire_date' => '2026-12-01',
                'employment_type' => 'FULL_TIME',
                'terms' => [
                    'work_location' => 'Shanghai',
                    'work_schedule' => 'STANDARD',
                    'base_salary_minor' => 1_500_000,
                    'salary_currency' => 'CNY',
                ],
            ],
            idempotencyKey: 'contract-onboarding-1',
            businessDate: '2026-11-23',
        );

        $this->assertGreaterThan(0, $created['case_id']);
        $this->assertNotSame('', $created['case_no']);
        $this->assertSame('SUBMITTED', $created['application_status']);
        $this->assertSame('NONE', $created['effect_status']);
        $this->assertSame('/v1/lifecycle/onboarding', $created['canonical_endpoint']);
        $this->assertFalse($created['replayed']);

        $this->assertSame(1, LifecycleIntent::query()->where('case_id', $created['case_id'])->count());
        $this->assertSame(0, Person::query()->count());
        $this->assertSame(0, Employment::query()->count());
        $this->assertSame(0, Assignment::query()->count());
        $this->assertSame(0, EmploymentTerms::query()->count());
    }

    public function test_the_contract_replays_the_same_case_for_the_same_idempotency_key(): void
    {
        $org = $this->peopleScenario->orgUnit('CONTRACT-REPLAY-ORG');
        $payload = [
            'person' => ['legal_name' => 'Synthetic Contract Replay'],
            'employee_no' => 'CONTRACT-REPLAY-1',
            'org_unit_id' => (int) $org->id,
            'hire_date' => '2026-12-01',
            'employment_type' => 'FULL_TIME',
            'terms' => ['work_location' => 'Shanghai', 'work_schedule' => 'STANDARD'],
        ];
        $command = app(OnboardingCaseCommand::class);

        $first = $command->submit(10, $payload, 'contract-replay-key', '2026-11-23');
        $second = $command->submit(10, $payload, 'contract-replay-key', '2026-11-23');

        $this->assertSame($first['case_id'], $second['case_id']);
        $this->assertSame($first['case_no'], $second['case_no']);
        $this->assertTrue($second['replayed']);
        $this->assertSame(1, LifecycleIntent::query()->count());
        $this->assertSame(0, Employment::query()->count());
    }
}
