<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

use App\Shared\Notifications\NotificationDeliveryRecord;
use App\Shared\Notifications\NotificationDeliveryStatus;
use App\Shared\Notifications\NotificationReceipt;

/**
 * Platform-owned delivery ledger. Engagement supplies the already-created
 * notification identity and the provider receipt; only Platform writes the
 * notification_deliveries table.
 */
interface NotificationDeliveryLedger
{
    /**
     * Idempotently create or retry one recipient delivery for an in-app
     * notification. Repeating the same notification/user must never fan out.
     */
    public function recordAttempt(
        int $notificationId,
        int $recipientUserId,
        string $eventKey,
        string $channel,
        NotificationReceipt $receipt,
    ): NotificationDeliveryRecord;

    public function findForNotification(int $notificationId, int $recipientUserId): ?NotificationDeliveryRecord;

    /**
     * @param  list<int>  $notificationIds
     * @return list<NotificationDeliveryRecord>
     */
    public function forNotifications(array $notificationIds): array;

    public function markFailed(int $deliveryId, string $reason): NotificationDeliveryRecord;

    public function status(int $deliveryId): ?NotificationDeliveryStatus;
}
