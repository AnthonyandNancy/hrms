<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Platform\Models\NotificationDelivery;
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

    public function test_delivery_log_is_a_projection_and_failed_delivery_can_be_retried_once(): void
    {
        $this->postJson('/v1/notification-templates', [
            'code' => 'APPROVAL_PENDING',
            'channel' => 'IN_APP',
            'subject' => 'Approval pending',
            'body' => 'You have a pending approval.',
        ], ['Idempotency-Key' => 'nt-retry'])->assertCreated();

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
