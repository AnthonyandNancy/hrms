<?php

declare(strict_types=1);

namespace App\Shared\Time;

use Carbon\CarbonImmutable;

/**
 * Injectable time source. Domain and application code must never call now()
 * directly; tests use FrozenClock to make as-of and recording behaviour stable.
 */
interface Clock
{
    public function now(): CarbonImmutable;
}
