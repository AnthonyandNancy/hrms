<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Time\Contracts\PayrollPeriodLockReader;

/**
 * Payroll-owned implementation of Time's read-only freeze port.
 *
 * Time never reads payroll tables. A period is frozen for an employment when
 * that employment participates in a published/paid/closed run for the period.
 */
final class PayrollPeriodLockReaderService implements PayrollPeriodLockReader
{
    public function isFrozen(int $employmentId, string $period): bool
    {
        return PayrollRun::query()
            ->where('period', $period)
            ->whereIn('status', [
                PayrollRunStatus::PUBLISHED->value,
                PayrollRunStatus::PAID->value,
                PayrollRunStatus::CLOSED->value,
            ])
            ->whereHas('employees', static fn ($query) => $query->where('employment_id', $employmentId))
            ->exists();
    }
}
