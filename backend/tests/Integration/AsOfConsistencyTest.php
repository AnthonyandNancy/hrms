<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use Tests\Support\PeopleTestCase;

final class AsOfConsistencyTest extends PeopleTestCase
{
    public function test_employee_list_detail_org_tree_and_directory_agree_at_same_as_of(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $division = $scenario->orgUnit($entity, 'CONS-DIV', null, 'Division', 'DIVISION');
        $team = $scenario->orgUnit($entity, 'CONS-TEAM', $division);
        $employment = $scenario->employment($scenario->person(), $entity, 'CONS-1');
        $assignment = $scenario->assignment($employment, $team);
        $scenario->terms($employment);

        $asOf = '2026-11-23';

        $list = $this->getJson("/v1/employees?as_of={$asOf}&org_unit_id={$team->id}")->assertOk();
        $detail = $this->getJson("/v1/employees/{$employment->id}?as_of={$asOf}")->assertOk();
        $tree = $this->getJson("/v1/org-units/tree?as_of={$asOf}&legal_entity_id={$entity->id}")->assertOk();

        $this->assertSame(1, $list->json('data.total'));
        $this->assertSame($assignment->id, $list->json('data.items.0.current_assignment.id'));
        $this->assertSame($assignment->id, $detail->json('data.current_assignment.id'));
        $this->assertSame($team->id, $detail->json('data.current_assignment.org_unit_id'));
        $this->assertContains($team->id, array_column($tree->json('data.items'), 'id'));

        $directory = app(PeopleDirectory::class);
        $snapshot = $directory->employmentSnapshot(1, $employment->id, $asOf);
        $this->assertNotNull($snapshot);
        $this->assertSame($detail->json('data.current_assignment.org_unit_id'), $snapshot->orgUnitId);

        $organization = app(OrganizationDirectory::class);
        $this->assertContains($team->id, $organization->subtreeIds(1, $division->id, $asOf));
        $this->assertSame([$employment->id], $directory->employmentIdsForLegalEntities(1, [$entity->id], $asOf));
    }

    public function test_org_tree_and_as_of_subtree_agree_after_move_boundary(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $first = $scenario->orgUnit($entity, 'CONS-A', null, 'A', 'DIVISION');
        $second = $scenario->orgUnit($entity, 'CONS-B', null, 'B', 'DIVISION');
        $team = $scenario->orgUnit($entity, 'CONS-C', $first);

        $this->postJson("/v1/org-units/{$team->id}/move", [
            'new_parent_id' => $second->id,
            'effective_from' => '2026-12-01',
        ])->assertOk();

        $before = $this->getJson("/v1/org-units/tree?as_of=2026-11-30&legal_entity_id={$entity->id}")->assertOk();
        $after = $this->getJson("/v1/org-units/tree?as_of=2026-12-01&legal_entity_id={$entity->id}")->assertOk();

        $beforeParent = null;
        $afterParent = null;

        foreach ((array) $before->json('data.items') as $item) {
            if ((int) $item['id'] === $team->id) {
                $beforeParent = $item['parent_id'];
            }
        }

        foreach ((array) $after->json('data.items') as $item) {
            if ((int) $item['id'] === $team->id) {
                $afterParent = $item['parent_id'];
            }
        }

        $this->assertSame($first->id, $beforeParent);
        $this->assertSame($second->id, $afterParent);
    }
}
