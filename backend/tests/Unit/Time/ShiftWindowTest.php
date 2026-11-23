<?php

declare(strict_types=1);

namespace Tests\Unit\Time;

use App\Modules\Time\Services\ShiftWindow;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ShiftWindowTest extends TestCase
{
    public function test_cross_midnight_elapsed_minutes_are_real_utc_minutes(): void
    {
        $window = new ShiftWindow;

        $minutes = $window->elapsedMinutes(
            CarbonImmutable::parse('2026-03-07 22:00:00', 'America/New_York'),
            CarbonImmutable::parse('2026-03-08 06:00:00', 'America/New_York'),
        );

        // Spring forward: 22:00 EST -> 06:00 EDT is seven real hours.
        $this->assertSame(420, $minutes);
    }

    public function test_fall_back_elapsed_minutes_include_repeated_hour_once_as_real_time(): void
    {
        $window = new ShiftWindow;

        $minutes = $window->elapsedMinutes(
            CarbonImmutable::parse('2026-10-31 22:00:00', 'America/New_York'),
            CarbonImmutable::parse('2026-11-01 06:00:00', 'America/New_York'),
        );

        // Fall back: 22:00 EDT -> 06:00 EST is nine real hours.
        $this->assertSame(540, $minutes);
    }

    public function test_shift_boundaries_resolve_dst_wall_clock_correctly(): void
    {
        $window = new ShiftWindow;

        [$start, $end] = $window->boundaries(
            'America/New_York',
            '2026-03-07',
            '22:00:00',
            '06:00:00',
            true,
        );

        $this->assertSame('2026-03-07T22:00:00-05:00', $start->toIso8601String());
        $this->assertSame('2026-03-08T06:00:00-04:00', $end->toIso8601String());
        $this->assertSame(420, $window->elapsedMinutes($start, $end));
    }

    public function test_overnight_window_without_midnight_crossing_rejects_configuration(): void
    {
        $this->expectException(\App\Shared\Exceptions\ValidationException::class);

        (new ShiftWindow)->boundaries('Asia/Shanghai', '2026-11-23', '09:00:00', '18:00:00', true);
    }
}
