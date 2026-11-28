<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Contracts\NotificationTransport;
use App\Shared\Notifications\NotificationReceipt;
use Illuminate\Support\Str;

/**
 * Reference transport used by the template/outbox dispatcher. It returns a
 * synthetic provider receipt; a real provider adapter replaces this binding.
 */
final class LogNotificationTransport implements NotificationTransport
{
    public function channel(): string
    {
        return 'IN_APP';
    }

    public function send(string $templateCode, int $recipientUserId, array $variables): NotificationReceipt
    {
        return NotificationReceipt::delivered('platform-log-'.Str::uuid()->toString());
    }
}
