<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\Engagement\Contracts\ExternalNotificationChannel;
use App\Shared\Notifications\NotificationReceipt;

/**
 * Production/unknown-environment default. There is no real delivery vendor in
 * this build, so no provider receipt may be fabricated. The delivery ledger
 * records FAILED/NO_PROVIDER_CONFIGURED and remains explicitly retryable.
 */
final class UnavailableExternalNotificationChannel implements ExternalNotificationChannel
{
    public const REASON = 'NO_PROVIDER_CONFIGURED';

    public function channel(): string
    {
        return 'UNCONFIGURED';
    }

    public function send(string $type, int $userId, array $payload): NotificationReceipt
    {
        return NotificationReceipt::failed(self::REASON);
    }
}
