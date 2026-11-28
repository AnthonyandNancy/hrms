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
use Illuminate\Support\Facades\DB;

/**
 * Sole writer of notification_deliveries. The ledger is evidence: PENDING is
 * observed before the provider call, only a successful receipt yields SENT,
 * and a failure keeps the same row retryable.
 */
final class EloquentNotificationDeliveryLedger implements NotificationDeliveryLedger
{
    public function __construct(private readonly Clock $clock) {}

    public function recordAttempt(
        int $notificationId,
        int $recipientUserId,
        string $eventKey,
        string $channel,
        NotificationReceipt $receipt,
    ): NotificationDeliveryRecord {
        return DB::transaction(function () use ($notificationId, $recipientUserId, $eventKey, $channel, $receipt): NotificationDeliveryRecord {
            /** @var NotificationDelivery|null $delivery */
            $delivery = NotificationDelivery::query()
                ->where('notification_id', $notificationId)
                ->where('recipient_user_id', $recipientUserId)
                ->lockForUpdate()
                ->first();

            if ($delivery === null) {
                // notification_outbox_id is mandatory for the existing
                // template dispatcher and unique. In-app Engagement deliveries
                // use a deterministic synthetic outbox row per event key so the
                // ledger stays one table with one writer and one uniqueness rule.
                $outbox = NotificationOutbox::query()->firstOrCreate(
                    ['event_key' => $eventKey],
                    [
                        'template_code' => 'IN_APP_'.$channel,
                        'recipient_user_id' => $recipientUserId,
                        'payload_json' => ['notification_id' => $notificationId],
                        'status' => NotificationOutbox::STATUS_DISPATCHED,
                    ],
                );

                $delivery = NotificationDelivery::query()->create([
                    'notification_outbox_id' => (int) $outbox->id,
                    'notification_id' => $notificationId,
                    'template_code' => 'IN_APP_'.$channel,
                    'recipient_user_id' => $recipientUserId,
                    'channel' => $channel,
                    'status' => NotificationDelivery::STATUS_PENDING,
                    'attempts' => 0,
                    'provider_message_id' => null,
                    'last_error' => null,
                    'sent_at' => null,
                ]);
            }

            if ((string) $delivery->status === NotificationDelivery::STATUS_SENT) {
                return $this->record($delivery);
            }

            $providerId = $receipt->providerMessageId;
            $status = $receipt->successful ? NotificationDelivery::STATUS_SENT : NotificationDelivery::STATUS_FAILED;

            $delivery->forceFill([
                'status' => $status,
                'attempts' => (int) $delivery->attempts + 1,
                'provider_message_id' => $providerId,
                'last_error' => $receipt->successful ? null : $receipt->failureReason,
                'sent_at' => $receipt->successful ? $this->clock->now() : null,
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
        /** @var NotificationDelivery $delivery */
        $delivery = NotificationDelivery::query()->whereKey($deliveryId)->firstOrFail();
        $message = mb_substr(trim($reason), 0, 255);

        $delivery->forceFill([
            'status' => NotificationDelivery::STATUS_FAILED,
            'attempts' => (int) $delivery->attempts + 1,
            'provider_message_id' => null,
            'last_error' => $message !== '' ? $message : 'PROVIDER_DELIVERY_FAILED',
            'sent_at' => null,
        ])->save();

        return $this->record($delivery->refresh());
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
        );
    }
}
