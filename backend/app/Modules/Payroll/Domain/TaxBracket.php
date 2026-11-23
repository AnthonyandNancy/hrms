<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Shared\Money\Percentage;
use InvalidArgumentException;

/**
 * Statutory progressive bracket bounds are inclusive at both ends for display.
 * Tax is calculated as taxable * rate - quick deduction, rounded once at the
 * configured statutory step.
 */
final readonly class TaxBracket
{
    public function __construct(
        public int $lowerCents,
        public ?int $upperCents,
        public Percentage $rate,
        public int $quickDeductionCents,
    ) {
        if ($lowerCents < 0) {
            throw new InvalidArgumentException('Tax bracket lower bound cannot be negative.');
        }

        if ($upperCents !== null && $upperCents <= $lowerCents) {
            throw new InvalidArgumentException('Tax bracket upper bound must exceed its lower bound.');
        }

        if ($quickDeductionCents < 0) {
            throw new InvalidArgumentException('Tax bracket quick deduction cannot be negative.');
        }
    }

    public function contains(int $taxableCents): bool
    {
        return $taxableCents >= $this->lowerCents
            && ($this->upperCents === null || $taxableCents <= $this->upperCents);
    }
}
