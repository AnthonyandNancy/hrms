<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * Tenant-scoped assignment read model. Organization and Lifecycle consume this
 * contract instead of importing People models or touching People tables.
 */
final readonly class AssignmentOccupant
{
    public function __construct(
        public int $assignmentId,
        public int $employmentId,
        public int $legalEntityId,
        public string $employeeNo,
        public string $personName,
        public int $orgUnitId,
        public ?int $positionId,
        public string $assignmentType,
        public bool $isPrimary,
        public string $validFrom,
        public string $validTo,
    ) {}
}
