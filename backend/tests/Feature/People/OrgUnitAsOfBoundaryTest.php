<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Tests\Support\PeopleTestCase;

final class OrgUnitAsOfBoundaryTest extends PeopleTestCase
{
    public function test_org_unit_validity_window_is_half_open_in_directory(): void
    {
        $scenario = $this->scenario();
        $unit = $scenario->orgUnit('BOUNDARY-ORG', null, 'Boundary', 'DEPARTMENT', '2026-01-01', '2026-06-01');

        $directory = app(\App\Modules\Organization\Contracts\OrganizationDirectory::class);

        $this->assertSame([$unit->id], $directory->orgUnitIds([$unit->id], '2026-05-31'));
        $this->assertSame([], $directory->orgUnitIds([$unit->id], '2026-06-01'));
    }

    public function test_invalid_or_blank_as_of_defaults_to_company_timezone_today(): void
    {
        $scenario = $this->scenario();
        $scenario->orgUnit('DEFAULT-DATE');

        // Frozen clock is 2026-11-23T01:00Z == 2026-11-23 09:00 Asia/Shanghai.
        $this->getJson('/v1/org-units/tree')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_org_tree_does_not_require_a_company_id(): void
    {
        $this->getJson('/v1/org-units/tree')->assertOk();
    }

    public function test_org_move_requires_effective_from_and_rejects_before_current_start(): void
    {
        $scenario = $this->scenario();
        $unit = $scenario->orgUnit('MOVE-VALIDATION');

        $this->postJson("/v1/org-units/{$unit->id}/move", ['new_parent_id' => null])
            ->assertUnprocessable();

        $this->postJson("/v1/org-units/{$unit->id}/move", [
            'new_parent_id' => null,
            'effective_from' => '2025-12-31',
        ])->assertUnprocessable();
    }
}
