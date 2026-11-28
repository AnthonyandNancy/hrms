<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Contracts\NotificationTransport;
use App\Shared\Notifications\NotificationReceipt;

/**
 * Production default. No real provider is configured in this build, so the
 * transport must never invent a delivered receipt. Dispatch/retry persists
 * FAILED with a stable Chinese-free machine reason.
 */
final class UnavailableNotificationTransport implements NotificationTransport
{
    public const REASON = 'NO_PROVIDER_CONFIGURED';

    public function channel(): string
    {
        return 'UNCONFIGURED';
    }

    public function send(string $templateCode, int $recipientUserId, array $variables): NotificationReceipt
    {
        return NotificationReceipt::failed(self::REASON);
    }
}
