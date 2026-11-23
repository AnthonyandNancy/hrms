<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Shared\Money\Percentage;
use InvalidArgumentException;

/**
 * Versioned housing-fund parameters with a base clamp independent from social
 * insurance. No statutory rate or bound is hardcoded in this domain.
 */
final readonly class HousingFundRule
{
    public function __construct(
        public string $version,
        public int $minBaseCents,
        public int $maxBaseCents,
        public Percentage $employeeRate,
        public Percentage $employerRate,
    ) {
        if (trim($version) === '') {
            throw new InvalidArgumentException('Housing fund rule version is mandatory.');
        }

        if ($minBaseCents < 0 || $maxBaseCents < $minBaseCents) {
            throw new InvalidArgumentException('Housing fund base bounds are invalid.');
        }
    }
}
