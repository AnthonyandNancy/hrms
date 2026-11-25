<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Talent\Models\Requisition;
use App\Modules\Talent\Models\RequisitionOpening;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;

/**
 * Only an APPROVED requisition may publish a hiring opening. Duplicate
 * channel openings are idempotent rather than silently duplicated.
 */
final class RequisitionOpeningService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function open(int $tenantId, int $requisitionId, string $channel, int $actorUserId): array
    {
        $requisition = Requisition::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($requisitionId)
            ->first();

        if ($requisition === null) {
            throw new NotFoundOrForbiddenException;
        }

        if ((string) $requisition->status !== 'APPROVED') {
            throw new ConflictException('A requisition must be approved before it can be opened.', [
                'reason' => 'REQUISITION_NOT_APPROVED',
                'status' => (string) $requisition->status,
            ]);
        }

        $opening = RequisitionOpening::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'requisition_id' => $requisitionId, 'channel' => $channel],
            ['status' => 'OPEN', 'published_at' => $this->clock->now(), 'recorded_at' => $this->clock->now()],
        );
        $created = $opening->wasRecentlyCreated;

        if ($created) {
            $this->audit->record($tenantId, $actorUserId, 'talent.requisition_opening.published', 'requisition_opening', (int) $opening->id, after: [
                'requisition_id' => $requisitionId,
                'channel' => $channel,
            ]);
        }

        return [
            'opening_id' => (int) $opening->id,
            'requisition_id' => $requisitionId,
            'channel' => (string) $opening->channel,
            'status' => (string) $opening->status,
            'published_at' => $opening->published_at?->toIso8601ZuluString(),
            'replayed' => ! $created,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, int $requisitionId): array
    {
        return RequisitionOpening::query()
            ->where('tenant_id', $tenantId)
            ->where('requisition_id', $requisitionId)
            ->orderBy('id')
            ->get()
            ->map(static fn (RequisitionOpening $opening): array => [
                'opening_id' => (int) $opening->id,
                'requisition_id' => (int) $opening->requisition_id,
                'channel' => (string) $opening->channel,
                'status' => (string) $opening->status,
                'published_at' => $opening->published_at?->toIso8601ZuluString(),
            ])->all();
    }
}
