<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Employment;
use Tests\Support\LifecycleTestCase;

final class LifecycleToPeopleTest extends LifecycleTestCase
{
    public function test_effect_rows_satisfy_people_kernel_invariants(): void
    {
        $scenario = $this->peopleScenario;
        $org = $scenario->orgUnit('INT-ORG');

        $created = $this->createLifecycleCase('onboarding', [
            'person' => ['legal_name' => 'Integration Person'],
            'employee_no' => 'INT-0001',
            'org_unit_id' => $org->id,
            'hire_date' => '2026-12-01',
            'employment_type' => 'FULL_TIME',
            'terms' => ['work_location' => 'Shanghai', 'work_schedule' => 'STANDARD', 'base_salary_minor' => 1_000_000],
            'submit' => true,
        ], 'int-onboard-create')->assertCreated();

        $this->approveCase((int) $created->json('data.case_id'), 'int-onboard-approve')->assertOk();

        $employment = Employment::query()->firstOrFail();
        $assignments = Assignment::query()->where('employment_id', $employment->id)->get();

        $this->assertCount(1, $assignments);
        $this->assertTrue((bool) $assignments->first()->is_primary);
        $this->assertSame('2026-12-01', (string) $employment->hire_date);
        $this->assertSame('9999-12-31', (string) $employment->valid_to);

        $directory = app(EmploymentLifecycleDirectory::class);
        $snapshot = $directory->assignmentAsOf($employment->id, '2026-12-01');
        $this->assertNotNull($snapshot);
        $this->assertSame($org->id, $snapshot->orgUnitId);
    }
}
