<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Modules\Organization\Models\LegalEntity;
use App\Modules\Organization\Models\Position;
use Tests\Support\PeopleTestCase;

final class PositionApiTest extends PeopleTestCase
{
    public function test_hr_creates_lists_and_updates_a_position(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'POS-ORG');

        $created = $this->postJson('/v1/positions', [
            'legal_entity_id' => $entity->id,
            'org_unit_id' => $org->id,
            'code' => 'ENG-001',
            'title' => 'Synthetic Engineer',
            'grade' => 'P5',
            'headcount_limit' => 3,
            'valid_from' => '2026-01-01',
        ])->assertCreated();

        $id = (int) $created->json('data.id');
        $this->assertDatabaseHas('positions', [
            'id' => $id,
            'tenant_id' => 1,
            'code' => 'ENG-001',
            'headcount_limit' => 3,
        ]);

        $this->getJson('/v1/positions?legal_entity_id='.$entity->id.'&as_of=2026-11-23')
            ->assertOk()
            ->assertJsonPath('data.items.0.code', 'ENG-001')
            ->assertJsonPath('data.items.0.occupancy', 0);

        $this->patchJson("/v1/positions/{$id}", [
            'title' => 'Synthetic Senior Engineer',
            'headcount_limit' => 5,
        ])->assertOk();

        $this->assertDatabaseHas('positions', [
            'id' => $id,
            'title' => 'Synthetic Senior Engineer',
            'headcount_limit' => 5,
        ]);
    }

    public function test_disabling_position_with_active_incumbents_is_rejected(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'OCC-ORG');
        $position = $this->position($entity, $org, 'OCC-001');
        $employment = $scenario->employment($scenario->person(), $entity, 'OCC-EMP');
        $scenario->assignment($employment, $org, ['position_id' => $position->id]);

        $this->patchJson("/v1/positions/{$position->id}", [
            'status' => 'DISABLED',
        ])->assertConflict()->assertJsonPath('data.reason', 'POSITION_HAS_INCUMBENTS');
    }

    public function test_cross_tenant_position_read_is_404(): void
    {
        $scenario = $this->scenario(1);
        $entity = $scenario->legalEntity();
        $position = $this->position($entity, $scenario->orgUnit($entity, 'X-ORG'), 'X-001');

        $this->actAs(tenantId: 2, userId: 999);

        $this->patchJson("/v1/positions/{$position->id}", ['title' => 'Hijack'])->assertNotFound();
    }

    public function test_missing_manage_capability_is_403(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $position = $this->position($entity, $scenario->orgUnit($entity, 'D-ORG'), 'D-001');

        $this->authorizer->deniedCapabilities = ['org.position.manage'];

        $this->patchJson("/v1/positions/{$position->id}", ['title' => 'Denied'])->assertForbidden();
    }

    private function position(LegalEntity $entity, \App\Modules\Organization\Models\OrgUnit $org, string $code): Position
    {
        return Position::query()->create([
            'tenant_id' => 1,
            'legal_entity_id' => $entity->id,
            'org_unit_id' => $org->id,
            'code' => $code,
            'title' => 'Synthetic Position',
            'grade' => 'P4',
            'headcount_limit' => 2,
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ]);
    }
}
