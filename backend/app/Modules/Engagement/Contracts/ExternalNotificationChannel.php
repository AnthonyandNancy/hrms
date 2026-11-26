<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Contracts;

/**
 * External channel seam for email/SMS/push adapters. The reference binding is
 * a log stub; no provider receives personal data in this build.
 */
interface ExternalNotificationChannel
{
    /**
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $payload  IDs only; never PII.
     */
    public function notify(string $type, array $userIds, array $payload): void;
}
