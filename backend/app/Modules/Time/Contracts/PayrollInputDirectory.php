<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

/**
 * The only Time→Payroll contract. Payroll never reads Time tables directly.
 */
interface PayrollInputDirectory
{
    public function attendanceForPeriod(int $tenantId, int $employmentId, string $period): ?PayrollAttendanceExport;
}
