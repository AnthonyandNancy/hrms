<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Shared\Money\Decimal;
use App\Shared\Money\RoundingMode;
use InvalidArgumentException;

/**
 * A versioned progressive tax table. Brackets must start at zero, be ordered,
 * have no gaps or overlaps, and end with an open top bracket.
 */
final readonly class TaxTable
{
    /**
     * @param  list<TaxBracket>  $brackets
     */
    public function __construct(
        public string $version,
        public int $thresholdCents,
        public array $brackets,
    ) {
        if (trim($version) === '') {
            throw new InvalidArgumentException('Tax table version is mandatory.');
        }

        if ($thresholdCents < 0) {
            throw new InvalidArgumentException('Tax threshold cannot be negative.');
        }

        if ($brackets === []) {
            throw new InvalidArgumentException('Tax table must contain at least one bracket.');
        }

        $count = count($brackets);
        for ($index = 0; $index < $count; $index++) {
            $bracket = $brackets[$index];

            if ($index === 0 && $bracket->lowerCents !== 0) {
                throw new InvalidArgumentException('First tax bracket must start at zero.');
            }

            if ($index < $count - 1 && $bracket->upperCents === null) {
                throw new InvalidArgumentException('Only the final tax bracket may be open ended.');
            }

            if ($index > 0) {
                $previous = $brackets[$index - 1];
                if ($previous->upperCents === null || $bracket->lowerCents !== $previous->upperCents) {
                    throw new InvalidArgumentException('Tax brackets must be contiguous and non-overlapping.');
                }
            }
        }

        if ($brackets[$count - 1]->upperCents !== null) {
            throw new InvalidArgumentException('Final tax bracket must be open ended.');
        }
    }

    /**
     * Calculate cumulative tax for a taxable amount. The amount is floored at
     * zero by the calculator; a negative value here is a programming error.
     */
    public function taxOn(int $taxableCents, RoundingMode $mode = RoundingMode::HALF_UP): int
    {
        if ($taxableCents < 0) {
            throw new InvalidArgumentException('Taxable amount cannot be negative.');
        }

        if ($taxableCents === 0) {
            return 0;
        }

        foreach ($this->brackets as $bracket) {
            if (! $bracket->contains($taxableCents)) {
                continue;
            }

            $raw = Decimal::fromScaledInt($taxableCents, 2)
                ->multiply($bracket->rate->toRatio())
                ->subtract(Decimal::fromScaledInt($bracket->quickDeductionCents, 2));

            return max(0, $raw->toScaledInt(2, $mode));
        }

        throw new InvalidArgumentException('Tax table does not cover the taxable amount.');
    }
}
