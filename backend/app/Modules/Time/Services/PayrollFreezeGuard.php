<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\PayrollPeriodLockReader;
use App\Shared\Exceptions\ConflictException;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Fail-closed payroll freeze guard. Payroll owns its tables and supplies the
 * read-only adapter; Time never queries payroll_runs/payroll_run_employees.
 *
 * When the contract is not bound (for example before the Payroll integration
 * lands) or the adapter throws, revoke/cancel of an applied leave is rejected
 * with LEAVE_PERIOD_FROZEN rather than silently changing a frozen input.
 */
final class PayrollFreezeGuard
{
    public function __construct(private readonly Container $container) {}

    /**
     * @param  list<string>  $periods  Payroll periods (YYYY-MM) touched by the leave.
     */
    public function assertNotFrozen(int $employmentId, array $periods): void
    {
        $reader = $this->reader();

        if ($reader === null) {
            throw new ConflictException(
                'The payroll freeze status cannot be verified, so this leave cannot be revoked.',
                ['reason' => 'LEAVE_PERIOD_FROZEN', 'failure' => 'PAYROLL_LOCK_READER_UNAVAILABLE'],
            );
        }

        foreach (array_values(array_unique($periods)) as $period) {
            try {
                $frozen = $reader->isFrozen($employmentId, $period);
            } catch (Throwable $exception) {
                report($exception);

                throw new ConflictException(
                    'The payroll freeze status cannot be verified, so this leave cannot be revoked.',
                    ['reason' => 'LEAVE_PERIOD_FROZEN', 'failure' => 'PAYROLL_LOCK_READ_FAILED', 'period' => $period],
                );
            }

            if ($frozen) {
                throw new ConflictException(
                    'The payroll period is frozen; approved leave cannot be revoked.',
                    ['reason' => 'LEAVE_PERIOD_FROZEN', 'period' => $period],
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

        while ($start <= $end) {
            $periods[(string) $start->format('Y-m')] = true;
            $start = $start->modify('+1 month')->modify('first day of this month');
        }

        return array_keys($periods);
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
