<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

use App\Shared\Notifications\NotificationReceipt;

/**
 * Platform-owned transport seam for template/outbox dispatch. A send without a
 * receipt is not evidence; only a successful receipt may mark a delivery SENT.
 */
interface NotificationTransport
{
    public function channel(): string;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function send(string $templateCode, int $recipientUserId, array $variables): NotificationReceipt;
}
