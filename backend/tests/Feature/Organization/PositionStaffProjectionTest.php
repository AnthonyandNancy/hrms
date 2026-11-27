<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use Tests\Support\PeopleTestCase;

/**
 * M06 position detail reads the selected position only. The source of truth
 * for incumbents is the People assignment fact exposed through
 * AssignmentDirectory; Organization never scans the employee list.
 */
final class PositionStaffProjectionTest extends PeopleTestCase
{
    public function test_staff_projection_returns_only_incumbents_of_the_selected_position(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('STAFF-ORG');
        $selected = $scenario->position($org, 'STAFF-SELECTED');
        $other = $scenario->position($org, 'STAFF-OTHER');

        $selectedPerson = $scenario->person(['legal_name' => '合成在岗甲']);
        $selectedEmployment = $scenario->employment($selectedPerson, 'STAFF-E1001');
        $selectedAssignment = $scenario->assignment($selectedEmployment, $org, [
            'position_id' => $selected->id,
            'assignment_type' => 'PRIMARY',
            'is_primary' => true,
        ]);

        $otherPerson = $scenario->person(['legal_name' => '合成非本岗乙']);
        $otherEmployment = $scenario->employment($otherPerson, 'STAFF-E1002');
        $scenario->assignment($otherEmployment, $org, [
            'position_id' => $other->id,
            'assignment_type' => 'SECONDMENT',
            'is_primary' => false,
        ]);

        $this->getJson("/v1/positions/{$selected->id}/staff?as_of=2026-11-27")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.assignment_id', (int) $selectedAssignment->id)
            ->assertJsonPath('data.items.0.employment_id', (int) $selectedEmployment->id)
            ->assertJsonPath('data.items.0.employee_no', 'STAFF-E1001')
            ->assertJsonPath('data.items.0.person_name', '合成在岗甲')
            ->assertJsonPath('data.items.0.position_id', (int) $selected->id)
            ->assertJsonPath('data.items.0.assignment_type', 'PRIMARY')
            ->assertJsonPath('data.items.0.is_primary', true)
            ->assertJsonPath('data.drilldown.filters.position_id', (int) $selected->id);
    }

    public function test_staff_projection_is_effective_dated(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('STAFF-ASOF-ORG');
        $position = $scenario->position($org, 'STAFF-ASOF');

        $active = $scenario->assignment(
            $scenario->employment($scenario->person(['legal_name' => '合成当前任职']), 'STAFF-ACTIVE'),
            $org,
            [
                'position_id' => $position->id,
                'valid_from' => '2026-01-01',
                'valid_to' => '9999-12-31',
            ],
        );
        $scenario->assignment(
            $scenario->employment($scenario->person(['legal_name' => '合成未来任职']), 'STAFF-FUTURE'),
            $org,
            [
                'position_id' => $position->id,
                'valid_from' => '2027-01-01',
                'valid_to' => '9999-12-31',
            ],
        );

        $this->getJson("/v1/positions/{$position->id}/staff?as_of=2026-11-27")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.assignment_id', (int) $active->id);
    }

    public function test_staff_without_capability_is_forbidden(): void
    {
        $scenario = $this->scenario();
        $position = $scenario->position($scenario->orgUnit('STAFF-CAP-ORG'), 'STAFF-CAP');

        $this->authorizer->deniedCapabilities = ['org.position.staff.read'];

        $this->getJson("/v1/positions/{$position->id}/staff")
            ->assertForbidden();
    }

    public function test_staff_for_unknown_position_is_not_enumerable(): void
    {
        $this->getJson('/v1/positions/999999/staff')->assertNotFound();
    }

    public function test_position_list_does_not_return_staff_rows(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('STAFF-LIST-ORG');
        $position = $scenario->position($org, 'STAFF-LIST');

        $assignment = $scenario->assignment(
            $scenario->employment($scenario->person(), 'STAFF-LIST-E1'),
            $org,
            ['position_id' => $position->id],
        );

        $response = $this->getJson('/v1/positions?as_of=2026-11-27')->assertOk();

        /** @var list<array<string, mixed>> $items */
        $items = (array) $response->json('data.items');
        $row = collect($items)->firstWhere('id', (int) $position->id);

        $this->assertIsArray($row);
        $this->assertSame(1, $row['occupancy']);
        $this->assertArrayNotHasKey('staff', $row);
        $this->assertArrayNotHasKey('assignments', $row);
        $this->assertDatabaseHas('assignments', ['id' => (int) $assignment->id]);
    }
}
