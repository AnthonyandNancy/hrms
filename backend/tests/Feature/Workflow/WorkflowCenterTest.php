<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Models\Decision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WorkflowTestHelpers;
use Tests\TestCase;

/**
 * Consistency contract for the platform workflow center (Volume 3 Task 17)
 * and the case/decision endpoints it consumes.
 *
 * The page itself is a projection, so these tests pin the server behaviour the
 * UI relies on: task/list scope, assignee-only decisions, decided-case
 * immutability, idempotent replay and timeline evidence.
 */
final class WorkflowCenterTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_assignee_sees_the_pending_task_and_case_in_my_approvals(): void
    {
        $this->bootWorkflow(userId: 10);
        $template = $this->createTemplate();
        $case = $this->submittedCase($template);

        $this->getJson('/v1/tasks/my')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.case_id', $case->id)
            ->assertJsonPath('data.items.0.node_key', 'manager_approval')
            ->assertJsonPath('data.items.0.sla_state', 'ON_TRACK');

        $this->getJson('/v1/cases?view=my_approvals')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $case->id)
            ->assertJsonPath('data.items.0.is_awaiting_me', true)
            ->assertJsonPath('data.items.0.application_status', 'SUBMITTED');
    }

    public function test_decided_case_leaves_my_approvals_and_appears_in_my_decided(): void
    {
        $this->bootWorkflow(userId: 10, capabilities: ['workflow.case.read', 'workflow.case.create', 'workflow.case.decide']);
        $template = $this->createTemplate();
        $pendingCase = $this->submittedCase($template);
        $decidedCase = $this->submittedCase($template);

        $this->decideThroughApi($decidedCase->id, 'workflow-center-my-decided-key')->assertOk();

        $this->getJson('/v1/cases?view=my_approvals')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $pendingCase->id);

        $this->getJson('/v1/cases?view=my_decided')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $decidedCase->id)
            ->assertJsonPath('data.items.0.application_status', 'APPROVED');

        $this->actAs(99, ['workflow.case.read', 'workflow.case.decide']);
        $this->getJson('/v1/cases?view=my_decided')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_visible_non_assignee_cannot_decide_someone_elses_task(): void
    {
        $this->bootWorkflow(
            userId: 10,
            capabilities: ['workflow.case.read', 'workflow.case.create', 'workflow.case.decide'],
        );
        $template = $this->createTemplate([], $this->singleApprovalDefinition([], 'USER', ['user_id' => 55]));
        $case = $this->submittedCase($template);

        // The requester can read their own case, but cannot decide a task that
        // belongs to user 55 and holds no workflow.case.admin override.
        $this->getJson("/v1/cases/{$case->id}")
            ->assertOk()
            ->assertJsonPath('data.is_awaiting_me', false);

        $this->decideThroughApi($case->id, 'visible-non-assignee-key-1')
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');

        $this->assertSame(0, Decision::query()->where('case_id', $case->id)->count());
    }

    public function test_non_assignee_cannot_read_or_decide_an_out_of_scope_case(): void
    {
        $this->bootWorkflow(userId: 10);
        $template = $this->createTemplate();
        $case = $this->submittedCase($template);

        $this->actAs(99, ['workflow.case.read', 'workflow.case.create', 'workflow.case.decide']);

        $this->getJson('/v1/tasks/my')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/v1/cases?view=my_approvals')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson("/v1/cases/{$case->id}")->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');

        // Existence hiding: a caller holding workflow.case.decide still gets
        // 404, not 403, when the case has no pending task assigned to them.
        $this->decideThroughApi($case->id, 'non-assignee-key-1')->assertNotFound();
        $this->assertSame(0, Decision::query()->where('case_id', $case->id)->count());
    }

    public function test_unauthorized_user_cannot_post_a_decision(): void
    {
        $this->bootWorkflow(userId: 10);
        $template = $this->createTemplate();
        $case = $this->submittedCase($template);

        $this->actAs(10, ['workflow.case.read']);

        $this->decideThroughApi($case->id, 'missing-capability-key-1')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->assertSame(0, Decision::query()->where('case_id', $case->id)->count());
    }

    public function test_repeated_same_decision_replays_but_conflicting_decision_on_decided_case_is_409(): void
    {
        $this->bootWorkflow(userId: 10);
        $template = $this->createTemplate();
        $case = $this->submittedCase($template);

        $first = $this->decideThroughApi($case->id, 'workflow-center-replay-key');
        $first->assertOk()
            ->assertJsonPath('data.application_status', 'APPROVED')
            ->assertJsonPath('data.effect_status', 'NONE');

        $replay = $this->decideThroughApi($case->id, 'workflow-center-replay-key');
        $replay->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.application_status', 'APPROVED');

        $this->decideThroughApi($case->id, 'workflow-center-conflicting-key', 'REJECT')
            ->assertStatus(409)
            ->assertJsonPath('code', 'CONFLICT');

        $this->assertSame(1, Decision::query()->where('case_id', $case->id)->count());
        $this->assertDatabaseHas('cases', ['id' => $case->id, 'status' => 'APPROVED']);
    }

    public function test_decision_creates_a_timeline_entry_with_actor_and_timestamp(): void
    {
        $this->bootWorkflow(userId: 10);
        $template = $this->createTemplate();
        $case = $this->submittedCase($template);

        $this->decideThroughApi($case->id, 'workflow-center-timeline-key')
            ->assertOk()
            ->assertJsonPath('data.application_status', 'APPROVED');

        $timeline = $this->getJson("/v1/cases/{$case->id}/timeline")
            ->assertOk()
            ->assertJsonPath('data.application.status', 'APPROVED')
            ->assertJsonPath('data.effect.status', 'NONE');

        $decisions = collect((array) $timeline->json('data.items'))
            ->where('type', 'DECISION')
            ->values();

        $this->assertCount(1, $decisions);
        $this->assertSame('APPROVE', $decisions[0]['action']);
        $this->assertSame(10, $decisions[0]['actor_user_id']);
        $this->assertNotNull($decisions[0]['occurred_at']);

        $eventTypes = collect((array) $timeline->json('data.items'))->pluck('event_type')->filter()->all();
        $this->assertContains('decision.recorded', $eventTypes);
        $this->assertContains('case.approved', $eventTypes);
    }

    public function test_decided_case_detail_remains_readable_but_has_no_pending_task(): void
    {
        $this->bootWorkflow(userId: 10);
        $template = $this->createTemplate();
        $case = $this->submittedCase($template);

        $this->decideThroughApi($case->id, 'workflow-center-readonly-key')
            ->assertOk();

        $this->getJson("/v1/cases/{$case->id}")
            ->assertOk()
            ->assertJsonPath('data.application_status', 'APPROVED')
            ->assertJsonPath('data.is_awaiting_me', false);

        $this->getJson('/v1/tasks/my')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }
}
