<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Contracts\AssignmentDirectory;
use App\Modules\People\Contracts\AssignmentOccupant;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\Person;

final class AssignmentDirectoryService implements AssignmentDirectory
{
    public function positionIncumbents(int $positionId, string $on): array
    {
        $assignments = Assignment::query()
            ->where('position_id', $positionId)
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        return $this->occupants($assignments->all());
    }

    public function secondments(?int $employmentId = null): array
    {
        $query = Assignment::query()
            ->where('assignment_type', 'SECONDMENT')
            ->orderByDesc('valid_from')
            ->orderByDesc('id');

        if ($employmentId !== null) {
            $query->where('employment_id', $employmentId);
        }

        return $this->occupants($query->get()->all());
    }

    public function occupant(int $assignmentId): ?AssignmentOccupant
    {
        $assignment = Assignment::query()
            ->whereKey($assignmentId)
            ->first();

        if ($assignment === null) {
            return null;
        }

        return $this->occupants([$assignment])[0] ?? null;
    }

    /**
     * @param  list<Assignment>  $assignments
     * @return list<AssignmentOccupant>
     */
    private function occupants(array $assignments): array
    {
        if ($assignments === []) {
            return [];
        }

        $employmentIds = array_values(array_unique(array_map(
            static fn (Assignment $assignment): int => (int) $assignment->employment_id,
            $assignments,
        )));

        $employments = Employment::query()
            ->whereIn('id', $employmentIds)
            ->get()
            ->keyBy('id');

        $personIds = $employments->pluck('person_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();

        $persons = Person::query()
            ->whereIn('id', $personIds)
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($assignments as $assignment) {
            $employment = $employments->get((int) $assignment->employment_id);

            if (! $employment instanceof Employment) {
                continue;
            }

            $person = $persons->get((int) $employment->person_id);

            $rows[] = new AssignmentOccupant(
                assignmentId: (int) $assignment->id,
                employmentId: (int) $employment->id,
                employeeNo: (string) $employment->employee_no,
                personName: $person instanceof Person ? (string) $person->legal_name : '',
                orgUnitId: (int) $assignment->org_unit_id,
                positionId: $assignment->position_id !== null ? (int) $assignment->position_id : null,
                assignmentType: (string) $assignment->assignment_type,
                isPrimary: (bool) $assignment->is_primary,
                validFrom: (string) $assignment->valid_from,
                validTo: (string) $assignment->valid_to,
            );
        }

        return $rows;
    }
}
