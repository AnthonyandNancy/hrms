<?php

declare(strict_types=1);

namespace App\Shared\Time;

use Carbon\CarbonImmutable;

/**
 * Injectable time source. Domain and security code must never call now()
 * directly; tests use FrozenClock to make as-of and expiry behaviour stable.
 *
 * The UTC instant and the local business day are deliberately separate:
 * timestamps are stored in UTC, while "today" is resolved in the legal
 * entity's IANA timezone.
 */
interface Clock
{
    public function now(): CarbonImmutable;

    public function today(string $timezone): CarbonImmutable;
}
