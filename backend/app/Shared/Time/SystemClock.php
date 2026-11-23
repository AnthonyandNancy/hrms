<?php

declare(strict_types=1);

namespace App\Shared\Time;

use Carbon\CarbonImmutable;

final class SystemClock implements Clock
{
    public function nowUtc(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public function today(string $timezone): CarbonImmutable
    {
        return CarbonImmutable::now($timezone)->startOfDay();
    }
}
