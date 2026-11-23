<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;

final class FrozenClock implements Clock
{
    public function __construct(private CarbonImmutable $now) {}

    public function now(): CarbonImmutable
    {
        return $this->now->utc();
    }

    public function setNow(CarbonImmutable $now): void
    {
        $this->now = $now;
    }

    public function advanceTo(CarbonImmutable $now): void
    {
        $this->now = $now;
    }
}
