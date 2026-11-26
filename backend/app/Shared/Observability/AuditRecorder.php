<?php

declare(strict_types=1);

namespace App\Shared\Observability;

/**
 * Cross-cutting audit port. Every business module records evidence through
 * this contract; the append-only Platform implementation is bound at boot.
 */
interface AuditRecorder
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        ?int $actorUserId,
        string $action,
        string $aggregateType,
        int|string $aggregateId,
        array $before = [],
        array $after = [],
        array $metadata = [],
    ): void;
}
