<?php

declare(strict_types=1);

namespace App\Shared\Time;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;

final class FrozenClock implements Clock
{
    private CarbonImmutable $now;

    public function __construct(string|DateTimeInterface|CarbonImmutable $now = '2026-11-23T00:00:00Z', string $timezone = 'UTC')
    {
        if ($now instanceof CarbonImmutable) {
            $this->now = $now->utc();
        } else {
            $this->now = $now instanceof DateTimeInterface
                ? CarbonImmutable::instance($now)->setTimezone('UTC')
                : CarbonImmutable::parse($now, new DateTimeZone($timezone))->utc();
        }
    }

    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    public function today(string $timezone): CarbonImmutable
    {
        return $this->now->setTimezone($timezone)->startOfDay();
    }

    public function set(CarbonImmutable $now): void
    {
        $this->now = $now->utc();
    }

    public function setNow(string|DateTimeInterface $now): void
    {
        $this->now = $now instanceof DateTimeInterface
            ? CarbonImmutable::instance($now)->setTimezone('UTC')
            : CarbonImmutable::parse($now, 'UTC')->utc();
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->addSeconds($seconds);
    }
}
