<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Time\FrozenClock;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;

/**
 * Shared helper to install a deterministic clock in feature tests.
 */
trait UsesFrozenClock
{
    protected FrozenClock $clock;

    protected function freezeClock(string $time = '2026-11-23T09:00:00Z'): FrozenClock
    {
        $this->clock = new FrozenClock(CarbonImmutable::parse($time, 'UTC'));
        $this->app->instance(Clock::class, $this->clock);

        return $this->clock;
    }

    protected function advanceClock(int $seconds): void
    {
        $this->clock->advanceSeconds($seconds);
    }
}
