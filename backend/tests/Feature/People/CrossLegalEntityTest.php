<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Tests\Support\PeopleTestCase;

/**
 * Cross-department behavior replaces the retired multi-legal-entity tests.
 */
final class CrossLegalEntityTest extends PeopleTestCase
{
    public function test_assignment_to_an_inactive_department_is_rejected_with_reason_code(): void
    {
        $scenario = $this->scenario();
        $home = $scenario->orgUnit('HOME-DEPT');
        $closed = $scenario->orgUnit('CLOSED-DEPT', null, 'Closed', 'DEPARTMENT', '2026-01-01', '2026-02-01');
        $person = $scenario->person();
        $employment = $scenario->employment($person, 'X-1', ['valid_from' => '2026-03-01', 'hire_date' => '2026-03-01']);

        $response = $this->postJson("/v1/employments/{$employment->id}/assignments", [
            'org_unit_id' => $closed->id,
            'assignment_type' => 'PRIMARY',
            'valid_from' => '2026-03-01',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('data.fields.org_unit_id.0', 'The organization unit is not active on the assignment start date.');
    }

    public function test_employee_number_is_unique_company_wide(): void
    {
        $scenario = $this->scenario();
        $person = $scenario->person();
        $scenario->employment($person, 'SHARED-1');

        $this->postJson('/v1/employments', [
            'person_id' => $person->id,
            'employee_no' => 'SHARED-1',
            'employment_type' => 'FULL_TIME',
            'valid_from' => '2026-02-01',
            'hire_date' => '2026-02-01',
        ])->assertConflict()->assertJsonPath('data.reason', 'EMPLOYEE_NO_DUPLICATE');
    }

    public function test_department_move_ends_the_old_assignment_and_opens_the_new_one(): void
    {
        $scenario = $this->scenario();
        $home = $scenario->orgUnit('MOVE-HOME');
        $target = $scenario->orgUnit('MOVE-TARGET');
        $employment = $scenario->employment($scenario->person(), 'MOVE-1');
        $old = $scenario->assignment($employment, $home);

        $response = $this->postJson("/v1/departments/{$target->id}/members", [
            'employment_id' => $employment->id,
            'effective_from' => '2026-12-01',
            'reason' => 'Department transfer',
        ], ['Idempotency-Key' => 'cross-legal-member-move']);

        $response->assertCreated();
        $old->refresh();
        $this->assertSame('2026-12-01', $old->valid_to);
        $this->assertDatabaseHas('assignments', [
            'employment_id' => $employment->id,
            'org_unit_id' => $target->id,
            'valid_from' => '2026-12-01',
        ]);
    }
}
