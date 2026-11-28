<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Models\AttendanceDay;
use App\Modules\Time\Models\AttendancePeriodClosure;
use App\Modules\Time\Models\ShiftSchedule;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

final class AttendanceClosingTest extends TimeHttpTestCase
{
    private function seedFactDay(string $date = '2026-11-02'): void
    {
        $shift = $this->shift(code: 'CLOSE-FIX');
        ShiftSchedule::query()->create([
            'employment_id' => 1,
            'business_date' => $date,
            'shift_template_id' => (int) $shift->id,
            'created_by' => 10,
            'created_at' => $this->clock->now(),
        ]);

        AttendanceDay::query()->create([
            'employment_id' => 1,
            'business_date' => $date,
            'shift_template_id' => (int) $shift->id,
            'scheduled_minutes' => 480,
            'worked_minutes' => 480,
            'overtime_minutes' => 0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'absence_minutes' => 0,
            'status' => 'NORMAL',
            'rule_version' => 'TIME-RULES-2026.1',
            'source_hash' => hash('sha256', 'closing-fixture-'.$date),
            'calculated_at' => $this->clock->now(),
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);
    }

    public function test_month_close_locks_attendance_and_blocks_recalculation(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, capabilities: [
            'time.attendance.read',
            'time.attendance.manage',
            'time.calendar.read',
            'time.shift.read',
        ]));
        $this->calendar();
        $this->seedFactDay('2026-11-02');

        $this->postJson('/v1/time/attendance/closings', [
            'month' => '2026-11',
        ], ['Idempotency-Key' => 'close-2026-11'])->assertCreated();

        $this->assertDatabaseHas('attendance_period_closures', [
            'month' => '2026-11',
        ]);

        $this->postJson('/v1/time/attendance/recalculate', [
            'month' => '2026-11',
            'employment_id' => 1,
        ], ['Idempotency-Key' => 'recalc-closed'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'PERIOD_CLOSED');
    }

    public function test_closing_the_same_month_is_idempotent(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, capabilities: [
            'time.attendance.read',
            'time.attendance.manage',
            'time.calendar.read',
            'time.shift.read',
        ]));
        $this->calendar();
        $this->seedFactDay('2026-10-02');

        $first = $this->postJson('/v1/time/attendance/closings', [
            'month' => '2026-10',
        ], ['Idempotency-Key' => 'close-2026-10-a'])->assertCreated();

        $second = $this->postJson('/v1/time/attendance/closings', [
            'month' => '2026-10',
        ], ['Idempotency-Key' => 'close-2026-10-b'])->assertCreated();

        $this->assertSame($first->json('data.closure_id'), $second->json('data.closure_id'));
        $this->assertDatabaseCount('attendance_period_closures', 1);
        $this->assertTrue(AttendancePeriodClosure::query()->exists());
    }

    public function test_reopen_requires_a_privileged_capability_and_unlocks_recalculation(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, capabilities: [
            'time.attendance.read',
            'time.attendance.manage',
            'time.calendar.read',
            'time.shift.read',
        ]));
        $this->calendar();
        $this->seedFactDay('2026-09-02');

        $closure = $this->postJson('/v1/time/attendance/closings', [
            'month' => '2026-09',
        ], ['Idempotency-Key' => 'close-2026-09'])->assertCreated();

        $id = (int) $closure->json('data.closure_id');

        $this->postJson("/v1/time/attendance/closings/{$id}/reopen", [
            'reason' => 'Synthetic correction after close.',
        ], ['Idempotency-Key' => 'reopen-2026-09'])
            ->assertForbidden();

        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, capabilities: [
            'time.attendance.read',
            'time.attendance.manage',
            'time.calendar.read',
            'time.shift.read',
            'time.attendance.reopen',
        ]));

        $this->postJson("/v1/time/attendance/closings/{$id}/reopen", [
            'reason' => 'Synthetic correction after close.',
        ], ['Idempotency-Key' => 'reopen-2026-09-ok'])
            ->assertOk()
            ->assertJsonPath('data.status', 'REOPENED');
    }
}
