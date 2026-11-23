<?php

declare(strict_types=1);

namespace App\Shared\Time;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;

final class FrozenClock implements Clock
{
    private CarbonImmutable $now;

    public function __construct(string|DateTimeInterface $now = '2026-11-23T00:00:00Z', string $timezone = 'UTC')
    {
        $this->set($now, $timezone);
    }

    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    public function today(string $timezone): CarbonImmutable
    {
        return $this->now->setTimezone($timezone)->startOfDay();
    }

    public function setNow(string|DateTimeInterface $now): void
    {
        $this->set($now);
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->addSeconds($seconds);
    }

    public function set(string|DateTimeInterface $now, string $timezone = 'UTC'): void
    {
        $this->now = $now instanceof DateTimeInterface
            ? CarbonImmutable::instance($now)->setTimezone('UTC')
            : CarbonImmutable::parse($now, new DateTimeZone($timezone))->utc();
    }
}
