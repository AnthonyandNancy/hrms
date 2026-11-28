<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\PayrollAttendanceExport;
use App\Modules\Time\Contracts\PayrollInputDirectory;
use App\Modules\Time\Contracts\PayrollTimeFactExport;
use App\Modules\Time\Models\AttendanceDay;
use App\Modules\Time\Models\AttendancePeriodClosure;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeavePolicyVersion;
use App\Modules\Time\Models\LeaveRequestProjection;
use App\Modules\Time\Models\LeaveType;
use App\Modules\Time\Models\TimePayrollFactRow;
use App\Modules\Time\Models\TimePayrollFactVersion;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Time\BusinessDate;
use Carbon\CarbonImmutable;

final class PayrollInputDirectoryService implements PayrollInputDirectory
{
    public function closedFactForPeriod(int $employmentId, string $period): ?PayrollTimeFactExport
    {
        if (preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
            return null;
        }

        $closure = AttendancePeriodClosure::query()
            ->where('month', $period)
            ->where('status', AttendancePeriodClosure::STATUS_CLOSED)
            ->first();

        if ($closure === null) {
            return null;
        }

        $version = $closure->current_fact_version_id !== null
            ? TimePayrollFactVersion::query()
                ->whereKey((int) $closure->current_fact_version_id)
                ->where('status', TimePayrollFactVersion::STATUS_CLOSED)
                ->first()
            : TimePayrollFactVersion::query()
                ->where('period', $period)
                ->where('status', TimePayrollFactVersion::STATUS_CLOSED)
                ->orderByDesc('version')
                ->first();

        if ($version === null || $version->source_hash === null) {
            return null;
        }

        $rows = TimePayrollFactRow::query()
            ->where('fact_version_id', (int) $version->id)
            ->where('employment_id', $employmentId)
            ->orderBy('business_date')
            ->orderBy('source_type')
            ->orderBy('source_id')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        /** @var list<array<string, mixed>> $facts */
        $facts = [];

        foreach ($rows as $row) {
            $facts[] = [
                'employment_id' => (int) $row->employment_id,
                'business_date' => $row->business_date->format('Y-m-d'),
                'range_start' => $row->range_start?->format('Y-m-d'),
                'range_end' => $row->range_end?->format('Y-m-d'),
                'scheduled_minutes' => (int) $row->scheduled_minutes,
                'worked_minutes' => (int) $row->worked_minutes,
                'paid_leave_minutes' => (int) $row->paid_leave_minutes,
                'partial_leave_minutes' => (int) $row->partial_leave_minutes,
                'unpaid_leave_minutes' => (int) $row->unpaid_leave_minutes,
                'overtime_pay_minutes' => (int) $row->overtime_pay_minutes,
                'overtime_timeoff_minutes' => (int) $row->overtime_timeoff_minutes,
                'travel_minutes' => (int) $row->travel_minutes,
                'leave_type_id' => $row->leave_type_id !== null ? (int) $row->leave_type_id : null,
                'leave_policy_version_id' => $row->leave_policy_version_id !== null ? (int) $row->leave_policy_version_id : null,
                'overtime_category' => $row->overtime_category,
                'travel_mode' => $row->travel_mode,
                'source_type' => (string) $row->source_type,
                'source_id' => (string) $row->source_id,
                'source_revision' => $row->source_revision,
                'approval_status' => (string) $row->approval_status,
                'source_hash' => (string) $row->source_hash,
            ];
        }

        $start = $period.'-01';
        $end = CarbonImmutable::parse($start, 'UTC')->endOfMonth()->format('Y-m-d');

        $sum = static fn (string $field): int => (int) array_sum(array_map(
            static fn (array $fact): int => (int) $fact[$field],
            $facts,
        ));

        return new PayrollTimeFactExport(
            employmentId: $employmentId,
            period: $period,
            from: $start,
            to: $end,
            factVersion: (int) $version->version,
            factVersionId: (int) $version->id,
            sourceHash: (string) $version->source_hash,
            exceptionCount: (int) $version->exception_count,
            scheduledMinutes: $sum('scheduled_minutes'),
            workedMinutes: $sum('worked_minutes'),
            paidLeaveMinutes: $sum('paid_leave_minutes'),
            partialLeaveMinutes: $sum('partial_leave_minutes'),
            unpaidLeaveMinutes: $sum('unpaid_leave_minutes'),
            overtimePayMinutes: $sum('overtime_pay_minutes'),
            overtimeTimeoffMinutes: $sum('overtime_timeoff_minutes'),
            travelMinutes: $sum('travel_minutes'),
            facts: $facts,
        );
    }

    public function attendanceForPeriod(int $employmentId, string $period): ?PayrollAttendanceExport
    {
        if (preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
            return null;
        }

        $start = BusinessDate::from($period.'-01');
        $end = BusinessDate::from(CarbonImmutable::parse($start->value(), 'UTC')->endOfMonth()->format('Y-m-d'));

        $days = AttendanceDay::query()
            ->where('employment_id', $employmentId)
            ->where('business_date', '>=', $start->value())
            ->where('business_date', '<=', $end->value())
            ->orderBy('business_date')
            ->get();

        $scheduled = 0;
        $worked = 0;
        $overtime = 0;

        foreach ($days as $day) {
            $scheduled += (int) $day->scheduled_minutes;
            $worked += (int) $day->worked_minutes;
            $overtime += (int) $day->overtime_minutes;
        }

        // Leave payroll input is the signed net of the append-only ledger:
        // CONSUME is negative, REVERSE/RELEASE are positive. Treatment comes
        // only from the versioned policy; a leave-type code is never used to
        // guess whether time is paid.
        $leaveRows = LeaveLedgerEntry::query()
            ->where('employment_id', $employmentId)
            ->whereIn('account', ['CONSUME', 'REVERSE', 'RELEASE'])
            ->where('effective_on', '>=', $start->value())
            ->where('effective_on', '<=', $end->value())
            ->orderBy('id')
            ->get();

        $paidLeave = 0;
        $unpaidLeave = 0;
        $consumption = [];

        /** @var array<int, int> $signedNetByCase */
        $signedNetByCase = [];
        /** @var array<int, int> $typeByCase */
        $typeByCase = [];

        foreach ($leaveRows as $entry) {
            if ($entry->account === 'RELEASE') {
                // RELEASE closes the submit-time hold and is not payroll leave.
                continue;
            }

            $caseId = $entry->case_id !== null ? (int) $entry->case_id : 0;
            $signedNetByCase[$caseId] = ($signedNetByCase[$caseId] ?? 0) + (int) $entry->quantity_minutes;
            $typeByCase[$caseId] = (int) $entry->leave_type_id;
        }

        $caseIds = array_values(array_filter(array_keys($signedNetByCase), static fn (int $id): bool => $id > 0));
        $projections = LeaveRequestProjection::query()
            ->where('employment_id', $employmentId)
            ->whereIn('case_id', $caseIds)
            ->get()
            ->keyBy(static fn (LeaveRequestProjection $projection): int => (int) $projection->case_id);
        $policyIds = $projections->pluck('leave_policy_version_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();
        $policies = LeavePolicyVersion::query()
            ->whereIn('id', $policyIds)
            ->get()
            ->keyBy(static fn (LeavePolicyVersion $policy): int => (int) $policy->id);
        $types = LeaveType::query()
            ->whereIn('id', array_values(array_unique($typeByCase)))
            ->get()
            ->keyBy('id');

        foreach ($signedNetByCase as $caseId => $signedNet) {
            // CONSUME is negative and REVERSE is positive. The remaining net
            // magnitude is the only minute count that can enter payroll.
            $effectiveMinutes = max(0, -$signedNet);

            if ($effectiveMinutes === 0) {
                continue;
            }

            $projection = $caseId > 0 ? $projections->get($caseId) : null;
            $policyId = $projection?->leave_policy_version_id;
            $policy = $policyId !== null ? $policies->get((int) $policyId) : null;

            // Entries without a case are legacy/manual imports. They keep the
            // old compatibility policy lookup for this rollout only; a real
            // request must carry the frozen policy version or fail closed.
            if ($policy === null && $caseId === 0) {
                $policy = LeavePolicyVersion::query()
                    ->where('leave_type_id', (int) $typeByCase[$caseId])
                    ->where('status', 'PUBLISHED')
                    ->whereNull('retired_at')
                    ->where('effective_from', '<=', $start->value())
                    ->where('effective_to', '>=', $start->value())
                    ->orderByDesc('version')
                    ->first();
            }

            $treatment = $policy !== null ? $this->treatment($policy) : null;

            if ($treatment === null) {
                throw new ConflictException(
                    '已批准请假的政策版本缺少明确计薪属性，无法生成工资输入。',
                    [
                        'reason' => 'LEAVE_PAYROLL_TREATMENT_UNRESOLVED',
                        'case_id' => $caseId > 0 ? $caseId : null,
                        'leave_type_id' => $typeByCase[$caseId],
                    ],
                );
            }

            [$entryPaid, $entryPartial, $entryUnpaid] = $this->split($effectiveMinutes, $treatment);
            $paidLeave += $entryPaid + $entryPartial;
            $unpaidLeave += $entryUnpaid;

            $leaveTypeId = (int) $typeByCase[$caseId];
            $type = $types->get($leaveTypeId);
            $consumption[$leaveTypeId] ??= [
                'leave_type_id' => $leaveTypeId,
                'leave_type_code' => $type->code ?? 'UNKNOWN',
                'paid' => $entryUnpaid === 0,
                'minutes' => 0,
            ];
            $consumption[$leaveTypeId]['minutes'] += $effectiveMinutes;
        }

        $leaveConsumption = array_values($consumption);
        usort($leaveConsumption, static fn (array $a, array $b): int => $a['leave_type_id'] <=> $b['leave_type_id']);

        $hash = hash('sha256', implode('|', [
            $period,
            (string) $scheduled,
            (string) $worked,
            (string) $paidLeave,
            (string) $unpaidLeave,
            (string) $overtime,
            ...array_map(static fn (array $row): string => implode(':', [$row['leave_type_id'], $row['paid'] ? 'PAID' : 'UNPAID', $row['minutes']]), $leaveConsumption),
            ...$days->map(static fn (AttendanceDay $day): string => (string) $day->source_hash)->all(),
        ]));

        return new PayrollAttendanceExport(
            employmentId: $employmentId,
            period: $period,
            from: $start->value(),
            to: $end->value(),
            scheduledMinutes: $scheduled,
            workedMinutes: $worked,
            paidLeaveMinutes: $paidLeave,
            unpaidLeaveMinutes: $unpaidLeave,
            overtimeMinutes: $overtime,
            leaveConsumption: $leaveConsumption,
            sourceHash: $hash,
        );
    }

    /**
     * @return array{treatment: string, paid_ratio_scaled: int}|null
     */
    private function treatment(LeavePolicyVersion $policy): ?array
    {
        $column = is_string($policy->payroll_treatment) ? strtoupper(trim($policy->payroll_treatment)) : '';
        $legacy = (array) $policy->accrual_json;
        $treatment = $column !== '' ? $column : strtoupper((string) ($legacy['payroll_treatment'] ?? 'UNRESOLVED'));
        $ratio = $policy->paid_ratio_scaled !== null
            ? (int) $policy->paid_ratio_scaled
            : (int) ($legacy['paid_ratio_scaled'] ?? 0);

        return match ($treatment) {
            'PAID' => ['treatment' => 'PAID', 'paid_ratio_scaled' => 1_000_000],
            'UNPAID' => ['treatment' => 'UNPAID', 'paid_ratio_scaled' => 0],
            'PARTIAL' => $ratio >= 1 && $ratio <= 999_999
                ? ['treatment' => 'PARTIAL', 'paid_ratio_scaled' => $ratio]
                : null,
            default => null,
        };
    }

    /**
     * @param  array{treatment: string, paid_ratio_scaled: int}  $treatment
     * @return array{0: int, 1: int, 2: int}
     */
    private function split(int $minutes, array $treatment): array
    {
        $paid = match ($treatment['treatment']) {
            'PAID' => $minutes,
            'UNPAID' => 0,
            default => intdiv($minutes * $treatment['paid_ratio_scaled'], 1_000_000),
        };

        return [$paid, 0, $minutes - $paid];
    }

    public function hasRecordedAttendance(int $employmentId, string $period): bool
    {
        if (preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
            return false;
        }

        $start = BusinessDate::from($period.'-01');
        $end = BusinessDate::from(CarbonImmutable::parse($start->value(), 'UTC')->endOfMonth()->format('Y-m-d'));

        return AttendanceDay::query()
            ->where('employment_id', $employmentId)
            ->where('business_date', '>=', $start->value())
            ->where('business_date', '<=', $end->value())
            ->exists()
            || LeaveLedgerEntry::query()
                ->where('employment_id', $employmentId)
                ->where('account', 'CONSUME')
                ->where('effective_on', '>=', $start->value())
                ->where('effective_on', '<=', $end->value())
                ->exists();
    }
}
