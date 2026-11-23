<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\Employment;
use Tests\Support\PeopleTestCase;

final class EmploymentWriteTest extends PeopleTestCase
{
    public function test_create_employment_validates_hire_inside_validity(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $person = $scenario->person();

        $this->postJson('/v1/employments', [
            'person_id' => $person->id,
            'legal_entity_id' => $entity->id,
            'employee_no' => 'HIRE-BAD',
            'employment_type' => 'FULL_TIME',
            'valid_from' => '2026-02-01',
            'valid_to' => '2026-12-31',
            'hire_date' => '2026-01-01',
        ])->assertUnprocessable()->assertJsonPath('data.fields.hire_date.0', 'Hire date must fall inside the employment validity window.');
    }

    public function test_create_employment_rejects_unknown_person_or_entity_tenant_safely(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $person = $scenario->person();

        $this->postJson('/v1/employments', [
            'person_id' => 999999,
            'legal_entity_id' => $entity->id,
            'employee_no' => 'NO-PERSON',
            'employment_type' => 'FULL_TIME',
            'valid_from' => '2026-01-01',
            'hire_date' => '2026-01-01',
        ])->assertUnprocessable();

        $this->postJson('/v1/employments', [
            'person_id' => $person->id,
            'legal_entity_id' => 999999,
            'employee_no' => 'NO-ENTITY',
            'employment_type' => 'FULL_TIME',
            'valid_from' => '2026-01-01',
            'hire_date' => '2026-01-01',
        ])->assertUnprocessable();
    }

    public function test_employment_type_and_status_enums_are_enforced(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $person = $scenario->person();

        $this->postJson('/v1/employments', [
            'person_id' => $person->id,
            'legal_entity_id' => $entity->id,
            'employee_no' => 'BAD-ENUM',
            'employment_type' => 'GIG',
            'valid_from' => '2026-01-01',
            'hire_date' => '2026-01-01',
        ])->assertUnprocessable();
    }

    public function test_assignment_cannot_outlive_employment_window(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'OUTLIVE');
        $employment = $scenario->employment($scenario->person(), $entity, 'OUTLIVE-1', ['valid_to' => '2026-06-01']);

        $this->postJson("/v1/employments/{$employment->id}/assignments", [
            'org_unit_id' => $org->id,
            'assignment_type' => 'PRIMARY',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-07-01',
        ])->assertUnprocessable();

        $this->assertSame(0, Employment::query()->firstOrFail()->newQuery()->getConnection()->table('assignments')->count());
    }

    public function test_assignment_to_future_org_unit_is_rejected_before_activation(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $futureOrg = $scenario->orgUnit($entity, 'FUTURE-ORG', null, 'Future', 'DEPARTMENT', '2027-01-01');
        $employment = $scenario->employment($scenario->person(), $entity, 'FUTURE-ORG-EMP');

        $this->postJson("/v1/employments/{$employment->id}/assignments", [
            'org_unit_id' => $futureOrg->id,
            'assignment_type' => 'PRIMARY',
            'valid_from' => '2026-01-01',
        ])->assertUnprocessable();
    }

    public function test_terms_cannot_start_before_employment(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $employment = $scenario->employment($scenario->person(), $entity, 'TERMS-BEFORE', ['valid_from' => '2026-02-01']);

        $this->postJson("/v1/employments/{$employment->id}/terms", [
            'effective_from' => '2026-01-01',
            'work_location' => 'Shanghai',
            'work_schedule' => 'STANDARD',
        ])->assertUnprocessable();
    }
}
