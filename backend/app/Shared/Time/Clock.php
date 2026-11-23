<?php

declare(strict_types=1);

namespace App\Shared\Time;

use Carbon\CarbonImmutable;

/**
 * Injectable wall clock. Domain and security code must never call now()
 * directly so expiry and effective-date behaviour is testable.
 */
interface Clock
{
    public function now(): CarbonImmutable;
}
