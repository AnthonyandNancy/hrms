<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use InvalidArgumentException;

/**
 * Immutable opening position for cumulative withholding. Amounts are the
 * cumulative values immediately before this calculation.
 */
final readonly class YtdTaxSnapshot
{
    public function __construct(
        public int $taxYear,
        public int $cumulativeTaxableCents = 0,
        public int $taxWithheldCents = 0,
    ) {
        if ($taxYear < 1900 || $taxYear > 9999) {
            throw new InvalidArgumentException('YTD tax year must be between 1900 and 9999.');
        }

        if ($cumulativeTaxableCents < 0 || $taxWithheldCents < 0) {
            throw new InvalidArgumentException('YTD cumulative taxable and withheld amounts cannot be negative.');
        }
    }
}
