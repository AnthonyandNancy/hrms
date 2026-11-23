<?php

declare(strict_types=1);

namespace App\Shared\Time;

use Carbon\CarbonImmutable;

/**
 * Domain code must obtain "now" through this clock. Tests inject FrozenClock;
 * production binds SystemClock.
 */
interface Clock
{
    public function now(): CarbonImmutable;
}
