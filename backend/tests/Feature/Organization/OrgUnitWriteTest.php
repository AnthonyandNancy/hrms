<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use Tests\Support\PeopleTestCase;

final class OrgUnitWriteTest extends PeopleTestCase
{
    public function test_org_unit_create_writer_is_retired(): void
    {
        $scenario = $this->scenario();
        $parent = $scenario->orgUnit('WRITE-PARENT', null, 'Parent', 'COMPANY');

        $this->postJson('/v1/org-units', [
            'parent_id' => $parent->id,
            'code' => 'WRITE-CHILD',
            'name' => 'Child',
            'type' => 'DEPARTMENT',
        ])->assertNotFound();

        $this->assertDatabaseMissing('org_units', ['code' => 'WRITE-CHILD']);
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
}
