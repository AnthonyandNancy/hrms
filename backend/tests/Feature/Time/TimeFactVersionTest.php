<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Contracts\PayrollInputDirectory;
use App\Modules\Time\Contracts\PayrollPeriodLockReader;
use App\Modules\Time\Contracts\PayrollTimeFactExport;
use App\Modules\Time\Models\AttendanceDay;
use App\Modules\Time\Models\AttendancePeriodClosure;
use App\Modules\Time\Models\BusinessTripRecord;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeavePolicyVersion;
use App\Modules\Time\Models\LeaveRequestProjection;
use App\Modules\Time\Models\OvertimeRecord;
use App\Modules\Time\Models\OvertimeRequest;
use App\Modules\Time\Models\OvertimeSettlement;
use App\Modules\Time\Models\PunchRecord;
use App\Modules\Time\Models\ShiftSchedule;
use App\Modules\Time\Models\TimePayrollFactRow;
use App\Modules\Time\Models\TimePayrollFactVersion;
use App\Modules\Time\Models\TravelAttendanceLink;
use App\Modules\Time\Services\AttendanceClosingService;
use App\Modules\Time\Services\LeaveApprovalService;
use App\Modules\Time\Services\LeaveLedgerService;
use App\Modules\Time\Services\PayrollFreezeGuard;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

final class TimeFactVersionTest extends TimeHttpTestCase
{
    public function test_close_creates_an_immutable_fact_version_rows_hash_and_exception_count(): void
    {
        $this->calendar();
        $this->actingAsCloser();

        $this->attendance('2026-11-02', 480, 480, 'NORMAL');
        $this->attendance('2026-11-03', 480, 440, 'LATE');

        $response = $this->closeMonth('2026-11')
            ->assertCreated()
            ->assertJsonPath('data.month', '2026-11')
            ->assertJsonPath('data.status', 'CLOSED')
            ->assertJsonPath('data.fact_version', 1)
            ->assertJsonPath('data.exception_count', 1);

        $hash = (string) $response->json('data.source_hash');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);

        $version = TimePayrollFactVersion::query()->where('period', '2026-11')->firstOrFail();
        $this->assertSame(1, (int) $version->version);
        $this->assertSame(TimePayrollFactVersion::STATUS_CLOSED, (string) $version->status);
        $this->assertSame($hash, (string) $version->source_hash);
        $this->assertSame(1, (int) $version->exception_count);
        $this->assertDatabaseCount('time_payroll_fact_rows', 2);

        $closure = AttendancePeriodClosure::query()->where('month', '2026-11')->firstOrFail();
        $this->assertSame((int) $version->id, (int) $closure->current_fact_version_id);

        $row = TimePayrollFactRow::query()
            ->where('fact_version_id', (int) $version->id)
            ->where('business_date', '2026-11-03')
            ->firstOrFail();

        $this->expectDomainRejection(static fn () => $row->forceFill(['worked_minutes' => 1])->save());
        $this->expectDomainRejection(static fn () => $row->delete());
    }

    public function test_incomplete_or_missing_schedule_facts_block_close_with_chinese_location(): void
    {
        $this->calendar();
        $this->actingAsCloser();

        $this->attendance('2026-11-03', 0, 480, 'INCOMPLETE', withSchedule: false);

        $response = $this->closeMonth('2026-11')
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TIME_FACT_PRECHECK_FAILED');

        $blockers = (array) $response->json('data.blockers');
        $this->assertNotSame([], $blockers);
        $this->assertContains('SHIFT_SCHEDULE_MISSING', array_column($blockers, 'code'));
        $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', (string) $blockers[0]['message']);

        $this->assertArrayNotHasKey('fact_version_id', (array) $response->json('data'));
        $this->assertDatabaseCount('time_payroll_fact_versions', 0);
        $this->assertDatabaseCount('time_payroll_fact_rows', 0);
        $this->assertDatabaseCount('attendance_period_closures', 0);
    }

    public function test_closing_the_same_month_replays_the_same_version_without_duplicate_rows(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $this->attendance('2026-11-04', 480, 480, 'NORMAL');

        $first = $this->closeMonth('2026-11', 'fact-close-a')
            ->assertCreated()
            ->assertJsonPath('data.replayed', false);
        $second = $this->closeMonth('2026-11', 'fact-close-b')
            ->assertCreated()
            ->assertJsonPath('data.replayed', true);

        $this->assertSame($first->json('data.fact_version'), $second->json('data.fact_version'));
        $this->assertSame($first->json('data.source_hash'), $second->json('data.source_hash'));
        $this->assertDatabaseCount('time_payroll_fact_versions', 1);
        $this->assertDatabaseCount('time_payroll_fact_rows', 1);
    }

    public function test_reopen_keeps_v1_and_the_next_close_creates_v2(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $day = $this->attendance('2026-11-05', 480, 480, 'NORMAL');

        $first = $this->closeMonth('2026-11')->assertCreated();
        $closureId = (int) $first->json('data.closure_id');
        $v1Hash = (string) $first->json('data.source_hash');
        $v1Id = (int) TimePayrollFactVersion::query()->where('period', '2026-11')->where('version', 1)->value('id');
        $v1Rows = $this->factRows($v1Id);

        $this->postJson("/v1/time/attendance/closings/{$closureId}/reopen", [
            'reason' => 'Synthetic correction after monthly close.',
        ], ['Idempotency-Key' => 'fact-reopen-1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'REOPENED')
            ->assertJsonPath('data.fact_version', 1);

        $this->assertSame(
            TimePayrollFactVersion::STATUS_REOPENED,
            (string) TimePayrollFactVersion::query()->whereKey($v1Id)->value('status'),
        );

        $day->forceFill([
            'worked_minutes' => 440,
            'source_hash' => hash('sha256', 'corrected-2026-11-05'),
        ])->save();

        $second = $this->closeMonth('2026-11', 'fact-close-v2')
            ->assertCreated()
            ->assertJsonPath('data.fact_version', 2);

        $this->assertNotSame($v1Hash, (string) $second->json('data.source_hash'));
        $this->assertDatabaseCount('time_payroll_fact_versions', 2);
        $this->assertSame($v1Rows, $this->factRows($v1Id));

        $v2 = TimePayrollFactVersion::query()->where('period', '2026-11')->where('version', 2)->firstOrFail();
        $this->assertSame($v1Id, (int) $v2->reopened_from_version_id);
    }

    public function test_every_closed_period_mutation_is_rejected_through_the_shared_freeze_guard(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $shift = $this->shift();
        $this->attendance('2026-11-06', 480, 480, 'NORMAL');

        $this->closeMonth('2026-11')->assertCreated();

        $this->postJson('/v1/time/shift-schedules', [
            'employment_id' => 1,
            'business_date' => '2026-11-06',
            'shift_template_id' => (int) $shift->id,
        ], ['Idempotency-Key' => 'fact-closed-schedule'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'PERIOD_CLOSED');

        $this->assertDatabaseCount('shift_schedules', 1);
    }

    public function test_closed_period_blocks_punch_attendance_leave_overtime_and_travel_fact_mutations(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $this->attendance('2026-11-12', 480, 480, 'NORMAL');
        $this->closeMonth('2026-11')->assertCreated();

        $operations = [
            static fn () => app(\App\Modules\Time\Services\PunchService::class)
                ->record(1, '2026-11-12T01:00:00Z', 'IMPORT', 'closed-period-punch'),
            static fn () => app(\App\Modules\Time\Services\AttendanceCalculator::class)
                ->recalculate(1, '2026-11-12'),
            static fn () => app(LeaveLedgerService::class)
                ->grant(1, 1, 480, '2026-11-12', 'ACCRUAL', 'CLOSED-TEST', 'Synthetic closed grant'),
            static fn () => app(\App\Modules\Time\Services\OvertimeRequestService::class)
                ->apply(1, '2026-11-12', 60, 'Synthetic closed overtime', 10),
            static fn () => app(\App\Modules\Time\Services\TravelService::class)->openRequest(1, [
                'from_date' => '2026-11-12',
                'to_date' => '2026-11-12',
                'destination' => 'Synthetic closed city',
                'purpose' => 'Synthetic closed travel',
            ], 10),
        ];

        foreach ($operations as $operation) {
            $this->expectConflictReason('PERIOD_CLOSED', $operation);
        }
    }

    public function test_payroll_directory_reads_only_the_closed_fact_and_returns_stable_data(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $this->attendance('2026-11-09', 480, 420, 'LATE');

        $closed = $this->closeMonth('2026-11')->assertCreated();
        $directory = app(PayrollInputDirectory::class);

        $first = $directory->closedFactForPeriod(1, '2026-11');
        $second = $directory->closedFactForPeriod(1, '2026-11');

        $this->assertInstanceOf(PayrollTimeFactExport::class, $first);
        $this->assertInstanceOf(PayrollTimeFactExport::class, $second);
        $this->assertSame((string) $closed->json('data.source_hash'), $first->sourceHash);
        $this->assertSame(1, $first->factVersion);
        $this->assertSame(480, $first->scheduledMinutes);
        $this->assertSame(420, $first->workedMinutes);
        $this->assertSame($first->sourceHash, $second->sourceHash);
        $this->assertSame($first->facts, $second->facts);
        $this->assertDatabaseCount('time_payroll_fact_rows', 1);
    }

    public function test_leave_without_a_versioned_payroll_treatment_blocks_close_instead_of_guessing(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $type = $this->leaveType();
        $this->leavePolicy((int) $type->id, ['mode' => 'ANNUAL_FIXED', 'minutes_per_year' => 4800]);
        $this->approvedLeave($type->id, 480, '2026-11-10');

        $response = $this->closeMonth('2026-11')
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TIME_FACT_PRECHECK_FAILED');

        $this->assertContains(
            'LEAVE_PAYROLL_TREATMENT_UNRESOLVED',
            array_column((array) $response->json('data.blockers'), 'code'),
        );
        $this->assertDatabaseCount('time_payroll_fact_versions', 0);
    }

    public function test_leave_with_an_explicit_policy_treatment_is_split_into_paid_minutes(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $type = $this->leaveType();
        $this->leavePolicy((int) $type->id, [
            'mode' => 'ANNUAL_FIXED',
            'minutes_per_year' => 4800,
            'payroll_treatment' => 'PAID',
            'paid_ratio_scaled' => 1_000_000,
        ]);
        $this->approvedLeave($type->id, 480, '2026-11-11');

        $this->closeMonth('2026-11')->assertCreated();

        $row = TimePayrollFactRow::query()->where('business_date', '2026-11-11')->firstOrFail();
        $this->assertSame(480, (int) $row->paid_leave_minutes);
        $this->assertSame(0, (int) $row->partial_leave_minutes);
        $this->assertSame(0, (int) $row->unpaid_leave_minutes);
        $this->assertSame((int) $type->id, (int) $row->leave_type_id);
        $this->assertSame('APPROVED', (string) $row->approval_status);
    }

    public function test_leave_reversal_reduces_the_net_fact_instead_of_double_counting(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $type = $this->leaveType();
        $this->leavePolicy((int) $type->id, [
            'mode' => 'ANNUAL_FIXED',
            'minutes_per_year' => 4800,
            'payroll_treatment' => 'PAID',
            'paid_ratio_scaled' => 1_000_000,
        ]);
        $this->approvedLeave($type->id, 480, '2026-11-13');

        $projection = LeaveRequestProjection::query()->firstOrFail();
        LeaveLedgerEntry::query()->create([
            'employment_id' => 1,
            'leave_type_id' => $type->id,
            'account' => 'REVERSE',
            'quantity_minutes' => 240,
            'business_date' => '2026-11-13',
            'effective_on' => '2026-11-13',
            'source_type' => 'LEAVE_REVERSAL',
            'source_id' => 'REV-'.$projection->case_id,
            'case_id' => $projection->case_id,
            'reason' => 'Synthetic partial reversal',
            'entry_hash' => hash('sha256', 'leave-reverse|'.$projection->case_id),
            'recorded_at' => $this->clock->now(),
            'created_at' => $this->clock->now(),
        ]);

        $this->closeMonth('2026-11')->assertCreated();

        $row = TimePayrollFactRow::query()
            ->where('fact_version_id', (int) TimePayrollFactVersion::query()->where('version', 1)->value('id'))
            ->where('business_date', '2026-11-13')
            ->firstOrFail();

        $this->assertSame(240, (int) $row->paid_leave_minutes);
        $this->assertSame(0, (int) $row->unpaid_leave_minutes);
    }

    public function test_leave_crossing_months_uses_each_months_effective_days_and_reversal(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $type = $this->leaveType();
        $this->leavePolicy((int) $type->id, [
            'mode' => 'ANNUAL_FIXED',
            'minutes_per_year' => 4800,
            'payroll_treatment' => 'PAID',
            'paid_ratio_scaled' => 1_000_000,
        ]);
        $projection = $this->approvedLeaveRange($type->id, 1920, '2026-01-29', '2026-02-03');
        $this->leaveReversal($projection, 480, '2026-02-02');

        $this->closeMonth('2026-01', 'cross-month-jan')->assertCreated();
        $this->closeMonth('2026-02', 'cross-month-feb')->assertCreated();

        $jan = $this->factRowsForMonth('2026-01');
        $feb = $this->factRowsForMonth('2026-02');

        $this->assertCount(2, $jan);
        $this->assertCount(2, $feb);
        $this->assertSame([360, 360], array_column($jan, 'paid_leave_minutes'));
        $this->assertSame([360, 360], array_column($feb, 'paid_leave_minutes'));
        $this->assertSame(720, array_sum(array_column($jan, 'paid_leave_minutes')));
        $this->assertSame(720, array_sum(array_column($feb, 'paid_leave_minutes')));
    }

    public function test_scheduled_working_day_without_any_fact_blocks_close(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $shift = $this->shift();
        ShiftSchedule::query()->create([
            'employment_id' => 1,
            'business_date' => '2026-11-18',
            'shift_template_id' => (int) $shift->id,
            'created_by' => 10,
            'created_at' => $this->clock->now(),
        ]);

        $response = $this->closeMonth('2026-11')
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TIME_FACT_PRECHECK_FAILED');

        $this->assertContains(
            'SCHEDULE_WITHOUT_FACT',
            array_column((array) $response->json('data.blockers'), 'code'),
        );
        $this->assertDatabaseCount('time_payroll_fact_versions', 0);
    }

    public function test_rest_day_attendance_without_schedule_is_not_mistaken_for_missing_shift(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $this->attendance('2026-11-07', 0, 480, 'NORMAL', withSchedule: false);

        $this->closeMonth('2026-11')->assertCreated();

        $this->assertDatabaseHas('time_payroll_fact_rows', [
            'employment_id' => 1,
            'business_date' => '2026-11-07',
            'scheduled_minutes' => 0,
            'worked_minutes' => 480,
        ]);
    }

    public function test_non_terminal_leave_and_overtime_requests_block_close(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $type = $this->leaveType();
        $this->leavePolicy((int) $type->id, [
            'mode' => 'ANNUAL_FIXED',
            'minutes_per_year' => 4800,
            'payroll_treatment' => 'PAID',
            'paid_ratio_scaled' => 1_000_000,
        ]);
        $caseId = $this->workflow->createCase(10, 'LEAVE_REQUEST', 'employment', 1, ['title' => 'Pending leave'], '2026-11-09');
        LeaveRequestProjection::query()->create([
            'employment_id' => 1,
            'actor_user_id' => 10,
            'leave_type_id' => (int) $type->id,
            'leave_policy_version_id' => (int) LeavePolicyVersion::query()->where('leave_type_id', $type->id)->value('id'),
            'case_id' => $caseId,
            'from_date' => '2026-11-09',
            'to_date' => '2026-11-09',
            'minutes' => 480,
            'state' => 'IN_REVIEW',
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);
        OvertimeRequest::query()->create([
            'employment_id' => 1,
            'business_date' => '2026-11-10',
            'declared_minutes' => 60,
            'confirmed_minutes' => null,
            'status' => OvertimeRequest::STATUS_SUBMITTED,
            'reason' => 'Synthetic pending overtime',
            'recorded_at' => $this->clock->now(),
        ]);

        $response = $this->closeMonth('2026-11')
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TIME_FACT_PRECHECK_FAILED');

        $codes = array_column((array) $response->json('data.blockers'), 'code');
        $this->assertContains('LEAVE_APPROVAL_UNRESOLVED', $codes);
        $this->assertContains('OVERTIME_APPROVAL_UNRESOLVED', $codes);
    }

    public function test_travel_change_approval_checks_the_proposed_month_after_the_original_was_opened(): void
    {
        $this->calendar();
        $this->actingAsTravelManager();
        $trip = $this->createApprovedTrip();
        $this->travelLink((int) $trip->id, '2026-12-01', '2026-12-02', 1001);

        $change = $this->postJson("/v1/travel/requests/{$trip->id}/change", [
            'from_date' => '2027-01-05',
            'to_date' => '2027-01-06',
            'destination' => 'Synthetic Next Year City',
            'purpose' => 'Synthetic next-year change.',
            'reason' => 'Synthetic next-year schedule.',
        ], ['Idempotency-Key' => 'travel-cross-month-change'])->assertCreated();

        AttendancePeriodClosure::query()->create([
            'month' => '2027-01',
            'status' => AttendancePeriodClosure::STATUS_CLOSED,
            'closed_by' => 10,
            'closed_at' => $this->clock->now(),
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);

        $this->postJson("/v1/travel/requests/{$trip->id}/approve", [
            'decision' => 'APPROVE',
            'comment' => 'Synthetic cross-month approval.',
        ], ['Idempotency-Key' => 'travel-cross-month-approve'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'PERIOD_CLOSED');

        $this->assertDatabaseHas('business_trip_records', [
            'id' => (int) $trip->id,
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-02',
            'status' => 'APPROVED',
        ]);
        $this->assertDatabaseHas('travel_attendance_links', [
            'travel_id' => (int) $trip->id,
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-02',
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('travel_request_changes', [
            'case_id' => (int) $change->json('data.case_id'),
            'status' => 'PENDING',
        ]);
    }

    public function test_periods_for_range_does_not_skip_february_after_january_31(): void
    {
        $this->assertSame(
            ['2026-01', '2026-02', '2026-03'],
            PayrollFreezeGuard::periodsForRange('2026-01-31', '2026-03-31'),
        );
    }

    public function test_close_and_reopen_use_required_idempotency_and_replay_stably(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $this->attendance('2026-11-20', 480, 480, 'NORMAL');

        $this->postJson('/v1/time/attendance/closings', ['month' => '2026-11'])
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');

        $first = $this->closeMonth('2026-11', 'idem-close-same')->assertCreated();
        $replay = $this->closeMonth('2026-11', 'idem-close-same')
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json('data.closure_id'), $replay->json('data.closure_id'));
        $this->assertSame($first->json('data.source_hash'), $replay->json('data.source_hash'));

        $this->closeMonth('2026-10', 'idem-close-same')
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_PAYLOAD_MISMATCH');

        $closureId = (int) $first->json('data.closure_id');
        $this->postJson("/v1/time/attendance/closings/{$closureId}/reopen", [
            'reason' => 'Synthetic missing key reopen.',
        ])->assertStatus(422)->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->postJson("/v1/time/attendance/closings/{$closureId}/reopen", [
            'reason' => 'Synthetic idempotent reopen.',
        ], ['Idempotency-Key' => 'idem-reopen'])->assertOk();
    }

    public function test_real_schedule_route_recalculate_and_close_use_the_same_shift_source(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $shift = $this->shift();

        $this->postJson('/v1/time/shift-schedules', [
            'employment_id' => 1,
            'business_date' => '2026-11-19',
            'shift_template_id' => (int) $shift->id,
        ], ['Idempotency-Key' => 'real-schedule-route'])->assertCreated();

        $this->postJson('/v1/time/attendance/recalculate', [
            'month' => '2026-11',
            'employment_id' => 1,
        ], ['Idempotency-Key' => 'real-schedule-recalculate'])->assertAccepted();

        app(\App\Modules\Time\Services\AttendanceCalculator::class)
            ->recalculate(1, '2026-11-19');

        $this->assertDatabaseHas('attendance_days', [
            'employment_id' => 1,
            'business_date' => '2026-11-19',
            'scheduled_minutes' => 480,
            'status' => 'ABSENT',
        ]);

        $this->closeMonth('2026-11', 'real-schedule-close')->assertCreated();

        $this->assertDatabaseHas('time_payroll_fact_rows', [
            'employment_id' => 1,
            'business_date' => '2026-11-19',
            'scheduled_minutes' => 480,
        ]);
    }

    public function test_punch_import_is_all_or_nothing_when_a_later_item_is_invalid(): void
    {
        $this->calendar();

        try {
            app(\App\Modules\Time\Services\PunchService::class)->import(1, [
                ['punched_at_utc' => '2026-11-21T01:00:00Z', 'source' => 'IMPORT'],
                ['punched_at_utc' => '2026-11-21T10:00:00Z', 'source' => 'EMAIL'],
            ]);
            $this->fail('Expected the invalid second punch to reject the batch.');
        } catch (\App\Shared\Exceptions\ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, PunchRecord::query()->count());
    }

    public function test_each_reopen_points_the_next_version_to_its_direct_predecessor(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $this->attendance('2026-11-24', 480, 480, 'NORMAL');

        $closure = $this->closeMonth('2026-11', 'direct-v1')->assertCreated();
        $closureId = (int) $closure->json('data.closure_id');
        $v1 = TimePayrollFactVersion::query()->where('period', '2026-11')->where('version', 1)->firstOrFail();

        $this->postJson("/v1/time/attendance/closings/{$closureId}/reopen", [
            'reason' => 'Synthetic first reopen.',
        ], ['Idempotency-Key' => 'direct-reopen-1'])->assertOk();
        $this->closeMonth('2026-11', 'direct-v2')->assertCreated();
        $v2 = TimePayrollFactVersion::query()->where('period', '2026-11')->where('version', 2)->firstOrFail();

        $this->postJson("/v1/time/attendance/closings/{$closureId}/reopen", [
            'reason' => 'Synthetic second reopen.',
        ], ['Idempotency-Key' => 'direct-reopen-2'])->assertOk();
        $this->closeMonth('2026-11', 'direct-v3')->assertCreated();
        $v3 = TimePayrollFactVersion::query()->where('period', '2026-11')->where('version', 3)->firstOrFail();

        $this->assertSame((int) $v1->id, (int) $v2->reopened_from_version_id);
        $this->assertSame((int) $v2->id, (int) $v3->reopened_from_version_id);
    }

    public function test_overtime_pay_and_timeoff_are_captured_as_distinct_fact_columns(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $this->settledOvertime('2026-11-25', 60, 'PAY');
        $this->settledOvertime('2026-11-26', 90, 'TIMEOFF');

        $this->closeMonth('2026-11', 'overtime-facts')->assertCreated();

        $this->assertDatabaseHas('time_payroll_fact_rows', [
            'business_date' => '2026-11-25',
            'overtime_pay_minutes' => 60,
            'overtime_timeoff_minutes' => 0,
            'overtime_category' => 'PAY',
        ]);
        $this->assertDatabaseHas('time_payroll_fact_rows', [
            'business_date' => '2026-11-26',
            'overtime_pay_minutes' => 0,
            'overtime_timeoff_minutes' => 90,
            'overtime_category' => 'TIMEOFF',
        ]);
    }

    public function test_travel_link_is_captured_as_attendance_credit_fact(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $caseId = $this->workflow->createCase(10, 'TRAVEL_REQUEST', 'employment', 1, ['title' => 'Approved travel'], '2026-11-27');
        $this->workflow->approve($caseId);
        $trip = BusinessTripRecord::query()->create([
            'employment_id' => 1,
            'from_date' => '2026-11-27',
            'to_date' => '2026-11-27',
            'destination' => 'Synthetic Fact City',
            'purpose' => 'Synthetic fact travel.',
            'status' => 'APPROVED',
            'case_id' => $caseId,
            'created_at' => $this->clock->now(),
        ]);
        $this->travelLink((int) $trip->id, '2026-11-27', '2026-11-27', $caseId);

        $this->closeMonth('2026-11', 'travel-fact')->assertCreated();

        $this->assertDatabaseHas('time_payroll_fact_rows', [
            'business_date' => '2026-11-27',
            'travel_minutes' => 480,
            'travel_mode' => 'ATTENDANCE_CREDIT',
            'approval_status' => 'APPROVED',
        ]);
    }

    public function test_partial_and_unpaid_leave_are_captured_using_policy_treatment(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $partialType = $this->leaveType(['code' => 'PARTIAL_CARE']);
        $this->leavePolicy((int) $partialType->id, [
            'mode' => 'ANNUAL_FIXED',
            'minutes_per_year' => 4800,
            'payroll_treatment' => 'PARTIAL',
            'paid_ratio_scaled' => 500000,
        ]);
        $unpaidType = $this->leaveType(['code' => 'UNPAID_FAMILY']);
        $this->leavePolicy((int) $unpaidType->id, [
            'mode' => 'ANNUAL_FIXED',
            'minutes_per_year' => 0,
            'payroll_treatment' => 'UNPAID',
            'paid_ratio_scaled' => 0,
        ]);
        $this->approvedLeave((int) $partialType->id, 480, '2026-11-23');
        $this->approvedLeave((int) $unpaidType->id, 480, '2026-11-24');

        $this->closeMonth('2026-11', 'leave-treatment-facts')->assertCreated();

        $this->assertDatabaseHas('time_payroll_fact_rows', [
            'business_date' => '2026-11-23',
            'partial_leave_minutes' => 240,
            'unpaid_leave_minutes' => 240,
            'paid_leave_minutes' => 0,
        ]);
        $this->assertDatabaseHas('time_payroll_fact_rows', [
            'business_date' => '2026-11-24',
            'unpaid_leave_minutes' => 480,
            'partial_leave_minutes' => 0,
            'paid_leave_minutes' => 0,
        ]);
    }

    public function test_open_reopened_and_hash_missing_facts_do_not_yield_a_payroll_export(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $directory = app(PayrollInputDirectory::class);
        $this->assertNull($directory->closedFactForPeriod(1, '2026-11'));

        $empty = $this->closeMonth('2026-11', 'empty-directory-close')
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TIME_FACT_PRECHECK_FAILED');
        $this->assertContains(
            'NO_ELIGIBLE_TIME_FACTS',
            array_column((array) $empty->json('data.blockers'), 'code'),
        );
        $this->assertNull($directory->closedFactForPeriod(1, '2026-11'));

        $version = TimePayrollFactVersion::query()->create([
            'period' => '2026-10',
            'version' => 1,
            'status' => TimePayrollFactVersion::STATUS_CLOSED,
            'source_hash' => null,
            'exception_count' => 0,
            'precheck_json' => [],
            'generated_at' => $this->clock->now(),
            'closed_at' => $this->clock->now(),
            'closed_by' => 10,
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);
        AttendancePeriodClosure::query()->create([
            'month' => '2026-10',
            'status' => AttendancePeriodClosure::STATUS_CLOSED,
            'closed_by' => 10,
            'closed_at' => $this->clock->now(),
            'current_fact_version_id' => (int) $version->id,
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);
        TimePayrollFactRow::query()->create([
            'fact_version_id' => (int) $version->id,
            'employment_id' => 1,
            'business_date' => '2026-10-01',
            'scheduled_minutes' => 480,
            'worked_minutes' => 480,
            'source_type' => 'ATTENDANCE_DAY',
            'source_id' => '1',
            'approval_status' => 'CALCULATED',
            'source_hash' => hash('sha256', 'hash-missing-row'),
            'recorded_at' => $this->clock->now(),
            'created_at' => $this->clock->now(),
        ]);

        $this->assertNull($directory->closedFactForPeriod(1, '2026-10'));
    }

    public function test_duplicate_source_and_overlap_facts_are_blocked_before_close(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $type = $this->leaveType();
        $this->leavePolicy((int) $type->id, [
            'mode' => 'ANNUAL_FIXED',
            'minutes_per_year' => 4800,
            'payroll_treatment' => 'PAID',
            'paid_ratio_scaled' => 1_000_000,
        ]);
        $this->approvedLeave($type->id, 480, '2026-11-30');
        $this->approvedLeave($type->id, 240, '2026-11-30');
        $this->attendance('2026-11-30', 480, 480, 'NORMAL');

        $response = $this->closeMonth('2026-11', 'duplicate-overlap')
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TIME_FACT_PRECHECK_FAILED');

        $codes = array_column((array) $response->json('data.blockers'), 'code');
        $this->assertContains('OVERLAPPING_TIME_FACT', $codes);
    }

    public function test_reopen_rolls_back_every_change_when_a_later_step_fails(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $this->attendance('2026-11-28', 480, 480, 'NORMAL');

        $closed = $this->closeMonth('2026-11', 'rollback-close')->assertCreated();
        $closureId = (int) $closed->json('data.closure_id');
        $versionId = (int) TimePayrollFactVersion::query()->where('period', '2026-11')->where('version', 1)->value('id');

        AttendancePeriodClosure::updating(static function (): void {
            throw new RuntimeException('Synthetic failure after version update.');
        });

        try {
            app(AttendanceClosingService::class)->reopen(
                $closureId,
                'Synthetic transactional rollback.',
                10,
            );
            $this->fail('Expected the synthetic reopen failure.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            AttendancePeriodClosure::flushEventListeners();
        }

        $this->assertSame(
            TimePayrollFactVersion::STATUS_CLOSED,
            (string) TimePayrollFactVersion::query()->whereKey($versionId)->value('status'),
        );
        $this->assertSame(
            AttendancePeriodClosure::STATUS_CLOSED,
            (string) AttendancePeriodClosure::query()->whereKey($closureId)->value('status'),
        );
    }

    public function test_real_http_revoke_after_the_original_month_closed_reduces_the_reopened_fact(): void
    {
        $this->calendar();
        $type = $this->leaveType();
        $this->leavePolicy((int) $type->id, [
            'mode' => 'ANNUAL_FIXED',
            'minutes_per_year' => 4800,
            'payroll_treatment' => 'PAID',
            'paid_ratio_scaled' => 1_000_000,
        ]);
        $this->leaveAccount($type);
        app(LeaveLedgerService::class)->grant(1, (int) $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');

        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'COMPANY',
            capabilities: [
                'time.attendance.read',
                'time.attendance.manage',
                'time.attendance.reopen',
                'time.leave.read',
                'time.leave.request',
                'time.leave.request.cancel',
                'time.leave.admin',
                'time.leave.ledger.read',
                'time.calendar.read',
                'time.shift.read',
            ],
        ));

        $this->app->instance(PayrollPeriodLockReader::class, new class implements PayrollPeriodLockReader
        {
            public function isFrozen(int $employmentId, string $period): bool
            {
                return false;
            }
        });

        $created = $this->postJson('/v1/time/leave/requests', [
            'leaveTypeCode' => 'ANNUAL',
            'startDate' => '2026-11-10',
            'endDate' => '2026-11-11',
            'reason' => 'Synthetic real-path revoke.',
        ], ['Idempotency-Key' => 'real-revoke-submit'])->assertStatus(201)->json('data');
        $caseId = (int) $created['caseId'];
        $this->workflow->approve($caseId);
        app(LeaveApprovalService::class)->applyApprovedCase($caseId, 10);
        $this->assertEquals('APPROVED', LeaveRequestProjection::query()->where('case_id', $caseId)->value('state'));

        // The original month has a real attendance day so it can be closed.
        $this->attendance('2026-11-02', 480, 480, 'NORMAL');
        $closed = $this->closeMonth('2026-11', 'real-revoke-close-v1')->assertCreated();
        $v1Id = (int) $closed->json('data.fact_version_id');
        $originalNet = (int) TimePayrollFactRow::query()
            ->where('fact_version_id', $v1Id)
            ->where('source_id', (string) $caseId)
            ->sum('paid_leave_minutes');
        $this->assertSame(960, $originalNet);

        $closureId = (int) $closed->json('data.closure_id');
        $this->postJson("/v1/time/attendance/closings/{$closureId}/reopen", [
            'reason' => 'Synthetic real-path revoke reopen.',
        ], ['Idempotency-Key' => 'real-revoke-reopen'])->assertOk();

        // Move the simulated clock into December: the revoke business date is
        // December, while the original leave month is November.
        $this->clock->setNow('2026-12-02T09:00:00Z');

        $this->postJson("/v1/time/leave/requests/{$caseId}/revoke", [
            'reason' => 'Synthetic real management revoke in a later month.',
        ], ['Idempotency-Key' => 'real-revoke-cancel'])->assertOk()
            ->assertJsonPath('data.state', 'REVOKED');

        $reversal = LeaveLedgerEntry::query()
            ->where('case_id', $caseId)
            ->where('account', 'REVERSE')
            ->where('source_type', 'LEAVE_REVOKE')
            ->firstOrFail();
        $this->assertSame(960, (int) $reversal->quantity_minutes);
        $this->assertSame('2026-12-02', substr((string) $reversal->business_date, 0, 10));

        $v2 = $this->closeMonth('2026-11', 'real-revoke-close-v2')->assertCreated();
        $v2Id = (int) $v2->json('data.fact_version_id');

        $this->assertSame(0, (int) TimePayrollFactRow::query()
            ->where('fact_version_id', $v2Id)
            ->where('source_id', (string) $caseId)
            ->sum('paid_leave_minutes'));
        $this->assertSame(0, (int) TimePayrollFactRow::query()
            ->where('fact_version_id', $v2Id)
            ->where('source_id', (string) $caseId)
            ->sum('partial_leave_minutes'));
        $this->assertSame(480, (int) TimePayrollFactRow::query()
            ->where('fact_version_id', $v2Id)
            ->where('source_type', 'ATTENDANCE_DAY')
            ->sum('worked_minutes'));
    }

    public function test_a_completely_empty_month_blocks_close_with_no_eligible_facts(): void
    {
        $this->calendar();
        $this->actingAsCloser();

        $response = $this->closeMonth('2026-08', 'empty-month-block')
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TIME_FACT_PRECHECK_FAILED');

        $this->assertContains(
            'NO_ELIGIBLE_TIME_FACTS',
            array_column((array) $response->json('data.blockers'), 'code'),
        );
        $this->assertDatabaseCount('time_payroll_fact_versions', 0);
        $this->assertDatabaseCount('time_payroll_fact_rows', 0);
        $this->assertNull(app(PayrollInputDirectory::class)->closedFactForPeriod(1, '2026-08'));
    }

    public function test_month_mutex_rolls_back_a_write_when_its_transaction_fails(): void
    {
        $this->calendar();
        $this->actingAsCloser();

        $this->expectException(RuntimeException::class);

        try {
            app(PayrollFreezeGuard::class)->withOpenPeriods(['2026-11'], function (): void {
                ShiftSchedule::query()->create([
                    'employment_id' => 1,
                    'business_date' => '2026-11-19',
                    'shift_template_id' => 1,
                    'created_by' => 10,
                    'created_at' => $this->clock->now(),
                ]);

                throw new RuntimeException('Synthetic write transaction failure.');
            });
        } finally {
            $this->assertDatabaseCount('shift_schedules', 0);
            $this->assertDatabaseCount('time_period_locks', 0);
        }
    }

    public function test_closed_month_locks_out_a_mutation_and_reopen_restores_the_same_mutex(): void
    {
        $this->calendar();
        $this->actingAsCloser();
        $this->attendance('2026-11-02', 480, 480, 'NORMAL');

        $this->closeMonth('2026-11', 'mutex-close')->assertCreated();
        $this->assertDatabaseCount('time_period_locks', 1);

        try {
            app(PayrollFreezeGuard::class)->withOpenPeriods(['2026-11'], static fn (): null => null);
            $this->fail('Expected the locked closed month to reject a write.');
        } catch (\App\Shared\Exceptions\ConflictException $exception) {
            $this->assertSame('PERIOD_CLOSED', $exception->details()['reason'] ?? null);
        }

        $closureId = (int) AttendancePeriodClosure::query()->where('month', '2026-11')->value('id');
        $this->postJson("/v1/time/attendance/closings/{$closureId}/reopen", [
            'reason' => 'Synthetic mutex reopen.',
        ], ['Idempotency-Key' => 'mutex-reopen'])->assertOk();

        app(PayrollFreezeGuard::class)->withOpenPeriods(['2026-11'], static fn (): string => 'writable');

        $this->assertSame(1, \App\Modules\Time\Models\TimePeriodLock::query()->where('month', '2026-11')->count());
    }

    private function actingAsCloser(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'COMPANY',
            capabilities: [
                'time.attendance.read',
                'time.attendance.manage',
                'time.attendance.reopen',
                'time.shift.read',
                'time.shift.schedule.manage',
                'time.calendar.read',
            ],
        ));
    }

    /**
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function closeMonth(string $month, string $key = 'fact-close'): TestResponse
    {
        return $this->postJson('/v1/time/attendance/closings', [
            'month' => $month,
        ], ['Idempotency-Key' => $key]);
    }

    private function attendance(string $date, int $scheduled, int $worked, string $status, bool $withSchedule = true): AttendanceDay
    {
        if ($withSchedule) {
            ShiftSchedule::query()->create([
                'employment_id' => 1,
                'business_date' => $date,
                'shift_template_id' => 1,
                'created_by' => 10,
                'created_at' => $this->clock->now(),
            ]);
        }

        return AttendanceDay::query()->create([
            'employment_id' => 1,
            'business_date' => $date,
            'shift_template_id' => null,
            'scheduled_minutes' => $scheduled,
            'worked_minutes' => $worked,
            'overtime_minutes' => 0,
            'late_minutes' => $status === 'LATE' ? 20 : 0,
            'early_leave_minutes' => 0,
            'absence_minutes' => max(0, $scheduled - $worked),
            'status' => $status,
            'rule_version' => 'TIME-RULES-2026.1',
            'source_hash' => hash('sha256', $date.'|'.$scheduled.'|'.$worked.'|'.$status),
            'calculated_at' => $this->clock->now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $accrual
     */
    private function leavePolicy(int $typeId, array $accrual): LeavePolicyVersion
    {
        return LeavePolicyVersion::query()->create([
            'leave_type_id' => $typeId,
            'version' => 1,
            'status' => 'PUBLISHED',
            'effective_from' => '2026-01-01',
            'effective_to' => '9999-12-31',
            'applies_to_json' => ['scope' => 'COMPANY'],
            'accrual_json' => $accrual,
            'carryover_json' => null,
            'min_notice_days' => 0,
            'max_consecutive_days' => null,
            'requires_attachment_over_days' => null,
            'allow_half_day' => true,
            'legal_basis' => null,
            'published_at' => $this->clock->now(),
            'published_by' => 10,
            'created_at' => $this->clock->now(),
        ]);
    }

    private function approvedLeave(int $leaveTypeId, int $minutes, string $date): LeaveRequestProjection
    {
        $caseId = $this->workflow->createCase(
            requesterUserId: 10,
            type: 'LEAVE_REQUEST',
            subjectType: 'employment',
            subjectId: 1,
            payload: ['title' => 'Synthetic monthly-fact leave'],
            businessDate: $date,
        );
        $this->workflow->approve($caseId);

        $projection = LeaveRequestProjection::query()->create([
            'employment_id' => 1,
            'actor_user_id' => 10,
            'leave_type_id' => $leaveTypeId,
            'leave_policy_version_id' => (int) LeavePolicyVersion::query()
                ->where('leave_type_id', $leaveTypeId)
                ->value('id'),
            'case_id' => $caseId,
            'from_date' => $date,
            'to_date' => $date,
            'minutes' => $minutes,
            'state' => 'APPROVED',
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);

        LeaveLedgerEntry::query()->create([
            'employment_id' => 1,
            'leave_type_id' => $leaveTypeId,
            'account' => 'CONSUME',
            'quantity_minutes' => -$minutes,
            'business_date' => $date,
            'effective_on' => $date,
            'source_type' => 'LEAVE_REQUEST',
            'source_id' => (string) $projection->case_id,
            'case_id' => $projection->case_id,
            'reason' => 'Synthetic approved leave fact',
            'entry_hash' => hash('sha256', 'leave|'.$date.'|'.$minutes),
            'recorded_at' => $this->clock->now(),
            'created_at' => $this->clock->now(),
        ]);

        return $projection;
    }

    private function approvedLeaveRange(int $leaveTypeId, int $minutes, string $from, string $to): LeaveRequestProjection
    {
        $caseId = $this->workflow->createCase(
            requesterUserId: 10,
            type: 'LEAVE_REQUEST',
            subjectType: 'employment',
            subjectId: 1,
            payload: ['title' => 'Synthetic cross-month leave'],
            businessDate: $from,
        );
        $this->workflow->approve($caseId);

        $projection = LeaveRequestProjection::query()->create([
            'employment_id' => 1,
            'actor_user_id' => 10,
            'leave_type_id' => $leaveTypeId,
            'leave_policy_version_id' => (int) LeavePolicyVersion::query()
                ->where('leave_type_id', $leaveTypeId)
                ->value('id'),
            'case_id' => $caseId,
            'from_date' => $from,
            'to_date' => $to,
            'minutes' => $minutes,
            'state' => 'APPROVED',
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);

        LeaveLedgerEntry::query()->create([
            'employment_id' => 1,
            'leave_type_id' => $leaveTypeId,
            'account' => 'CONSUME',
            'quantity_minutes' => -$minutes,
            'business_date' => $from,
            'effective_on' => $from,
            'source_type' => 'LEAVE_REQUEST',
            'source_id' => (string) $caseId,
            'case_id' => $caseId,
            'reason' => 'Synthetic approved cross-month leave fact',
            'entry_hash' => hash('sha256', 'leave-range|'.$from.'|'.$to.'|'.$minutes),
            'recorded_at' => $this->clock->now(),
            'created_at' => $this->clock->now(),
        ]);

        return $projection;
    }

    private function leaveReversal(LeaveRequestProjection $projection, int $minutes, string $date): void
    {
        LeaveLedgerEntry::query()->create([
            'employment_id' => 1,
            'leave_type_id' => (int) $projection->leave_type_id,
            'account' => 'REVERSE',
            'quantity_minutes' => $minutes,
            'business_date' => $date,
            'effective_on' => $date,
            'source_type' => 'LEAVE_REVERSAL',
            'source_id' => 'RANGE-REV-'.$projection->case_id,
            'case_id' => (int) $projection->case_id,
            'reason' => 'Synthetic cross-month partial reversal',
            'entry_hash' => hash('sha256', 'leave-range-reverse|'.$projection->case_id.'|'.$date),
            'recorded_at' => $this->clock->now(),
            'created_at' => $this->clock->now(),
        ]);
    }

    private function travelLink(int $travelId, string $from, string $to, int $caseId): void
    {
        TravelAttendanceLink::query()->create([
            'travel_id' => $travelId,
            'employment_id' => 1,
            'from_date' => $from,
            'to_date' => $to,
            'source_case_id' => $caseId,
            'status' => TravelAttendanceLink::STATUS_ACTIVE,
            'created_at' => $this->clock->now(),
        ]);
    }

    private function settledOvertime(string $date, int $minutes, string $method): void
    {
        $record = OvertimeRecord::query()->create([
            'employment_id' => 1,
            'business_date' => $date,
            'minutes' => $minutes,
            'status' => 'APPROVED',
            'created_at' => $this->clock->now(),
        ]);
        OvertimeRequest::query()->create([
            'employment_id' => 1,
            'business_date' => $date,
            'declared_minutes' => $minutes,
            'confirmed_minutes' => $minutes,
            'status' => OvertimeRequest::STATUS_CONFIRMED,
            'reason' => 'Synthetic settled overtime '.$method,
            'overtime_record_id' => (int) $record->id,
            'recorded_at' => $this->clock->now(),
        ]);
        OvertimeSettlement::query()->create([
            'overtime_record_id' => (int) $record->id,
            'employment_id' => 1,
            'method' => $method,
            'minutes' => $minutes,
            'reason' => 'Synthetic '.$method.' settlement',
            'recorded_at' => $this->clock->now(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function factRows(int $versionId): array
    {
        return TimePayrollFactRow::query()
            ->where('fact_version_id', $versionId)
            ->orderBy('id')
            ->get()
            ->map(static fn (TimePayrollFactRow $row): array => $row->getAttributes())
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function factRowsForMonth(string $month): array
    {
        $start = $month.'-01';
        $end = \Carbon\CarbonImmutable::parse($start, 'UTC')->endOfMonth()->format('Y-m-d');

        return TimePayrollFactRow::query()
            ->where('business_date', '>=', $start)
            ->where('business_date', '<=', $end)
            ->orderBy('business_date')
            ->orderBy('id')
            ->get()
            ->map(static fn (TimePayrollFactRow $row): array => $row->getAttributes())
            ->all();
    }

    private function expectConflictReason(string $reason, callable $operation): void
    {
        try {
            $operation();
        } catch (\App\Shared\Exceptions\ConflictException $exception) {
            $this->assertSame($reason, $exception->details()['reason'] ?? null);

            return;
        }

        $this->fail('Expected mutation to be rejected with '.$reason.'.');
    }

    private function expectDomainRejection(callable $operation): void
    {
        try {
            $operation();
        } catch (\DomainException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the append-only Time payroll fact row to reject the mutation.');
    }
}
