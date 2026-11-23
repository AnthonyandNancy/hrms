<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Models\AttendanceDay;
use App\Modules\Time\Models\PunchRecord;
use App\Modules\Time\Services\AttendanceCalculator;
use App\Modules\Time\Services\PunchService;
use App\Modules\Time\Services\ShiftService;
use Tests\Support\Time\TimeTestCase;

class OvernightShiftTest extends TimeTestCase
{
    public function test_morning_punch_after_midnight_maps_to_shift_start_business_date(): void
    {
        $this->calendar(timezone: 'America/New_York');
        $shift = $this->shift(
            code: 'NIGHT',
            start: '22:00:00',
            end: '06:00:00',
            crossesMidnight: true,
            breakMinutes: 0,
        );
        $this->assignShift(1, $shift);

        app(PunchService::class)->record($this->tenantId, 1, '2026-11-07T03:00:00Z', 'DEVICE', 'raw-1', 'DEVICE-1', '-05:00');
        app(PunchService::class)->record($this->tenantId, 1, '2026-11-07T11:00:00Z', 'DEVICE', 'raw-2', 'DEVICE-1', '-05:00');

        $day = app(AttendanceCalculator::class)->recalculate($this->tenantId, 1, '2026-11-06');

        $this->assertSame('2026-11-06', $day->business_date->format('Y-m-d'));
        $this->assertSame(480, $day->worked_minutes);
        $this->assertSame('NORMAL', $day->status);
        $this->assertDatabaseMissing('attendance_days', [
            'tenant_id' => $this->tenantId,
            'employment_id' => 1,
            'business_date' => '2026-11-07',
        ]);
    }

    public function test_overnight_shift_across_spring_forward_dst_counts_real_minutes_once(): void
    {
        $this->calendar(timezone: 'America/New_York');
        $shift = $this->shift(
            code: 'NIGHT',
            start: '22:00:00',
            end: '06:00:00',
            crossesMidnight: true,
            breakMinutes: 0,
        );
        $this->assignShift(1, $shift);

        app(PunchService::class)->record($this->tenantId, 1, '2026-03-08T03:00:00Z', 'DEVICE', 'dst-in', 'D1', '-05:00');
        app(PunchService::class)->record($this->tenantId, 1, '2026-03-08T10:00:00Z', 'DEVICE', 'dst-out', 'D1', '-04:00');

        $day = app(AttendanceCalculator::class)->recalculate($this->tenantId, 1, '2026-03-07');

        $this->assertSame(420, $day->worked_minutes);
        $this->assertSame(420, $day->scheduled_minutes);
        $this->assertSame('NORMAL', $day->status);
        $this->assertSame('2026-03-07', $day->business_date->format('Y-m-d'));
    }

    public function test_overnight_shift_across_fall_back_counts_repeated_hour_once(): void
    {
        $this->calendar(timezone: 'America/New_York');
        $shift = $this->shift(
            code: 'NIGHT',
            start: '22:00:00',
            end: '06:00:00',
            crossesMidnight: true,
            breakMinutes: 0,
        );
        $this->assignShift(1, $shift);

        app(PunchService::class)->record($this->tenantId, 1, '2026-11-01T02:00:00Z', 'DEVICE', 'fall-in', 'D1', '-04:00');
        app(PunchService::class)->record($this->tenantId, 1, '2026-11-01T11:00:00Z', 'DEVICE', 'fall-out', 'D1', '-05:00');

        $day = app(AttendanceCalculator::class)->recalculate($this->tenantId, 1, '2026-10-31');

        $this->assertSame(540, $day->worked_minutes);
        $this->assertSame(540, $day->scheduled_minutes);
    }

    public function test_asia_shanghai_has_no_dst_shift_minutes(): void
    {
        $this->calendar(timezone: 'Asia/Shanghai');
        $shift = $this->shift(
            code: 'NIGHT',
            start: '22:00:00',
            end: '06:00:00',
            crossesMidnight: true,
            breakMinutes: 0,
        );
        $this->assignShift(1, $shift);

        app(PunchService::class)->record($this->tenantId, 1, '2026-11-06T14:00:00Z', 'DEVICE', 'sh-in', 'D1', '+08:00');
        app(PunchService::class)->record($this->tenantId, 1, '2026-11-06T22:00:00Z', 'DEVICE', 'sh-out', 'D1', '+08:00');

        $day = app(AttendanceCalculator::class)->recalculate($this->tenantId, 1, '2026-11-06');

        $this->assertSame(480, $day->worked_minutes);
        $this->assertSame(480, $day->scheduled_minutes);
    }

}
