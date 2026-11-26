<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\Engagement\Contracts\ExternalNotificationChannel;
use App\Modules\Engagement\Models\Notification;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Observability\OutboxRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

final class NotificationService
{
    public function __construct(
        private readonly ExternalNotificationChannel $external,
        private readonly OutboxRecorder $outbox,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $payload  IDs only.
     * @return list<int>
     */
    public function notify(array $userIds, string $type, array $payload): array
    {
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
