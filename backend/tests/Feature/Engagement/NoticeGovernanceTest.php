<?php

declare(strict_types=1);

namespace Tests\Feature\Engagement;

use App\Modules\Engagement\Models\Notice;
use App\Modules\IAM\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\EngagementFixture;
use Tests\Support\PeopleTestCase;

/**
 * Notice governance acceptance tests.
 *
 * Approval must be a real Workflow Case decision, audience previews must use
 * the live directory, and acknowledgement exports must be whitelisted and
 * leave sensitive-access evidence.
 */
final class NoticeGovernanceTest extends PeopleTestCase
{
    use EngagementFixture;

    public function test_rejected_notice_cannot_be_published_and_approval_is_a_workflow_decision(): void
    {
        $fixture = $this->workforceFixture();
        $this->grantRole($fixture['user_a_id'], 'HR_ADMIN');

        $this->actAs(1, $fixture['manager_id'], $fixture['employment_a_id']);
        $created = $this->postJson('/v1/engagement/notices', [
            'title' => 'Governed synthetic notice',
            'body' => 'Synthetic body that requires an independent workflow decision.',
            'audience' => 'ALL',
            'audienceLabel' => 'All employees',
            'requiresAck' => true,
            'approval_required' => true,
        ], ['Idempotency-Key' => 'notice-governed-create'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING_APPROVAL')
            ->json('data');

        $noticeId = (int) $created['id'];
        $caseId = (int) $created['approval_case_id'];
        $this->assertGreaterThan(0, $caseId);
        $this->assertDatabaseHas('cases', [
            'id' => $caseId,
            'tenant_id' => 1,
            'subject_type' => 'notice',
            'subject_id' => $noticeId,
        ]);

        $this->actAs(1, $fixture['user_a_id'], $fixture['employment_b_id']);
        $this->postJson("/v1/engagement/notices/{$noticeId}/decision", [
            'action' => 'REJECT',
            'comment' => 'Synthetic rejection for governance test.',
        ], ['Idempotency-Key' => 'notice-decision-reject'])
            ->assertOk()
            ->assertJsonPath('data.notice_status', 'REJECTED')
            ->assertJsonPath('data.application_status', 'REJECTED');

        $this->actAs(1, $fixture['manager_id'], $fixture['employment_a_id']);
        $this->postJson("/v1/engagement/notices/{$noticeId}/publish", [], ['Idempotency-Key' => 'notice-publish-rejected'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'NOTICE_NOT_APPROVED');

        $this->assertSame('REJECTED', (string) Notice::query()->whereKey($noticeId)->value('status'));
    }

    public function test_approved_notice_requires_an_approved_case_before_publication(): void
    {
        $fixture = $this->workforceFixture();
        $this->grantRole($fixture['user_a_id'], 'HR_ADMIN');

        $this->actAs(1, $fixture['manager_id'], $fixture['employment_a_id']);
        $noticeId = (int) $this->postJson('/v1/engagement/notices', [
            'title' => 'Approvable synthetic notice',
            'body' => 'Synthetic body that waits for an approved workflow case.',
            'audience' => 'ALL',
            'audienceLabel' => 'All employees',
            'requiresAck' => false,
            'approval_required' => true,
        ], ['Idempotency-Key' => 'notice-approved-create'])->json('data.id');

        $this->actAs(1, $fixture['user_a_id'], $fixture['employment_b_id']);
        $this->postJson("/v1/engagement/notices/{$noticeId}/decision", [
            'action' => 'APPROVE',
            'comment' => 'Synthetic approval for governance test.',
        ], ['Idempotency-Key' => 'notice-decision-approve'])
            ->assertOk()
            ->assertJsonPath('data.notice_status', 'APPROVED')
            ->assertJsonPath('data.application_status', 'APPROVED');

        $this->actAs(1, $fixture['manager_id'], $fixture['employment_a_id']);
        $this->postJson("/v1/engagement/notices/{$noticeId}/publish", [], ['Idempotency-Key' => 'notice-publish-approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED');

        $this->assertDatabaseHas('notices', ['id' => $noticeId, 'status' => 'PUBLISHED']);
    }

    public function test_audience_preview_uses_live_membership_not_a_stored_guess(): void
    {
        $fixture = $this->workforceFixture();

        $this->actAs(1, $fixture['manager_id'], $fixture['employment_a_id']);
        $noticeId = (int) $this->postJson('/v1/engagement/notices', [
            'title' => 'Org A audience preview',
            'body' => 'Synthetic org audience preview body.',
            'audience' => 'ORG',
            'audienceLabel' => 'Org A only',
            'orgUnitIds' => [$fixture['org_a_id']],
            'requiresAck' => false,
        ], ['Idempotency-Key' => 'notice-audience-create'])->json('data.id');

        $preview = $this->getJson("/v1/engagement/notices/{$noticeId}/audience")
            ->assertOk()
            ->assertJsonPath('data.audienceType', 'ORG')
            ->assertJsonPath('data.recipientCount', 1)
            ->json('data');

        $this->assertSame([$fixture['user_a_id']], $preview['recipientUserIds']);
    }

    public function test_read_details_and_export_are_whitelisted_and_audited(): void
    {
        $fixture = $this->workforceFixture();

        $this->actAs(1, $fixture['manager_id'], $fixture['employment_a_id']);
        $noticeId = (int) $this->postJson('/v1/engagement/notices', [
            'title' => 'Acknowledgement evidence notice',
            'body' => 'Synthetic notice used to prove acknowledgement evidence.',
            'audience' => 'ALL',
            'audienceLabel' => 'All employees',
            'requiresAck' => true,
        ], ['Idempotency-Key' => 'notice-reads-create'])->json('data.id');

        $this->actAs(1, $fixture['user_a_id'], $fixture['employment_a_id']);
        $this->postJson("/v1/engagement/notices/{$noticeId}/acknowledge")->assertOk();

        $this->actAs(1, $fixture['manager_id'], $fixture['employment_a_id']);
        $this->getJson("/v1/engagement/notices/{$noticeId}/reads")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.user_id', $fixture['user_a_id']);

        $this->getJson("/v1/engagement/notices/{$noticeId}/reads/export?fields=user_id,acknowledged_at")
            ->assertOk()
            ->assertJsonPath('data.fields', ['user_id', 'acknowledged_at'])
            ->assertJsonPath('data.row_count', 1)
            ->assertJsonPath('data.rows.0.user_id', $fixture['user_a_id']);

        $audit = DB::table('audit_events')
            ->where('action', 'sensitive_field_access')
            ->where('aggregate_type', 'notice_acknowledgement')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($audit);
        $metadata = json_decode((string) $audit->metadata_json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['user_id', 'acknowledged_at'], $metadata['fields']);
        $this->assertSame($noticeId, $metadata['notice_id']);

        $this->getJson("/v1/engagement/notices/{$noticeId}/reads/export?fields=user_id,id_number")
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'EXPORT_FIELD_NOT_ALLOWED');
    }

    public function test_templates_archive_and_restore_use_real_persisted_state(): void
    {
        $fixture = $this->workforceFixture();

        $this->actAs(1, $fixture['manager_id'], $fixture['employment_a_id']);
        $this->postJson('/v1/engagement/notice-templates', [
            'code' => 'PAYROLL_REMINDER',
            'name' => 'Payroll reminder',
            'body_template' => 'Payroll for {{period}} is scheduled.',
            'variables' => ['period'],
        ], ['Idempotency-Key' => 'notice-template-1'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'PAYROLL_REMINDER')
            ->assertJsonPath('data.version', 1);

        $this->getJson('/v1/engagement/notice-templates')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.code', 'PAYROLL_REMINDER');

        $noticeId = (int) $this->postJson('/v1/engagement/notices', [
            'title' => 'Archival synthetic notice',
            'body' => 'Synthetic body used for archive and restore.',
            'audience' => 'ALL',
            'audienceLabel' => 'All employees',
            'requiresAck' => false,
        ], ['Idempotency-Key' => 'notice-archive-create'])->json('data.id');

        $this->postJson("/v1/engagement/notices/{$noticeId}/archive", [
            'reason' => 'Synthetic retention window completed.',
        ], ['Idempotency-Key' => 'notice-archive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ARCHIVED');

        $this->getJson('/v1/engagement/notices/archive')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $noticeId);

        $this->postJson("/v1/engagement/notices/{$noticeId}/restore", [], ['Idempotency-Key' => 'notice-restore'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED');
    }

    private function grantRole(int $userId, string $role): void
    {
        UserRole::query()->create([
            'tenant_id' => 1,
            'user_id' => $userId,
            'role_code' => $role,
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '9999-12-31 00:00:00',
            'granted_by' => null,
            'reason' => 'TEST',
            'auth_version' => 1,
            'created_at' => $this->clock->now(),
        ]);
    }
}
