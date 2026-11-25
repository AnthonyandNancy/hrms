<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Modules\Organization\Models\OrgUnit;
use App\Modules\People\Models\Assignment;
use Tests\Support\PeopleTestCase;

final class DepartmentApiTest extends PeopleTestCase
{
    public function test_department_list_includes_headcount_and_can_be_filtered(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'DEP-001', null, 'Synthetic Department');
        $employment = $scenario->employment($scenario->person(), $entity, 'DEP-EMP-1');
        $scenario->assignment($employment, $org);

        $this->getJson('/v1/departments?legal_entity_id='.$entity->id.'&as_of=2026-11-23')
            ->assertOk()
            ->assertJsonPath('data.items.0.code', 'DEP-001')
            ->assertJsonPath('data.items.0.headcount', 1)
            ->assertJsonPath('data.items.0.direct_headcount', 1);
    }

    public function test_members_can_be_listed_for_a_department(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'MEM-001');
        $employment = $scenario->employment($scenario->person(), $entity, 'MEM-EMP');
        $scenario->assignment($employment, $org);

        $this->getJson("/v1/departments/{$org->id}/members?as_of=2026-11-23")
            ->assertOk()
            ->assertJsonPath('data.items.0.employment_id', $employment->id)
            ->assertJsonPath('data.items.0.assignment_type', 'PRIMARY');
    }

    public function test_staffing_compares_headcount_limit_with_active_assignments(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'STAFF-001');
        $employment = $scenario->employment($scenario->person(), $entity, 'STAFF-EMP');
        $scenario->assignment($employment, $org);
        OrgUnit::query()->whereKey($org->id)->update(['headcount_limit' => 3]);

        $this->getJson('/v1/departments/staffing?as_of=2026-11-23&legal_entity_id='.$entity->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.org_unit_id', $org->id)
            ->assertJsonPath('data.items.0.headcount_limit', 3)
            ->assertJsonPath('data.items.0.staffed', 1)
            ->assertJsonPath('data.items.0.vacancies', 2);
    }

    public function test_department_member_listing_is_tenant_scoped(): void
    {
        $scenario = $this->scenario(1);
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'TEN-001');

        $this->actAs(tenantId: 2, userId: 999);

        $this->getJson("/v1/departments/{$org->id}/members")->assertNotFound();
    }

    public function test_assignment_can_be_moved_to_another_department_without_rewriting_history(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $from = $scenario->orgUnit($entity, 'MOVE-FROM');
        $to = $scenario->orgUnit($entity, 'MOVE-TO');
        $employment = $scenario->employment($scenario->person(), $entity, 'MOVE-EMP');
        $assignment = $scenario->assignment($employment, $from);

        $this->postJson("/v1/departments/{$to->id}/members", [
            'employment_id' => $employment->id,
            'effective_from' => '2026-12-01',
            'reason' => 'Synthetic department move.',
        ])->assertCreated();

        $old = Assignment::query()->findOrFail($assignment->id);
        $this->assertSame('2026-12-01', (string) $old->valid_to);
        $this->assertDatabaseHas('assignments', [
            'employment_id' => $employment->id,
            'org_unit_id' => $to->id,
            'valid_from' => '2026-12-01',
        ]);
    }
}
