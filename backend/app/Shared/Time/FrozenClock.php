<?php

declare(strict_types=1);

namespace App\Shared\Time;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class FrozenClock implements Clock
{
    private CarbonImmutable $now;

    public function __construct(string|DateTimeInterface $now)
    {
        $this->now = $now instanceof DateTimeInterface
            ? CarbonImmutable::instance($now)->setTimezone('UTC')
            : CarbonImmutable::parse($now, 'UTC');
    }

    public function nowUtc(): CarbonImmutable
    {
        return $this->now;
    }

    public function today(string $timezone): CarbonImmutable
    {
        return $this->now->setTimezone($timezone)->startOfDay();
    }

    public function setNow(string|DateTimeInterface $now): void
    {
        $this->now = $now instanceof DateTimeInterface
            ? CarbonImmutable::instance($now)->setTimezone('UTC')
            : CarbonImmutable::parse($now, 'UTC');
    }
}
