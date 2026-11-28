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
     * Create or resume the PENDING reservation for one recipient. The
     * reservation is committed before any provider call. Repeating the same
     * notification/user must never create a second row.
     *
     * @param  array<string, mixed>  $payload
     */
    public function reserve(
        int $notificationId,
        int $recipientUserId,
        string $eventKey,
        string $channel,
        string $notificationType,
        array $payload,
    ): NotificationDeliveryRecord;

    /**
     * Atomically claim a PENDING/FAILED row for the given owner. Returns null
     * when the row is already SENT or another process holds the claim.
     */
    public function claim(int $deliveryId, string $claimToken): ?NotificationDeliveryRecord;

    /**
     * Finish a claimed attempt. Only a successful provider receipt yields
     * SENT; any failure clears the claim and stores FAILED on the same row.
     */
    public function complete(int $deliveryId, string $claimToken, NotificationReceipt $receipt): ?NotificationDeliveryRecord;

    public function findForNotification(int $notificationId, int $recipientUserId): ?NotificationDeliveryRecord;

    public function find(int $deliveryId): ?NotificationDeliveryRecord;

    /**
     * @param  list<int>  $notificationIds
     * @return list<NotificationDeliveryRecord>
     */
    public function forNotifications(array $notificationIds): array;

    public function markFailed(int $deliveryId, string $reason): NotificationDeliveryRecord;

    public function status(int $deliveryId): ?NotificationDeliveryStatus;
}
