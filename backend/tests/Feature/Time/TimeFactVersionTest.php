<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Contracts\PayrollInputDirectory;
use App\Modules\Time\Contracts\PayrollTimeFactExport;
use App\Modules\Time\Models\AttendanceDay;
use App\Modules\Time\Models\AttendancePeriodClosure;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeavePolicyVersion;
use App\Modules\Time\Models\LeaveRequestProjection;
use App\Modules\Time\Models\ShiftSchedule;
use App\Modules\Time\Models\TimePayrollFactRow;
use App\Modules\Time\Models\TimePayrollFactVersion;
use Illuminate\Testing\TestResponse;
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
            static fn () => app(\App\Modules\Time\Services\LeaveLedgerService::class)
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

    private function approvedLeave(int $leaveTypeId, int $minutes, string $date): void
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
            ->map(static fn (TimePayrollFactRow $row): array => [
                'business_date' => $row->business_date->format('Y-m-d'),
                'worked_minutes' => (int) $row->worked_minutes,
                'source_hash' => (string) $row->source_hash,
            ])
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
