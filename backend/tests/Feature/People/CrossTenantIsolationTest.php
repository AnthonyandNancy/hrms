<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\Person;
use Tests\Support\PeopleTestCase;

/**
 * Single-company isolation: the scope dimension is department/org-unit and
 * explicit row visibility, never a company id.
 */
final class CrossTenantIsolationTest extends PeopleTestCase
{
    public function test_employment_create_rejects_unknown_person(): void
    {
        $scenario = $this->scenario();
        $person = $scenario->person();

        // Remove the person so the create request references an out-of-scope id.
        $person->delete();

        $this->postJson('/v1/employments', [
            'person_id' => $person->id,
            'employee_no' => 'CROSS-1',
            'employment_type' => 'FULL_TIME',
            'valid_from' => '2026-01-01',
            'hire_date' => '2026-01-01',
        ])->assertUnprocessable();
    }

    public function test_person_identity_dedup_is_company_wide(): void
    {
        $payload = ['legal_name' => 'Same Identity', 'id_type' => 'NATIONAL_ID', 'id_number' => 'COMPANY-SAME'];
        $this->postJson('/v1/persons', $payload)->assertCreated();

        $this->actAs(userId: 21);
        $this->postJson('/v1/persons', $payload)->assertConflict();

        $this->assertSame(1, Person::query()->count());
    }

    public function test_assignment_route_404s_for_employment_outside_the_authorized_scope(): void
    {
        $scenario = $this->scenario();
        $home = $scenario->orgUnit('HOME-ORG');
        $other = $scenario->orgUnit('OTHER-ORG');
        $employment = $scenario->employment($scenario->person(), 'OUT-OF-SCOPE-EMP');
        $scenario->assignment($employment, $home);

        $this->authorizer->deniedCapabilities = [];
        $this->actingAsUserWithCapabilities(['people.employee.read']);

        $this->postJson("/v1/employments/{$employment->id}/assignments", [
            'org_unit_id' => $other->id,
            'assignment_type' => 'PRIMARY',
            'valid_from' => '2026-01-01',
        ])->assertForbidden();
    }
}
