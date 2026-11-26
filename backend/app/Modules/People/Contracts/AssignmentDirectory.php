<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

interface AssignmentDirectory
{
    /**
     * @return list<AssignmentOccupant>
     */
    public function positionIncumbents(int $positionId, string $on): array;

    /**
     * @return list<AssignmentOccupant>
     */
    public function secondments(?int $employmentId = null): array;

    public function occupant(int $assignmentId): ?AssignmentOccupant;
}
