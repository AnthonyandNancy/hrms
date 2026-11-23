<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\AttendanceWorkspaceReader;
use App\Modules\Time\Models\AttendanceDay;
use Carbon\CarbonImmutable;

/**
 * Workspace attendance summary. It reports only recorded projection rows and
 * never infers presenteeism from absent rows, so a day without evidence is not
 * silently counted as attendance.
 */
final class AttendanceWorkspaceReaderService implements AttendanceWorkspaceReader
{
    /**
     * @return array{month: string, summary: array{expectedDays: int, presentDays: int, lateCount: int, absentCount: int, leaveDays: int, remoteDays: int}, latestException: array{date: string, label: string}|null}
     */
    public function currentMonth(int $tenantId, int $employmentId, string $month): array
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return [
                'month' => $month,
                'summary' => $this->emptySummary(),
                'latestException' => null,
            ];
        }

        $start = CarbonImmutable::parse($month.'-01', 'UTC')->startOfMonth();
        $end = $start->endOfMonth();

        $rows = AttendanceDay::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->where('business_date', '>=', $start->format('Y-m-d'))
            ->where('business_date', '<=', $end->format('Y-m-d'))
            ->orderBy('business_date')
            ->get();

        $summary = $this->emptySummary();
        $latestException = null;

        foreach ($rows as $row) {
            $summary['expectedDays'] += (int) ($row->scheduled_minutes > 0 ? 1 : 0);

            if (in_array((string) $row->status, ['NORMAL', 'PRESENT', 'LATE', 'EARLY', 'INCOMPLETE'], true)) {
                $summary['presentDays']++;
            }

            if ((string) $row->status === 'LATE' || (int) $row->late_minutes > 0) {
                $summary['lateCount']++;
                $latestException = [
                    'date' => $row->business_date->format('Y-m-d'),
                    'label' => '迟到 '.$row->late_minutes.' 分钟',
                ];
            }

            if ((string) $row->status === 'ABSENT') {
                $summary['absentCount']++;
                $latestException = [
                    'date' => $row->business_date->format('Y-m-d'),
                    'label' => '缺勤',
                ];
            }

            if ((string) $row->status === 'LEAVE') {
                $summary['leaveDays']++;
            }

            if ((string) $row->status === 'REMOTE') {
                $summary['remoteDays']++;
            }
        }

        return [
            'month' => $start->format('Y-m'),
            'summary' => $summary,
            'latestException' => $latestException,
        ];
    }

    /**
     * @return array{expectedDays: int, presentDays: int, lateCount: int, absentCount: int, leaveDays: int, remoteDays: int}
     */
    private function emptySummary(): array
    {
        return [
            'expectedDays' => 0,
            'presentDays' => 0,
            'lateCount' => 0,
            'absentCount' => 0,
            'leaveDays' => 0,
            'remoteDays' => 0,
        ];
    }
}
