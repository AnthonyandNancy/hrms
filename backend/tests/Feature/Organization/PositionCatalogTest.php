<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use Tests\Support\PeopleTestCase;

final class PositionCatalogTest extends PeopleTestCase
{
    public function test_position_cannot_be_disabled_while_staffed_and_staff_list_is_authorized(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('POS-ORG');
        $employment = $scenario->employment($scenario->person(), 'POS-0001');
        $position = $scenario->position($org, 'POS-001');
        $scenario->assignment($employment, $org, ['position_id' => $position->id]);

        $this->getJson("/v1/positions/{$position->id}/staff")
            ->assertOk()
            ->assertJsonPath('data.items.0.employment_id', $employment->id);

        $this->patchJson("/v1/positions/{$position->id}", ['status' => 'DISABLED'], ['Idempotency-Key' => 'pos-disable-1'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'POSITION_STAFFED');
    }

    public function test_requirement_versions_are_append_only_and_idempotent(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('REQ-ORG');
        $position = $scenario->position($org, 'REQ-001');

        $first = $this->postJson("/v1/positions/{$position->id}/requirements", [
            'requirements' => ['education' => 'BACHELOR', 'years' => 3],
            'effective_from' => '2026-12-01',
        ], ['Idempotency-Key' => 'req-v1'])->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.requirements_json.years', 3);

        // Same key and payload replays the stored 201 body as 200.
        $this->postJson("/v1/positions/{$position->id}/requirements", [
            'requirements' => ['education' => 'BACHELOR', 'years' => 3],
            'effective_from' => '2026-12-01',
        ], ['Idempotency-Key' => 'req-v1'])
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.id', (int) $first->json('data.id'));

        // Same key with a different payload is a conflict, not a second write.
        $this->postJson("/v1/positions/{$position->id}/requirements", [
            'requirements' => ['education' => 'MASTER'],
            'effective_from' => '2026-12-01',
        ], ['Idempotency-Key' => 'req-v1'])->assertStatus(409);

        $this->postJson("/v1/positions/{$position->id}/requirements", [
            'requirements' => ['education' => 'MASTER', 'years' => 5],
            'effective_from' => '2027-01-01',
        ], ['Idempotency-Key' => 'req-v2'])->assertCreated()
            ->assertJsonPath('data.version', 2);

        $this->assertDatabaseCount('position_qualifications', 2);

        $this->getJson("/v1/positions/{$position->id}/requirements?as_of=2027-01-01")
            ->assertOk()
            ->assertJsonPath('data.items.0.version', 2)
            ->assertJsonPath('data.items.0.requirements_json.education', 'MASTER')
            ->assertJsonPath('data.items.1.version', 1);

        // Qualification writes require an Idempotency-Key.
        $this->postJson("/v1/positions/{$position->id}/requirements", [
            'requirements' => ['education' => 'PHD'],
            'effective_from' => '2028-01-01',
        ])->assertStatus(422)->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    public function test_grade_catalogue_upserts_and_lists(): void
    {
        $scenario = $this->scenario();

        $this->postJson('/v1/positions/grades', [
            'code' => 'P5',
            'name' => 'Professional 5',
            'level' => 5,
        ], ['Idempotency-Key' => 'grade-p5'])->assertCreated()
            ->assertJsonPath('data.code', 'P5')
            ->assertJsonPath('data.active', true);

        $this->postJson('/v1/positions/grades', [
            'code' => 'P5',
            'name' => 'Professional 5 (revised)',
            'level' => 6,
            'active' => false,
        ], ['Idempotency-Key' => 'grade-p5-v2'])->assertOk()
            ->assertJsonPath('data.level', 6)
            ->assertJsonPath('data.active', false);

        $this->assertDatabaseCount('position_grades', 1);

        $this->getJson('/v1/positions/grades?active=false')
            ->assertOk()
            ->assertJsonPath('data.items.0.code', 'P5')
            ->assertJsonPath('data.items.0.level', 6);

        $this->getJson('/v1/positions/grades?active=true')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_position_subresources_require_an_existing_position(): void
    {
        $this->getJson('/v1/positions/999999/staff')->assertNotFound();
        $this->getJson('/v1/positions/999999/requirements')->assertNotFound();
        $this->postJson('/v1/positions/999999/requirements', [
            'requirements' => ['education' => 'BACHELOR'],
            'effective_from' => '2026-12-01',
        ], ['Idempotency-Key' => 'unknown-position'])->assertNotFound();
    }

    public function test_position_subresources_require_their_capabilities(): void
    {
        $scenario = $this->scenario();
        $position = $scenario->position($scenario->orgUnit('CAP-ORG'), 'CAP-001');

        $this->authorizer->deniedCapabilities = [
            'org.position.staff.read',
            'org.position.qualification.manage',
            'org.position.grade.manage',
        ];

        $this->getJson("/v1/positions/{$position->id}/staff")->assertForbidden();
        $this->getJson("/v1/positions/{$position->id}/requirements")->assertForbidden();
        $this->getJson('/v1/positions/grades')->assertForbidden();
    }
}
