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
    public function notify(int $tenantId, string $type, array $userIds, array $payload): void
    {
        Log::info('engagement.external_notification_stub', [
            'tenant_id' => $tenantId,
            'type' => $type,
            'user_ids' => array_values($userIds),
            'payload_keys' => array_keys($payload),
        ]);
    }
}
