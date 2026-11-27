<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Models\OvertimeRecord;
use App\Modules\Time\Models\OvertimeRequest;
use App\Modules\Time\Models\OvertimeSettlement;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\Clock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Overtime application and confirmation are deliberately two commands:
 *
 *  - apply() stores the employee declaration in `declared_minutes` only;
 *  - confirm() requires an APPROVED request, stores the actual minutes in
 *    `confirmed_minutes`, and materialises exactly one `overtime_records` row.
 *
 * The record is then the only compensation key. `overtime_settlements` has a
 * unique `overtime_record_id`, so PAY and TIMEOFF can never both happen even
 * under a race. Time-off balances are always derived from TIMEOFF settlements;
 * no balance row is written or cached here.
 */
final class OvertimeRequestService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    public function apply(
        int $employmentId,
        string $businessDate,
        int $declaredMinutes,
        string $reason,
        int $actorUserId,
    ): OvertimeRequest {
        if ($declaredMinutes < 1) {
            throw new ConflictException('Declared overtime minutes must be positive.', [
                'reason' => 'OVERTIME_REQUEST_INVALID_MINUTES',
            ]);
        }

        return DB::transaction(function () use ($employmentId, $businessDate, $declaredMinutes, $reason, $actorUserId): OvertimeRequest {
            $duplicate = OvertimeRequest::query()
                ->where('employment_id', $employmentId)
                ->where('business_date', $businessDate)
                ->lockForUpdate()
                ->first();

            if ($duplicate !== null) {
                throw new ConflictException('An overtime request already exists for this employee and date.', [
                    'reason' => 'OVERTIME_REQUEST_DUPLICATE',
                    'overtime_request_id' => (int) $duplicate->id,
                ]);
            }

            try {
                /** @var OvertimeRequest $request */
                $request = OvertimeRequest::query()->create([
                    'employment_id' => $employmentId,
                    'business_date' => $businessDate,
                    'declared_minutes' => $declaredMinutes,
                    'confirmed_minutes' => null,
                    'status' => OvertimeRequest::STATUS_APPROVED,
                    'reason' => $reason,
                    'case_id' => null,
                    'overtime_record_id' => null,
                    'recorded_at' => $this->clock->now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new ConflictException('An overtime request already exists for this employee and date.', [
                    'reason' => 'OVERTIME_REQUEST_DUPLICATE',
                ]);
            }

            $this->audit->record($actorUserId, 'time.overtime.requested', 'overtime_request', (int) $request->id, after: [
                'employment_id' => $employmentId,
                'business_date' => $businessDate,
                'declared_minutes' => $declaredMinutes,
            ]);

            return $request;
        });
    }

    /**
     * Move APPROVED -> CONFIRMED exactly once and materialise the overtime
     * record used by the settlement command. A second confirmation is a state
     * conflict even with a fresh idempotency key.
     */
    public function confirm(
        int $overtimeRequestId,
        int $confirmedMinutes,
        int $actorUserId,
    ): OvertimeRequest {
        if ($confirmedMinutes < 1) {
            throw new ConflictException('Confirmed overtime minutes must be positive.', [
                'reason' => 'OVERTIME_REQUEST_INVALID_MINUTES',
            ]);
        }

        return DB::transaction(function () use ($overtimeRequestId, $confirmedMinutes, $actorUserId): OvertimeRequest {
            $request = OvertimeRequest::query()
                ->whereKey($overtimeRequestId)
                ->lockForUpdate()
                ->first();

            if ($request === null) {
                throw new NotFoundOrForbiddenException;
            }

            if ((string) $request->status === OvertimeRequest::STATUS_CONFIRMED) {
                throw new ConflictException('This overtime request has already been confirmed.', [
                    'reason' => 'ALREADY_CONFIRMED',
                    'confirmed_minutes' => (int) $request->confirmed_minutes,
                    'overtime_record_id' => $request->overtime_record_id !== null ? (int) $request->overtime_record_id : null,
                ]);
            }

            if ((string) $request->status !== OvertimeRequest::STATUS_APPROVED) {
                throw new ConflictException('Only an approved overtime request can be confirmed.', [
                    'reason' => 'OVERTIME_REQUEST_NOT_APPROVED',
                    'status' => (string) $request->status,
                ]);
            }

            $businessDate = $request->business_date->format('Y-m-d');

            /** @var OvertimeRecord $record */
            $record = OvertimeRecord::query()->create([
                'employment_id' => (int) $request->employment_id,
                'business_date' => $businessDate,
                'minutes' => $confirmedMinutes,
                'status' => 'APPROVED',
                'case_id' => $request->case_id !== null ? (int) $request->case_id : null,
                'created_at' => $this->clock->now(),
            ]);

            $request->forceFill([
                'confirmed_minutes' => $confirmedMinutes,
                'status' => OvertimeRequest::STATUS_CONFIRMED,
                'overtime_record_id' => (int) $record->id,
                'updated_at' => $this->clock->now(),
            ])->save();

            $this->audit->record(
                $actorUserId,
                'time.overtime.confirmed',
                'overtime_request',
                (int) $request->id,
                before: [
                    'status' => OvertimeRequest::STATUS_APPROVED,
                    'confirmed_minutes' => null,
                ],
                after: [
                    'status' => OvertimeRequest::STATUS_CONFIRMED,
                    'declared_minutes' => (int) $request->declared_minutes,
                    'confirmed_minutes' => $confirmedMinutes,
                    'overtime_record_id' => (int) $record->id,
                ],
            );

            return $request->refresh();
        });
    }

    public function find(int $overtimeRequestId): ?OvertimeRequest
    {
        return OvertimeRequest::query()
            ->whereKey($overtimeRequestId)
            ->first();
    }

    public function project(
        int $employmentId,
        string $asOf,
        ?string $status,
        int $page,
        int $pageSize,
    ): ProjectionResult {
        $query = OvertimeRequest::query()
            ->where('employment_id', $employmentId)
            ->where('business_date', '<=', $asOf);

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $items = $query
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->forPage($page, $pageSize)
            ->get()
            ->map(fn (OvertimeRequest $request): array => $this->payload($request))
            ->all();

        return new ProjectionResult(
            items: $items,
            page: $page,
            pageSize: $pageSize,
            total: $total,
            asOf: $asOf,
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: [
                'resource' => 'overtime_requests',
                'filters' => [
                    'employment_id' => $employmentId,
                    'status' => $status,
                ],
            ],
        );
    }

    /**
     * Time-off balance is a projection over TIMEOFF settlements only. No row
     * is written to a balance table, and PAY settlements never contribute.
     */
    public function timeoffBalances(int $employmentId, string $asOf): ProjectionResult
    {
        $records = OvertimeRecord::query()
            ->where('employment_id', $employmentId)
            ->where('business_date', '<=', $asOf)
            ->get()
            ->keyBy('id');

        $settlements = OvertimeSettlement::query()
            ->where('employment_id', $employmentId)
            ->where('method', 'TIMEOFF')
            ->whereIn('overtime_record_id', $records->keys()->all())
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get();

        /** @var list<array<string, mixed>> $items */
        $items = [];

        /** @var OvertimeSettlement $settlement */
        foreach ($settlements as $settlement) {
            $record = $records->get((int) $settlement->overtime_record_id);

            $items[] = [
                'settlement_id' => (int) $settlement->id,
                'overtime_record_id' => (int) $settlement->overtime_record_id,
                'employment_id' => (int) $settlement->employment_id,
                'business_date' => $record instanceof OvertimeRecord ? $record->business_date->format('Y-m-d') : null,
                'method' => (string) $settlement->method,
                'minutes' => (int) $settlement->minutes,
                'reason' => (string) $settlement->reason,
                'recorded_at' => $settlement->recorded_at?->toIso8601String(),
            ];
        }

        $available = array_reduce(
            $items,
            static fn (int $total, array $item): int => $total + (int) $item['minutes'],
            0,
        );

        return new ProjectionResult(
            items: $items,
            page: 1,
            pageSize: 100,
            total: count($items),
            asOf: $asOf,
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: [
                'resource' => 'overtime_settlements',
                'filters' => ['employment_id' => $employmentId, 'method' => 'TIMEOFF'],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(OvertimeRequest $request): array
    {
        return [
            'id' => (int) $request->id,
            'employment_id' => (int) $request->employment_id,
            'business_date' => $request->business_date->format('Y-m-d'),
            'declared_minutes' => (int) $request->declared_minutes,
            'confirmed_minutes' => $request->confirmed_minutes !== null ? (int) $request->confirmed_minutes : null,
            'status' => (string) $request->status,
            'reason' => (string) $request->reason,
            'case_id' => $request->case_id !== null ? (int) $request->case_id : null,
            'overtime_record_id' => $request->overtime_record_id !== null ? (int) $request->overtime_record_id : null,
            'recorded_at' => $request->recorded_at?->toIso8601String(),
        ];
    }
}
