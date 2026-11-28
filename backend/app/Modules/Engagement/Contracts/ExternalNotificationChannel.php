<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Contracts;

use App\Shared\Notifications\NotificationReceipt;

/**
 * External channel seam for email/SMS/push adapters. The reference binding is
 * a log stub; no provider receives personal data in this build. A void return
 * is not evidence of delivery, so every send must return a provider receipt.
 */
interface ExternalNotificationChannel
{
    public function channel(): string;

    /**
     * @param  array<string, mixed>  $payload  IDs only; never PII or amounts.
     */
    public function send(string $type, int $userId, array $payload): NotificationReceipt;
}
