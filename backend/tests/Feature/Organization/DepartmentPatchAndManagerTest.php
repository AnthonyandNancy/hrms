<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use Tests\Support\PeopleTestCase;

final class DepartmentPatchAndManagerTest extends PeopleTestCase
{
    public function test_department_patch_saves_only_real_manager_employment_ids(): void
    {
        $scenario = $this->scenario();
        $unit = $scenario->orgUnit('MGR-DEPT');
        $manager = $scenario->employment($scenario->person(), 'MGR-EMP-1');

        $this->patchJson('/v1/departments/'.$unit->id, [
            'manager_employment_id' => (int) $manager->id,
        ], [
            'X-CSRF-Token' => 'test-csrf',
            'Idempotency-Key' => 'mgr-1',
        ])->assertOk()
            ->assertJsonPath('data.org_unit.manager_employment_id', (int) $manager->id);

        $this->assertDatabaseHas('org_units', [
            'id' => (int) $unit->id,
            'manager_employment_id' => (int) $manager->id,
        ]);
    }

    public function test_department_patch_refuses_an_unknown_manager(): void
    {
        $unit = $this->scenario()->orgUnit('MGR-BAD');

        $this->patchJson('/v1/departments/'.$unit->id, [
            'manager_employment_id' => 999999,
        ], [
            'X-CSRF-Token' => 'test-csrf',
            'Idempotency-Key' => 'mgr-bad',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->assertDatabaseHas('org_units', [
            'id' => (int) $unit->id,
            'manager_employment_id' => null,
        ]);
    }

    public function test_department_patch_refuses_temporal_rewrite_without_a_move(): void
    {
        $unit = $this->scenario()->orgUnit('MGR-TEMPORAL');

        $this->patchJson('/v1/departments/'.$unit->id, [
            'valid_from' => '2026-02-01',
        ], [
            'X-CSRF-Token' => 'test-csrf',
            'Idempotency-Key' => 'mgr-temporal',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('org_units', [
            'id' => (int) $unit->id,
            'valid_from' => '2026-01-01',
        ]);
    }
}
