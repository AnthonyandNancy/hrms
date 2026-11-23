<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

/**
 * Bounded attendance summary for the self-service workspace. It counts the
 * current business month only and is always scoped to one employment.
 */
interface AttendanceWorkspaceReader
{
    /**
     * @return array{month: string, summary: array{expectedDays: int, presentDays: int, lateCount: int, absentCount: int, leaveDays: int, remoteDays: int}, latestException: array{date: string, label: string}|null}
     */
    public function currentMonth(int $tenantId, int $employmentId, string $month): array;
}
