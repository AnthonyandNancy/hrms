<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Modules\IAM\Models\User;
use App\Modules\Reporting\Models\ReportRun;
use App\Modules\Reporting\Models\ReportSubscription;
use App\Modules\Reporting\Services\ReportSubscriptionService;
use App\Shared\Authorization\AnchorType;
use Tests\Fakes\SpySensitiveAccessAuditor;

/**
 * D4 / K12: a subscription is a stored, company-wide schedule over a report
 * definition. It never copies report rows, never bypasses the report field
 * whitelist, and re-authorizes its recipient against the current binding set
 * before every run.
 */
final class ReportSubscriptionTest extends ReportingTestCase
{
    private SpySensitiveAccessAuditor $sensitiveAuditor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sensitiveAuditor = new SpySensitiveAccessAuditor;
        $this->app->instance(\App\Shared\Authorization\Contracts\SensitiveAccessAuditor::class, $this->sensitiveAuditor);
    }

    public function test_subscription_requires_authorized_recipient_and_can_be_paused(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $created = $this->postJson('/v1/reporting/subscriptions', [
            'definition_code' => 'WORKFORCE_HEADCOUNT',
            'recipient_user_id' => (int) $ids['narrow_user']->id,
            'frequency' => 'MONTHLY',
            'filters' => [],
        ], ['Idempotency-Key' => 'sub-1'])->assertCreated();

        $id = (int) $created->json('data.id');
        $this->assertGreaterThan(0, $id);
        $created->assertJsonPath('data.active', true)
            ->assertJsonPath('data.frequency', 'MONTHLY')
            ->assertJsonPath('data.recipientUserId', (int) $ids['narrow_user']->id);

        $this->patchJson("/v1/reporting/subscriptions/{$id}", ['active' => false], ['Idempotency-Key' => 'sub-2'])
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->assertDatabaseHas('report_subscriptions', [
            'id' => $id,
            'active' => false,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'reporting.subscription.created',
            'aggregate_type' => 'report_subscription',
            'aggregate_id' => (string) $id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'reporting.subscription.paused',
            'aggregate_type' => 'report_subscription',
            'aggregate_id' => (string) $id,
        ]);
    }

    public function test_a_recipient_without_report_read_is_rejected_at_creation_and_reauthorized_at_execution(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $recipient = User::factory()->create();
        $this->peopleScope->employmentByUser[(int) $recipient->id] = [(int) $ids['employment_a2_id']];

        $this->postJson('/v1/reporting/subscriptions', [
            'definition_code' => 'WORKFORCE_HEADCOUNT',
            'recipient_user_id' => (int) $recipient->id,
            'frequency' => 'WEEKLY',
            'filters' => [],
        ], ['Idempotency-Key' => 'sub-no-read'])->assertStatus(422)
            ->assertJsonPath('data.reason', 'RECIPIENT_NOT_AUTHORIZED');

        $this->assertSame(0, ReportSubscription::query()->count());

        // A recipient who may read today but loses the grant tomorrow must not
        // receive a report from an already-stored subscription.
        $this->grant($recipient, 'reporting.report.read', AnchorType::ORG_UNIT, (int) $ids['org_a_id'], \App\Shared\Authorization\FieldProfile::P2_SENSITIVE);
        \Illuminate\Support\Facades\Cache::flush();

        $created = $this->postJson('/v1/reporting/subscriptions', [
            'definition_code' => 'WORKFORCE_HEADCOUNT',
            'recipient_user_id' => (int) $recipient->id,
            'frequency' => 'WEEKLY',
            'filters' => [],
        ], ['Idempotency-Key' => 'sub-read-then-revoked'])->assertCreated()->json('data');

        $this->assertTrue(app(ReportSubscriptionService::class)->authorizeForExecution(
            (int) $ids['broad_user']->id,
            (int) $created['id'],
        ));

        $this->authorizeSubscriptionRecipient($ids, $recipient, false);

        $this->assertFalse(app(ReportSubscriptionService::class)->authorizeForExecution(
            (int) $ids['broad_user']->id,
            (int) $created['id'],
        ));

        $this->assertDatabaseHas('audit_events', [
            'action' => 'reporting.subscription.execution_denied',
            'aggregate_type' => 'report_subscription',
            'aggregate_id' => (string) $created['id'],
        ]);
        $this->assertSame(0, ReportRun::query()->count(), 'A denied subscription must not execute a report.');
    }

    public function test_subscription_list_is_a_projection_and_mutations_are_idempotent_and_owner_scoped(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $payload = [
            'definition_code' => 'WORKFORCE_HEADCOUNT',
            'recipient_user_id' => (int) $ids['broad_user']->id,
            'frequency' => 'MONTHLY',
            'filters' => ['org_unit_id' => (int) $ids['org_a_id']],
        ];

        $created = $this->postJson('/v1/reporting/subscriptions', $payload, ['Idempotency-Key' => 'sub-list-1'])
            ->assertCreated()->json('data');
        $this->postJson('/v1/reporting/subscriptions', $payload, ['Idempotency-Key' => 'sub-list-1'])
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame(1, ReportSubscription::query()->count());

        $list = $this->getJson('/v1/reporting/subscriptions?page=1&page_size=20')->assertOk();
        $list->assertJsonStructure(['data' => [
            'items', 'page', 'page_size', 'total', 'as_of', 'freshness', 'source_hash',
            'partial', 'partial_reason', 'drilldown',
        ]]);
        $list->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $created['id'])
            ->assertJsonPath('data.items.0.active', true);

        $this->authenticate(
            (int) $ids['narrow_user']->id,
            (int) $ids['employment_a2_id'],
            (int) $ids['narrow_user']->auth_version,
        );

        $this->getJson('/v1/reporting/subscriptions')->assertOk()->assertJsonPath('data.total', 0);
        $this->patchJson('/v1/reporting/subscriptions/'.$created['id'], ['active' => false], ['Idempotency-Key' => 'sub-foreign-patch'])
            ->assertNotFound();
        $this->deleteJson('/v1/reporting/subscriptions/'.$created['id'], [], ['Idempotency-Key' => 'sub-foreign-delete'])
            ->assertNotFound();

        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $this->deleteJson('/v1/reporting/subscriptions/'.$created['id'], [], ['Idempotency-Key' => 'sub-delete-1'])
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
        $this->assertSoftDeleted('report_subscriptions', ['id' => $created['id']]);
    }

    public function test_export_history_is_queryable_with_hashes_whitelists_and_signed_download(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $created = $this->postExport('WORKFORCE_HEADCOUNT', [
            'fields' => ['employee_no', 'org_path'],
        ])->assertAccepted()->json('data');

        $history = $this->getJson('/v1/reporting/exports?page=1&page_size=20')->assertOk();
        $history->assertJsonStructure(['data' => [
            'items', 'page', 'page_size', 'total', 'as_of', 'freshness', 'source_hash',
            'partial', 'partial_reason', 'drilldown',
        ]]);
        $history->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $created['id'])
            ->assertJsonPath('data.items.0.fields', ['employee_no', 'org_path'])
            ->assertJsonPath('data.items.0.status', 'COMPLETED');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $history->json('data.items.0.source_hash'));
        $this->assertNotEmpty($history->json('data.items.0.downloadUrl'));
        $this->assertNotEmpty($history->json('data.items.0.auditTrail'));

        $download = $this->getJson((string) $created['downloadUrl'])
            ->assertOk();
        $this->assertStringStartsWith("\xEF\xBB\xBF", (string) $download->getContent());
        $this->assertDatabaseHas('audit_events', [
            'action' => 'reporting.export.downloaded',
            'aggregate_type' => 'export_job',
            'aggregate_id' => (string) $created['id'],
        ]);

        $this->authenticate(
            (int) $ids['narrow_user']->id,
            (int) $ids['employment_a2_id'],
            (int) $ids['narrow_user']->auth_version,
        );
        $this->getJson('/v1/reporting/exports')->assertOk()->assertJsonPath('data.total', 0);
    }

    /**
     * The real CapabilityAuthorizer resolves a recipient's row scope through
     * the production contract, and test doubles only replace those two ports.
     * To simulate revocation we remove the recipient's authority binding and
     * clear the short-lived binding cache before asking the service.
     *
     * @param  array<string, mixed>  $ids
     */
    private function authorizeSubscriptionRecipient(array $ids, User $recipient, bool $authorized): void
    {
        \App\Modules\IAM\Models\AuthorityBinding::query()
            ->where('principal_id', (int) $recipient->id)
            ->where('capability_code', 'reporting.report.read')
            ->delete();
        \Illuminate\Support\Facades\Cache::flush();

        if ($authorized) {
            $this->grant($recipient, 'reporting.report.read', AnchorType::ORG_UNIT, (int) $ids['org_a_id'], \App\Shared\Authorization\FieldProfile::P2_SENSITIVE);
        }
    }
}
