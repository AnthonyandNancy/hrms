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
        $entity = $scenario->legalEntity();
        $parent = $scenario->orgUnit($entity, 'WRITE-PARENT', null, 'Parent', 'COMPANY');

        $response = $this->postJson('/v1/org-units', [
            'legal_entity_id' => $entity->id,
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
        $entity = $scenario->legalEntity();
        $division = $scenario->orgUnit($entity, 'IMPACT-DIV', null, 'Division', 'DIVISION');
        $team = $scenario->orgUnit($entity, 'IMPACT-TEAM', $division);
        $other = $scenario->orgUnit($entity, 'IMPACT-OTHER', null, 'Other', 'DIVISION');
        $managerEmployment = $scenario->employment($scenario->person(), $entity, 'IMPACT-M');
        $managerAssignment = $scenario->assignment($managerEmployment, $team);
        $employeeEmployment = $scenario->employment($scenario->person(), $entity, 'IMPACT-E');
        $scenario->assignment($employeeEmployment, $team, ['manager_assignment_id' => $managerAssignment->id]);

        $response = $this->getJson("/v1/org-units/{$team->id}/impact?new_parent_id={$other->id}&as_of=2026-11-23")
            ->assertOk();

        $this->assertSame(2, $response->json('data.affected_employee_count'));
        $this->assertSame(1, $response->json('data.affected_manager_count'));
        $this->assertSame(0, $response->json('data.payroll_cost_center_count'));
        $this->assertFalse($response->json('data.cross_entity_warning'));
    }

    public function test_impact_warns_on_cross_entity_parent(): void
    {
        $scenario = $this->scenario();
        $cn = $scenario->legalEntity('IMPACT-CN');
        $sg = $scenario->legalEntity('IMPACT-SG', 'SG', 'Asia/Singapore', 'SGD');
        $unit = $scenario->orgUnit($cn, 'IMPACT-CN-ORG');
        $sgParent = $scenario->orgUnit($sg, 'IMPACT-SG-ORG');

        $this->getJson("/v1/org-units/{$unit->id}/impact?new_parent_id={$sgParent->id}&as_of=2026-11-23")
            ->assertOk()
            ->assertJsonPath('data.cross_entity_warning', true);
    }

    public function test_org_type_is_validated(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();

        $this->postJson('/v1/org-units', [
            'legal_entity_id' => $entity->id,
            'code' => 'BAD-TYPE',
            'name' => 'Bad',
            'type' => 'CUSTOM_DEPARTMENT',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_org_unit_codes_are_unique_within_tenant(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $scenario->orgUnit($entity, 'DUP-CODE');

        $this->postJson('/v1/org-units', [
            'legal_entity_id' => $entity->id,
            'code' => 'DUP-CODE',
            'name' => 'Duplicate',
            'type' => 'DEPARTMENT',
        ])->assertConflict()->assertJsonPath('data.reason', 'ORG_UNIT_CODE_DUPLICATE');
    }

    public function test_tenant_cannot_create_org_unit_under_another_tenants_entity(): void
    {
        $scenario = $this->scenario(1);
        $entity = $scenario->legalEntity();
        $this->actAs(tenantId: 2, userId: 5);

        $this->postJson('/v1/org-units', [
            'legal_entity_id' => $entity->id,
            'code' => 'CROSS-TENANT',
            'name' => 'Cross',
            'type' => 'DEPARTMENT',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->assertSame(0, OrgUnit::query()->where('tenant_id', 2)->count());
    }
}
