<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

/**
 * Read-only Payroll period lock port. Time never reads Payroll tables.
 *
 * Implementations MUST fail closed: an unbound adapter, an exception, or an
 * unknown state is treated as frozen by PayrollFreezeGuard because revoking
 * approved leave changes an input that may already be sealed by payroll.
 */
interface PayrollPeriodLockReader
{
    /**
     * Whether the employment's payroll period (YYYY-MM) is published or later.
     */
    public function isFrozen(int $employmentId, string $period): bool;
}
