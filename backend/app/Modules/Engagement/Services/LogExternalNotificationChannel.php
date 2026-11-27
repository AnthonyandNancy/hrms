<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\Engagement\Contracts\ExternalNotificationChannel;
use Illuminate\Support\Facades\Log;

/**
 * Reference external-channel adapter. It logs only identifiers and a type;
 * replacing it with a mail/push adapter does not change the domain service.
 */
final class LogExternalNotificationChannel implements ExternalNotificationChannel
{
    public function notify(string $type, array $userIds, array $payload): void
    {
        Log::info('engagement.external_notification_stub', [
            'type' => $type,
            'user_ids' => array_values($userIds),
            'payload_keys' => array_keys($payload),
        ]);
    }
}
