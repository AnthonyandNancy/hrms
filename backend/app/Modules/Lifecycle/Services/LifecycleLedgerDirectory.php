<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Services;

use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Modules\People\Contracts\WorkforceReportingDirectory;
use App\Modules\People\Contracts\WorkforceReportingRow;

/**
 * Narrow read adapter for the lifecycle ledger. Employee numbers and display
 * names come from the People-owned WorkforceReportingDirectory, which is the
 * reporting-safe projection intended for company-wide ledgers. No People model
 * or table is imported here.
 *
 * The directory is as-of one business date. When an employment is not in that
 * slice (for example a future onboarding or a terminated employee), the row
 * keeps the Lifecycle-owned employee id and returns blank labels rather than
 * inventing a name. Live employment facts still come from
 * EmploymentLifecycleDirectory, which is keyed by id and not date restricted.
 */
final class LifecycleLedgerDirectory
{
    /** @var array<string, array<int, WorkforceReportingRow>> */
    private array $cache = [];

    public function __construct(
        private readonly EmploymentLifecycleDirectory $employments,
        private readonly WorkforceReportingDirectory $workforce,
    ) {}

    /**
     * @return array{employee_no:string,employee_name:string,status:string,valid_from:string|null}
     */
    public function employee(int $employmentId, string $on): array
    {
        $employment = $this->employments->employment($employmentId);

        if ($employment === null) {
            return [
                'employee_no' => '',
                'employee_name' => '',
                'status' => '',
                'valid_from' => null,
            ];
        }

        $row = $this->rows($on)[$employmentId] ?? null;

        return [
            'employee_no' => $row !== null ? $row->employeeNo : $employment->employeeNo,
            'employee_name' => $row !== null ? $row->displayName : '',
            'status' => $employment->status,
            'valid_from' => $employment->validFrom,
        ];
    }

    public function employeeExists(int $employmentId): bool
    {
        return $this->employments->employment($employmentId) !== null;
    }

    /**
     * @return array<int, WorkforceReportingRow>
     */
    private function rows(string $on): array
    {
        if (isset($this->cache[$on])) {
            return $this->cache[$on];
        }

        $indexed = [];

        foreach ($this->workforce->rows($on) as $row) {
            $indexed[$row->employmentId] = $row;
        }

        return $this->cache[$on] = $indexed;
    }
}
