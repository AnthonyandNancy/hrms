<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Models\PunchRecord;
use App\Modules\Time\Services\AttendanceCorrectionService;
use App\Modules\Time\Services\PunchService;
use Tests\Support\Time\TimeTestCase;

class AttendanceCorrectionTest extends TimeTestCase
{
    public function test_correction_creates_case_and_does_not_change_raw_punch(): void
    {
        $this->calendar();
        $shift = $this->shift();
        $this->assignShift(1, $shift);
        $raw = app(PunchService::class)->record(1, '2026-11-23T01:10:00Z', 'DEVICE', 'late-in', 'D1', '+08:00');
        app(PunchService::class)->record(1, '2026-11-23T10:30:00Z', 'DEVICE', 'shift-out', 'D1', '+08:00');
        $before = app(\App\Modules\Time\Services\AttendanceCalculator::class)->recalculate(1, '2026-11-23');
        $this->assertGreaterThan(0, $before->late_minutes);
        $rawSnapshot = $raw->toArray();

        $correction = app(AttendanceCorrectionService::class)->request(
            employmentId: 1,
            businessDate: '2026-11-23',
            requestedBy: 10,
            reason: 'Device clock was wrong',
            correctedPunches: ['2026-11-23T01:00:00Z', '2026-11-23T10:30:00Z'],
        );

        $this->assertGreaterThan(0, $correction->case_id);
        $this->assertSame('SUBMITTED', $this->workflow->case($correction->case_id)?->status);
        $this->assertSame($rawSnapshot['punched_at_utc'], $raw->fresh()?->toArray()['punched_at_utc']);

        $this->workflow->approve($correction->case_id);
        $result = app(AttendanceCorrectionService::class)->applyEffect($correction->case_id);

        $this->assertTrue($result['applied']);
        $recalculated = \App\Modules\Time\Models\AttendanceDay::query()
            ->where('employment_id', 1)
            ->where('business_date', '2026-11-23')
            ->firstOrFail();
        $this->assertSame(0, $recalculated->late_minutes);
        $this->assertSame(0, $recalculated->absence_minutes);
        $this->assertSame(2, PunchRecord::query()->count());
    }

    public function test_recalculation_is_idempotent_and_reuses_source_hash(): void
    {
        $this->calendar();
        $shift = $this->shift();
        $this->assignShift(1, $shift);
        app(PunchService::class)->record(1, '2026-11-23T01:00:00Z', 'DEVICE', 'in', 'D1', '+08:00');
        app(PunchService::class)->record(1, '2026-11-23T10:00:00Z', 'DEVICE', 'out', 'D1', '+08:00');

        $calculator = app(\App\Modules\Time\Services\AttendanceCalculator::class);
        $first = $calculator->recalculate(1, '2026-11-23');
        $second = $calculator->recalculate(1, '2026-11-23');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->source_hash, $second->source_hash);
        $this->assertSame(1, \App\Modules\Time\Models\AttendanceDay::query()->count());
    }

    public function test_corrected_punches_do_not_replace_device_punches(): void
    {
        $this->calendar();
        $this->assignShift(1, $this->shift());
        app(PunchService::class)->record(1, '2026-11-23T01:10:00Z', 'DEVICE', 'raw', 'D1', '+08:00');

        $correction = app(AttendanceCorrectionService::class)->request(
            1,
            '2026-11-23',
            10,
            'Punch was misread',
            ['2026-11-23T01:00:00Z', '2026-11-23T10:00:00Z'],
        );

        $this->assertDatabaseCount('punch_records', 1);
        $this->assertSame(hash('sha256', 'raw'), PunchRecord::query()->firstOrFail()->raw_payload_hash);
        $this->assertSame(
            ['2026-11-23T01:00:00Z', '2026-11-23T10:00:00Z'],
            $correction->corrected_punches_json,
        );
    }
}
