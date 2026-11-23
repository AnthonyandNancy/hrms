<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

final readonly class EmploymentSnapshot
{
    public function __construct(
        public int $tenantId,
        public int $employmentId,
        public int $legalEntityId,
        public int $personId,
        public string $employeeNo,
        public string $validFrom,
        public string $validTo,
        public string $seniorityStartDate,
        public ?int $managerEmploymentId = null,
        public ?int $orgUnitId = null,
    ) {}
}
