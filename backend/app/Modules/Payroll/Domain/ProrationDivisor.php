<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * Versioned salary-proration divisor. The enum value is persisted in payroll
 * snapshots so a closed run can be recomputed with the same convention.
 */
enum ProrationDivisor: string
{
    /** Daily rate = monthly salary * 12 / 365. */
    case ANNUAL_DAYS_365 = 'ANNUAL_DAYS_365';

    /** Daily rate = monthly salary / 21.75. */
    case FIXED_21_75 = 'FIXED_21_75';
}
