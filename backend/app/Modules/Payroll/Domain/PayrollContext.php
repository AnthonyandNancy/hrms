<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use DateTimeZone;
use InvalidArgumentException;

final readonly class PayrollContext
{
    public function __construct(
        public string $currency,
        public string $timezone,
        public RoundingConfig $rounding,
        public ProrationDivisor $prorationDivisor = ProrationDivisor::ANNUAL_DAYS_365,
        public bool $allowNegativeNet = false,
    ) {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('Payroll currency must be a three-letter ISO code.');
        }

        try {
            new DateTimeZone($timezone);
        } catch (\Exception) {
            throw new InvalidArgumentException('Payroll timezone must be a valid IANA timezone.');
        }
    }
}
