<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

/**
 * The only Time→Payroll contract. Payroll never reads Time tables directly.
 */
interface PayrollInputDirectory
{
    public function attendanceForPeriod(int $employmentId, string $period): ?PayrollAttendanceExport;

    /**
     * Read the immutable monthly Time payroll facts for a closed period.
     *
     * This is the only Time input Payroll may consume. It returns null when
     * the period is not closed for the company, and never falls back to live
     * attendance rows.
     */
    public function closedFactForPeriod(int $employmentId, string $period): ?PayrollTimeFactExport;

    /**
     * Whether Time has any attendance or leave evidence for this employment
     * and period. Payroll uses this to distinguish "no Time module data yet"
     * (full-month salary) from an actual absence.
     */
    public function hasRecordedAttendance(int $employmentId, string $period): bool;
}
