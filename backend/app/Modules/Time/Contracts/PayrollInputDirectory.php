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
     * Whether Time has any attendance or leave evidence for this employment
     * and period. Payroll uses this to distinguish "no Time module data yet"
     * (full-month salary) from an actual absence.
     */
    public function hasRecordedAttendance(int $employmentId, string $period): bool;
}
