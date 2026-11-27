<?php

declare(strict_types=1);

namespace Tests\Feature\Engagement;

use App\Modules\Engagement\Models\Notice;
use App\Modules\Engagement\Models\Notification;
use Tests\Support\EngagementFixture;
use Tests\Support\PeopleTestCase;

/**
 * M18 read-statistics, export enrichment and reminder delivery.
 *
 * These tests are written but NOT RUN in this task. They assert that read
 * counts come from the acknowledgement ledger, exports never fabricate
 * directory fields, reminders only target unread audience members and replays
 * never create a second notification.
 */
final class NoticeReadsAndReminderTest extends PeopleTestCase
{
    use EngagementFixture;

    public function test_reads_return_audience_split_and_enriched_receipt_without_fake_rows(): void
    {
        $fixture = $this->workforceFixture();
        $noticeId = $this->publishedNoticeWithRecipients([$fixture['user_a_id'], $fixture['user_b_id']], $fixture);

        $this->actAs($fixture['user_a_id'], $fixture['employment_a_id']);
        $this->postJson("/v1/engagement/notices/{$noticeId}/acknowledge", [], [
            'Idempotency-Key' => 'reads-ack-a',
        ])->assertOk();

        $this->actAs($fixture['manager_id'], $fixture['employment_a_id']);
        $reads = $this->getJson("/v1/engagement/notices/{$noticeId}/reads")
            ->assertOk()
            ->json('data');

        // ALL resolves to all active synthetic users (manager + A + B). The
        // contract under test is the per-user split, not a fixed audience size.
        $this->assertSame(1, $reads['total']);
        $this->assertContains($fixture['user_a_id'], $reads['read_user_ids']);
        $this->assertNotContains($fixture['user_b_id'], $reads['read_user_ids']);
        $this->assertContains($fixture['user_b_id'], $reads['unread_user_ids']);
        $this->assertSame(count($reads['read_user_ids']), $reads['read_count']);
        $this->assertSame(count($reads['unread_user_ids']), $reads['unread_count']);
        $this->assertSame('E-A-'.$fixture['employee_no_suffix'], $reads['items'][0]['employee_no']);
        $this->assertArrayNotHasKey('invented_name', $reads['items'][0]);
    }

    public function test_export_fills_only_whitelisted_directory_fields_and_audits_the_access(): void
    {
        $fixture = $this->workforceFixture();
        $noticeId = $this->publishedNoticeWithRecipients([$fixture['user_a_id']], $fixture);

        $this->actAs($fixture['user_a_id'], $fixture['employment_a_id']);
        $this->postJson("/v1/engagement/notices/{$noticeId}/acknowledge", [], [
            'Idempotency-Key' => 'export-ack-a',
        ])->assertOk();

        $this->actAs($fixture['manager_id'], $fixture['employment_a_id']);
        $this->getJson("/v1/engagement/notices/{$noticeId}/reads/export?fields=user_id,employee_no,display_name")
            ->assertOk()
            ->assertJsonPath('data.fields', ['user_id', 'employee_no', 'display_name'])
            ->assertJsonPath('data.rows.0.employee_no', 'E-A-'.$fixture['employee_no_suffix'])
            ->assertJsonPath('data.rows.0.display_name', 'Synthetic Person')
            ->assertJsonPath('data.rows.0.user_id', $fixture['user_a_id']);

        $this->getJson("/v1/engagement/notices/{$noticeId}/reads/export?fields=user_id,id_number")
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'EXPORT_FIELD_NOT_ALLOWED');
    }

    public function test_remind_only_notifies_unread_snapshot_recipients_and_is_idempotent(): void
    {
        $fixture = $this->workforceFixture();
        $noticeId = $this->publishedNoticeWithRecipients([
            $fixture['user_a_id'],
            $fixture['user_b_id'],
            $fixture['manager_id'],
        ], $fixture);

        $this->actAs($fixture['user_a_id'], $fixture['employment_a_id']);
        $this->postJson("/v1/engagement/notices/{$noticeId}/acknowledge", [], [
            'Idempotency-Key' => 'remind-ack-a',
        ])->assertOk();

        $this->actAs($fixture['manager_id'], $fixture['employment_a_id']);
        $remindKey = 'remind-'.bin2hex(random_bytes(4));
        $first = $this->postJson("/v1/engagement/notices/{$noticeId}/remind", [], [
            'Idempotency-Key' => $remindKey,
        ])->assertOk()->json('data');
        $this->assertSame(2, $first['reminded_count']);

        $second = $this->postJson("/v1/engagement/notices/{$noticeId}/remind", [], [
            'Idempotency-Key' => $remindKey,
        ])->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->json('data');

        $this->assertSame($first['notification_ids'], $second['notification_ids']);
        $this->assertSame(2, Notification::query()->where('type', 'NOTICE_REMINDER')->count());
        $this->assertDatabaseCount('notice_reminders', 2);
    }

    public function test_remind_requires_published_notice_and_rejects_anonymous(): void
    {
        $fixture = $this->workforceFixture();

        $this->actAs($fixture['manager_id'], $fixture['employment_a_id']);
        $created = $this->postJson('/v1/engagement/notices', [
            'title' => 'Draft reminder guard',
            'body' => 'Synthetic draft body used to prove reminder state conflict.',
            'audience' => 'ALL',
            'audienceLabel' => 'All employees',
            'requiresAck' => true,
        ], ['Idempotency-Key' => 'draft-guard-create-'.bin2hex(random_bytes(4))])->assertCreated()->json('data');
        $draftId = (int) ($created['id'] ?? 0);
        $this->assertGreaterThan(0, $draftId);
        // Force the production-invalid state for this guard: reminder only
        // accepts PUBLISHED. Editing the row keeps the test focused on that
        // state machine without inventing a second creation endpoint.
        Notice::query()->whereKey($draftId)->update(['status' => Notice::STATUS_PENDING_APPROVAL]);

        $this->postJson("/v1/engagement/notices/{$draftId}/remind", [], [
            'Idempotency-Key' => 'remind-draft-'.bin2hex(random_bytes(4)),
        ])->assertStatus(409)
            ->assertJsonPath('data.reason', 'NOTICE_NOT_PUBLISHED');

        $this->forgetPrincipal();
        $this->postJson("/v1/engagement/notices/{$draftId}/remind", [], [
            'Idempotency-Key' => 'remind-anonymous-'.bin2hex(random_bytes(4)),
        ])->assertUnauthorized();
    }

    /**
     * @param  list<int>  $userIds
     * @param  array<string, mixed>|null  $fixture
     */
    private function publishedNoticeWithRecipients(array $userIds, ?array $fixture = null): int
    {
        $fixture ??= $this->workforceFixture();
        $this->actAs($fixture['manager_id'], $fixture['employment_a_id']);
        $noticeId = (int) $this->postJson('/v1/engagement/notices', [
            'title' => 'Reminder and read-statistics synthetic notice',
            'body' => 'Synthetic body used to exercise acknowledgement statistics and reminders.',
            'audience' => 'ALL',
            'audienceLabel' => 'All employees',
            'requiresAck' => true,
        ], ['Idempotency-Key' => 'reads-reminder-create-'.bin2hex(random_bytes(4))])->json('data.id');

        // ALL resolves to every active synthetic user. The helper accepts the
        // expected subset so the assertions remain explicit about which users
        // belong to the persisted snapshot; it never inserts fabricated users.
        $actual = Notice::query()->whereKey($noticeId)->value('audience_count');
        $this->assertGreaterThanOrEqual(count(array_values(array_unique($userIds))), (int) $actual);

        return $noticeId;
    }
}
