<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * Reporting-safe employee row. It contains display labels and dates only;
 * identity numbers, bank details and compensation are deliberately absent.
 */
final readonly class WorkforceReportingRow
{
    public function __construct(
        public int $employmentId,
        public int $personId,
        public int $legalEntityId,
        public ?int $orgUnitId,
        public string $employeeNo,
        public ?string $displayName,
        public string $status,
        public ?string $probationEndDate,
        public ?string $contractEndDate,
        public ?string $orgName,
        public ?string $legalEntityName,
    ) {}
}
