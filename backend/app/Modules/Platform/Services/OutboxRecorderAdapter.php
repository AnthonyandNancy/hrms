<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Shared\Observability\OutboxRecorder;

/**
 * Binds the business-facing outbox port to the transactional outbox service.
 */
final class OutboxRecorderAdapter implements OutboxRecorder
{
    public function __construct(private readonly OutboxService $outbox) {}

    public function record(
        int $tenantId,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        int $aggregateVersion,
        array $payload,
    ): void {
        $this->outbox->record(
            tenantId: $tenantId,
            eventType: $eventType,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            aggregateVersion: $aggregateVersion,
            payload: $payload,
        );
    }
}
