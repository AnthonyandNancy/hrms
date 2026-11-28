<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Contracts;

use App\Shared\Notifications\NotificationDeliveryRecord;

/**
 * Owning-module retry seam for in-app notification deliveries. Platform may
 * delegate a retry only to the module that owns the stored channel/type/payload;
 * it must never substitute its own template transport or an empty variable bag.
 */
interface NotificationDeliveryRetry
{
    public function supportsChannel(string $channel): bool;

    public function retryDelivery(int $deliveryId): ?NotificationDeliveryRecord;
}
