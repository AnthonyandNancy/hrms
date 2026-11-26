<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Shared\Money\Percentage;
use InvalidArgumentException;

/**
 * Versioned social-insurance parameters for the company and insurance
 * locality. Base bounds and both contribution rates are caller supplied; no
 * statutory rate or bound is hardcoded in this domain.
 */
final readonly class SocialInsuranceRule
{
    public function __construct(
        public string $version,
        public int $minBaseCents,
        public int $maxBaseCents,
        public Percentage $employeeRate,
        public Percentage $employerRate,
    ) {
        if (trim($version) === '') {
            throw new InvalidArgumentException('Social insurance rule version is mandatory.');
        }

        if ($minBaseCents < 0 || $maxBaseCents < $minBaseCents) {
            throw new InvalidArgumentException('Social insurance base bounds are invalid.');
        }
    }
}
