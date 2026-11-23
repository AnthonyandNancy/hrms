<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Contracts\OutboxDispatcher;
use App\Modules\Platform\Models\OutboxEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reference dispatcher: logs events without external side effects. A real
 * broker adapter replaces this binding without changing module code.
 */
final class LogOutboxDispatcher implements OutboxDispatcher
{
    public function dispatch(string $eventId): bool
    {
        $event = OutboxEvent::query()->find($eventId);

        if ($event === null) {
            return false;
        }

        try {
            Log::info('outbox.dispatch', [
                'event_id' => $event->id,
                'event_type' => $event->event_type,
                'aggregate_type' => $event->aggregate_type,
                'aggregate_id' => $event->aggregate_id,
                'aggregate_version' => $event->aggregate_version,
            ]);
            $event->forceFill([
                'published_at' => now('UTC'),
                'attempts' => $event->attempts + 1,
            ])->save();

            return true;
        } catch (Throwable $exception) {
            $event->forceFill([
                'attempts' => $event->attempts + 1,
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();

            return false;
        }
    }
}
