<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Shared\Money\RoundingMode;
use InvalidArgumentException;

/**
 * Versioned rounding policy for the company payroll configuration. V1 money is integer
 * cents, so the only supported monetary scale is 2; an unsupported scale is
 * rejected instead of being silently coerced.
 */
final readonly class RoundingConfig
{
    public const MONEY_SCALE = 2;

    public function __construct(
        public RoundingMode $mode,
        public int $scale = self::MONEY_SCALE,
    ) {
        if ($scale !== self::MONEY_SCALE) {
            throw new InvalidArgumentException('V1 payroll money must be rounded at scale 2 (cents).');
        }
    }
}
