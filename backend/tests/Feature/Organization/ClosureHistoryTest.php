<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Modules\Organization\Models\OrgUnitClosure;
use Tests\Support\PeopleTestCase;

final class ClosureHistoryTest extends PeopleTestCase
{
    public function test_move_does_not_rewrite_existing_closure_paths(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $a = $scenario->orgUnit($entity, 'HIST-A', null, 'A', 'DIVISION');
        $b = $scenario->orgUnit($entity, 'HIST-B', null, 'B', 'DIVISION');
        $team = $scenario->orgUnit($entity, 'HIST-TEAM', $a);

        $before = OrgUnitClosure::query()->where('descendant_id', $team->id)->orderBy('ancestor_id')->get()->map(fn ($row) => $row->only(['ancestor_id', 'descendant_id', 'depth', 'valid_from', 'valid_to']))->all();

        $this->postJson("/v1/org-units/{$team->id}/move", [
            'new_parent_id' => $b->id,
            'effective_from' => '2026-12-01',
        ])->assertOk();

        foreach ($before as $row) {
            $this->assertDatabaseHas('org_unit_closure', [
                'ancestor_id' => $row['ancestor_id'],
                'descendant_id' => $row['descendant_id'],
                'depth' => $row['depth'],
                'valid_from' => $row['valid_from'],
                'valid_to' => '2026-12-01',
            ]);
        }

        $this->assertDatabaseHas('org_unit_closure', [
            'ancestor_id' => $b->id,
            'descendant_id' => $team->id,
            'depth' => 1,
            'valid_from' => '2026-12-01',
            'valid_to' => '9999-12-31',
        ]);
    }

    public function test_union_closures_are_derived_from_existing_ancestor_paths(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $root = $scenario->orgUnit($entity, 'UNION-ROOT', null, 'Root', 'COMPANY');
        $division = $scenario->orgUnit($entity, 'UNION-DIV', $root, 'Div', 'DIVISION');
        $team = $scenario->orgUnit($entity, 'UNION-TEAM', $division);

        $this->assertDatabaseHas('org_unit_closure', ['ancestor_id' => $root->id, 'descendant_id' => $team->id, 'depth' => 2]);
        $this->assertDatabaseHas('org_unit_closure', ['ancestor_id' => $division->id, 'descendant_id' => $team->id, 'depth' => 1]);
    }
}
