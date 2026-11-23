<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use Tests\Support\PeopleTestCase;

final class OrgTenantIsolationTest extends PeopleTestCase
{
    public function test_move_of_other_tenant_org_is_404(): void
    {
        $scenario = $this->scenario(1);
        $entity = $scenario->legalEntity();
        $unit = $scenario->orgUnit($entity, 'OTHER-TENANT-MOVE');

        $this->actAs(tenantId: 2, userId: 55);

        $this->postJson("/v1/org-units/{$unit->id}/move", [
            'new_parent_id' => null,
            'effective_from' => '2026-12-01',
        ])->assertNotFound();
    }

    public function test_impact_of_other_tenant_org_is_404(): void
    {
        $scenario = $this->scenario(1);
        $entity = $scenario->legalEntity();
        $unit = $scenario->orgUnit($entity, 'OTHER-TENANT-IMPACT');

        $this->actAs(tenantId: 2, userId: 56);

        $this->getJson("/v1/org-units/{$unit->id}/impact?new_parent_id=")->assertNotFound();
    }

    public function test_tree_for_other_tenant_legal_entity_is_404(): void
    {
        $scenario = $this->scenario(1);
        $entity = $scenario->legalEntity();
        $scenario->orgUnit($entity, 'OTHER-TENANT-TREE');

        $this->actAs(tenantId: 2, userId: 57);

        $this->getJson("/v1/org-units/tree?legal_entity_id={$entity->id}")->assertNotFound();
    }
}
