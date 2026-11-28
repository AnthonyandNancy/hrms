<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use InvalidArgumentException;

/**
 * Minutes supplied by the Time module in an immutable payroll input snapshot.
 * Worked minutes exclude paid leave, unpaid leave, and overtime.
 */
final readonly class AttendanceSummary
{
    /** 21.75 standard days * 8 hours * 60 minutes. */
    public const STANDARD_MONTH_MINUTES = 10440;

    public function __construct(
        public int $scheduledMinutes,
        public int $workedMinutes,
        public int $paidLeaveMinutes,
        public int $unpaidLeaveMinutes,
        public int $overtimeMinutes,
        public int $travelMinutes = 0,
    ) {
        foreach ([
            'scheduled' => $scheduledMinutes,
            'worked' => $workedMinutes,
            'paid leave' => $paidLeaveMinutes,
            'unpaid leave' => $unpaidLeaveMinutes,
            'overtime' => $overtimeMinutes,
            'travel' => $travelMinutes,
        ] as $label => $minutes) {
            if ($minutes < 0) {
                throw new InvalidArgumentException("Attendance {$label} minutes cannot be negative.");
            }
        }

        if ($scheduledMinutes > 0 && $workedMinutes + $paidLeaveMinutes + $unpaidLeaveMinutes + $travelMinutes > $scheduledMinutes) {
            throw new InvalidArgumentException('Worked, paid leave, and unpaid leave minutes cannot exceed scheduled minutes.');
        }
    }

    public function paidMinutes(): int
    {
        return $this->workedMinutes + $this->paidLeaveMinutes + $this->travelMinutes;
    }

    public function unworkedMinutes(): int
    {
        return max(0, $this->scheduledMinutes - $this->paidMinutes());
    }
}
