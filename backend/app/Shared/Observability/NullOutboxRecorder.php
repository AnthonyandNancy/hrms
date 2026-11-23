<?php

declare(strict_types=1);

namespace App\Shared\Observability;

final class NullOutboxRecorder implements OutboxRecorder
{
    public function record(
        int $tenantId,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        int $aggregateVersion,
        array $payload,
    ): void {}
}
