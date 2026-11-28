<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\PayrollPeriodLockReader;
use App\Modules\Time\Models\OvertimeRecord;
use App\Modules\Time\Models\OvertimeSettlement;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Overtime compensation is single-settlement: a record becomes either payroll
 * input or time-off credit, never both. The unique key makes the rule safe
 * under concurrent requests, not just sequential ones.
 *
 * PAY is additionally refused when the employment's target payroll period is
 * sealed. Freeze state is read through the Time-owned PayrollPeriodLockReader
 * contract; this service never imports Payroll models or tables. TIMEOFF is not
 * affected by a PAY freeze. A missing adapter fails closed for PAY.
 */
final class OvertimeSettlementService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly Container $container,
        private readonly PayrollFreezeGuard $freezeGuard,
        private readonly TimeoffLedgerService $timeoffLedger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function settle(int $recordId, string $method, string $reason, int $actorUserId): array
    {
        if (! in_array($method, ['PAY', 'TIMEOFF'], true)) {
            throw new ValidationException(['method' => ['Settlement method must be PAY or TIMEOFF.']], 'The given data was invalid.', ['reason' => 'INVALID_SETTLEMENT_METHOD']);
        }

        return DB::transaction(function () use ($recordId, $method, $reason, $actorUserId): array {
            $record = OvertimeRecord::query()
                ->whereKey($recordId)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                throw new NotFoundOrForbiddenException;
            }

            $existing = OvertimeSettlement::query()
                ->where('overtime_record_id', $recordId)
                ->first();

            if ($existing !== null) {
                if ((string) $existing->method === $method) {
                    return $this->payload($existing, true);
                }

                throw new ConflictException('This overtime record has already been settled by another method.', [
                    'reason' => 'ALREADY_SETTLED',
                    'settled_method' => (string) $existing->method,
                ]);
            }

            $period = $record->business_date->format('Y-m');
            $this->freezeGuard->lockAndAssertOpen($period);

            if ($method === 'PAY' && $this->isFrozen((int) $record->employment_id, $period)) {
                throw new ConflictException(
                    'The payroll period is frozen; this overtime record can only be settled as time off.',
                    [
                        'reason' => 'PAYROLL_PERIOD_FROZEN',
                        'period' => $period,
                    ],
                );
            }

            try {
                /** @var OvertimeSettlement $settlement */
                $settlement = OvertimeSettlement::query()->create([
                    'overtime_record_id' => $recordId,
                    'employment_id' => (int) $record->employment_id,
                    'method' => $method,
                    'minutes' => (int) $record->minutes,
                    'reason' => $reason,
                    'recorded_at' => $this->clock->now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $existing = OvertimeSettlement::query()
                    ->where('overtime_record_id', $recordId)
                    ->firstOrFail();

                if ((string) $existing->method !== $method) {
                    throw new ConflictException('This overtime record has already been settled by another method.', [
                        'reason' => 'ALREADY_SETTLED',
                        'settled_method' => (string) $existing->method,
                    ]);
                }

                return $this->payload($existing, true);
            }

            if ($method === 'TIMEOFF') {
                $entry = $this->timeoffLedger->earnFromSettlement($settlement, $record->business_date->format('Y-m-d'), $actorUserId);
                $settlement->forceFill(['leave_ledger_entry_id' => (int) $entry->id])->save();
            }

            $this->audit->record($actorUserId, 'time.overtime.settled', 'overtime_settlement', (int) $settlement->id, after: [
                'overtime_record_id' => $recordId,
                'method' => $method,
                'minutes' => (int) $settlement->minutes,
                'period' => $period,
                'payroll_period_frozen_checked' => $method === 'PAY',
            ]);

            return $this->payload($settlement, false);
        });
    }

    /**
     * Resolve the read-only freeze adapter. A missing or failing adapter fails
     * closed for PAY settlement: Time cannot prove the period is open.
     */
    private function isFrozen(int $employmentId, string $period): bool
    {
        if (! $this->container->bound(PayrollPeriodLockReader::class)) {
            return true;
        }

        try {
            $reader = $this->container->make(PayrollPeriodLockReader::class);

            if (! $reader instanceof PayrollPeriodLockReader) {
                return true;
            }

            return $reader->isFrozen($employmentId, $period);
        } catch (Throwable $exception) {
            report($exception);

            return true;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(OvertimeSettlement $settlement, bool $replayed): array
    {
        return [
            'settlement_id' => (int) $settlement->id,
            'overtime_record_id' => (int) $settlement->overtime_record_id,
            'employment_id' => (int) $settlement->employment_id,
            'method' => (string) $settlement->method,
            'minutes' => (int) $settlement->minutes,
            'timeoff_ledger_entry_id' => $settlement->leave_ledger_entry_id !== null ? (int) $settlement->leave_ledger_entry_id : null,
            'replayed' => $replayed,
        ];
    }
}
