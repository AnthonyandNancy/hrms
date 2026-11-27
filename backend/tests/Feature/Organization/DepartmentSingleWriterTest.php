<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Modules\Organization\Models\OrgUnit;
use Tests\Support\PeopleTestCase;

final class DepartmentSingleWriterTest extends PeopleTestCase
{
    public function test_department_create_is_the_only_org_unit_create_writer(): void
    {
        $this->postJson('/v1/departments', [
            'code' => 'ORG-1',
            'name' => '合成一部',
            'type' => 'DEPARTMENT',
            'valid_from' => '2026-01-01',
        ], [
            'X-CSRF-Token' => 'test-csrf',
            'Idempotency-Key' => 'm05-create-1',
        ])->assertCreated()->assertJsonPath('data.code', 'ORG-1');

        $this->postJson('/v1/org-units', [
            'code' => 'ORG-2',
            'name' => '第二写入口',
            'type' => 'DEPARTMENT',
            'valid_from' => '2026-01-01',
        ], [
            'X-CSRF-Token' => 'test-csrf',
            'Idempotency-Key' => 'm05-create-2',
        ])->assertNotFound();

        $this->assertDatabaseMissing('org_units', ['code' => 'ORG-2']);
        $this->assertSame(1, OrgUnit::query()->where('code', 'ORG-1')->count());
    }

    public function test_department_create_replays_the_same_command_key(): void
    {
        $payload = [
            'code' => 'ORG-IDEMPOTENT',
            'name' => '幂等部门',
            'type' => 'DEPARTMENT',
            'valid_from' => '2026-01-01',
        ];
        $headers = ['X-CSRF-Token' => 'test-csrf', 'Idempotency-Key' => 'm05-create-idem'];

        $first = $this->postJson('/v1/departments', $payload, $headers)->assertCreated();

        $this->postJson('/v1/departments', $payload, $headers)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.id', (int) $first->json('data.id'));

        $this->assertDatabaseCount('org_units', 1);
    }

    public function test_department_patch_revises_basic_facts_without_a_second_parent_writer(): void
    {
        $unit = $this->scenario()->orgUnit('ORG-PATCH', null, '旧名称');

        $this->patchJson('/v1/departments/'.$unit->id, [
            'code' => 'ORG-PATCH',
            'name' => '新名称',
            'type' => 'DEPARTMENT',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'manager_employment_id' => null,
        ], [
            'X-CSRF-Token' => 'test-csrf',
            'Idempotency-Key' => 'm05-patch-1',
        ])->assertOk()
            ->assertJsonPath('data.moved', false)
            ->assertJsonPath('data.org_unit.name', '新名称');

        $this->assertDatabaseHas('org_units', [
            'id' => (int) $unit->id,
            'name' => '新名称',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'org.unit.updated',
            'aggregate_id' => (string) $unit->id,
        ]);
    }

    public function test_department_patch_can_still_move_and_returns_moved_shape(): void
    {
        $scenario = $this->scenario();
        $parent = $scenario->orgUnit('M05-PARENT', null, '目标上级');
        $child = $scenario->orgUnit('M05-CHILD', null, '待迁移部门');

        $this->patchJson('/v1/departments/'.$child->id, [
            'new_parent_id' => (int) $parent->id,
            'effective_from' => '2026-12-01',
        ], [
            'X-CSRF-Token' => 'test-csrf',
            'Idempotency-Key' => 'm05-patch-move-1',
        ])->assertOk()
            ->assertJsonPath('data.moved', true)
            ->assertJsonPath('data.old_org_unit_id', (int) $child->id)
            ->assertJsonPath('data.org_unit.parent_id', (int) $parent->id);

        $this->assertSame((int) $parent->id, (int) OrgUnit::query()->whereKey($child->id)->value('parent_id'));
    }

    public function test_department_writes_require_the_manage_capability(): void
    {
        $this->actingAsUserWithCapabilities(['org.unit.read']);

        $this->postJson('/v1/departments', [
            'code' => 'ORG-DENIED',
            'name' => '越权部门',
            'type' => 'DEPARTMENT',
            'valid_from' => '2026-01-01',
        ], [
            'X-CSRF-Token' => 'test-csrf',
            'Idempotency-Key' => 'm05-denied',
        ])->assertForbidden();

        $this->assertDatabaseMissing('org_units', ['code' => 'ORG-DENIED']);
    }
}
