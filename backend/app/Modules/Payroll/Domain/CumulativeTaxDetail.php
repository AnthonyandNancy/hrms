<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use InvalidArgumentException;

/**
 * Audit detail for cumulative resident withholding. carryForwardCents is the
 * unrefunded negative cumulative difference and is zero or negative.
 */
final readonly class CumulativeTaxDetail
{
    public function __construct(
        public string $taxTableVersion,
        public int $taxYear,
        public int $ytdTaxableCents,
        public int $currentTaxableCents,
        public int $cumulativeTaxableCents,
        public int $thresholdCents,
        public int $specialDeductionsCents,
        public int $ytdTaxWithheldCents,
        public int $cumulativeTaxCents,
        public int $currentTaxCents,
        public int $carryForwardCents,
    ) {
        if ($currentTaxableCents < 0 || $cumulativeTaxableCents < 0) {
            throw new InvalidArgumentException('Cumulative tax detail taxable amounts cannot be negative.');
        }

        if ($thresholdCents < 0 || $specialDeductionsCents < 0) {
            throw new InvalidArgumentException('Cumulative tax detail threshold and special deductions cannot be negative.');
        }

        if ($ytdTaxWithheldCents < 0 || $cumulativeTaxCents < 0 || $currentTaxCents < 0) {
            throw new InvalidArgumentException('Cumulative tax detail withheld/tax amounts cannot be negative.');
        }

        if ($carryForwardCents > 0) {
            throw new InvalidArgumentException('Tax carry forward cannot be positive.');
        }
    }
}
