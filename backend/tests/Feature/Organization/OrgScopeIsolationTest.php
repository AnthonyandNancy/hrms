<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use Tests\Support\PeopleTestCase;

final class OrgScopeIsolationTest extends PeopleTestCase
{
    public function test_move_of_unknown_org_is_404(): void
    {
        $scenario = $this->scenario();
        $unit = $scenario->orgUnit('UNKNOWN-MOVE');

        $this->postJson('/v1/org-units/'.($unit->id + 1000).'/move', [
            'new_parent_id' => null,
            'effective_from' => '2026-12-01',
        ], ['X-CSRF-Token' => 'test-csrf', 'Idempotency-Key' => 'scope-move-404'])->assertNotFound();
    }

    public function test_impact_of_unknown_org_is_404(): void
    {
        $this->getJson('/v1/org-units/999999/impact?new_parent_id=')
            ->assertNotFound();
    }

    public function test_tree_only_exposes_the_company_org_as_of_the_requested_date(): void
    {
        $scenario = $this->scenario();
        $scenario->orgUnit('VISIBLE-TREE', null, 'Visible', 'DEPARTMENT', '2026-01-01', '2026-06-01');

        $this->getJson('/v1/org-units/tree?as_of=2026-11-23')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        $this->getJson('/v1/org-units/tree?as_of=2026-05-31')
            ->assertOk()
            ->assertJsonPath('data.items.0.code', 'VISIBLE-TREE');
    }
}
