<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * Point-in-time employment projection consumed by IAM, Time and Payroll. It
 * intentionally carries identifiers rather than cross-module model objects.
 */
final readonly class EmploymentSnapshot
{
    public function __construct(
        public int $employmentId,
        public int $personId,
        public int $legalEntityId,
        public ?int $orgUnitId,
        public ?int $positionId,
        public ?int $managerEmploymentId,
        public string $status,
        public string $employeeNo,
        public ?int $assignmentId = null,
        public ?int $managerAssignmentId = null,
    ) {}
}
