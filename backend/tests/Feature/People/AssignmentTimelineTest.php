<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\Assignment;
use Tests\Support\PeopleTestCase;

final class AssignmentTimelineTest extends PeopleTestCase
{
    public function test_concurrent_second_assignment_is_allowed(): void
    {
        $scenario = $this->scenario();
        $parent = $scenario->orgUnit('DIV-ENG', null, 'Engineering', 'DIVISION');
        $secondary = $scenario->orgUnit('DEPT-PLATFORM', $parent);
        $person = $scenario->person();
        $employment = $scenario->employment($person, 'E1001');
        $scenario->assignment($employment, $parent);

        $response = $this->postJson("/v1/employments/{$employment->id}/assignments", [
            'org_unit_id' => $secondary->id,
            'assignment_type' => 'SECONDMENT',
            'valid_from' => '2026-02-01',
            'valid_to' => '2026-03-01',
        ]);

        $response->assertCreated()
            ->assertJsonPath('code', 'CREATED')
            ->assertJsonPath('data.assignment_type', 'SECONDMENT')
            ->assertJsonPath('data.is_primary', false);
    }

    public function test_two_overlapping_primary_assignments_are_rejected(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('DEPT-A');
        $person = $scenario->person();
        $employment = $scenario->employment($person, 'E1002');
        $scenario->assignment($employment, $org, ['valid_from' => '2026-01-01', 'valid_to' => '2026-04-01']);

        $response = $this->postJson("/v1/employments/{$employment->id}/assignments", [
            'org_unit_id' => $org->id,
            'assignment_type' => 'PRIMARY',
            'valid_from' => '2026-03-31',
            'valid_to' => '2026-06-01',
        ]);

        $response->assertConflict()->assertJsonPath('code', 'CONFLICT');
        $this->assertSame(1, Assignment::query()->count());
    }

    public function test_future_assignment_does_not_change_today(): void
    {
        $scenario = $this->scenario();
        $current = $scenario->orgUnit('DEPT-CURRENT');
        $future = $scenario->orgUnit('DEPT-FUTURE');
        $person = $scenario->person();
        $employment = $scenario->employment($person, 'E1003');
        $scenario->assignment($employment, $current, ['valid_from' => '2026-01-01', 'valid_to' => '2026-12-01']);

        $this->postJson("/v1/employments/{$employment->id}/assignments", [
            'org_unit_id' => $future->id,
            'assignment_type' => 'PRIMARY',
            'valid_from' => '2026-12-01',
        ])->assertCreated();

        $this->getJson("/v1/employees/{$employment->id}?as_of=2026-11-23")
            ->assertOk()
            ->assertJsonPath('data.current_assignment.org_unit_id', $current->id);

        $this->getJson("/v1/employees/{$employment->id}?as_of=2026-12-02")
            ->assertOk()
            ->assertJsonPath('data.current_assignment.org_unit_id', $future->id);
    }

    public function test_half_open_boundaries_of_assignments(): void
    {
        $scenario = $this->scenario();
        $first = $scenario->orgUnit('DEPT-FIRST');
        $second = $scenario->orgUnit('DEPT-SECOND');
        $person = $scenario->person();
        $employment = $scenario->employment($person, 'E1004');
        $scenario->assignment($employment, $first, ['valid_from' => '2026-01-01', 'valid_to' => '2026-02-01']);
        $scenario->assignment($employment, $second, ['valid_from' => '2026-02-01', 'valid_to' => '2026-03-01']);

        $this->getJson("/v1/employees/{$employment->id}?as_of=2026-01-31")
            ->assertOk()
            ->assertJsonPath('data.current_assignment.org_unit_id', $first->id);

        $this->getJson("/v1/employees/{$employment->id}?as_of=2026-02-01")
            ->assertOk()
            ->assertJsonPath('data.current_assignment.org_unit_id', $second->id);
    }

    public function test_manager_chain_is_projected_in_snapshot(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('DEPT-MANAGED');
        $manager = $scenario->person(['legal_name' => 'Synthetic Manager']);
        $managerEmployment = $scenario->employment($manager, 'M1001');
        $managerAssignment = $scenario->assignment($managerEmployment, $org);

        $report = $scenario->person(['legal_name' => 'Synthetic Report']);
        $reportEmployment = $scenario->employment($report, 'M1002');
        $scenario->assignment($reportEmployment, $org, ['manager_assignment_id' => $managerAssignment->id]);

        $this->getJson("/v1/employees/{$reportEmployment->id}?as_of=2026-11-23")
            ->assertOk()
            ->assertJsonPath('data.manager_employment_id', $managerEmployment->id);

        $directories = app(\App\Modules\People\Contracts\PeopleDirectory::class);
        $this->assertSame(
            [$reportEmployment->id],
            $directories->managedEmploymentIds($managerEmployment->id, '2026-11-23'),
        );
    }
}
