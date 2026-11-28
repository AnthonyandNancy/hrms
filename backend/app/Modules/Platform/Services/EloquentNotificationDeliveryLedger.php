<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Contracts\NotificationDeliveryLedger;
use App\Modules\Platform\Models\NotificationDelivery;
use App\Modules\Platform\Models\NotificationOutbox;
use App\Shared\Notifications\NotificationDeliveryRecord;
use App\Shared\Notifications\NotificationDeliveryStatus;
use App\Shared\Notifications\NotificationReceipt;
use App\Shared\Time\Clock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Sole writer of notification_deliveries. The ledger is evidence: PENDING is
 * committed as a reservation before the provider call, only a successful
 * receipt yields SENT, and one claim token owns the in-flight send.
 */
final class EloquentNotificationDeliveryLedger implements NotificationDeliveryLedger
{
    public function __construct(private readonly Clock $clock) {}

    public function reserve(
        int $notificationId,
        int $recipientUserId,
        string $eventKey,
        string $channel,
        string $notificationType,
        array $payload,
    ): NotificationDeliveryRecord {
        return DB::transaction(function () use ($notificationId, $recipientUserId, $eventKey, $channel, $notificationType, $payload): NotificationDeliveryRecord {
            /** @var NotificationDelivery|null $delivery */
            $delivery = NotificationDelivery::query()
                ->where('notification_id', $notificationId)
                ->where('recipient_user_id', $recipientUserId)
                ->lockForUpdate()
                ->first();

            if ($delivery === null) {
                // notification_outbox_id is mandatory for the existing
                // template dispatcher and unique. Engagement reservations use
                // deterministic synthetic outbox rows so the ledger stays one
                // table with one writer and one uniqueness rule.
                $outbox = NotificationOutbox::query()->firstOrCreate(
                    ['event_key' => $eventKey],
                    [
                        'template_code' => 'IN_APP_'.$channel,
                        'recipient_user_id' => $recipientUserId,
                        'payload_json' => [
                            'notification_id' => $notificationId,
                            'notification_type' => $notificationType,
                            'payload' => $payload,
                        ],
                        'status' => NotificationOutbox::STATUS_DISPATCHED,
                    ],
                );

                try {
                    $delivery = NotificationDelivery::query()->create([
                        'notification_outbox_id' => (int) $outbox->id,
                        'notification_id' => $notificationId,
                        'template_code' => 'IN_APP_'.$channel,
                        'recipient_user_id' => $recipientUserId,
                        'channel' => $channel,
                        'notification_type' => $notificationType,
                        'payload_json' => $payload,
                        'status' => NotificationDelivery::STATUS_PENDING,
                        'attempts' => 0,
                        'provider_message_id' => null,
                        'last_error' => null,
                        'sent_at' => null,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    $delivery = NotificationDelivery::query()
                        ->where('notification_id', $notificationId)
                        ->where('recipient_user_id', $recipientUserId)
                        ->firstOrFail();
                }
            }

            return $this->record($delivery);
        });
    }

    public function claim(int $deliveryId, string $claimToken): ?NotificationDeliveryRecord
    {
        return DB::transaction(function () use ($deliveryId, $claimToken): ?NotificationDeliveryRecord {
            /** @var NotificationDelivery|null $delivery */
            $delivery = NotificationDelivery::query()
                ->whereKey($deliveryId)
                ->lockForUpdate()
                ->first();

            if ($delivery === null) {
                return null;
            }

            if ((string) $delivery->status === NotificationDelivery::STATUS_SENT) {
                return null;
            }

            if ($delivery->claim_token !== null && (string) $delivery->claim_token !== $claimToken) {
                return null;
            }

            $delivery->forceFill([
                'claim_token' => $claimToken,
                'claimed_at' => $this->clock->now(),
            ])->save();

            return $this->record($delivery->refresh());
        });
    }

    public function complete(int $deliveryId, string $claimToken, NotificationReceipt $receipt): ?NotificationDeliveryRecord
    {
        return DB::transaction(function () use ($deliveryId, $claimToken, $receipt): ?NotificationDeliveryRecord {
            /** @var NotificationDelivery|null $delivery */
            $delivery = NotificationDelivery::query()
                ->whereKey($deliveryId)
                ->lockForUpdate()
                ->first();

            if ($delivery === null
                || (string) $delivery->status === NotificationDelivery::STATUS_SENT
                || $delivery->claim_token === null
                || ! hash_equals((string) $delivery->claim_token, $claimToken)) {
                return null;
            }

            $delivery->forceFill([
                'status' => $receipt->successful ? NotificationDelivery::STATUS_SENT : NotificationDelivery::STATUS_FAILED,
                'attempts' => (int) $delivery->attempts + 1,
                'provider_message_id' => $receipt->providerMessageId,
                'last_error' => $receipt->successful ? null : $receipt->failureReason,
                'sent_at' => $receipt->successful ? $this->clock->now() : null,
                'claim_token' => null,
                'claimed_at' => null,
            ])->save();

            return $this->record($delivery->refresh());
        });
    }

    public function findForNotification(int $notificationId, int $recipientUserId): ?NotificationDeliveryRecord
    {
        $delivery = NotificationDelivery::query()
            ->where('notification_id', $notificationId)
            ->where('recipient_user_id', $recipientUserId)
            ->first();

        return $delivery !== null ? $this->record($delivery) : null;
    }

    public function find(int $deliveryId): ?NotificationDeliveryRecord
    {
        $delivery = NotificationDelivery::query()->whereKey($deliveryId)->first();

        return $delivery !== null ? $this->record($delivery) : null;
    }

    /**
     * @param  list<int>  $notificationIds
     * @return list<NotificationDeliveryRecord>
     */
    public function forNotifications(array $notificationIds): array
    {
        if ($notificationIds === []) {
            return [];
        }

        return NotificationDelivery::query()
            ->whereIn('notification_id', $notificationIds)
            ->orderBy('id')
            ->get()
            ->map(fn (NotificationDelivery $delivery): NotificationDeliveryRecord => $this->record($delivery))
            ->all();
    }

    public function markFailed(int $deliveryId, string $reason): NotificationDeliveryRecord
    {
        return DB::transaction(function () use ($deliveryId, $reason): NotificationDeliveryRecord {
            /** @var NotificationDelivery $delivery */
            $delivery = NotificationDelivery::query()
                ->whereKey($deliveryId)
                ->lockForUpdate()
                ->firstOrFail();
            $message = mb_substr(trim($reason), 0, 255);

            $delivery->forceFill([
                'status' => NotificationDelivery::STATUS_FAILED,
                'attempts' => (int) $delivery->attempts + 1,
                'provider_message_id' => null,
                'last_error' => $message !== '' ? $message : 'PROVIDER_DELIVERY_FAILED',
                'sent_at' => null,
                'claim_token' => null,
                'claimed_at' => null,
            ])->save();

            return $this->record($delivery->refresh());
        });
    }

    public function status(int $deliveryId): ?NotificationDeliveryStatus
    {
        $status = NotificationDelivery::query()->whereKey($deliveryId)->value('status');

        return is_string($status) ? NotificationDeliveryStatus::tryFrom($status) : null;
    }

    private function record(NotificationDelivery $delivery): NotificationDeliveryRecord
    {
        $sentAt = $delivery->getAttribute('sent_at');

        return new NotificationDeliveryRecord(
            id: (int) $delivery->id,
            notificationId: (int) $delivery->notification_id,
            recipientUserId: (int) $delivery->recipient_user_id,
            channel: (string) $delivery->channel,
            status: NotificationDeliveryStatus::from((string) $delivery->status),
            attempts: (int) $delivery->attempts,
            providerMessageId: $delivery->provider_message_id !== null ? (string) $delivery->provider_message_id : null,
            lastError: $delivery->last_error !== null ? (string) $delivery->last_error : null,
            sentAt: $sentAt instanceof \Carbon\CarbonInterface ? $sentAt->toIso8601ZuluString() : null,
            notificationType: $delivery->notification_type !== null ? (string) $delivery->notification_type : null,
            payload: (array) ($delivery->payload_json ?? []),
            claimedAt: $delivery->getAttribute('claimed_at') instanceof \Carbon\CarbonInterface
                ? $delivery->getAttribute('claimed_at')->toIso8601ZuluString()
                : null,
        );
    }
}
