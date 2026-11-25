<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Models\OvertimeRecord;
use App\Modules\Time\Models\OvertimeSettlement;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Overtime compensation is single-settlement: a record becomes either payroll
 * input or time-off credit, never both. The unique key makes the rule safe
 * under concurrent requests, not just sequential ones.
 */
final class OvertimeSettlementService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function settle(int $tenantId, int $recordId, string $method, string $reason, int $actorUserId): array
    {
        if (! in_array($method, ['PAY', 'TIMEOFF'], true)) {
            throw new ValidationException(['method' => ['Settlement method must be PAY or TIMEOFF.']], 'The given data was invalid.', ['reason' => 'INVALID_SETTLEMENT_METHOD']);
        }

        return DB::transaction(function () use ($tenantId, $recordId, $method, $reason, $actorUserId): array {
            $record = OvertimeRecord::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($recordId)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                throw new NotFoundOrForbiddenException;
            }

            $existing = OvertimeSettlement::query()
                ->where('tenant_id', $tenantId)
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

            try {
                /** @var OvertimeSettlement $settlement */
                $settlement = OvertimeSettlement::query()->create([
                    'tenant_id' => $tenantId,
                    'overtime_record_id' => $recordId,
                    'employment_id' => (int) $record->employment_id,
                    'method' => $method,
                    'minutes' => (int) $record->minutes,
                    'reason' => $reason,
                    'recorded_at' => $this->clock->now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $existing = OvertimeSettlement::query()
                    ->where('tenant_id', $tenantId)
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

            $this->audit->record($tenantId, $actorUserId, 'time.overtime.settled', 'overtime_settlement', (int) $settlement->id, after: [
                'overtime_record_id' => $recordId,
                'method' => $method,
                'minutes' => (int) $settlement->minutes,
            ]);

            return $this->payload($settlement, false);
        });
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
            'replayed' => $replayed,
        ];
    }
}
