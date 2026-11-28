<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\PayrollAttendanceExport;
use App\Modules\Time\Contracts\PayrollInputDirectory;
use App\Modules\Time\Contracts\PayrollTimeFactExport;
use App\Modules\Time\Models\AttendanceDay;
use App\Modules\Time\Models\AttendancePeriodClosure;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeaveType;
use App\Modules\Time\Models\TimePayrollFactRow;
use App\Modules\Time\Models\TimePayrollFactVersion;
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

        // Leave consumption is read from the append-only ledger for the period.
        // Paid vs unpaid is a property of the leave type, not the account.
        $leaveRows = LeaveLedgerEntry::query()
            ->where('employment_id', $employmentId)
            ->where('account', 'CONSUME')
            ->where('effective_on', '>=', $start->value())
            ->where('effective_on', '<=', $end->value())
            ->orderBy('id')
            ->get();

        $paidLeave = 0;
        $unpaidLeave = 0;
        $consumption = [];

        $types = LeaveType::query()
            ->whereIn('id', $leaveRows->pluck('leave_type_id')->unique()->all())
            ->get()
            ->keyBy('id');

        foreach ($leaveRows as $entry) {
            $minutes = abs((int) $entry->quantity_minutes);
            $type = $types->get((int) $entry->leave_type_id);
            $paid = ! $this->isUnpaidCode($type?->code);

            if ($paid) {
                $paidLeave += $minutes;
            } else {
                $unpaidLeave += $minutes;
            }

            $consumption[(int) $entry->leave_type_id] = [
                'leave_type_id' => (int) $entry->leave_type_id,
                'leave_type_code' => $type->code ?? 'UNKNOWN',
                'paid' => $paid,
                'minutes' => ($consumption[(int) $entry->leave_type_id]['minutes'] ?? 0) + $minutes,
            ];
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

    private function isUnpaidCode(?string $code): bool
    {
        return $code !== null && in_array(strtoupper($code), ['UNPAID', 'UNPAID_LEAVE', 'NO_PAY'], true);
    }
}
