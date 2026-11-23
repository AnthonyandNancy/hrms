<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Modules\Organization\Contracts\OrganizationDirectory;
use Tests\Support\PeopleTestCase;

final class OrgUnitAsOfBoundaryTest extends PeopleTestCase
{
    public function test_org_unit_validity_window_is_half_open_in_directory(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $unit = $scenario->orgUnit($entity, 'BOUNDARY-ORG', null, 'Boundary', 'DEPARTMENT', '2026-01-01', '2026-06-01');

        $directory = app(OrganizationDirectory::class);

        $this->assertSame([$unit->id], $directory->orgUnitIds(1, [$unit->id], '2026-05-31'));
        $this->assertSame([], $directory->orgUnitIds(1, [$unit->id], '2026-06-01'));
        $this->assertSame($entity->id, $directory->legalEntityOfOrg(1, $unit->id, '2026-05-31'));
        $this->assertNull($directory->legalEntityOfOrg(1, $unit->id, '2026-06-01'));
    }

    public function test_invalid_or_blank_as_of_defaults_to_tenant_timezone_today(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $scenario->orgUnit($entity, 'DEFAULT-DATE');

        // Frozen clock is 2026-11-23T01:00Z == 2026-11-23 09:00 Asia/Shanghai.
        $this->getJson("/v1/org-units/tree?legal_entity_id={$entity->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_org_tree_requires_legal_entity_id(): void
    {
        $this->getJson('/v1/org-units/tree')->assertUnprocessable()
            ->assertJsonPath('data.fields.legal_entity_id.0', 'The legal entity id field is required.');
    }

    public function test_org_move_requires_effective_from_and_rejects_before_current_start(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $unit = $scenario->orgUnit($entity, 'MOVE-VALIDATION');

        $this->postJson("/v1/org-units/{$unit->id}/move", ['new_parent_id' => null])
            ->assertUnprocessable();

        $this->postJson("/v1/org-units/{$unit->id}/move", [
            'new_parent_id' => null,
            'effective_from' => '2025-12-31',
        ])->assertUnprocessable();
    }
}
