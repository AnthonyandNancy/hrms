<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Modules\People\Models\Assignment;
use Tests\Support\PeopleTestCase;

final class DepartmentHeadcountTest extends PeopleTestCase
{
    public function test_headcount_version_is_append_only_and_not_derived_from_employee_count(): void
    {
        $scenario = $this->scenario();
        $unit = $scenario->orgUnit('HC-1');
        $employment = $scenario->employment($scenario->person(), 'HC-EMP-1');
        $assignment = $scenario->assignment($employment, $unit);

        $this->patchJson("/v1/departments/{$unit->id}", [
            'headcount_limit' => 5,
            'headcount_effective_from' => '2026-11-01',
        ], [
            'X-CSRF-Token' => 'test-csrf',
            'Idempotency-Key' => 'hc-1',
        ])->assertCreated()
            ->assertJsonPath('data.headcount_limit', 5)
            ->assertJsonPath('data.effective_from', '2026-11-01');

        $this->patchJson("/v1/departments/{$unit->id}", [
            'headcount_limit' => 7,
            'headcount_effective_from' => '2026-12-01',
        ], [
            'X-CSRF-Token' => 'test-csrf',
            'Idempotency-Key' => 'hc-2',
        ])->assertCreated()
            ->assertJsonPath('data.headcount_limit', 7);

        $this->assertDatabaseCount('org_unit_headcount_versions', 2);

        $this->getJson("/v1/departments/staffing?as_of=2026-11-15&org_unit_id={$unit->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.headcount_limit', 5)
            ->assertJsonPath('data.items.0.staffed', 1)
            ->assertJsonPath('data.items.0.vacancies', 4);

        // The live assignment is the staffed fact; no headcount version stores it.
        $this->assertSame((int) $assignment->id, (int) Assignment::query()->whereKey($assignment->id)->value('id'));
        $this->assertDatabaseMissing('org_unit_headcount_versions', ['headcount_limit' => 1]);
    }

    public function test_same_headcount_effective_date_is_idempotent_only_for_the_same_limit(): void
    {
        $unit = $this->scenario()->orgUnit('HC-IDEM');

        $payload = ['headcount_limit' => 4, 'headcount_effective_from' => '2026-11-01'];
        $headers = ['X-CSRF-Token' => 'test-csrf', 'Idempotency-Key' => 'hc-idem'];

        $first = $this->patchJson("/v1/departments/{$unit->id}", $payload, $headers)->assertCreated();

        $this->patchJson("/v1/departments/{$unit->id}", $payload, $headers)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.version', (int) $first->json('data.version'));

        $this->assertDatabaseCount('org_unit_headcount_versions', 1);

        $this->patchJson("/v1/departments/{$unit->id}", [
            'headcount_limit' => 6,
            'headcount_effective_from' => '2026-11-01',
        ], [
            'X-CSRF-Token' => 'test-csrf',
            'Idempotency-Key' => 'hc-idem-conflict',
        ])->assertStatus(409)
            ->assertJsonPath('data.reason', 'ORG_HEADCOUNT_VERSION_CONFLICT');
    }

    public function test_headcount_staffing_never_fabricates_staffed_or_limit(): void
    {
        $unit = $this->scenario()->orgUnit('HC-EMPTY');

        $this->getJson("/v1/departments/staffing?as_of=2026-11-15&org_unit_id={$unit->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.headcount_limit', null)
            ->assertJsonPath('data.items.0.staffed', 0)
            ->assertJsonPath('data.items.0.vacancies', null)
            ->assertJsonPath('data.items.0.overstaffed', false);
    }

    public function test_headcount_requires_an_idempotency_key(): void
    {
        $unit = $this->scenario()->orgUnit('HC-NO-KEY');

        $this->patchJson("/v1/departments/{$unit->id}", [
            'headcount_limit' => 3,
            'headcount_effective_from' => '2026-11-01',
        ], ['X-CSRF-Token' => 'test-csrf'])
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->assertDatabaseCount('org_unit_headcount_versions', 0);
    }

    public function test_headcount_requires_a_manage_capability(): void
    {
        $unit = $this->scenario()->orgUnit('HC-DENIED');

        $this->actingAsUserWithCapabilities(['org.unit.read']);

        $this->patchJson("/v1/departments/{$unit->id}", [
            'headcount_limit' => 3,
            'headcount_effective_from' => '2026-11-01',
        ], ['X-CSRF-Token' => 'test-csrf', 'Idempotency-Key' => 'hc-denied'])
            ->assertForbidden();

        $this->assertDatabaseCount('org_unit_headcount_versions', 0);
    }
}
