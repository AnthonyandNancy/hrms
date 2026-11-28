<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Platform\Contracts\NotificationTransport;
use App\Modules\Platform\Models\NotificationDelivery;
use App\Shared\Notifications\NotificationReceipt;
use Tests\Support\PeopleTestCase;

final class NotificationDeliveryTest extends PeopleTestCase
{
    public function test_same_outbox_event_is_delivered_only_once_across_idempotency_keys(): void
    {
        $this->postJson('/v1/notification-templates', [
            'code' => 'APPROVAL_PENDING',
            'channel' => 'IN_APP',
            'subject' => 'Approval pending',
            'body' => 'You have a pending approval.',
        ], ['Idempotency-Key' => 'nt-1'])->assertCreated();

        $first = $this->postJson('/v1/notifications/dispatch', [
            'event_key' => 'case-1-created',
            'template_code' => 'APPROVAL_PENDING',
            'recipient_user_id' => 1,
        ], ['Idempotency-Key' => 'nd-1'])->assertCreated();

        $second = $this->postJson('/v1/notifications/dispatch', [
            'event_key' => 'case-1-created',
            'template_code' => 'APPROVAL_PENDING',
            'recipient_user_id' => 1,
        ], ['Idempotency-Key' => 'nd-2'])->assertOk();

        $this->assertSame($first->json('data.delivery_id'), $second->json('data.delivery_id'));
        $this->assertDatabaseCount('notification_outbox', 1);
        $this->assertDatabaseCount('notification_deliveries', 1);
    }

    public function test_same_idempotency_key_replays_with_the_marker_header(): void
    {
        $this->postJson('/v1/notification-templates', [
            'code' => 'APPROVAL_PENDING',
            'channel' => 'IN_APP',
            'subject' => 'Approval pending',
            'body' => 'You have a pending approval.',
        ], ['Idempotency-Key' => 'nt-replay'])->assertCreated();

        $body = [
            'event_key' => 'case-2-created',
            'template_code' => 'APPROVAL_PENDING',
            'recipient_user_id' => 1,
        ];

        $this->postJson('/v1/notifications/dispatch', $body, ['Idempotency-Key' => 'nd-replay'])->assertCreated();
        $this->postJson('/v1/notifications/dispatch', $body, ['Idempotency-Key' => 'nd-replay'])
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');
    }

    public function test_reusing_an_event_key_with_different_payload_is_a_conflict(): void
    {
        $this->postJson('/v1/notification-templates', [
            'code' => 'APPROVAL_PENDING',
            'channel' => 'IN_APP',
            'subject' => 'Approval pending',
            'body' => 'You have a pending approval.',
        ], ['Idempotency-Key' => 'nt-conflict'])->assertCreated();

        $this->postJson('/v1/notifications/dispatch', [
            'event_key' => 'case-3-created',
            'template_code' => 'APPROVAL_PENDING',
            'recipient_user_id' => 1,
        ], ['Idempotency-Key' => 'nd-conflict-1'])->assertCreated();

        $this->postJson('/v1/notifications/dispatch', [
            'event_key' => 'case-3-created',
            'template_code' => 'APPROVAL_PENDING',
            'recipient_user_id' => 2,
        ], ['Idempotency-Key' => 'nd-conflict-2'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'EVENT_KEY_PAYLOAD_MISMATCH');
    }

    public function test_dispatch_without_a_transport_receipt_is_failed_and_retry_requires_a_new_receipt(): void
    {
        $this->postJson('/v1/notification-templates', [
            'code' => 'APPROVAL_PENDING',
            'channel' => 'IN_APP',
            'subject' => 'Approval pending',
            'body' => 'You have a pending approval.',
        ], ['Idempotency-Key' => 'nt-receipt'])->assertCreated();

        $transport = new ReceiptDrivenNotificationTransport([
            NotificationReceipt::failed('SYNTHETIC_PROVIDER_REJECTED'),
        ]);
        $this->app->instance(NotificationTransport::class, $transport);

        $deliveryId = (int) $this->postJson('/v1/notifications/dispatch', [
            'event_key' => 'case-receipt-1',
            'template_code' => 'APPROVAL_PENDING',
            'recipient_user_id' => 1,
        ], ['Idempotency-Key' => 'nd-receipt-1'])->assertCreated()
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.sent_at', null)
            ->json('data.delivery_id');

        $this->assertNull(NotificationDelivery::query()->whereKey($deliveryId)->value('provider_message_id'));

        $retry = $this->postJson('/v1/notifications/deliveries/'.$deliveryId.'/retry', [], ['Idempotency-Key' => 'nd-receipt-retry-1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.attempts', 2);
        $this->assertNull($retry->json('data.sent_at'));

        $transport->queue(NotificationReceipt::delivered('provider-receipt-retry'));
        $this->postJson('/v1/notifications/deliveries/'.$deliveryId.'/retry', [], ['Idempotency-Key' => 'nd-receipt-retry-2'])
            ->assertOk()
            ->assertJsonPath('data.status', 'SENT')
            ->assertJsonPath('data.attempts', 3)
            ->assertJsonPath('data.provider_message_id', 'provider-receipt-retry');
    }

    public function test_default_transport_without_a_provider_keeps_delivery_failed(): void
    {
        $this->postJson('/v1/notification-templates', [
            'code' => 'APPROVAL_PENDING',
            'channel' => 'IN_APP',
            'subject' => 'Approval pending',
            'body' => 'You have a pending approval.',
        ], ['Idempotency-Key' => 'nt-default-provider'])->assertCreated();

        $deliveryId = (int) $this->postJson('/v1/notifications/dispatch', [
            'event_key' => 'case-default-provider',
            'template_code' => 'APPROVAL_PENDING',
            'recipient_user_id' => 1,
        ], ['Idempotency-Key' => 'nd-default-provider'])->assertCreated()
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.last_error', 'NO_PROVIDER_CONFIGURED')
            ->assertJsonPath('data.sent_at', null)
            ->json('data.delivery_id');

        $this->assertNull(NotificationDelivery::query()->whereKey($deliveryId)->value('provider_message_id'));
    }

    public function test_delivery_log_is_a_projection_and_failed_delivery_can_be_retried_once(): void
    {
        $this->postJson('/v1/notification-templates', [
            'code' => 'APPROVAL_PENDING',
            'channel' => 'IN_APP',
            'subject' => 'Approval pending',
            'body' => 'You have a pending approval.',
        ], ['Idempotency-Key' => 'nt-retry'])->assertCreated();

        $transport = new ReceiptDrivenNotificationTransport([
            NotificationReceipt::delivered('dispatch-receipt'),
            NotificationReceipt::delivered('retry-receipt'),
        ]);
        $this->app->instance(NotificationTransport::class, $transport);

        $deliveryId = (int) $this->postJson('/v1/notifications/dispatch', [
            'event_key' => 'case-4-created',
            'template_code' => 'APPROVAL_PENDING',
            'recipient_user_id' => 1,
        ], ['Idempotency-Key' => 'nd-retry'])->assertCreated()->json('data.delivery_id');

        NotificationDelivery::query()->whereKey($deliveryId)->update([
            'status' => NotificationDelivery::STATUS_FAILED,
            'last_error' => 'SYNTHETIC_TRANSPORT_FAILURE',
        ]);

        $this->getJson('/v1/notifications/deliveries', ['page' => 1, 'page_size' => 50])
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'page', 'page_size', 'total', 'as_of', 'freshness', 'source_hash']])
            ->assertJsonFragment(['id' => $deliveryId, 'status' => 'FAILED']);

        $this->postJson('/v1/notifications/deliveries/'.$deliveryId.'/retry', [], ['Idempotency-Key' => 'nd-retry-2'])
            ->assertOk()
            ->assertJsonPath('data.status', 'SENT')
            ->assertJsonPath('data.attempts', 2);

        $this->postJson('/v1/notifications/deliveries/'.$deliveryId.'/retry', [], ['Idempotency-Key' => 'nd-retry-3'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'DELIVERY_ALREADY_SENT');
    }

    public function test_notification_writes_require_an_idempotency_key(): void
    {
        $this->postJson('/v1/notification-templates', [
            'code' => 'APPROVAL_PENDING',
            'channel' => 'IN_APP',
            'subject' => 'Approval pending',
            'body' => 'You have a pending approval.',
        ])->assertUnprocessable()->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');
    }
}

final class ReceiptDrivenNotificationTransport implements NotificationTransport
{
    /** @var list<NotificationReceipt> */
    private array $receipts;

    /** @param list<NotificationReceipt> $receipts */
    public function __construct(array $receipts = [])
    {
        $this->receipts = $receipts;
    }

    public function queue(NotificationReceipt $receipt): void
    {
        $this->receipts[] = $receipt;
    }

    public function channel(): string
    {
        return 'IN_APP';
    }

    public function send(string $templateCode, int $recipientUserId, array $variables): NotificationReceipt
    {
        return array_shift($this->receipts)
            ?? NotificationReceipt::failed('NO_STUBBED_RECEIPT');
    }
}
