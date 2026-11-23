<?php

declare(strict_types=1);

namespace Tests\Feature\Engagement;

use App\Modules\Engagement\Models\Notice;
use Tests\Support\EngagementFixture;
use Tests\Support\PeopleTestCase;

final class NoticeAudienceTest extends PeopleTestCase
{
    use EngagementFixture;

    public function test_org_audience_is_resolved_through_organization_contracts_and_is_visible_only_to_targets(): void
    {
        $fixture = $this->workforceFixture();
        $this->app->instance(\App\Shared\Authorization\Authorizer::class, new \Tests\Support\EngagementAuthorizer($fixture['manager_id']));

        $this->actAs(1, $fixture['manager_id'], $fixture['employment_a_id']);
        $created = $this->postJson('/v1/engagement/notices', [
            'title' => 'Org A safety briefing',
            'body' => "Synthetic safety notice for Org A.\nPlease read carefully.",
            'audience' => 'ORG',
            'audienceLabel' => 'Org A',
            'orgUnitIds' => [$fixture['org_a_id']],
            'publishAt' => '2026-11-23T00:00:00Z',
            'requiresAck' => true,
        ])->assertCreated()
            ->assertJsonPath('data.audienceLabel', 'Org A')
            ->assertJsonPath('data.status', 'PUBLISHED');

        $noticeId = (int) $created->json('data.id');

        $this->actAs(1, $fixture['user_a_id'], $fixture['employment_a_id']);
        $this->getJson('/v1/engagement/notices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $noticeId)
            ->assertJsonPath('data.0.acknowledged', false);

        $this->actAs(1, $fixture['user_b_id'], $fixture['employment_b_id']);
        $this->getJson('/v1/engagement/notices')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/v1/engagement/notices/'.$noticeId)->assertNotFound();
    }

    public function test_role_audience_and_acknowledgement_is_idempotent(): void
    {
        $fixture = $this->workforceFixture();

        $this->actAs(1, $fixture['manager_id'], $fixture['employment_a_id']);
        $noticeId = (int) $this->postJson('/v1/engagement/notices', [
            'title' => 'Employee handbook acknowledgement',
            'body' => 'Synthetic handbook acknowledgement body for the test.',
            'audience' => 'ROLE',
            'audienceLabel' => 'Employees',
            'roleCodes' => ['EMPLOYEE'],
            'publishAt' => '2026-11-23T00:00:00Z',
            'requiresAck' => true,
        ])->assertCreated()->json('data.id');

        $this->actAs(1, $fixture['user_b_id'], $fixture['employment_b_id']);
        $this->postJson("/v1/engagement/notices/{$noticeId}/acknowledge")->assertOk();
        $this->postJson("/v1/engagement/notices/{$noticeId}/acknowledge")
            ->assertOk()
            ->assertJsonPath('data.acknowledged', true)
            ->assertJsonPath('data.acknowledgementCount', 1);

        $this->assertDatabaseCount('notice_acknowledgements', 1);
        $this->getJson('/v1/engagement/notices?filter=acknowledged')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_all_audience_reaches_notifications_created_for_target_users_without_cross_user_leakage(): void
    {
        $fixture = $this->workforceFixture();

        $this->actAs(1, $fixture['manager_id'], $fixture['employment_a_id']);
        $noticeId = (int) $this->postJson('/v1/engagement/notices', [
            'title' => 'Company all-hands notice',
            'body' => 'Synthetic all-hands notice body for notification testing.',
            'audience' => 'ALL',
            'audienceLabel' => 'All employees',
            'publishAt' => '2026-11-23T00:00:00Z',
            'requiresAck' => false,
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('notifications', [
            'tenant_id' => 1,
            'user_id' => $fixture['user_a_id'],
            'type' => 'NOTICE_PUBLISHED',
        ]);
        $this->assertDatabaseHas('notifications', [
            'tenant_id' => 1,
            'user_id' => $fixture['user_b_id'],
            'type' => 'NOTICE_PUBLISHED',
        ]);

        $this->actAs(1, $fixture['user_a_id'], $fixture['employment_a_id']);
        $this->getJson('/v1/engagement/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'NOTICE_PUBLISHED')
            ->assertJsonPath('data.0.payload.notice_id', $noticeId);

        $notificationId = (int) $this->getJson('/v1/engagement/notifications')->json('data.0.id');
        $this->postJson("/v1/engagement/notifications/{$notificationId}/read")
            ->assertOk()
            ->assertJsonPath('data.read', true);

        $this->actAs(1, $fixture['user_b_id'], $fixture['employment_b_id']);
        $this->postJson("/v1/engagement/notifications/{$notificationId}/read")->assertNotFound();
    }

    public function test_retraction_hides_notice_from_target_but_keeps_acknowledgements(): void
    {
        $fixture = $this->workforceFixture();
        $this->actAs(1, $fixture['manager_id'], $fixture['employment_a_id']);
        $noticeId = (int) $this->postJson('/v1/engagement/notices', [
            'title' => 'Retractable synthetic notice',
            'body' => 'Synthetic body that will be retracted shortly.',
            'audience' => 'ALL',
            'audienceLabel' => 'All employees',
            'publishAt' => '2026-11-23T00:00:00Z',
            'requiresAck' => true,
        ])->json('data.id');

        $this->actAs(1, $fixture['user_a_id'], $fixture['employment_a_id']);
        $this->postJson("/v1/engagement/notices/{$noticeId}/acknowledge")->assertOk();

        $this->actAs(1, $fixture['manager_id'], $fixture['employment_a_id']);
        $this->postJson("/v1/engagement/notices/{$noticeId}/retract")
            ->assertOk()
            ->assertJsonPath('data.status', 'RETRACTED');

        $this->actAs(1, $fixture['user_a_id'], $fixture['employment_a_id']);
        $this->getJson('/v1/engagement/notices')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('notice_acknowledgements', 1);
    }

    public function test_cross_tenant_notice_detail_is_not_found(): void
    {
        $fixture = $this->workforceFixture();
        $foreign = Notice::query()->create([
            'tenant_id' => 2,
            'title' => 'Foreign tenant notice',
            'body' => 'Synthetic foreign notice body.',
            'audience_json' => ['type' => 'ALL', 'label' => 'All employees'],
            'publish_at' => '2026-11-23 00:00:00',
            'retract_at' => null,
            'created_by' => $fixture['manager_id'],
            'status' => 'PUBLISHED',
            'requires_ack' => false,
            'audience_count' => 0,
        ]);

        $this->actAs(1, $fixture['user_a_id'], $fixture['employment_a_id']);
        $this->getJson('/v1/engagement/notices/'.$foreign->id)->assertNotFound();
    }

    public function test_notice_routes_reject_unauthenticated_and_missing_capability(): void
    {
        $fixture = $this->workforceFixture();

        $this->actAs(1, $fixture['user_a_id'], $fixture['employment_a_id']);
        $this->authorizer->deniedCapabilities = ['engagement.notice.read'];
        $this->getJson('/v1/engagement/notices')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');

        $this->actAs(1, $fixture['manager_id'], $fixture['employment_a_id']);
        $this->authorizer->deniedCapabilities = ['engagement.notice.manage'];
        $this->postJson('/v1/engagement/notices', [
            'title' => 'Denied notice',
            'body' => 'Synthetic denied notice body.',
            'audience' => 'ALL',
            'audienceLabel' => 'All',
            'publishAt' => '2026-11-23T00:00:00Z',
            'requiresAck' => false,
        ])->assertForbidden();

        $this->forgetPrincipal();
        $this->getJson('/v1/engagement/notices')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
