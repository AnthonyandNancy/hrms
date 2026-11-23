<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

/**
 * Transport-neutral outbox dispatcher. Implementations publish pending
 * events; modules only call OutboxService::record() inside their transaction.
 */
interface OutboxDispatcher
{
    /**
     * Publish one pending event. Returning true marks it published.
     */
    public function dispatch(string $eventId): bool;
}
