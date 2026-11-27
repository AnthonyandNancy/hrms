<?php

declare(strict_types=1);

namespace Tests\Unit\Time;

use App\Modules\Time\Services\BusinessCalendar;
use App\Modules\Time\Services\ShiftWindow;
use App\Shared\Time\FrozenClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_workweek_holiday_and_makeup_override_weekday_rules(): void
    {
        $clock = new FrozenClock('2026-11-23T09:00:00Z');
        $calendar = new BusinessCalendar($clock);

        $calendar->configure('Asia/Shanghai', [1, 2, 3, 4, 5], 480);
        $calendar->addHoliday('2026-11-23', 'Synthetic public holiday', 'PUBLIC', 'TEST');
        $calendar->addHoliday('2026-11-28', 'Synthetic make-up workday', 'MAKEUP', 'TEST');

        $this->assertTrue($calendar->isRestDay('2026-11-21'));
        $this->assertTrue($calendar->isRestDay('2026-11-23'));
        $this->assertFalse($calendar->isRestDay('2026-11-28'));
        $this->assertTrue($calendar->isRestDay('2026-11-29'));
        $this->assertSame(480, $calendar->standardDayMinutes());
    }

    public function test_public_holiday_and_company_holiday_are_non_working(): void
    {
        $calendar = new BusinessCalendar(new FrozenClock('2026-11-23T09:00:00Z'));
        $calendar->configure('Asia/Shanghai', [1, 2, 3, 4, 5], 480);
        $calendar->addHoliday('2026-11-24', 'Public', 'PUBLIC', 'TEST');
        $calendar->addHoliday('2026-11-25', 'Company', 'COMPANY', 'TEST');

        $this->assertTrue($calendar->isRestDay('2026-11-24'));
        $this->assertTrue($calendar->isRestDay('2026-11-25'));
    }

    public function test_makeup_workday_on_a_weekend_is_working(): void
    {
        $calendar = new BusinessCalendar(new FrozenClock('2026-11-23T09:00:00Z'));
        $calendar->configure('Asia/Shanghai', [1, 2, 3, 4, 5], 480);
        $calendar->addHoliday('2026-11-28', 'Make-up', 'MAKEUP', 'TEST');

        $this->assertFalse($calendar->isRestDay('2026-11-28'));
    }

    public function test_unknown_timezone_is_rejected(): void
    {
        $this->expectException(\App\Shared\Exceptions\ValidationException::class);

        (new BusinessCalendar(new FrozenClock('2026-11-23T09:00:00Z')))
            ->configure('Mars/Olympus', [1, 2, 3, 4, 5], 480);
    }

    public function test_shift_window_maps_overnight_morning_punch_to_start_business_date(): void
    {
        $window = new ShiftWindow;

        $this->assertSame(
            '2026-11-22',
            $window->businessDate('America/New_York', '22:00:00', '06:00:00', true, '2026-11-23T06:00:00Z'),
        );
        $this->assertSame(
            '2026-11-23',
            $window->businessDate('America/New_York', '22:00:00', '06:00:00', true, '2026-11-24T03:00:00Z'),
        );
    }

    public function test_day_shift_uses_company_local_date_not_utc_date(): void
    {
        $window = new ShiftWindow;

        // 2026-11-23 17:00 UTC is 2026-11-24 01:00 in Asia/Shanghai.
        $this->assertSame(
            '2026-11-24',
            $window->businessDate('Asia/Shanghai', '09:00:00', '18:00:00', false, '2026-11-23T17:00:00Z'),
        );
    }
}
