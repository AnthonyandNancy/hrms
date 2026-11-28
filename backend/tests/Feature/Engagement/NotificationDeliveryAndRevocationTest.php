<?php

declare(strict_types=1);

namespace Tests\Feature\Engagement;

use App\Modules\Engagement\Contracts\ExternalNotificationChannel;
use App\Modules\Engagement\Services\NotificationService;
use App\Modules\Engagement\Services\PayslipNotificationService;
use App\Modules\Payroll\Contracts\PayslipNotificationDirectory;
use App\Modules\Payroll\Contracts\PublishedPayslipRef;
use App\Shared\Notifications\NotificationReceipt;
use Illuminate\Support\Facades\DB;
use Tests\Support\EngagementFixture;
use Tests\Support\PeopleTestCase;

/**
 * T16 notification delivery is evidence-based. A provider receipt is the only
 * reason a delivery may be SENT; repeated business events must not duplicate an
 * inbox row, and a revoked/expired target must not remain readable.
 */
final class NotificationDeliveryAndRevocationTest extends PeopleTestCase
{
    use EngagementFixture;

    public function test_without_a_provider_receipt_a_delivery_is_not_sent_and_can_be_retried_once(): void
    {
        $fixture = $this->workforceFixture();
        $channel = new ReceiptNotificationChannel([
            NotificationReceipt::failed('SYNTHETIC_PROVIDER_TIMEOUT'),
        ]);
        $this->app->instance(ExternalNotificationChannel::class, $channel);

        $service = $this->app->make(NotificationService::class);
        $ids = $service->notify(
            [$fixture['user_a_id']],
            'APPROVAL_PENDING',
            ['case_id' => 91],
            eventKey: 'case-91-approved-pending',
        );

        $this->assertCount(1, $ids);
        $delivery = DB::table('notification_deliveries')->first();
        $this->assertNotNull($delivery);
        $this->assertSame('FAILED', (string) $delivery->status);
        $this->assertNull($delivery->provider_message_id);
        $this->assertSame(1, (int) $delivery->attempts);

        $channel->queue(NotificationReceipt::delivered('provider-receipt-2'));
        $retried = $service->notify(
            [$fixture['user_a_id']],
            'APPROVAL_PENDING',
            ['case_id' => 91],
            eventKey: 'case-91-approved-pending',
        );

        $this->assertSame($ids, $retried);
        $this->assertSame(1, DB::table('notifications')->count());
        $this->assertSame(1, DB::table('notification_deliveries')->count());
        $delivery = DB::table('notification_deliveries')->first();
        $this->assertSame('SENT', (string) $delivery->status);
        $this->assertSame('provider-receipt-2', (string) $delivery->provider_message_id);
        $this->assertSame(2, (int) $delivery->attempts);
        $this->assertCount(2, $channel->calls);
    }

    public function test_repeating_the_same_delivery_event_does_not_create_or_send_a_second_notification(): void
    {
        $fixture = $this->workforceFixture();
        $channel = new ReceiptNotificationChannel;
        $this->app->instance(ExternalNotificationChannel::class, $channel);

        $service = $this->app->make(NotificationService::class);
        $first = $service->notify(
            [$fixture['user_a_id']],
            'NOTICE_PUBLISHED',
            ['notice_id' => 7001, 'requires_ack' => false],
            eventKey: 'notice-published:7001',
        );
        $second = $service->notify(
            [$fixture['user_a_id']],
            'NOTICE_PUBLISHED',
            ['notice_id' => 7001, 'requires_ack' => false],
            eventKey: 'notice-published:7001',
        );

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('notifications')->count());
        $this->assertSame(1, DB::table('notification_deliveries')->count());
        $this->assertSame('SENT', (string) DB::table('notification_deliveries')->value('status'));
        $this->assertCount(1, $channel->calls);
    }

    public function test_retracted_notice_notification_is_invalid_and_can_no_longer_be_opened_or_marked_read(): void
    {
        $fixture = $this->workforceFixture();
        $this->app->instance(
            \App\Shared\Authorization\Authorizer::class,
            new \Tests\Support\EngagementAuthorizer($fixture['manager_id']),
        );
        $this->app->instance(ExternalNotificationChannel::class, new ReceiptNotificationChannel);

        $this->actAs($fixture['manager_id'], $fixture['employment_a_id']);
        $noticeId = (int) $this->postJson('/v1/engagement/notices', [
            'title' => 'Synthetic revocable notice',
            'body' => 'Synthetic notice body used to prove old links stop working.',
            'audience' => 'ALL',
            'audienceLabel' => '全体人员',
            'requiresAck' => false,
        ])->assertCreated()->json('data.id');

        $this->actAs($fixture['user_a_id'], $fixture['employment_a_id']);
        $before = $this->getJson('/v1/engagement/notifications')
            ->assertOk()
            ->json('data.0');
        $this->assertTrue((bool) $before['valid']);
        $this->assertSame(['notice_id' => $noticeId, 'requires_ack' => false], $before['payload']);

        $this->actAs($fixture['manager_id'], $fixture['employment_a_id']);
        $this->postJson('/v1/engagement/notices/'.$noticeId.'/retract')
            ->assertOk()
            ->assertJsonPath('data.status', 'RETRACTED');

        $this->actAs($fixture['user_a_id'], $fixture['employment_a_id']);
        $after = $this->getJson('/v1/engagement/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');
        $this->assertFalse((bool) $after['valid']);
        $this->assertSame([], $after['payload']);
        $this->assertStringContainsString('撤回、过期或不再属于有效受众', (string) $after['invalidReason']);
        $this->postJson('/v1/engagement/notifications/'.$before['id'].'/read')->assertNotFound();
        $this->getJson('/v1/engagement/notices/'.$noticeId)->assertNotFound();
    }

    public function test_audience_change_makes_the_old_notification_link_invalid(): void
    {
        $fixture = $this->workforceFixture();
        $this->app->instance(
            \App\Shared\Authorization\Authorizer::class,
            new \Tests\Support\EngagementAuthorizer($fixture['manager_id']),
        );
        $this->app->instance(ExternalNotificationChannel::class, new ReceiptNotificationChannel);

        $this->actAs($fixture['manager_id'], $fixture['employment_a_id']);
        $noticeId = (int) $this->postJson('/v1/engagement/notices', [
            'title' => 'Synthetic role-scoped notice',
            'body' => 'Synthetic notice body used to prove live audience checks.',
            'audience' => 'ROLE',
            'audienceLabel' => 'Employees',
            'roleCodes' => ['EMPLOYEE'],
            'publishAt' => '2026-11-23T00:00:00Z',
            'requiresAck' => false,
        ])->assertCreated()->json('data.id');

        $this->actAs($fixture['user_a_id'], $fixture['employment_a_id']);
        $visible = $this->getJson('/v1/engagement/notifications')
            ->assertOk()
            ->json('data.0');
        $this->assertTrue((bool) $visible['valid']);
        $this->assertSame(['notice_id' => $noticeId, 'requires_ack' => false], $visible['payload']);

        // The frozen snapshot still names user A, but the live audience no
        // longer does. Possession of the old notification is not authority.
        \App\Modules\IAM\Models\UserRole::query()
            ->where('user_id', $fixture['user_a_id'])
            ->where('role_code', 'EMPLOYEE')
            ->delete();

        $invalid = $this->getJson('/v1/engagement/notifications')
            ->assertOk()
            ->json('data.0');
        $this->assertFalse((bool) $invalid['valid']);
        $this->assertSame([], $invalid['payload']);
        $this->assertStringContainsString('不再属于有效受众', (string) $invalid['invalidReason']);
        $this->postJson('/v1/engagement/notifications/'.$visible['id'].'/read')->assertNotFound();
        $this->getJson('/v1/engagement/notices/'.$noticeId)->assertNotFound();
    }

    public function test_expired_notice_notification_is_invalid_and_old_notice_link_returns_not_found(): void
    {
        $fixture = $this->workforceFixture();
        $this->app->instance(
            \App\Shared\Authorization\Authorizer::class,
            new \Tests\Support\EngagementAuthorizer($fixture['manager_id']),
        );
        $this->app->instance(ExternalNotificationChannel::class, new ReceiptNotificationChannel);

        $this->actAs($fixture['manager_id'], $fixture['employment_a_id']);
        $noticeId = (int) $this->postJson('/v1/engagement/notices', [
            'title' => 'Synthetic expiring notice',
            'body' => 'Synthetic notice body used to prove expiry handling.',
            'audience' => 'ALL',
            'audienceLabel' => '全体人员',
            'publishAt' => '2026-11-23T00:00:00Z',
            'expiresAt' => '2026-11-23T02:00:00Z',
            'requiresAck' => false,
        ])->assertCreated()->json('data.id');

        $this->clock->setNow(\Carbon\CarbonImmutable::parse('2026-11-23T03:00:00Z', 'UTC'));

        $this->actAs($fixture['user_a_id'], $fixture['employment_a_id']);
        $notification = $this->getJson('/v1/engagement/notifications')
            ->assertOk()
            ->json('data.0');
        $this->assertFalse((bool) $notification['valid']);
        $this->assertSame([], $notification['payload']);
        $this->getJson('/v1/engagement/notices/'.$noticeId)->assertNotFound();
    }

    public function test_payslip_notification_contains_only_ids_and_is_rechecked_against_publication_and_self_scope(): void
    {
        $fixture = $this->workforceFixture();
        $directory = new FakePayslipNotificationDirectory([
            501 => new PublishedPayslipRef(
                payslipId: 501,
                employmentId: $fixture['employment_a_id'],
                runId: 77,
                period: '2026-11',
            ),
        ]);
        $this->app->instance(PayslipNotificationDirectory::class, $directory);
        $this->app->instance(ExternalNotificationChannel::class, new ReceiptNotificationChannel);

        $this->app->make(PayslipNotificationService::class)->notifyRunPublished(77);

        $notification = DB::table('notifications')
            ->where('type', 'PAYSLIP_PUBLISHED')
            ->where('user_id', $fixture['user_a_id'])
            ->first();
        $this->assertNotNull($notification);
        $payload = json_decode((string) $notification->payload_json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['payslip_id' => 501, 'period' => '2026-11'], $payload);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['gross', 'net', 'amount', 'employer', 'cents'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }

        $this->actAs($fixture['user_a_id'], $fixture['employment_a_id']);
        $visible = $this->getJson('/v1/engagement/notifications')
            ->assertOk()
            ->json('data.0');
        $this->assertTrue((bool) $visible['valid']);
        $this->assertSame(['payslip_id' => 501, 'period' => '2026-11'], $visible['payload']);

        $directory->published = [];
        $invalid = $this->getJson('/v1/engagement/notifications')
            ->assertOk()
            ->json('data.0');
        $this->assertFalse((bool) $invalid['valid']);
        $this->assertSame([], $invalid['payload']);
        $this->postJson('/v1/engagement/notifications/'.$visible['id'].'/read')->assertNotFound();
    }
}

final class ReceiptNotificationChannel implements ExternalNotificationChannel
{
    /** @var list<array{type: string, user_id: int, payload: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<NotificationReceipt> */
    private array $receipts;

    /**
     * @param  list<NotificationReceipt>  $receipts
     */
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
        return 'LOG_STUB';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(string $type, int $userId, array $payload): NotificationReceipt
    {
        $this->calls[] = ['type' => $type, 'user_id' => $userId, 'payload' => $payload];

        return array_shift($this->receipts)
            ?? NotificationReceipt::delivered('receipt-'.count($this->calls));
    }
}

final class FakePayslipNotificationDirectory implements PayslipNotificationDirectory
{
    /**
     * @param  array<int, PublishedPayslipRef>  $published
     */
    public function __construct(public array $published) {}

    public function publishedPayslip(int $payslipId): ?PublishedPayslipRef
    {
        return $this->published[$payslipId] ?? null;
    }

    /** @return list<PublishedPayslipRef> */
    public function publishedForRun(int $runId): array
    {
        return array_values(array_filter(
            $this->published,
            static fn (PublishedPayslipRef $payslip): bool => $payslip->runId === $runId,
        ));
    }
}
