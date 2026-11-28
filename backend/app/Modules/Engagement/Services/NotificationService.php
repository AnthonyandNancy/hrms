<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\Engagement\Contracts\ExternalNotificationChannel;
use App\Modules\Engagement\Models\Notification;
use App\Modules\IAM\Contracts\Principal;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\Platform\Contracts\NotificationDeliveryLedger;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Notifications\NotificationDeliveryRecord;
use App\Shared\Notifications\NotificationDeliveryStatus;
use App\Shared\Notifications\NotificationReceipt;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Observability\OutboxRecorder;
use App\Shared\Time\Clock;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Support\Facades\DB;

final class NotificationService
{
    public function __construct(
        private readonly ExternalNotificationChannel $external,
        private readonly OutboxRecorder $outbox,
        private readonly Clock $clock,
        private readonly ?AuditRecorder $audit = null,
        private readonly ?NotificationDeliveryLedger $deliveries = null,
        private readonly ?PeopleDirectory $people = null,
        private readonly ?CompanyBusinessDate $businessDates = null,
    ) {}

    /**
     * Notice notification payloads are an identifier boundary, never a copy of
     * the notice. Payslip notifications are likewise identifiers only.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertPayloadShape(string $type, array $payload): void
    {
        if (str_starts_with($type, 'NOTICE_')) {
            $allowed = ['notice_id', 'requires_ack'];
            $unexpected = array_values(array_diff(array_keys($payload), $allowed));

            if ($unexpected !== [] || ! isset($payload['notice_id'])) {
                throw new \InvalidArgumentException(
                    'Notice notifications may contain only notice_id and requires_ack; received: '.implode(',', $unexpected),
                );
            }

            return;
        }

        if ($type === 'PAYSLIP_PUBLISHED') {
            $allowed = ['payslip_id', 'period'];
            $unexpected = array_values(array_diff(array_keys($payload), $allowed));

            if ($unexpected !== [] || ! isset($payload['payslip_id'])) {
                throw new \InvalidArgumentException(
                    'Payslip notifications may contain only payslip_id and period; received: '.implode(',', $unexpected),
                );
            }
        }
    }

    /**
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $payload  IDs only.
     * @return list<int>
     */
    public function notify(array $userIds, string $type, array $payload, ?string $eventKey = null): array
    {
        $this->assertPayloadShape($type, $payload);
        $recipients = array_values(array_unique(array_map('intval', $userIds)));
        sort($recipients);
        $eventKey = $this->eventKey($eventKey, $type, $payload, $recipients);
        $ids = [];
        $now = $this->clock->now();
        $created = [];

        DB::transaction(function () use ($recipients, $type, $payload, $eventKey, $now, &$ids, &$created): void {
            foreach ($recipients as $userId) {
                $notification = Notification::query()
                    ->where('event_key', $eventKey)
                    ->where('user_id', $userId)
                    ->first();

                if ($notification === null) {
                    try {
                        $notification = Notification::query()->create([
                            'event_key' => $eventKey,
                            'user_id' => $userId,
                            'type' => $type,
                            'payload_json' => $payload,
                            'read_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $created[] = (int) $notification->id;
                    } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                        $notification = Notification::query()
                            ->where('event_key', $eventKey)
                            ->where('user_id', $userId)
                            ->firstOrFail();
                    }
                }

                $ids[] = (int) $notification->id;
            }

            if ($created !== []) {
                $this->outbox->record(
                    eventType: 'engagement.notification.external.requested',
                    aggregateType: 'notification',
                    aggregateId: (string) $created[0],
                    aggregateVersion: 1,
                    payload: ['notification_ids' => $created, 'type' => $type, 'channel' => $this->external->channel()],
                );
            }
        });

        foreach ($ids as $index => $notificationId) {
            $recipientUserId = $recipients[$index] ?? 0;
            $ledger = $this->ledger();
            $existing = $ledger->findForNotification($notificationId, $recipientUserId);

            if ($existing?->status === NotificationDeliveryStatus::SENT) {
                continue;
            }

            $receipt = $this->sendReceipt($recipientUserId, $type, $payload);

            $ledger->recordAttempt(
                notificationId: $notificationId,
                recipientUserId: $recipientUserId,
                eventKey: $eventKey.':'.$notificationId,
                channel: $this->external->channel(),
                receipt: $receipt,
            );
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendReceipt(int $userId, string $type, array $payload): NotificationReceipt
    {
        if ($userId <= 0) {
            return NotificationReceipt::failed('INVALID_RECIPIENT');
        }

        try {
            return $this->external->send($type, $userId, $payload);
        } catch (\Throwable $exception) {
            return NotificationReceipt::failed(mb_substr($exception->getMessage(), 0, 255));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<int>  $recipients
     */
    private function eventKey(?string $eventKey, string $type, array $payload, array $recipients): string
    {
        if ($eventKey !== null && trim($eventKey) !== '') {
            return mb_substr(trim($eventKey), 0, 190);
        }

        return mb_substr($type.':'.hash('sha256', json_encode([$payload, $recipients], JSON_THROW_ON_ERROR)), 0, 190);
    }

    private function ledger(): NotificationDeliveryLedger
    {
        return $this->deliveries ?? app(NotificationDeliveryLedger::class);
    }

    /**
     * Re-authorize every notification on each list. A stale notice or payslip
     * link keeps its inbox row but loses its payload and cannot be marked read.
     *
     * @return list<array<string, mixed>>
     */
    public function listForUser(Principal $principal): array
    {
        $notifications = Notification::query()
            ->where('user_id', $principal->userId())
            ->orderByDesc('id')
            ->get();

        $deliveries = [];
        $ids = $notifications->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        foreach ($this->ledger()->forNotifications($ids) as $delivery) {
            $deliveries[$delivery->notificationId] = $delivery;
        }

        return $notifications->map(function (Notification $notification) use ($principal, $deliveries): array {
            $invalidReason = $this->invalidReason($principal, $notification);

            return $this->payload($notification, $deliveries[(int) $notification->id] ?? null, $invalidReason);
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function markRead(Principal $principal, int $notificationId): array
    {
        $notification = Notification::query()
            ->where('user_id', $principal->userId())
            ->whereKey($notificationId)
            ->first();

        if ($notification === null || ($invalidReason = $this->invalidReason($principal, $notification)) !== null) {
            throw new NotFoundException;
        }

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => $this->clock->now()])->save();

            ($this->audit ?? app(AuditRecorder::class))->record(
                actorUserId: $principal->userId(),
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
    public function payload(Notification $notification, ?NotificationDeliveryRecord $delivery = null, ?string $invalidReason = null): array
    {
        return [
            'id' => (int) $notification->id,
            'type' => (string) $notification->type,
            'payload' => $invalidReason === null ? (array) $notification->payload_json : [],
            'read' => $notification->read_at !== null,
            'valid' => $invalidReason === null,
            'invalidReason' => $invalidReason,
            'delivery' => $delivery?->toArray(),
            'createdAt' => $notification->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Perform a live re-authorization for the notification's referenced target.
     * Only implemented identifier types are treated as valid; unknown types do
     * not get to synthesize content.
     */
    private function invalidReason(Principal $principal, Notification $notification): ?string
    {
        $type = (string) $notification->type;
        $payload = (array) $notification->payload_json;

        if (str_starts_with($type, 'NOTICE_')) {
            $noticeId = isset($payload['notice_id']) ? (int) $payload['notice_id'] : 0;

            return $noticeId > 0 && app(NoticeAccessPolicy::class)->canOpenNoticeNotification(
                $principal,
                $noticeId,
                ($this->businessDates ?? app(CompanyBusinessDate::class))->today()->value(),
            ) ? null : '公告已撤回、过期或不再属于有效受众，原通知内容不可查看。';
        }

        if ($type === 'PAYSLIP_PUBLISHED') {
            $payslipId = isset($payload['payslip_id']) ? (int) $payload['payslip_id'] : 0;
            $payslip = app(\App\Modules\Payroll\Contracts\PayslipNotificationDirectory::class)->publishedPayslip($payslipId);

            if ($payslip === null) {
                return '工资条尚未发布或已不可用，原通知内容不可查看。';
            }

            $employmentId = $principal->employmentId()
                ?? ($this->people ?? app(PeopleDirectory::class))->employmentForUser($principal->userId());

            return $employmentId !== null && $payslip->employmentId === $employmentId
                ? null
                : '该工资条不属于当前账号，原通知内容不可查看。';
        }

        return null;
    }
}
