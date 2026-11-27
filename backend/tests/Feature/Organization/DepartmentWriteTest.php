<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Modules\Organization\Models\OrgUnit;
use App\Modules\People\Models\Assignment;
use Tests\Support\PeopleTestCase;

/**
 * Volume 3 Task 5: department write surface.
 *
 * The workspace can create a department, move it under a new parent, add a
 * member and remove a member with an effective date. Removal must be
 * effective-dated (history preserved), never a destructive delete.
 */
final class DepartmentWriteTest extends PeopleTestCase
{
    public function test_it_creates_a_department(): void
    {
        $response = $this->postJson('/v1/departments', [
            'code' => 'SYNTH-DEPT-NEW',
            'name' => '合成新部门',
            'type' => 'DEPARTMENT',
            'valid_from' => '2026-01-01',
        ], ['X-CSRF-Token' => 'test-csrf', 'Idempotency-Key' => 'dept-create-1'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'SYNTH-DEPT-NEW');

        $this->assertDatabaseHas('org_units', [
            'id' => (int) $response->json('data.id'),
            'code' => 'SYNTH-DEPT-NEW',
        ]);
    }

    public function test_it_rejects_a_duplicate_department_code(): void
    {
        $this->postJson('/v1/departments', [
            'code' => 'SYNTH-DEPT-DUP',
            'name' => '合成重复部门',
            'type' => 'DEPARTMENT',
            'valid_from' => '2026-01-01',
        ], ['X-CSRF-Token' => 'test-csrf', 'Idempotency-Key' => 'dept-create-dup-1'])->assertCreated();

        $this->postJson('/v1/departments', [
            'code' => 'SYNTH-DEPT-DUP',
            'name' => '另一个部门',
            'type' => 'DEPARTMENT',
            'valid_from' => '2026-01-01',
        ], ['X-CSRF-Token' => 'test-csrf', 'Idempotency-Key' => 'dept-create-dup-2'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'ORG_UNIT_CODE_DUPLICATE');
    }

    public function test_it_moves_a_department_under_a_new_parent(): void
    {
        $parent = $this->scenario()->orgUnit('SYNTH-PARENT-'.bin2hex(random_bytes(3)), null, '上级部门');
        $child = $this->scenario()->orgUnit('SYNTH-CHILD-'.bin2hex(random_bytes(3)), null, '下级部门');

        $this->patchJson('/v1/departments/'.$child->id, [
            'new_parent_id' => (int) $parent->id,
            'effective_from' => '2026-06-01',
        ], ['X-CSRF-Token' => 'test-csrf', 'Idempotency-Key' => 'dept-move-1'])
            ->assertOk();

        $this->assertSame(
            (int) $parent->id,
            (int) OrgUnit::query()->whereKey($child->id)->value('parent_id'),
        );
    }

    public function test_removing_a_member_is_effective_dated_and_keeps_history(): void
    {
        $unit = $this->scenario()->orgUnit('SYNTH-MEMBER-DEPT-'.bin2hex(random_bytes(3)), null, '成员部门');
        $person = $this->scenario()->person();
        $employment = $this->scenario()->employment($person, 'SYNTH-MEM-'.bin2hex(random_bytes(3)));

        $assignment = Assignment::query()->create([
            'employment_id' => (int) $employment->id,
            'org_unit_id' => (int) $unit->id,
            'assignment_type' => 'PRIMARY',
            'is_primary' => true,
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ]);

        $this->deleteJson(
            '/v1/departments/'.$unit->id.'/members/'.$employment->id,
            ['effective_from' => '2026-07-01', 'reason' => '合成调岗测试'],
            ['X-CSRF-Token' => 'test-csrf', 'Idempotency-Key' => 'dept-member-remove-1'],
        )->assertOk();

        // The row survives with a closed validity window: history is preserved.
        $this->assertDatabaseHas('assignments', [
            'id' => (int) $assignment->id,
            'valid_to' => '2026-07-01',
        ]);
    }

    public function test_mutating_departments_requires_the_manage_capability(): void
    {
        $this->forgetPrincipal();

        $this->postJson('/v1/departments', [
            'code' => 'SYNTH-DEPT-DENIED',
            'name' => '越权部门',
            'type' => 'DEPARTMENT',
            'valid_from' => '2026-01-01',
        ], ['X-CSRF-Token' => 'test-csrf'])->assertUnauthorized();
    }
}
