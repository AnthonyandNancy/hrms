<?php

declare(strict_types=1);

namespace App\Shared\Notifications;

/**
 * Provider acknowledgement for exactly one user/channel send. A delivery is
 * SENT only when this value says the provider accepted it.
 */
final readonly class NotificationReceipt
{
    private function __construct(
        public bool $successful,
        public ?string $providerMessageId,
        public ?string $failureReason,
    ) {}

    public static function delivered(string $providerMessageId): self
    {
        $id = trim($providerMessageId);

        return new self($id !== '', $id !== '' ? $id : null, $id !== '' ? null : 'EMPTY_PROVIDER_RECEIPT');
    }

    public static function failed(string $reason): self
    {
        $message = trim($reason);

        return new self(false, null, $message !== '' ? $message : 'PROVIDER_DELIVERY_FAILED');
    }
}
