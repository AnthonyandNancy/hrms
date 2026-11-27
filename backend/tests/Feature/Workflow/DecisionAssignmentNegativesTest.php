<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Models\Decision;
use App\Modules\Workflow\Models\Effect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ProbeEffectHandler;
use Tests\Support\WorkflowTestHelpers;
use Tests\TestCase;

/**
 * Decision and effect negative matrix. Written, intentionally NOT RUN in this
 * implementation pass.
 */
final class DecisionAssignmentNegativesTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_non_assignee_gets_404_and_admin_override_requires_reason(): void
    {
        $this->bootWorkflow(userId: 10);
        $template = $this->createTemplate([], $this->singleApprovalDefinition([], 'USER', ['user_id' => 55]));
        $case = $this->submittedCase($template);

        $this->actAs(99, ['workflow.case.read', 'workflow.case.decide']);
        $this->decideThroughApi($case->id, 'non-assignee-key')->assertNotFound();
        $this->assertSame(0, Decision::query()->where('case_id', $case->id)->count());

        $this->actAs(1, ['workflow.case.read', 'workflow.case.decide', 'workflow.case.admin']);
        $this->decideThroughApi($case->id, 'override-empty', 'APPROVE', ['reason' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->decideThroughApi($case->id, 'override-too-short', 'APPROVE', ['reason' => '太短'])
            ->assertUnprocessable();

        $this->decideThroughApi(
            $case->id,
            'override-ok',
            'APPROVE',
            ['reason' => '直属主管长期休假，由管理员按制度代批'],
        )->assertOk();

        $this->assertDatabaseHas('decisions', [
            'case_id' => $case->id,
            'actor_user_id' => 1,
        ]);

        $decision = Decision::query()->where('case_id', $case->id)->firstOrFail();
        $this->assertTrue($decision->actor_snapshot_json['admin_override']);
        $this->assertSame('直属主管长期休假，由管理员按制度代批', $decision->actor_snapshot_json['admin_override_reason']);
    }

    public function test_non_admin_with_reason_still_gets_404_for_someone_elses_task(): void
    {
        $this->bootWorkflow(userId: 10);
        $template = $this->createTemplate([], $this->singleApprovalDefinition([], 'USER', ['user_id' => 55]));
        $case = $this->submittedCase($template);

        $this->actAs(99, ['workflow.case.read', 'workflow.case.decide']);
        $this->decideThroughApi($case->id, 'non-admin-reason-key', 'APPROVE', [
            'reason' => '这是一段超过十个字符的理由但仍然不是管理员',
        ])->assertNotFound();

        $this->assertSame(0, Decision::query()->where('case_id', $case->id)->count());
    }

    public function test_draft_decision_is_stable_409_and_does_not_create_decision(): void
    {
        $this->bootWorkflow(userId: 10);
        $template = $this->createTemplate();
        $case = $this->createCaseThroughApi($template)->assertCreated()->json('data');
        $caseId = (int) $case['id'];

        $this->decideThroughApi($caseId, 'draft-decision-key')
            ->assertStatus(409)
            ->assertJsonPath('code', 'CONFLICT')
            ->assertJsonPath('data.reason', 'INVALID_CASE_TRANSITION');

        $this->assertSame(0, Decision::query()->where('case_id', $caseId)->count());
    }

    public function test_same_key_replays_but_changed_payload_is_conflict(): void
    {
        $this->bootWorkflow(userId: 10);
        $template = $this->createTemplate();
        $case = $this->submittedCase($template);

        $first = $this->decideThroughApi($case->id, 'payload-mismatch-key')->assertOk();
        $replay = $this->decideThroughApi($case->id, 'payload-mismatch-key')->assertOk();
        $replay->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json('data'), $replay->json('data'));

        $this->decideThroughApi($case->id, 'payload-mismatch-key', 'APPROVE', [
            'comment' => 'Different payload under the same key.',
        ])->assertStatus(409)->assertJsonPath('data.reason', 'IDEMPOTENCY_PAYLOAD_MISMATCH');

        $this->assertSame(1, Decision::query()->where('case_id', $case->id)->count());
    }

    public function test_decision_and_effect_retry_require_idempotency_key_headers(): void
    {
        $this->bootWorkflow(userId: 10);
        $template = $this->createTemplate();
        $case = $this->submittedCase($template);

        $this->postJson("/v1/cases/{$case->id}/decisions", ['action' => 'APPROVE', 'comment' => 'No key.'])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->assertSame(0, Decision::query()->where('case_id', $case->id)->count());
    }

    public function test_effect_retry_keeps_decision_count_and_preserves_applied_noop_attempts(): void
    {
        $this->bootWorkflow(userId: 10);
        Schema::create('workflow_effect_probe', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('effect_id');
            $table->string('marker');
            $table->timestamp('created_at');
        });
        $handler = new ProbeEffectHandler;
        $handler->failuresRemaining = 1;
        $this->bindEffectHandler($handler, 'probe.local');
        $template = $this->createTemplate([], [
            'nodes' => $this->singleApprovalDefinition()['nodes'],
            'effects' => [[
                'type' => 'probe.local',
                'execution' => 'LOCAL',
                'aggregate_type' => 'case',
                'aggregate_id_field' => 'case_id',
            ]],
        ]);
        $case = $this->submittedCase($template);

        $this->decideThroughApi($case->id, 'retry-count-key')->assertOk();
        $effect = Effect::query()->where('case_id', $case->id)->firstOrFail();
        $this->assertSame('FAILED', $effect->status->value);
        $this->assertSame(1, $effect->attempts);

        $this->postJson(
            "/v1/effects/{$effect->id}/retry",
            ['reason' => '已修复下游服务，按幂等键重试'],
            ['Idempotency-Key' => 'effect-retry-once-key'],
        )->assertOk()->assertJsonPath('data.effect_status', 'APPLIED');

        $this->assertSame(1, Decision::query()->where('case_id', $case->id)->count());
        $this->assertSame(2, $effect->refresh()->attempts);

        $this->postJson(
            "/v1/effects/{$effect->id}/retry",
            ['reason' => '已经生效，重复重试应为幂等空操作'],
            ['Idempotency-Key' => 'effect-retry-noop-key'],
        )->assertOk()->assertJsonPath('data.effect_status', 'APPLIED');

        $this->assertSame(2, $effect->fresh()?->attempts);
        $this->assertSame(1, Decision::query()->where('case_id', $case->id)->count());
        $this->assertSame(1, Effect::query()->where('case_id', $case->id)->count());
    }

    public function test_effect_retry_requires_admin_and_preserves_attempts_when_forbidden(): void
    {
        $this->bootWorkflow(userId: 10);
        $handler = new ProbeEffectHandler;
        $handler->failuresRemaining = 1;
        $this->bindEffectHandler($handler, 'probe.local');
        $template = $this->createTemplate([], [
            'nodes' => $this->singleApprovalDefinition()['nodes'],
            'effects' => [[
                'type' => 'probe.local',
                'execution' => 'LOCAL',
                'aggregate_type' => 'case',
                'aggregate_id_field' => 'case_id',
            ]],
        ]);
        $case = $this->submittedCase($template);
        $this->decideThroughApi($case->id, 'retry-permission-key')->assertOk();
        $effect = Effect::query()->where('case_id', $case->id)->firstOrFail();

        $this->actAs(10, ['workflow.case.read', 'workflow.case.create', 'workflow.case.decide']);
        $this->postJson(
            "/v1/effects/{$effect->id}/retry",
            ['reason' => '没有管理员能力时不得重试生效任务'],
            ['Idempotency-Key' => 'retry-without-admin-key'],
        )->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');

        $this->assertSame(1, $effect->fresh()?->attempts);
    }

    public function test_cc_writes_a_durable_observer_membership_visible_in_my_cc(): void
    {
        $this->bootWorkflow(userId: 10);
        $template = $this->createTemplate();
        $case = $this->submittedCase($template);

        $this->decideThroughApi($case->id, 'cc-target-key', 'CC', ['target_user_id' => 20])
            ->assertOk();

        $this->assertDatabaseHas('case_members', [
            'case_id' => $case->id,
            'user_id' => 20,
            'role' => 'OBSERVER',
        ]);

        $this->actAs(20, ['workflow.case.read']);
        $this->getJson('/v1/cases?view=my_cc')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $case->id);

        $this->getJson("/v1/cases/{$case->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $case->id);
    }

    public function test_admin_override_and_delegation_evidence_remain_in_timeline_after_refresh(): void
    {
        $this->bootWorkflow(userId: 10);
        $template = $this->createTemplate([], $this->singleApprovalDefinition([], 'USER', ['user_id' => 55]));
        $case = $this->submittedCase($template);

        $this->actAs(1, ['workflow.case.read', 'workflow.case.decide', 'workflow.case.admin']);
        $this->decideThroughApi(
            $case->id,
            'timeline-override-key',
            'APPROVE',
            ['reason' => '直属主管长期休假，由管理员按制度代批。'],
        )->assertOk();

        $decision = Decision::query()->where('case_id', $case->id)->firstOrFail();
        $this->assertDatabaseHas('case_events', [
            'case_id' => $case->id,
            'event_type' => 'case.approved',
        ]);

        $timeline = $this->getJson("/v1/cases/{$case->id}/timeline")->assertOk();
        $decisionItem = collect((array) $timeline->json('data.items'))->firstWhere('type', 'DECISION');
        $this->assertTrue($decisionItem['admin_override']);
        $this->assertSame('直属主管长期休假，由管理员按制度代批。', $decisionItem['reason']);
        $this->assertSame($decision->actor_user_id, $decisionItem['decided_by_user_id']);
    }
}
