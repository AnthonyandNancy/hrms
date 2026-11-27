<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Modules\IAM\Contracts\UserAudienceDirectory;
use App\Modules\Workflow\Models\Decision;
use App\Modules\Workflow\Models\Effect;
use App\Modules\Workflow\Models\WorkflowTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WorkflowTestHelpers;
use Tests\TestCase;

/**
 * Contract tests for the Workflow Center read projection. These are written
 * but intentionally NOT RUN as part of this implementation pass.
 */
final class CaseProjectionNamesTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private UserAudienceDirectory $names;

    protected function setUp(): void
    {
        parent::setUp();

        $this->names = new class implements UserAudienceDirectory
        {
            /** @var array<int, string> */
            public array $names = [10 => 'demo.approver', 12 => 'demo.requester', 15 => 'demo.decider', 20 => 'demo.proxy'];

            public function activeUserIds(): array
            {
                return array_keys($this->names);
            }

            public function userIdsWithRoles(array $roleCodes, string $businessDate): array
            {
                return [];
            }

            public function roleCodesForUser(int $userId, string $businessDate): array
            {
                return [];
            }

            public function displayName(int $userId): ?string
            {
                return $this->names[$userId] ?? null;
            }
        };
    }

    public function test_case_projection_includes_display_names_and_rejects_unknown_view(): void
    {
        $this->bootWorkflow(userId: 10);
        $this->app->instance(UserAudienceDirectory::class, $this->names);
        $template = $this->createTemplate([], $this->singleApprovalDefinition([], 'USER', ['user_id' => 10]));
        $case = $this->submittedCase($template);

        // Fixture identity: user 10 is the assignee, user 12 is the requester.
        $case->forceFill(['requester_user_id' => 12])->save();

        $this->getJson('/v1/cases?view=my_approvals')
            ->assertOk()
            ->assertJsonPath('data.items.0.requester_display_name', 'demo.requester')
            ->assertJsonPath('data.items.0.current_assignee_display_name', 'demo.approver')
            ->assertJsonPath('data.items.0.decided_by_display_name', null)
            ->assertJsonPath('data.items.0.decision_context.is_awaiting_me', true)
            ->assertJsonPath('data.items.0.decision_context.can_admin_override', true)
            ->assertJsonPath('data.items.0.decision_context.requires_reason', false);

        $this->getJson("/v1/cases/{$case->id}")
            ->assertOk()
            ->assertJsonPath('data.requester_display_name', 'demo.requester')
            ->assertJsonPath('data.current_assignee_display_name', 'demo.approver')
            ->assertJsonPath('data.decision_context.is_awaiting_me', true);

        $this->getJson('/v1/cases?view=all')
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'INVALID_CASE_VIEW');
    }

    public function test_decision_timeline_projects_display_name_and_admin_override_reason(): void
    {
        $this->bootWorkflow(userId: 15);
        $this->app->instance(UserAudienceDirectory::class, $this->names);
        $template = $this->createTemplate([], $this->singleApprovalDefinition([], 'USER', ['user_id' => 10]));
        $case = $this->submittedCase($template);

        $this->decideThroughApi($case->id, 'projection-override-key', 'APPROVE', [
            'reason' => '直属主管长期休假，由管理员按制度代批。',
        ])->assertOk();

        $decision = Decision::query()->where('case_id', $case->id)->firstOrFail();
        $this->assertTrue($decision->actor_snapshot_json['admin_override']);
        $this->assertSame(
            '直属主管长期休假，由管理员按制度代批。',
            $decision->actor_snapshot_json['admin_override_reason'],
        );

        $timeline = $this->getJson("/v1/cases/{$case->id}/timeline")->assertOk();
        $decisionItem = collect((array) $timeline->json('data.items'))
            ->firstWhere('type', 'DECISION');

        $this->assertSame('demo.decider', $decisionItem['decided_by_display_name']);
        $this->assertTrue($decisionItem['admin_override']);
        $this->assertSame('直属主管长期休假，由管理员按制度代批。', $decisionItem['reason']);

        $this->getJson('/v1/cases?view=my_decided')
            ->assertOk()
            ->assertJsonPath('data.items.0.decided_by_display_name', 'demo.decider');
    }

    public function test_case_payload_projection_is_bounded_to_known_business_summary_keys(): void
    {
        $this->bootWorkflow(userId: 10);
        $this->app->instance(UserAudienceDirectory::class, $this->names);
        $template = $this->createTemplate();
        $case = $this->submittedCase($template, [
            'payload' => [
                'reason' => 'Synthetic family visit',
                'destination' => 'Shanghai',
                'from_date' => '2026-12-01',
                'to_date' => '2026-12-02',
                'amount_minor' => 125000,
                'employee_no' => 'SYN-0001',
                'id_number' => 'SHOULD-NOT-LEAK',
                'nested' => ['secret' => 'SHOULD-NOT-LEAK'],
            ],
        ]);

        $this->getJson("/v1/cases/{$case->id}")
            ->assertOk()
            ->assertJsonPath('data.payload.reason', 'Synthetic family visit')
            ->assertJsonPath('data.payload.amount_minor', 125000)
            ->assertJsonMissingPath('data.payload.id_number')
            ->assertJsonMissingPath('data.payload.nested');
    }

    public function test_failed_effect_projection_exposes_attempts_and_sanitized_failure_summary(): void
    {
        $this->bootWorkflow(userId: 10);
        $this->app->instance(UserAudienceDirectory::class, $this->names);
        $template = $this->createTemplate();
        $case = $this->submittedCase($template);
        $decision = Decision::query()->create([
            'case_id' => $case->id,
            'node_key' => 'manager_approval',
            'actor_user_id' => 10,
            'decision_seq' => 1,
            'action' => 'APPROVE',
            'comment' => null,
            'actor_snapshot_json' => ['user_id' => 10, 'auth_version' => 3, 'roles' => [], 'capabilities' => []],
            'template_version' => 1,
            'evidence_hash' => null,
            'decided_at' => now('UTC'),
            'request_id' => 'synthetic-request',
        ]);
        $effect = Effect::query()->create([
            'case_id' => $case->id,
            'decision_id' => $decision->id,
            'effect_type' => 'workflow.noop',
            'aggregate_type' => 'case',
            'aggregate_id' => (string) $case->id,
            'effect_seq' => 1,
            'status' => 'FAILED',
            'scheduled_for' => null,
            'applied_at' => null,
            'failed_at' => now('UTC'),
            'failure_reason' => 'Downstream timeout at D:/srv/app/PrivateHandler.php:42',
            'attempts' => 2,
            'payload_json' => [],
            'requires_escalation' => false,
        ]);

        $detail = $this->getJson("/v1/cases/{$case->id}")->assertOk();
        $detail->assertJsonPath('data.effects.0.effect_id', $effect->id)
            ->assertJsonPath('data.effects.0.status', 'FAILED')
            ->assertJsonPath('data.effects.0.attempts', 2)
            ->assertJsonPath('data.effects.0.requires_escalation', false);

        $failure = (string) $detail->json('data.effects.0.failure_summary');
        $this->assertStringContainsString('Downstream timeout', $failure);
        $this->assertStringNotContainsString('D:/srv', $failure);
        $this->assertStringNotContainsString('PrivateHandler.php', $failure);
    }

    public function test_current_user_task_projection_never_returns_another_assignee(): void
    {
        $this->bootWorkflow(userId: 11);
        $this->app->instance(UserAudienceDirectory::class, $this->names);
        $template = $this->createTemplate([], $this->singleApprovalDefinition([], 'USER', ['user_id' => 10]));
        $case = $this->submittedCase($template);

        $this->getJson('/v1/tasks/my')
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        $this->assertSame(0, WorkflowTask::query()->where('case_id', $case->id)->where('assignee_user_id', 11)->count());
    }
}
