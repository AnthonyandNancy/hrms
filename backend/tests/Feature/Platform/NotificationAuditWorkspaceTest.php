<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Engagement\Models\Notification;
use App\Modules\IAM\Models\AuthEvent;
use Illuminate\Support\Carbon;
use Tests\Support\PeopleTestCase;

/**
 * Contract tests for the two platform workspaces. These assert the real routes
 * only: caller-scoped inbox reads, idempotent single mark-read, the audit
 * capability gate and the paginated auth-event projection.
 */
final class NotificationAuditWorkspaceTest extends PeopleTestCase
{
    public function test_notification_list_is_scoped_to_the_caller_and_never_leaks_another_users_inbox(): void
    {
        $mine = $this->notification(10, 'APPROVAL_PENDING', ['case_id' => 71], false);
        $this->notification(11, 'NOTICE_PUBLISHED', ['notice_id' => 42], false);

        $this->actingAsUserWithCapabilities(['notification.read']);

        $response = $this->getJson('/v1/engagement/notifications')->assertOk();

        $this->assertSame([$mine->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.type', 'APPROVAL_PENDING')
            ->assertJsonPath('data.0.read', false)
            ->assertJsonMissing(['notice_id' => 42]);
    }

    public function test_mark_read_is_idempotent_and_does_not_touch_another_users_notification(): void
    {
        $mine = $this->notification(10, 'CASE_APPROVED', ['case_id' => 71], false);
        $other = $this->notification(11, 'CASE_REJECTED', ['case_id' => 72], false);

        $this->actingAsUserWithCapabilities(['notification.read']);

        $this->postJson('/v1/engagement/notifications/'.$mine->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.id', $mine->id)
            ->assertJsonPath('data.read', true);

        $firstReadAt = $mine->fresh()?->read_at?->toIso8601ZuluString();
        $this->assertNotNull($firstReadAt);

        $this->clock->setNow(Carbon::parse('2026-11-23T02:00:00Z', 'UTC'));

        $this->postJson('/v1/engagement/notifications/'.$mine->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.id', $mine->id)
            ->assertJsonPath('data.read', true);

        $this->assertSame($firstReadAt, $mine->fresh()?->read_at?->toIso8601ZuluString());
        $this->assertNull($other->fresh()?->read_at);

        $this->postJson('/v1/engagement/notifications/'.$other->id.'/read')->assertNotFound();
    }

    public function test_audit_list_requires_the_audit_capability_and_denies_a_regular_admin(): void
    {
        $this->authEvent(15, 'user.status.changed', 'SUCCESS');
        $this->actAs(12);

        $this->actingAsUserWithCapabilities(['iam.user.read']);
        $this->getJson('/v1/auth/events')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');

        $this->actingAsUserWithCapabilities(['iam.audit.read']);
        $this->getJson('/v1/auth/events')->assertOk();
    }

    public function test_audit_list_returns_the_paginated_security_projection_with_request_identifiers(): void
    {
        $older = $this->authEvent(15, 'login.succeeded', 'SUCCESS', 'req-audit-older');
        $newer = $this->authEvent(16, 'role.assigned', 'SUCCESS', 'req-audit-newer');

        $this->actAs(12);
        $this->actingAsUserWithCapabilities(['iam.audit.read']);

        $response = $this->getJson('/v1/auth/events?page=1&page_size=1')->assertOk();

        $response->assertJsonStructure([
            'data' => ['items', 'page', 'page_size', 'total'],
        ])->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 1)
            ->assertJsonPath('data.total', 2)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $newer->id)
            ->assertJsonPath('data.items.0.event_type', 'role.assigned')
            ->assertJsonPath('data.items.0.request_id', 'req-audit-newer');

        $second = $this->getJson('/v1/auth/events?page=2&page_size=1')->assertOk();
        $second->assertJsonPath('data.items.0.id', $older->id)
            ->assertJsonPath('data.items.0.request_id', 'req-audit-older');
    }

    public function test_audit_events_are_append_only_and_reading_them_does_not_create_a_second_event(): void
    {
        $event = $this->authEvent(15, 'login.failure', 'FAILURE', 'req-append-only');
        $this->actingAsUserWithCapabilities(['iam.audit.read']);

        $this->getJson('/v1/auth/events')->assertOk();

        $this->assertDatabaseCount('auth_events', 1);
        $this->assertSame($event->id, AuthEvent::query()->sole()->id);

        $this->expectException(\DomainException::class);
        $event->forceFill(['result' => 'SUCCESS'])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function notification(int $userId, string $type, array $payload, bool $read): Notification
    {
        return Notification::query()->create([
            'user_id' => $userId,
            'type' => $type,
            'payload_json' => $payload,
            'read_at' => $read ? $this->clock->now() : null,
        ]);
    }

    private function authEvent(int $userId, string $eventType, string $result, string $requestId = ''): AuthEvent
    {
        return AuthEvent::query()->create([
            'user_id' => $userId,
            'event_type' => $eventType,
            'result' => $result,
            'request_id' => $requestId !== '' ? $requestId : null,
            'metadata_json' => ['role_code' => 'SYNTHETIC_ROLE'],
            'occurred_at' => $this->clock->now(),
        ]);
    }
}
