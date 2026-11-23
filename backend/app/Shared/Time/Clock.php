<?php

declare(strict_types=1);

namespace App\Shared\Time;

use Carbon\CarbonImmutable;

/**
 * Injectable time source. Domain code must never call now() directly so that
 * effective-dating, DST and as-of behaviour can be tested deterministically.
 */
interface Clock
{
    public function nowUtc(): CarbonImmutable;

    public function today(string $timezone): CarbonImmutable;
}
