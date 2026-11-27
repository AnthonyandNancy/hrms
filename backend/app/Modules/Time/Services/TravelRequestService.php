<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Models\BusinessTripRecord;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;

/**
 * Employee travel request surface. Scope is always SELF at the HTTP boundary;
 * the service additionally filters by employment so an id from
 * another employee is indistinguishable from a missing row.
 */
final class TravelRequestService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function open(int $employmentId, array $input, int $actorUserId): array
    {
        $overlap = BusinessTripRecord::query()
            ->where('employment_id', $employmentId)
            ->whereIn('status', ['PENDING_APPROVAL', 'APPROVED'])
            ->where('from_date', '<=', (string) $input['to_date'])
            ->where('to_date', '>=', (string) $input['from_date'])
            ->exists();

        if ($overlap) {
            throw new ConflictException('An active travel request overlaps this period.', ['reason' => 'TRAVEL_OVERLAP']);
        }

        /** @var BusinessTripRecord $record */
        $record = BusinessTripRecord::query()->create([
            'employment_id' => $employmentId,
            'from_date' => (string) $input['from_date'],
            'to_date' => (string) $input['to_date'],
            'destination' => (string) $input['destination'],
            'purpose' => (string) $input['purpose'],
            'status' => 'PENDING_APPROVAL',
            'created_at' => $this->clock->now(),
        ]);

        $this->audit->record($actorUserId, 'time.travel.requested', 'business_trip', (int) $record->id, after: [
            'from_date' => (string) $record->from_date,
            'to_date' => (string) $record->to_date,
        ]);

        return $this->payload($record);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $employmentId): array
    {
        return BusinessTripRecord::query()
            ->where('employment_id', $employmentId)
            ->orderByDesc('from_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (BusinessTripRecord $record): array => $this->payload($record))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(int $employmentId, int $id, string $reason, int $actorUserId): array
    {
        $record = BusinessTripRecord::query()
            ->where('employment_id', $employmentId)
            ->whereKey($id)
            ->first();

        if ($record === null) {
            throw new NotFoundOrForbiddenException;
        }

        if (in_array((string) $record->status, ['CANCELLED', 'COMPLETED'], true)) {
            throw new ConflictException('This travel request cannot be cancelled in its current state.', [
                'reason' => 'TRAVEL_NOT_CANCELLABLE',
                'status' => (string) $record->status,
            ]);
        }

        $record->forceFill([
            'status' => 'CANCELLED',
            'cancel_reason' => $reason,
            'cancelled_at' => $this->clock->now(),
        ])->save();

        $this->audit->record($actorUserId, 'time.travel.cancelled', 'business_trip', (int) $record->id, after: [
            'reason' => $reason,
        ]);

        return $this->payload($record);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(BusinessTripRecord $record): array
    {
        return [
            'travel_id' => (int) $record->id,
            'employment_id' => (int) $record->employment_id,
            'from_date' => (string) $record->from_date,
            'to_date' => (string) $record->to_date,
            'destination' => $record->destination,
            'purpose' => $record->purpose,
            'status' => (string) $record->status,
        ];
    }
}
