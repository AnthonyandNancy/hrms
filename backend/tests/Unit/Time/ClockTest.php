<?php

declare(strict_types=1);

namespace Tests\Unit\Time;

use App\Shared\Time\FrozenClock;
use App\Shared\Time\SystemClock;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    public function test_frozen_clock_returns_utc_and_can_advance(): void
    {
        $clock = new FrozenClock('2026-11-23 08:00:00', 'Asia/Shanghai');

        $this->assertSame('2026-11-23T00:00:00+00:00', $clock->now()->toIso8601String());

        $clock->advanceSeconds(90);

        $this->assertSame('2026-11-23T00:01:30+00:00', $clock->now()->toIso8601String());
    }

    public function test_system_clock_returns_utc(): void
    {
        $now = (new SystemClock)->now();

        $this->assertSame('UTC', $now->timezoneName);
    }
}
