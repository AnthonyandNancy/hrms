<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\PayrollAttendanceExport;
use App\Modules\Time\Contracts\PayrollInputDirectory;
use App\Modules\Time\Models\AttendanceDay;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeaveType;
use App\Shared\Time\BusinessDate;
use Carbon\CarbonImmutable;

final class PayrollInputDirectoryService implements PayrollInputDirectory
{
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
