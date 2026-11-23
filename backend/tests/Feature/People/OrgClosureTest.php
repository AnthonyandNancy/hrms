<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Models\OrgUnit;
use App\Modules\Organization\Models\OrgUnitClosure;
use Tests\Support\PeopleTestCase;

final class OrgClosureTest extends PeopleTestCase
{
    public function test_subtree_as_of_before_and_after_a_move(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $engineering = $scenario->orgUnit($entity, 'DIV-ENG', null, 'Engineering', 'DIVISION');
        $sales = $scenario->orgUnit($entity, 'DIV-SALES', null, 'Sales', 'DIVISION');
        $team = $scenario->orgUnit($entity, 'TEAM-CORE', $engineering, 'Core');

        $response = $this->postJson("/v1/org-units/{$team->id}/move", [
            'new_parent_id' => $sales->id,
            'effective_from' => '2026-12-01',
        ]);

        $response->assertOk()->assertJsonPath('data.moved', true);

        $directory = app(OrganizationDirectory::class);

        $this->assertSame([$engineering->id, $team->id], $directory->subtreeIds(1, $engineering->id, '2026-11-30'));
        $this->assertSame([$engineering->id], $directory->subtreeIds(1, $engineering->id, '2026-12-01'));
        $this->assertSame([$sales->id, $team->id], $directory->subtreeIds(1, $sales->id, '2026-12-01'));
        $this->assertSame([$sales->id], $directory->subtreeIds(1, $sales->id, '2026-11-30'));

        $oldRows = OrgUnitClosure::query()
            ->where('ancestor_id', $engineering->id)
            ->where('descendant_id', $team->id)
            ->orderBy('valid_from')
            ->get();
        $this->assertCount(1, $oldRows);
        $this->assertSame('2026-12-01', $oldRows->firstOrFail()->valid_to);
    }

    public function test_move_rejects_a_cycle(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $root = $scenario->orgUnit($entity, 'ROOT', null, 'Root', 'COMPANY');
        $child = $scenario->orgUnit($entity, 'CHILD', $root);

        $this->postJson("/v1/org-units/{$root->id}/move", [
            'new_parent_id' => $child->id,
            'effective_from' => '2026-12-01',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_tree_reflects_the_requested_business_date(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $scenario->orgUnit($entity, 'ROOT', null, 'Root', 'COMPANY');

        $this->getJson("/v1/org-units/tree?as_of=2026-11-23&legal_entity_id={$entity->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', 'ROOT');
    }

    public function test_historical_org_unit_row_is_ended_not_rewritten(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $unit = $scenario->orgUnit($entity, 'HIST');

        $this->postJson("/v1/org-units/{$unit->id}/move", [
            'new_parent_id' => null,
            'effective_from' => '2026-12-01',
        ])->assertOk();

        $this->assertDatabaseHas('org_units', [
            'id' => $unit->id,
            'code' => 'HIST',
        ]);

        $closures = OrgUnitClosure::query()
            ->where('ancestor_id', $unit->id)
            ->where('descendant_id', $unit->id)
            ->orderBy('valid_from')
            ->get();
        $this->assertCount(2, $closures);
        $this->assertSame('2026-01-01', $closures[0]->valid_from);
        $this->assertSame('2026-12-01', $closures[0]->valid_to);
        $this->assertSame('2026-12-01', $closures[1]->valid_from);
        $this->assertSame(OrgUnit::query()->findOrFail($unit->id)->code, 'HIST');
    }
}
