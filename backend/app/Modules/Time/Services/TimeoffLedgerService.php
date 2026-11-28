<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Models\OvertimeSettlement;
use App\Modules\Time\Models\TimeoffLedgerEntry;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Append-only time-off ledger. A balance is always SUM(quantity_minutes), never
 * a cached number. Positive entries add credit; USE/REVERSE/EXPIRE entries are
 * negative and ADJUST may carry either sign.
 */
final class TimeoffLedgerService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    public function earnFromSettlement(
        OvertimeSettlement $settlement,
        string $effectiveOn,
        int $actorUserId,
    ): TimeoffLedgerEntry {
        if ((string) $settlement->method !== 'TIMEOFF') {
            throw new ValidationException(['settlement' => ['Only a TIMEOFF settlement can earn time-off credit.']]);
        }
        if ((int) $settlement->minutes <= 0) {
            throw new ValidationException(['minutes' => ['Time-off credit must be positive.']]);
        }

        return DB::transaction(function () use ($settlement, $effectiveOn, $actorUserId): TimeoffLedgerEntry {
            $existing = TimeoffLedgerEntry::query()
                ->where('overtime_settlement_id', (int) $settlement->id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            /** @var TimeoffLedgerEntry $entry */
            $entry = TimeoffLedgerEntry::query()->create([
                'employment_id' => (int) $settlement->employment_id,
                'overtime_settlement_id' => (int) $settlement->id,
                'entry_type' => TimeoffLedgerEntry::TYPE_EARN,
                'quantity_minutes' => (int) $settlement->minutes,
                'effective_on' => $effectiveOn,
                'reason' => (string) $settlement->reason,
                'created_by' => $actorUserId,
                'recorded_at' => $this->clock->now(),
                'created_at' => $this->clock->now(),
                'updated_at' => $this->clock->now(),
            ]);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'time.timeoff.earned',
                aggregateType: 'timeoff_ledger_entry',
                aggregateId: (int) $entry->id,
                after: [
                    'employment_id' => (int) $entry->employment_id,
                    'overtime_settlement_id' => (int) $settlement->id,
                    'minutes' => (int) $entry->quantity_minutes,
                    'effective_on' => $effectiveOn,
                ],
            );

            return $entry;
        });
    }

    /**
     * @return list<TimeoffLedgerEntry>
     */
    public function entries(int $employmentId, string $asOf): array
    {
        return TimeoffLedgerEntry::query()
            ->where('employment_id', $employmentId)
            ->where('effective_on', '<=', $asOf)
            ->orderByDesc('effective_on')
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    public function balance(int $employmentId, string $asOf): int
    {
        return (int) TimeoffLedgerEntry::query()
            ->where('employment_id', $employmentId)
            ->where('effective_on', '<=', $asOf)
            ->sum('quantity_minutes');
    }
}
