<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Modules\Organization\Models\OrgUnitClosure;
use Tests\Support\PeopleTestCase;

final class DepartmentMoveImpactTest extends PeopleTestCase
{
    public function test_impact_preview_exposes_cycle_refusal_and_authorization_warning_fields(): void
    {
        $scenario = $this->scenario();
        $root = $scenario->orgUnit('IMPACT-ROOT', null, 'Root', 'DIVISION');
        $child = $scenario->orgUnit('IMPACT-CHILD', $root, 'Child');

        $this->getJson("/v1/org-units/{$root->id}/impact?new_parent_id={$child->id}&as_of=2026-11-23")
            ->assertOk()
            ->assertJsonPath('data.cycle_refused', true)
            ->assertJsonStructure([
                'data' => [
                    'affected_employee_count',
                    'affected_manager_count',
                    'payroll_cost_center_count',
                    'cross_entity_warning',
                    'cycle_refused',
                    'authorization_warning',
                ],
            ]);
    }

    public function test_department_move_confirmation_is_idempotent(): void
    {
        $scenario = $this->scenario();
        $unit = $scenario->orgUnit('MOVE-IDEM-UNIT', null, 'Unit', 'DEPARTMENT');
        $target = $scenario->orgUnit('MOVE-IDEM-TARGET', null, 'Target', 'DIVISION');
        $payload = ['new_parent_id' => (int) $target->id, 'effective_from' => '2026-12-01'];
        $headers = ['X-CSRF-Token' => 'test-csrf', 'Idempotency-Key' => 'move-1'];

        $this->postJson("/v1/org-units/{$unit->id}/move", $payload, $headers)->assertOk();
        $closureRowsAfterFirstMove = OrgUnitClosure::query()
            ->where('descendant_id', (int) $unit->id)
            ->where('valid_from', '2026-12-01')
            ->count();

        $this->postJson("/v1/org-units/{$unit->id}/move", $payload, $headers)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(
            $closureRowsAfterFirstMove,
            OrgUnitClosure::query()
                ->where('descendant_id', (int) $unit->id)
                ->where('valid_from', '2026-12-01')
                ->count(),
        );
        $this->assertSame(1, OrgUnitClosure::query()
            ->where('ancestor_id', (int) $unit->id)
            ->where('descendant_id', (int) $unit->id)
            ->where('valid_from', '2026-12-01')
            ->count());
    }

    public function test_move_with_cycle_is_rejected_with_stable_reason(): void
    {
        $scenario = $this->scenario();
        $parent = $scenario->orgUnit('CYCLE-PARENT', null, 'Parent', 'DIVISION');
        $child = $scenario->orgUnit('CYCLE-CHILD', $parent, 'Child');

        $this->postJson("/v1/org-units/{$parent->id}/move", [
            'new_parent_id' => (int) $child->id,
            'effective_from' => '2026-12-01',
        ], [
            'X-CSRF-Token' => 'test-csrf',
            'Idempotency-Key' => 'move-cycle',
        ])->assertUnprocessable()
            ->assertJsonPath('data.reason', 'ORG_CYCLE');
    }

    public function test_move_requires_manage_capability_and_does_not_write_on_denial(): void
    {
        $scenario = $this->scenario();
        $unit = $scenario->orgUnit('MOVE-DENIED', null, 'Unit', 'DEPARTMENT');
        $target = $scenario->orgUnit('MOVE-DENIED-TARGET', null, 'Target', 'DIVISION');
        $this->actingAsUserWithCapabilities(['org.unit.read']);

        $this->postJson("/v1/org-units/{$unit->id}/move", [
            'new_parent_id' => (int) $target->id,
            'effective_from' => '2026-12-01',
        ], [
            'X-CSRF-Token' => 'test-csrf',
            'Idempotency-Key' => 'move-denied',
        ])->assertForbidden();

        $this->assertDatabaseMissing('org_unit_closure', [
            'descendant_id' => (int) $unit->id,
            'valid_from' => '2026-12-01',
        ]);
    }
}
