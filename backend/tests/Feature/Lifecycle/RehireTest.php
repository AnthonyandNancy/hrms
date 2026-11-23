<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\People\Models\Employment;
use Tests\Support\LifecycleTestCase;

final class RehireTest extends LifecycleTestCase
{
    public function test_rehire_creates_new_employment_and_preserves_old_terminated_employment(): void
    {
        $scenario = $this->peopleScenario;
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'REH-ORG');
        $person = $scenario->person();
        $old = $scenario->employment($person, $entity, 'REH-0001', [
            'status' => 'TERMINATED',
            'valid_from' => '2020-01-01',
            'valid_to' => '2024-01-01',
            'termination_date' => '2023-12-31',
        ]);

        $created = $this->createLifecycleCase('rehires', [
            'person_id' => $person->id,
            'previous_employment_id' => $old->id,
            'legal_entity_id' => $entity->id,
            'employee_no' => 'REH-0002',
            'org_unit_id' => $org->id,
            'hire_date' => '2026-12-01',
            'employment_type' => 'FULL_TIME',
            'seniority_rule' => 'PRESERVE_ORIGINAL',
            'terms' => [
                'work_location' => 'Shanghai',
                'work_schedule' => 'STANDARD',
                'base_salary_minor' => 900_000,
                'salary_currency' => 'CNY',
            ],
            'submit' => true,
        ], 'rehire-create')->assertCreated();

        $this->approveCase((int) $created->json('data.case_id'), 'rehire-approve')->assertOk();

        $old->refresh();
        $this->assertSame('TERMINATED', $old->status);

        $new = Employment::query()->where('employee_no', 'REH-0002')->firstOrFail();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame($person->id, $new->person_id);
        $this->assertSame('2026-12-01', (string) $new->valid_from);
        $this->assertSame('2020-01-01', (string) $new->seniority_start_date);
    }
}
