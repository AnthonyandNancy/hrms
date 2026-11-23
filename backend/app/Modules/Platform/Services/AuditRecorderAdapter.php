<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Shared\Observability\AuditRecorder;

/**
 * Binds the business-facing audit port to the append-only audit table.
 */
final class AuditRecorderAdapter implements AuditRecorder
{
    public function __construct(private readonly AuditService $audit) {}

    public function record(
        int $tenantId,
        ?int $actorUserId,
        string $action,
        string $aggregateType,
        int|string $aggregateId,
        array $before = [],
        array $after = [],
        array $metadata = [],
    ): void {
        $this->audit->record(
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            action: $action,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            before: $before,
            after: $after,
            metadata: $metadata,
        );
    }
}
