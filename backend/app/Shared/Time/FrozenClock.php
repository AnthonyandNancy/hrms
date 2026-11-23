<?php

declare(strict_types=1);

namespace App\Shared\Time;

use Carbon\CarbonImmutable;

/**
 * Mutable clock for deterministic tests and long-running simulation jobs.
 */
final class FrozenClock implements Clock
{
    public function __construct(private CarbonImmutable $now) {}

    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    public function set(CarbonImmutable $now): void
    {
        $this->now = $now;
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->addSeconds($seconds);
    }
}
