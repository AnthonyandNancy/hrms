<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

interface AssignmentDirectory
{
    /**
     * @return list<AssignmentOccupant>
     */
    public function positionIncumbents(int $tenantId, int $positionId, string $on): array;

    /**
     * @return list<AssignmentOccupant>
     */
    public function secondments(int $tenantId, ?int $employmentId = null): array;

    public function occupant(int $tenantId, int $assignmentId): ?AssignmentOccupant;
}
