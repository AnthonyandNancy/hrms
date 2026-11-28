<?php

declare(strict_types=1);

namespace App\Shared\Notifications;

/**
 * Delivery ledger projection returned by the Platform-owned port. Engagement
 * never queries the Platform table directly.
 */
final readonly class NotificationDeliveryRecord
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $id,
        public int $notificationId,
        public int $recipientUserId,
        public string $channel,
        public NotificationDeliveryStatus $status,
        public int $attempts,
        public ?string $providerMessageId,
        public ?string $lastError,
        public ?string $sentAt,
        public ?string $notificationType = null,
        public array $payload = [],
        public ?string $claimedAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'notification_id' => $this->notificationId,
            'recipient_user_id' => $this->recipientUserId,
            'channel' => $this->channel,
            'notification_type' => $this->notificationType,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'provider_message_id' => $this->providerMessageId,
            'last_error' => $this->lastError,
            'sent_at' => $this->sentAt,
            'claimed_at' => $this->claimedAt,
        ];
    }
}
