<?php

declare(strict_types=1);

namespace App\Shared\Observability;

/**
 * Cross-cutting integration-event port. Payloads carry IDs and versions only;
 * the Platform implementation enforces the prohibited-key allowlist.
 */
interface OutboxRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        int $aggregateVersion,
        array $payload,
    ): void;
}
