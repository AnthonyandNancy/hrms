<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\Engagement\Contracts\ExternalNotificationChannel;
use App\Modules\Engagement\Models\Notification;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Observability\OutboxRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

final class NotificationService
{
    public function __construct(
        private readonly ExternalNotificationChannel $external,
        private readonly OutboxRecorder $outbox,
        private readonly Clock $clock,
        private readonly ?AuditRecorder $audit = null,
    ) {}

    /**
     * Notice notification payloads are an identifier boundary, never a copy of
     * the notice. Reject any producer that tries to smuggle display facts in.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertNoticePayloadShape(string $type, array $payload): void
    {
        if (! str_starts_with($type, 'NOTICE_')) {
            return;
        }

        $allowed = ['notice_id', 'requires_ack'];
        $unexpected = array_values(array_diff(array_keys($payload), $allowed));

        if ($unexpected !== [] || ! isset($payload['notice_id'])) {
            throw new \InvalidArgumentException(
                'Notice notifications may contain only notice_id and requires_ack; received: '.implode(',', $unexpected),
            );
        }
    }

    /**
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $payload  IDs only.
     * @return list<int>
     */
    public function notify(array $userIds, string $type, array $payload): array
    {
        $this->assertNoticePayloadShape($type, $payload);
        $ids = [];
        $now = $this->clock->now();

        DB::transaction(function () use ($userIds, $type, $payload, $now, &$ids): void {
            foreach (array_values(array_unique($userIds)) as $userId) {
                $notification = Notification::query()->create([
                    'user_id' => $userId,
                    'type' => $type,
                    'payload_json' => $payload,
                    'read_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $ids[] = (int) $notification->id;
            }

            if ($ids !== []) {
                $this->outbox->record(
                    eventType: 'engagement.notification.external.requested',
                    aggregateType: 'notification',
                    aggregateId: (string) $ids[0],
                    aggregateVersion: 1,
                    payload: ['notification_ids' => $ids, 'type' => $type, 'channel' => 'LOG_STUB'],
                );
            }
        });

        if ($ids !== []) {
            $this->external->notify($type, array_values(array_unique($userIds)), $payload);
        }

        return $ids;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Notification $notification): array => $this->payload($notification))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function markRead(int $userId, int $notificationId): array
    {
        $notification = Notification::query()
            ->where('user_id', $userId)
            ->whereKey($notificationId)
            ->first();

        if ($notification === null) {
            throw new NotFoundException;
        }

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => $this->clock->now()])->save();

            ($this->audit ?? app(AuditRecorder::class))->record(
                actorUserId: $userId,
                action: 'engagement.notification.read',
                aggregateType: 'notification',
                aggregateId: (int) $notification->id,
                after: ['read' => true],
                metadata: ['type' => (string) $notification->type],
            );
        }

        return $this->payload($notification->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Notification $notification): array
    {
        return [
            'id' => (int) $notification->id,
            'type' => (string) $notification->type,
            'payload' => (array) $notification->payload_json,
            'read' => $notification->read_at !== null,
            'createdAt' => $notification->created_at?->toIso8601ZuluString(),
        ];
    }
}
