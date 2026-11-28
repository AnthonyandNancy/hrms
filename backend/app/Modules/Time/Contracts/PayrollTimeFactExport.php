<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

/**
 * A closed Time payroll fact projection.
 *
 * It is distinct from the live `PayrollAttendanceExport` preview: Payroll may
 * only use this export after the period has a CLOSED fact version. Minutes are
 * integers and `facts` is canonical, deterministic source evidence.
 */
final readonly class PayrollTimeFactExport
{
    /**
     * @param  list<array{
     *   employment_id: int,
     *   business_date: string,
     *   range_start: string|null,
     *   range_end: string|null,
     *   scheduled_minutes: int,
     *   worked_minutes: int,
     *   paid_leave_minutes: int,
     *   partial_leave_minutes: int,
     *   unpaid_leave_minutes: int,
     *   overtime_pay_minutes: int,
     *   overtime_timeoff_minutes: int,
     *   travel_minutes: int,
     *   leave_type_id: int|null,
     *   leave_policy_version_id: int|null,
     *   overtime_category: string|null,
     *   travel_mode: string|null,
     *   source_type: string,
     *   source_id: string,
     *   source_revision: string|null,
     *   approval_status: string,
     *   source_hash: string
     * }>  $facts
     */
    public function __construct(
        public int $employmentId,
        public string $period,
        public string $from,
        public string $to,
        public int $factVersion,
        public int $factVersionId,
        public string $sourceHash,
        public int $exceptionCount,
        public int $scheduledMinutes,
        public int $workedMinutes,
        public int $paidLeaveMinutes,
        public int $partialLeaveMinutes,
        public int $unpaidLeaveMinutes,
        public int $overtimePayMinutes,
        public int $overtimeTimeoffMinutes,
        public int $travelMinutes,
        public array $facts,
    ) {}
}
