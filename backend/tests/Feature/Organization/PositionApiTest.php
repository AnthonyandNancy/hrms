<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Modules\Organization\Models\OrgUnit;
use App\Modules\Organization\Models\Position;
use Tests\Support\PeopleTestCase;

final class PositionApiTest extends PeopleTestCase
{
    public function test_hr_creates_lists_and_updates_a_position(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('POS-ORG');

        $created = $this->postJson('/v1/positions', [
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
            'code' => 'ENG-001',
            'headcount_limit' => 3,
        ]);

        $this->getJson('/v1/positions?as_of=2026-11-23')
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
        $org = $scenario->orgUnit('OCC-ORG');
        $position = $this->position($org, 'OCC-001');
        $employment = $scenario->employment($scenario->person(), 'OCC-EMP');
        $scenario->assignment($employment, $org, ['position_id' => $position->id]);

        $this->patchJson("/v1/positions/{$position->id}", [
            'status' => 'DISABLED',
        ])->assertConflict()->assertJsonPath('data.reason', 'POSITION_STAFFED');
    }

    public function test_unknown_position_read_is_404(): void
    {
        $this->patchJson('/v1/positions/999999', ['title' => 'Hijack'])->assertNotFound();
    }

    public function test_missing_manage_capability_is_403(): void
    {
        $scenario = $this->scenario();
        $position = $this->position($scenario->orgUnit('D-ORG'), 'D-001');

        $this->authorizer->deniedCapabilities = ['org.position.manage'];

        $this->patchJson("/v1/positions/{$position->id}", ['title' => 'Denied'])->assertForbidden();
    }

    private function position(OrgUnit $org, string $code): Position
    {
        return Position::query()->create([
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
