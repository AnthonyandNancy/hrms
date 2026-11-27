<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Modules\Organization\Models\OrgUnit;
use Tests\Support\PeopleTestCase;

final class OrgUnitWriteTest extends PeopleTestCase
{
    public function test_create_org_unit_builds_closure_and_audits(): void
    {
        $scenario = $this->scenario();
        $parent = $scenario->orgUnit('WRITE-PARENT', null, 'Parent', 'COMPANY');

        $response = $this->postJson('/v1/org-units', [
            'parent_id' => $parent->id,
            'code' => 'WRITE-CHILD',
            'name' => 'Child',
            'type' => 'DEPARTMENT',
        ])->assertCreated();

        $childId = (int) $response->json('data.id');
        $this->assertDatabaseHas('org_unit_closure', [
            'ancestor_id' => $parent->id,
            'descendant_id' => $childId,
            'depth' => 1,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'org.unit.created',
            'aggregate_id' => (string) $childId,
        ]);
    }

    public function test_impact_preview_counts_employees_managers_and_zero_payroll_cost_centers(): void
    {
        $scenario = $this->scenario();
        $division = $scenario->orgUnit('IMPACT-DIV', null, 'Division', 'DIVISION');
        $team = $scenario->orgUnit('IMPACT-TEAM', $division);
        $other = $scenario->orgUnit('IMPACT-OTHER', null, 'Other', 'DIVISION');
        $managerEmployment = $scenario->employment($scenario->person(), 'IMPACT-M');
        $managerAssignment = $scenario->assignment($managerEmployment, $team);
        $employeeEmployment = $scenario->employment($scenario->person(), 'IMPACT-E');
        $scenario->assignment($employeeEmployment, $team, ['manager_assignment_id' => $managerAssignment->id]);

        $response = $this->getJson("/v1/org-units/{$team->id}/impact?new_parent_id={$other->id}&as_of=2026-11-23")
            ->assertOk();

        $this->assertSame(2, $response->json('data.affected_employee_count'));
        $this->assertSame(1, $response->json('data.affected_manager_count'));
        $this->assertSame(0, $response->json('data.payroll_cost_center_count'));
        $this->assertFalse($response->json('data.cross_entity_warning'));
    }

    public function test_impact_of_an_unknown_new_parent_is_not_found(): void
    {
        $scenario = $this->scenario();
        $unit = $scenario->orgUnit('IMPACT-UNKNOWN-PARENT');

        $this->getJson("/v1/org-units/{$unit->id}/impact?new_parent_id=999999&as_of=2026-11-23")
            ->assertNotFound();
    }

    public function test_org_type_is_validated(): void
    {
        $this->postJson('/v1/org-units', [
            'code' => 'BAD-TYPE',
            'name' => 'Bad',
            'type' => 'CUSTOM_DEPARTMENT',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_org_unit_codes_are_unique_within_the_company(): void
    {
        $scenario = $this->scenario();
        $scenario->orgUnit('DUP-CODE');

        $this->postJson('/v1/org-units', [
            'code' => 'DUP-CODE',
            'name' => 'Duplicate',
            'type' => 'DEPARTMENT',
        ])->assertConflict()->assertJsonPath('data.reason', 'ORG_UNIT_CODE_DUPLICATE');
    }

    public function test_org_unit_cannot_be_created_under_an_unknown_parent(): void
    {
        $this->postJson('/v1/org-units', [
            'parent_id' => 999999,
            'code' => 'ORPHAN',
            'name' => 'Orphan',
            'type' => 'DEPARTMENT',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->assertSame(0, OrgUnit::query()->where('code', 'ORPHAN')->count());
    }
}
