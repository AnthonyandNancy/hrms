<?php

declare(strict_types=1);

namespace App\Shared\Time;

use Carbon\CarbonImmutable;
use DateTimeZone;

final class FrozenClock implements Clock
{
    private CarbonImmutable $now;

    public function __construct(string $instant = '2026-11-23T00:00:00Z', string $timezone = 'UTC')
    {
        $this->now = CarbonImmutable::parse($instant, new DateTimeZone($timezone))->utc();
    }

    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->addSeconds($seconds);
    }

    public function set(string $instant, string $timezone = 'UTC'): void
    {
        $this->now = CarbonImmutable::parse($instant, new DateTimeZone($timezone))->utc();
    }
}
