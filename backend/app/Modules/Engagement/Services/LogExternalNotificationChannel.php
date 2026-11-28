<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\Engagement\Contracts\ExternalNotificationChannel;
use App\Shared\Notifications\NotificationReceipt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reference external-channel adapter. It logs only identifiers and a type and
 * synthesises a provider receipt; replacing it with a mail/push adapter does
 * not change the domain service.
 */
final class LogExternalNotificationChannel implements ExternalNotificationChannel
{
    public function channel(): string
    {
        return 'LOG_STUB';
    }

    public function send(string $type, int $userId, array $payload): NotificationReceipt
    {
        $receipt = 'log-'.Str::uuid()->toString();

        Log::info('engagement.external_notification_stub', [
            'type' => $type,
            'user_id' => $userId,
            'payload_keys' => array_keys($payload),
            'provider_message_id' => $receipt,
        ]);

        return NotificationReceipt::delivered($receipt);
    }
}
