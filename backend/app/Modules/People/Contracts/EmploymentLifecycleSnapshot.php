<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

final readonly class EmploymentLifecycleSnapshot
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public int $personId,
        public int $legalEntityId,
        public string $employeeNo,
        public string $employmentType,
        public string $status,
        public string $validFrom,
        public string $validTo,
        public string $hireDate,
        public ?string $terminationDate,
        public string $seniorityStartDate,
    ) {}
}
