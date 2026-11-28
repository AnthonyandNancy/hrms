<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\PayrollPeriodLockReader;
use App\Modules\Time\Models\AttendancePeriodClosure;
use App\Modules\Time\Models\TimePeriodLock;
use App\Shared\Exceptions\ConflictException;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Single closed-period gate for every Time fact mutation.
 *
 * Every month has one durable `time_period_locks` row. A mutation and a close
 * must lock that row inside the same transaction as their write, which makes a
 * close and a concurrent write mutually exclusive on databases with row
 * locking (MySQL/InnoDB). SQLite serializes writers, so its regression test
 * verifies transaction rollback and closed/reopened mutex semantics while T19
 * owns true parallel MySQL verification.
 *
 * The payroll lock reader remains the Payroll-owned freeze source. A missing or
 * failing reader fails closed because Time cannot prove a period is safe.
 */
final class PayrollFreezeGuard
{
    public function __construct(private readonly Container $container) {}

    /**
     * Run one mutation inside the write transaction while holding the one
     * durable lock row for every touched month. The closure row is created on
     * first write so a later close and the write contend on the same record.
     *
     * @template T
     *
     * @param  list<string>  $periods
     * @param  Closure(): T  $operation
     * @return T
     */
    public function withOpenPeriods(array $periods, Closure $operation): mixed
    {
        $periods = array_values(array_unique($periods));

        return DB::transaction(function () use ($periods, $operation): mixed {
            foreach ($periods as $period) {
                $this->lockAndAssertOpen($period);
            }

            return $operation();
        });
    }

    /**
     * Acquire and check the lock row for a month without starting an outer
     * transaction. Callers must already be inside their write transaction.
     */
    public function lockAndAssertOpen(string $period): void
    {
        $this->lockPeriod($period);

        $closed = AttendancePeriodClosure::query()
            ->where('month', $period)
            ->where('status', AttendancePeriodClosure::STATUS_CLOSED)
            ->exists();

        if ($closed) {
            throw new ConflictException(
                '该考勤月份已关闭，请先重开并生成新事实版本后再修改。',
                ['reason' => 'PERIOD_CLOSED', 'period' => $period],
            );
        }
    }

    /**
     * Lock the durable month mutex. Must be called inside a write transaction.
     */
    public function lockPeriod(string $period): TimePeriodLock
    {
        $lock = TimePeriodLock::query()
            ->where('month', $period)
            ->lockForUpdate()
            ->first();

        if ($lock !== null) {
            return $lock;
        }

        try {
            return TimePeriodLock::query()->create([
                'month' => $period,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return TimePeriodLock::query()
                ->where('month', $period)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    /**
     * @param  list<string>  $periods  Payroll periods (YYYY-MM) touched by the fact.
     */
    public function assertNotFrozen(int $employmentId, array $periods): void
    {
        $this->assertNotFrozenLocked($employmentId, array_values(array_unique($periods)));
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

    /**
     * @param  list<string>  $periods
     */
    private function assertNotFrozenLocked(int $employmentId, array $periods): void
    {
        $reader = $this->reader();

        foreach ($periods as $period) {
            $this->lockAndAssertOpen($period);

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

    private function reader(): ?PayrollPeriodLockReader
    {
        if (! $this->container->bound(PayrollPeriodLockReader::class)) {
            return null;
        }

        $reader = $this->container->make(PayrollPeriodLockReader::class);

        return $reader instanceof PayrollPeriodLockReader ? $reader : null;
    }
}
