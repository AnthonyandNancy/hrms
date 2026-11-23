<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

/**
 * Time-owned payroll input projection. Amounts are integer minutes and the
 * payload is deterministic so payroll can hash it.
 */
final readonly class PayrollAttendanceExport
{
    /**
     * @param  array<int, array{leave_type_id: int, leave_type_code: string, paid: bool, minutes: int}>  $leaveConsumption
     */
    public function __construct(
        public int $employmentId,
        public string $period,
        public string $from,
        public string $to,
        public int $scheduledMinutes,
        public int $workedMinutes,
        public int $paidLeaveMinutes,
        public int $unpaidLeaveMinutes,
        public int $overtimeMinutes,
        public array $leaveConsumption,
        public string $sourceHash,
    ) {}
}
