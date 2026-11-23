<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

final readonly class EmploymentAssignmentSnapshot
{
    public function __construct(
        public int $id,
        public int $employmentId,
        public int $orgUnitId,
        public ?int $positionId,
        public ?int $managerAssignmentId,
        public string $assignmentType,
        public bool $isPrimary,
        public string $validFrom,
        public string $validTo,
    ) {}
}
