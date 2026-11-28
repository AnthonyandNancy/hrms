<?php

declare(strict_types=1);

namespace App\Shared\Notifications;

/**
 * Owning-module retry seam for in-app notification deliveries. Platform may
 * delegate a retry only to the module that owns the stored channel/type/payload;
 * it must never substitute its own template transport for those rows.
 */
interface NotificationDeliveryRetry
{
    public function supportsChannel(string $channel): bool;

    public function retryDelivery(int $deliveryId): ?NotificationDeliveryRecord;
}
