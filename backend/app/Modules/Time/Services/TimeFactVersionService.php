<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Models\AttendanceDay;
use App\Modules\Time\Models\AttendancePeriodClosure;
use App\Modules\Time\Models\BusinessTripRecord;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeavePolicyVersion;
use App\Modules\Time\Models\LeaveRequestProjection;
use App\Modules\Time\Models\OvertimeRequest;
use App\Modules\Time\Models\OvertimeSettlement;
use App\Modules\Time\Models\ShiftSchedule;
use App\Modules\Time\Models\TimePayrollFactRow;
use App\Modules\Time\Models\TimePayrollFactVersion;
use App\Modules\Time\Models\TravelAttendanceLink;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds the immutable monthly Time payroll fact version.
 *
 * Inputs are approved/effective operational facts only:
 *  - attendance_days for scheduled/worked/overtime;
 *  - the append-only leave ledger joined to an approved leave request and its
 *    effective policy generation for paid/partial/unpaid treatment;
 *  - confirmed overtime requests plus their single settlement;
 *  - active travel attendance links;
 *  - shift schedules when present, used to prove missing-schedule blockers.
 *
 * The precheck is fail-closed. Any required missing or contradictory fact
 * blocks the close; values are never silently zero-filled. All table access is
 * Time-owned and all arithmetic is integer minutes.
 */
final class TimeFactVersionService
{
    private const TRACKED_ATTENDANCE_STATUSES = ['NORMAL', 'LATE', 'EARLY', 'INCOMPLETE', 'ABSENT', 'LEAVE'];

    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly BusinessCalendar $calendar,
    ) {}

    /**
     * Close the month by appending exactly one immutable fact version.
     *
     * Replaying a close against the same closed version returns that version
     * unchanged. A reopened period gets a fresh version whose referenced
     * previous version is the latest closed/reopened one.
     *
     * @return array{version: TimePayrollFactVersion, rows: list<TimePayrollFactRow>, replayed: bool}
     */
    public function close(string $month, int $actorUserId): array
    {
        $this->assertMonth($month);

        return DB::transaction(function () use ($month, $actorUserId): array {
            /** @var AttendancePeriodClosure|null $closure */
            $closure = AttendancePeriodClosure::query()
                ->where('month', $month)
                ->lockForUpdate()
                ->first();

            if ($closure !== null && (string) $closure->status === AttendancePeriodClosure::STATUS_CLOSED) {
                $current = $this->currentVersion($closure);

                if ($current === null || (string) $current->status !== TimePayrollFactVersion::STATUS_CLOSED) {
                    throw new ConflictException(
                        '该月份的关闭事实版本不存在，需先重开并重新生成。',
                        ['reason' => 'FACT_VERSION_MISSING', 'period' => $month],
                    );
                }

                return [
                    'version' => $current,
                    'rows' => $this->rowsFor((int) $current->id),
                    'replayed' => true,
                ];
            }

            $latest = TimePayrollFactVersion::query()
                ->where('period', $month)
                ->orderByDesc('version')
                ->first();

            $nextVersion = $latest !== null ? (int) $latest->version + 1 : 1;
            $previousVersionId = $latest?->id !== null ? (int) $latest->id : null;

            /** @var TimePayrollFactVersion $version */
            $version = TimePayrollFactVersion::query()->create([
                'period' => $month,
                'version' => $nextVersion,
                'status' => TimePayrollFactVersion::STATUS_CLOSING,
                'source_hash' => null,
                'exception_count' => 0,
                'precheck_json' => null,
                'generated_at' => $this->clock->now(),
                'reopened_from_version_id' => $latest?->reopened_from_version_id !== null
                    ? (int) $latest->reopened_from_version_id
                    : $previousVersionId,
                'created_at' => $this->clock->now(),
                'updated_at' => $this->clock->now(),
            ]);

            $facts = $this->buildFacts($month);

            if ($facts['blockers'] !== []) {
                $version->forceFill([
                    'precheck_json' => [
                        'checked_at' => $this->clock->now()->toIso8601ZuluString(),
                        'blockers' => $facts['blockers'],
                        'exception_count' => count($facts['blockers']),
                    ],
                    'exception_count' => count($facts['blockers']),
                    'updated_at' => $this->clock->now(),
                ])->save();

                throw new ConflictException(
                    '考勤月结预检未通过，请先处理下列问题后重新关闭。',
                    [
                        'reason' => 'TIME_FACT_PRECHECK_FAILED',
                        'period' => $month,
                        'fact_version_id' => (int) $version->id,
                        'exception_count' => count($facts['blockers']),
                        'blockers' => $facts['blockers'],
                    ],
                );
            }

            $sourceHash = $this->canonicalHash($month, $facts['rows']);
            $exceptionCount = $this->exceptionCount($month);

            foreach ($facts['rows'] as $row) {
                TimePayrollFactRow::query()->create([
                    ...$row,
                    'fact_version_id' => (int) $version->id,
                    'recorded_at' => $this->clock->now(),
                    'created_at' => $this->clock->now(),
                ]);
            }

            $version->forceFill([
                'status' => TimePayrollFactVersion::STATUS_CLOSED,
                'source_hash' => $sourceHash,
                'exception_count' => $exceptionCount,
                'precheck_json' => [
                    'checked_at' => $this->clock->now()->toIso8601ZuluString(),
                    'blockers' => [],
                    'exception_count' => $exceptionCount,
                ],
                'closed_at' => $this->clock->now(),
                'closed_by' => $actorUserId,
                'updated_at' => $this->clock->now(),
            ])->save();

            if ($closure === null) {
                $closure = AttendancePeriodClosure::query()->create([
                    'month' => $month,
                    'status' => AttendancePeriodClosure::STATUS_CLOSED,
                    'closed_by' => $actorUserId,
                    'closed_at' => $this->clock->now(),
                    'current_fact_version_id' => (int) $version->id,
                    'created_at' => $this->clock->now(),
                    'updated_at' => $this->clock->now(),
                ]);
            } else {
                $closure->forceFill([
                    'status' => AttendancePeriodClosure::STATUS_CLOSED,
                    'closed_by' => $actorUserId,
                    'closed_at' => $this->clock->now(),
                    'reopened_by' => null,
                    'reopened_at' => null,
                    'reopen_reason' => null,
                    'current_fact_version_id' => (int) $version->id,
                    'updated_at' => $this->clock->now(),
                ])->save();
            }

            $this->audit->record($actorUserId, 'time.attendance.fact_version.closed', 'time_payroll_fact_version', (int) $version->id, after: [
                'period' => $month,
                'version' => (int) $version->version,
                'source_hash' => $sourceHash,
                'row_count' => count($facts['rows']),
                'exception_count' => $exceptionCount,
            ]);

            return [
                'version' => $version->refresh(),
                'rows' => $this->rowsFor((int) $version->id),
                'replayed' => false,
            ];
        });
    }

    /**
     * Reopen one closure while preserving the closed fact version untouched.
     */
    public function reopen(AttendancePeriodClosure $closure, string $reason, int $actorUserId): AttendancePeriodClosure
    {
        $current = $this->currentVersion($closure);

        if ($current !== null && (string) $current->status === TimePayrollFactVersion::STATUS_CLOSED) {
            $current->forceFill([
                'status' => TimePayrollFactVersion::STATUS_REOPENED,
                'reopened_at' => $this->clock->now(),
                'reopened_by' => $actorUserId,
                'reopen_reason' => $reason,
                'updated_at' => $this->clock->now(),
            ])->save();
        }

        $closure->forceFill([
            'status' => AttendancePeriodClosure::STATUS_REOPENED,
            'reopened_by' => $actorUserId,
            'reopened_at' => $this->clock->now(),
            'reopen_reason' => $reason,
            'updated_at' => $this->clock->now(),
        ])->save();

        $this->audit->record($actorUserId, 'time.attendance.fact_version.reopened', 'attendance_period', (int) $closure->id, after: [
            'month' => (string) $closure->month,
            'fact_version_id' => $current?->id !== null ? (int) $current->id : null,
            'reason' => $reason,
        ]);

        return $closure->refresh();
    }

    /**
     * @return array{rows: list<array<string, mixed>>, blockers: list<array{code: string, message: string, employment_id: int|null, business_date: string|null, source_id: string|null}>}
     */
    public function buildFacts(string $month): array
    {
        $this->assertMonth($month);
        $start = $month.'-01';
        $end = CarbonImmutable::parse($start, 'UTC')->endOfMonth()->format('Y-m-d');

        $rows = [];
        $blockers = [];

        $attendance = AttendanceDay::query()
            ->where('business_date', '>=', $start)
            ->where('business_date', '<=', $end)
            ->orderBy('employment_id')
            ->orderBy('business_date')
            ->get();

        $schedules = ShiftSchedule::query()
            ->where('business_date', '>=', $start)
            ->where('business_date', '<=', $end)
            ->orderBy('employment_id')
            ->orderBy('business_date')
            ->get()
            ->keyBy(static fn (ShiftSchedule $schedule): string => (int) $schedule->employment_id.'|'.$schedule->business_date->format('Y-m-d'));

        foreach ($attendance as $day) {
            $employmentId = (int) $day->employment_id;
            $date = $day->business_date->format('Y-m-d');
            $status = strtoupper((string) $day->status);
            $scheduled = (int) $day->scheduled_minutes;
            $worked = (int) $day->worked_minutes;

            if (in_array($status, ['INCOMPLETE', 'CALCULATION_INCOMPLETE'], true)) {
                $blockers[] = $this->blocker(
                    'ATTENDANCE_INCOMPLETE',
                    "员工 {$employmentId} 在 {$date} 的考勤未闭环，无法生成月结事实。",
                    $employmentId,
                    $date,
                    (string) $day->id,
                );
            }

            if (! $schedules->has($employmentId.'|'.$date)) {
                $blockers[] = $this->blocker(
                    'SHIFT_SCHEDULE_MISSING',
                    "员工 {$employmentId} 在 {$date} 缺少排班，无法确定应出勤分钟。",
                    $employmentId,
                    $date,
                    (string) $day->id,
                );
            }

            if ($scheduled === 0 && $worked > 0) {
                $blockers[] = $this->blocker(
                    'SCHEDULED_MINUTES_UNRESOLVED',
                    "员工 {$employmentId} 在 {$date} 有出勤但排班分钟为 0，无法确定应出勤。",
                    $employmentId,
                    $date,
                    (string) $day->id,
                );
            }

            if (in_array($status, self::TRACKED_ATTENDANCE_STATUSES, true)) {
                $rows[] = [
                    'employment_id' => $employmentId,
                    'business_date' => $date,
                    'range_start' => $date,
                    'range_end' => $date,
                    'scheduled_minutes' => $scheduled,
                    'worked_minutes' => $worked,
                    'paid_leave_minutes' => 0,
                    'partial_leave_minutes' => 0,
                    'unpaid_leave_minutes' => 0,
                    'overtime_pay_minutes' => 0,
                    'overtime_timeoff_minutes' => 0,
                    'travel_minutes' => 0,
                    'leave_type_id' => null,
                    'leave_policy_version_id' => null,
                    'overtime_category' => null,
                    'travel_mode' => null,
                    'source_type' => 'ATTENDANCE_DAY',
                    'source_id' => (string) $day->id,
                    'source_revision' => (string) $day->source_hash,
                    'approval_status' => 'CALCULATED',
                    'source_hash' => (string) $day->source_hash,
                ];
            }
        }

        $leave = $this->leaveFacts($month, $start, $end, $blockers);
        $rows = [...$rows, ...$leave];

        $overtime = $this->overtimeFacts($month, $start, $end, $blockers);
        $rows = [...$rows, ...$overtime];

        $travel = $this->travelFacts($month, $start, $end, $blockers);
        $rows = [...$rows, ...$travel];

        $duplicates = [];
        foreach ($rows as $row) {
            $signature = implode('|', [$row['employment_id'], $row['business_date'], $row['source_type'], $row['source_id']]);
            if (isset($duplicates[$signature])) {
                $blockers[] = $this->blocker(
                    'DUPLICATE_SOURCE_FACT',
                    "员工 {$row['employment_id']} 在 {$row['business_date']} 存在重复来源事实，无法月结。",
                    (int) $row['employment_id'],
                    (string) $row['business_date'],
                    (string) $row['source_id'],
                );
            }
            $duplicates[$signature] = true;
        }

        $this->assertNoTimeOverlaps($rows, $blockers);

        usort($rows, static fn (array $a, array $b): int => [
            $a['employment_id'],
            $a['business_date'],
            $a['source_type'],
            $a['source_id'],
        ] <=> [
            $b['employment_id'],
            $b['business_date'],
            $b['source_type'],
            $b['source_id'],
        ]);

        return ['rows' => $rows, 'blockers' => $blockers];
    }

    /**
     * Exceptions are non-blocking attendance anomalies captured in a CLOSED
     * version, not precheck failures. A blocked close never reaches here.
     */
    private function exceptionCount(string $month): int
    {
        $start = $month.'-01';
        $end = CarbonImmutable::parse($start, 'UTC')->endOfMonth()->format('Y-m-d');

        return AttendanceDay::query()
            ->where('business_date', '>=', $start)
            ->where('business_date', '<=', $end)
            ->where(static function ($query): void {
                $query->where('late_minutes', '>', 0)
                    ->orWhere('early_leave_minutes', '>', 0)
                    ->orWhereIn('status', ['ABSENT']);
            })
            ->count();
    }

    private function currentVersion(AttendancePeriodClosure $closure): ?TimePayrollFactVersion
    {
        if ($closure->current_fact_version_id !== null) {
            return TimePayrollFactVersion::query()->whereKey((int) $closure->current_fact_version_id)->first();
        }

        return TimePayrollFactVersion::query()
            ->where('period', (string) $closure->month)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @return list<TimePayrollFactRow>
     */
    private function rowsFor(int $versionId): array
    {
        return TimePayrollFactRow::query()
            ->where('fact_version_id', $versionId)
            ->orderBy('employment_id')
            ->orderBy('business_date')
            ->orderBy('source_type')
            ->orderBy('source_id')
            ->get()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function canonicalHash(string $month, array $rows): string
    {
        $canonical = array_map(static fn (array $row): array => [
            'employment_id' => (int) $row['employment_id'],
            'business_date' => (string) $row['business_date'],
            'range_start' => $row['range_start'],
            'range_end' => $row['range_end'],
            'scheduled_minutes' => (int) $row['scheduled_minutes'],
            'worked_minutes' => (int) $row['worked_minutes'],
            'paid_leave_minutes' => (int) $row['paid_leave_minutes'],
            'partial_leave_minutes' => (int) $row['partial_leave_minutes'],
            'unpaid_leave_minutes' => (int) $row['unpaid_leave_minutes'],
            'overtime_pay_minutes' => (int) $row['overtime_pay_minutes'],
            'overtime_timeoff_minutes' => (int) $row['overtime_timeoff_minutes'],
            'travel_minutes' => (int) $row['travel_minutes'],
            'leave_type_id' => $row['leave_type_id'],
            'leave_policy_version_id' => $row['leave_policy_version_id'],
            'overtime_category' => $row['overtime_category'],
            'travel_mode' => $row['travel_mode'],
            'source_type' => (string) $row['source_type'],
            'source_id' => (string) $row['source_id'],
            'source_revision' => $row['source_revision'],
            'approval_status' => (string) $row['approval_status'],
            'source_hash' => (string) $row['source_hash'],
        ], $rows);

        return hash('sha256', json_encode([
            'schema' => 'time-payroll-fact.v1',
            'period' => $month,
            'rows' => $canonical,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Daily leave facts from approved requests and net ledger consumption.
     *
     * Paid treatment comes only from the versioned policy payload. The legacy
     * leave-type code is never used to guess payroll treatment.
     *
     * @param  list<array{code: string, message: string, employment_id: int|null, business_date: string|null, source_id: string|null}>  $blockers
     * @return list<array<string, mixed>>
     */
    private function leaveFacts(string $month, string $start, string $end, array &$blockers): array
    {
        $projections = LeaveRequestProjection::query()
            ->where('from_date', '<=', $end)
            ->where('to_date', '>=', $start)
            ->orderBy('employment_id')
            ->orderBy('from_date')
            ->orderBy('id')
            ->get();

        if ($projections->isEmpty()) {
            return [];
        }

        $caseIds = $projections->pluck('case_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $entries = LeaveLedgerEntry::query()
            ->whereIn('case_id', $caseIds)
            ->whereIn('account', ['CONSUME', 'REVERSE'])
            ->orderBy('id')
            ->get();

        $netByCase = [];
        foreach ($entries as $entry) {
            $caseId = (int) $entry->case_id;
            $netByCase[$caseId] = ($netByCase[$caseId] ?? 0) + abs((int) $entry->quantity_minutes);
        }

        $policyIds = $projections->pluck('leave_policy_version_id')->filter()->map(static fn (mixed $id): int => (int) $id)->unique()->all();
        $policies = LeavePolicyVersion::query()->whereIn('id', $policyIds)->get()->keyBy(static fn (LeavePolicyVersion $policy): int => (int) $policy->id);

        $rows = [];

        foreach ($projections as $projection) {
            $state = strtoupper((string) $projection->state);
            $caseId = (int) $projection->case_id;

            if (in_array($state, ['SUBMITTED', 'REJECTED', 'WITHDRAWN', 'CANCELLED', 'RELEASED', 'REVOKED'], true)) {
                continue;
            }

            if (! in_array($state, ['APPROVED', 'CONSUMED'], true)) {
                $blockers[] = $this->blocker(
                    'LEAVE_APPROVAL_UNRESOLVED',
                    "员工 {$projection->employment_id} 的请假申请 {$caseId} 审批状态未闭环。",
                    (int) $projection->employment_id,
                    $projection->from_date->format('Y-m-d'),
                    (string) $caseId,
                );

                continue;
            }

            $net = (int) ($netByCase[$caseId] ?? 0);
            if ($net <= 0) {
                $blockers[] = $this->blocker(
                    'LEAVE_LEDGER_NET_MISSING',
                    "员工 {$projection->employment_id} 的已批准请假 {$caseId} 缺少有效消费流水。",
                    (int) $projection->employment_id,
                    $projection->from_date->format('Y-m-d'),
                    (string) $caseId,
                );

                continue;
            }

            $policy = $projection->leave_policy_version_id !== null
                ? $policies->get((int) $projection->leave_policy_version_id)
                : null;

            if ($policy === null) {
                $blockers[] = $this->blocker(
                    'LEAVE_POLICY_VERSION_MISSING',
                    "员工 {$projection->employment_id} 的请假 {$caseId} 缺少假期政策版本。",
                    (int) $projection->employment_id,
                    $projection->from_date->format('Y-m-d'),
                    (string) $caseId,
                );

                continue;
            }

            $treatment = $this->leaveTreatment($policy);

            if ($treatment === null) {
                $blockers[] = $this->blocker(
                    'LEAVE_PAYROLL_TREATMENT_UNRESOLVED',
                    "员工 {$projection->employment_id} 的请假 {$caseId} 假期政策没有明确计薪属性，无法月结。",
                    (int) $projection->employment_id,
                    $projection->from_date->format('Y-m-d'),
                    (string) $caseId,
                );

                continue;
            }

            $requestStart = $projection->from_date->format('Y-m-d');
            $requestEnd = $projection->to_date->format('Y-m-d');
            $effectiveStart = max($requestStart, $start);
            $effectiveEnd = min($requestEnd, $end);
            $workingDays = $this->workingDates($effectiveStart, $effectiveEnd);

            if ($workingDays === []) {
                continue;
            }

            $perDay = intdiv($net, count($workingDays));
            $remainder = $net % count($workingDays);

            foreach ($workingDays as $index => $date) {
                $minutes = $perDay + ($index === 0 ? $remainder : 0);
                [$paid, $partial, $unpaid] = $this->splitLeaveMinutes($minutes, $treatment);

                $rows[] = [
                    'employment_id' => (int) $projection->employment_id,
                    'business_date' => $date,
                    'range_start' => $effectiveStart,
                    'range_end' => $effectiveEnd,
                    'scheduled_minutes' => 0,
                    'worked_minutes' => 0,
                    'paid_leave_minutes' => $paid,
                    'partial_leave_minutes' => $partial,
                    'unpaid_leave_minutes' => $unpaid,
                    'overtime_pay_minutes' => 0,
                    'overtime_timeoff_minutes' => 0,
                    'travel_minutes' => 0,
                    'leave_type_id' => (int) $projection->leave_type_id,
                    'leave_policy_version_id' => (int) $policy->id,
                    'overtime_category' => null,
                    'travel_mode' => null,
                    'source_type' => 'LEAVE_REQUEST',
                    'source_id' => (string) $caseId,
                    'source_revision' => 'POLICY-'.$policy->version,
                    'approval_status' => 'APPROVED',
                    'source_hash' => hash('sha256', implode('|', [
                        'leave',
                        (int) $projection->employment_id,
                        $date,
                        $caseId,
                        $minutes,
                        $treatment['treatment'],
                        $treatment['paid_ratio_scaled'],
                        (int) $policy->id,
                    ])),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{code: string, message: string, employment_id: int|null, business_date: string|null, source_id: string|null}>  $blockers
     * @return list<array<string, mixed>>
     */
    private function overtimeFacts(string $month, string $start, string $end, array &$blockers): array
    {
        $requests = OvertimeRequest::query()
            ->where('business_date', '>=', $start)
            ->where('business_date', '<=', $end)
            ->orderBy('employment_id')
            ->orderBy('business_date')
            ->get();

        if ($requests->isEmpty()) {
            return [];
        }

        $recordIds = $requests->pluck('overtime_record_id')->filter()->unique()->all();
        $settlements = OvertimeSettlement::query()
            ->whereIn('overtime_record_id', $recordIds)
            ->orderBy('id')
            ->get()
            ->keyBy(static fn (OvertimeSettlement $settlement): int => (int) $settlement->overtime_record_id);

        $rows = [];

        foreach ($requests as $request) {
            $date = $request->business_date->format('Y-m-d');
            $status = strtoupper((string) $request->status);

            if (! in_array($status, [OvertimeRequest::STATUS_APPROVED, OvertimeRequest::STATUS_CONFIRMED], true)) {
                continue;
            }

            if ($status === OvertimeRequest::STATUS_APPROVED || $request->overtime_record_id === null) {
                $blockers[] = $this->blocker(
                    'OVERTIME_NOT_CONFIRMED',
                    "员工 {$request->employment_id} 在 {$date} 的加班申请尚未确认，无法月结。",
                    (int) $request->employment_id,
                    $date,
                    (string) $request->id,
                );

                continue;
            }

            $minutes = (int) $request->confirmed_minutes;
            if ($minutes <= 0) {
                $blockers[] = $this->blocker(
                    'OVERTIME_MINUTES_UNRESOLVED',
                    "员工 {$request->employment_id} 在 {$date} 的加班确认分钟无效。",
                    (int) $request->employment_id,
                    $date,
                    (string) $request->id,
                );

                continue;
            }

            $settlement = $settlements->get((int) $request->overtime_record_id);
            if ($settlement === null) {
                $blockers[] = $this->blocker(
                    'OVERTIME_SETTLEMENT_MISSING',
                    "员工 {$request->employment_id} 在 {$date} 的加班已确认但未结算，无法确定工资或调休。",
                    (int) $request->employment_id,
                    $date,
                    (string) $request->id,
                );

                continue;
            }

            $method = strtoupper((string) $settlement->method);
            $rows[] = [
                'employment_id' => (int) $request->employment_id,
                'business_date' => $date,
                'range_start' => $date,
                'range_end' => $date,
                'scheduled_minutes' => 0,
                'worked_minutes' => 0,
                'paid_leave_minutes' => 0,
                'partial_leave_minutes' => 0,
                'unpaid_leave_minutes' => 0,
                'overtime_pay_minutes' => $method === 'PAY' ? $minutes : 0,
                'overtime_timeoff_minutes' => $method === 'TIMEOFF' ? $minutes : 0,
                'travel_minutes' => 0,
                'leave_type_id' => null,
                'leave_policy_version_id' => null,
                'overtime_category' => $method === 'PAY' ? 'PAY' : 'TIMEOFF',
                'travel_mode' => null,
                'source_type' => 'OVERTIME_SETTLEMENT',
                'source_id' => (string) $settlement->id,
                'source_revision' => (string) $request->overtime_record_id,
                'approval_status' => 'CONFIRMED',
                'source_hash' => hash('sha256', implode('|', [
                    'overtime',
                    (int) $request->employment_id,
                    $date,
                    (int) $settlement->id,
                    $method,
                    $minutes,
                ])),
            ];

            // Keep one row per settlement. Two settlements for the same source
            // are impossible by schema, while duplicate requests on a day are
            // rejected by the overtime request unique key.
        }

        return $rows;
    }

    /**
     * @param  list<array{code: string, message: string, employment_id: int|null, business_date: string|null, source_id: string|null}>  $blockers
     * @return list<array<string, mixed>>
     */
    private function travelFacts(string $month, string $start, string $end, array &$blockers): array
    {
        $links = TravelAttendanceLink::query()
            ->where('status', TravelAttendanceLink::STATUS_ACTIVE)
            ->where('from_date', '<=', $end)
            ->where('to_date', '>=', $start)
            ->orderBy('employment_id')
            ->orderBy('from_date')
            ->get();

        $standardDay = $this->calendar->standardDayMinutes();
        $rows = [];

        foreach ($links as $link) {
            $trip = BusinessTripRecord::query()->whereKey((int) $link->travel_id)->first();

            if ($trip === null || ! in_array((string) $trip->status, ['APPROVED', 'COMPLETED'], true)) {
                $blockers[] = $this->blocker(
                    'TRAVEL_APPROVAL_UNRESOLVED',
                    "员工 {$link->employment_id} 的出差计勤 {$link->travel_id} 缺少已批准行程。",
                    (int) $link->employment_id,
                    $link->from_date->format('Y-m-d'),
                    (string) $link->travel_id,
                );
            }

            $linkStart = $link->from_date->format('Y-m-d');
            $linkEnd = $link->to_date->format('Y-m-d');
            $effectiveStart = max($linkStart, $start);
            $effectiveEnd = min($linkEnd, $end);

            foreach ($this->workingDates($effectiveStart, $effectiveEnd) as $date) {
                $rows[] = [
                    'employment_id' => (int) $link->employment_id,
                    'business_date' => $date,
                    'range_start' => $linkStart,
                    'range_end' => $linkEnd,
                    'scheduled_minutes' => 0,
                    'worked_minutes' => 0,
                    'paid_leave_minutes' => 0,
                    'partial_leave_minutes' => 0,
                    'unpaid_leave_minutes' => 0,
                    'overtime_pay_minutes' => 0,
                    'overtime_timeoff_minutes' => 0,
                    'travel_minutes' => $standardDay,
                    'leave_type_id' => null,
                    'leave_policy_version_id' => null,
                    'overtime_category' => null,
                    'travel_mode' => 'ATTENDANCE_CREDIT',
                    'source_type' => 'TRAVEL_ATTENDANCE_LINK',
                    'source_id' => (string) $link->id,
                    'source_revision' => (string) $link->source_case_id,
                    'approval_status' => 'APPROVED',
                    'source_hash' => hash('sha256', implode('|', [
                        'travel',
                        (int) $link->employment_id,
                        $date,
                        (int) $link->id,
                        (int) $link->source_case_id,
                        $standardDay,
                    ])),
                ];
            }
        }

        return $rows;
    }

    /**
     * Reject a leave and a travel/attendance fact that claim the same day.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{code: string, message: string, employment_id: int|null, business_date: string|null, source_id: string|null}>  $blockers
     */
    private function assertNoTimeOverlaps(array $rows, array &$blockers): void
    {
        $occupancy = [];

        foreach ($rows as $row) {
            $claimed = (int) $row['worked_minutes']
                + (int) $row['paid_leave_minutes']
                + (int) $row['partial_leave_minutes']
                + (int) $row['unpaid_leave_minutes']
                + (int) $row['travel_minutes'];

            if ($claimed === 0) {
                continue;
            }

            $key = (int) $row['employment_id'].'|'.$row['business_date'];

            if (isset($occupancy[$key])) {
                $blockers[] = $this->blocker(
                    'OVERLAPPING_TIME_FACT',
                    "员工 {$row['employment_id']} 在 {$row['business_date']} 的时间被多个事实重复占用，无法月结。",
                    (int) $row['employment_id'],
                    (string) $row['business_date'],
                    (string) $row['source_id'],
                );
            }

            $occupancy[$key] = true;
        }
    }

    /**
     * @return list<string>
     */
    private function workingDates(string $from, string $to): array
    {
        if ($to < $from) {
            return [];
        }

        $dates = [];
        $cursor = CarbonImmutable::parse($from, 'UTC')->startOfDay();
        $end = CarbonImmutable::parse($to, 'UTC')->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $date = $cursor->format('Y-m-d');
            if ($this->calendar->isWorkingDay($date)) {
                $dates[] = $date;
            }
            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    /**
     * @return array{treatment: string, paid_ratio_scaled: int}|null
     */
    private function leaveTreatment(LeavePolicyVersion $policy): ?array
    {
        $accrual = (array) $policy->accrual_json;
        $treatment = strtoupper((string) ($accrual['payroll_treatment'] ?? 'UNRESOLVED'));
        $ratio = (int) ($accrual['paid_ratio_scaled'] ?? 0);

        if ($treatment === 'PAID') {
            return ['treatment' => 'PAID', 'paid_ratio_scaled' => 1_000_000];
        }

        if ($treatment === 'PARTIAL') {
            if ($ratio < 1 || $ratio > 999_999) {
                return null;
            }

            return ['treatment' => 'PARTIAL', 'paid_ratio_scaled' => $ratio];
        }

        if ($treatment === 'UNPAID') {
            return ['treatment' => 'UNPAID', 'paid_ratio_scaled' => 0];
        }

        return null;
    }

    /**
     * @param  array{treatment: string, paid_ratio_scaled: int}|null  $treatment
     * @return array{0: int, 1: int, 2: int}
     */
    private function splitLeaveMinutes(int $minutes, ?array $treatment): array
    {
        if ($treatment === null || $treatment['treatment'] === 'UNRESOLVED') {
            return [0, 0, $minutes];
        }

        if ($treatment['treatment'] === 'PAID') {
            return [$minutes, 0, 0];
        }

        if ($treatment['treatment'] === 'UNPAID') {
            return [0, 0, $minutes];
        }

        $paid = intdiv($minutes * $treatment['paid_ratio_scaled'], 1_000_000);
        $unpaid = $minutes - $paid;

        // Partial leave keeps the paid and unpaid split in the dedicated
        // partial column; downstream payroll can derive either component.
        return [0, $paid, $unpaid];
    }

    /**
     * @return array{code: string, message: string, employment_id: int|null, business_date: string|null, source_id: string|null}
     */
    private function blocker(string $code, string $message, ?int $employmentId, ?string $businessDate, ?string $sourceId): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'employment_id' => $employmentId,
            'business_date' => $businessDate,
            'source_id' => $sourceId,
        ];
    }

    private function assertMonth(string $month): void
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            throw new ValidationException(['month' => ['月份必须使用 YYYY-MM 格式。']]);
        }

        BusinessDate::parse($month.'-01');
    }
}
