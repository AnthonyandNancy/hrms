<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\PayrollPeriodLockReader;
use App\Modules\Time\Models\AttendancePeriodClosure;
use App\Shared\Exceptions\ConflictException;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Single closed-period gate for every Time fact mutation.
 *
 * The existing payroll freeze reader remains the Payroll-owned lock source.
 * Time closing is a separate Time-owned boundary: a month is writable only
 * when it has no CLOSED attendance-period closure. Reopening restores writes
 * because the closure status becomes REOPENED.
 *
 * A missing or failing reader fails closed, matching the existing freeze
 * guard: Time never assumes an unknown period is safe to modify.
 */
final class PayrollFreezeGuard
{
    public function __construct(private readonly Container $container) {}

    /**
     * @param  list<string>  $periods  Payroll periods (YYYY-MM) touched by the fact.
     */
    public function assertNotFrozen(int $employmentId, array $periods): void
    {
        $reader = $this->reader();
        $periods = array_values(array_unique($periods));

        foreach ($periods as $period) {
            if ($this->isClosed($period)) {
                throw new ConflictException(
                    '该考勤月份已关闭，请先重开并生成新事实版本后再修改。',
                    ['reason' => 'PERIOD_CLOSED', 'period' => $period],
                );
            }

            if ($reader === null) {
                throw new ConflictException(
                    '无法确认工资期间冻结状态，本次变更已被拒绝。',
                    ['reason' => 'LEAVE_PERIOD_FROZEN', 'failure' => 'PAYROLL_LOCK_READER_UNAVAILABLE', 'period' => $period],
                );
            }

            try {
                $frozen = $reader->isFrozen($employmentId, $period);
            } catch (Throwable $exception) {
                report($exception);

                throw new ConflictException(
                    '无法确认工资期间冻结状态，本次变更已被拒绝。',
                    ['reason' => 'LEAVE_PERIOD_FROZEN', 'failure' => 'PAYROLL_LOCK_READ_FAILED', 'period' => $period],
                );
            }

            if ($frozen) {
                throw new ConflictException(
                    '工资期间已冻结，本次变更已被拒绝。',
                    ['reason' => 'LEAVE_PERIOD_FROZEN', 'period' => $period],
                );
            }
        }
    }

    /**
     * Pure closing check used by operational entrypoints that do not require a
     * Payroll run lock (for example shift scheduling).
     *
     * @param  list<string>  $periods
     */
    public function assertPeriodsOpen(array $periods): void
    {
        foreach (array_values(array_unique($periods)) as $period) {
            if ($this->isClosed($period)) {
                throw new ConflictException(
                    '该考勤月份已关闭，请先重开并生成新事实版本后再修改。',
                    ['reason' => 'PERIOD_CLOSED', 'period' => $period],
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function periodsForRange(string $fromDate, string $toDate): array
    {
        $start = new \DateTimeImmutable($fromDate);
        $end = new \DateTimeImmutable($toDate);
        $periods = [];

        // Iterate on year/month integers. DateTime::modify('+1 month')
        // overflows Jan 31 to Mar 3 and silently skips February.
        $cursor = (int) $start->format('Y') * 12 + (int) $start->format('n') - 1;
        $last = (int) $end->format('Y') * 12 + (int) $end->format('n') - 1;

        while ($cursor <= $last) {
            $year = intdiv($cursor, 12);
            $month = ($cursor % 12) + 1;
            $periods[sprintf('%04d-%02d', $year, $month)] = true;
            $cursor++;
        }

        return array_keys($periods);
    }

    private function isClosed(string $period): bool
    {
        return AttendancePeriodClosure::query()
            ->where('month', $period)
            ->where('status', AttendancePeriodClosure::STATUS_CLOSED)
            ->exists();
    }

    private function reader(): ?PayrollPeriodLockReader
    {
        if (! $this->container->bound(PayrollPeriodLockReader::class)) {
            return null;
        }

        $reader = $this->container->make(PayrollPeriodLockReader::class);

        return $reader instanceof PayrollPeriodLockReader ? $reader : null;
    }
}
