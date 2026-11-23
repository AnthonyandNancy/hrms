<?php

declare(strict_types=1);

namespace App\Shared\Observability;

/**
 * No-op recorder for isolated unit tests. Production always binds the
 * append-only Platform implementation.
 */
final class NullAuditRecorder implements AuditRecorder
{
    public function record(
        int $tenantId,
        ?int $actorUserId,
        string $action,
        string $aggregateType,
        int|string $aggregateId,
        array $before = [],
        array $after = [],
        array $metadata = [],
    ): void {}
}
