<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Models\Decision;
use App\Modules\Workflow\Models\Effect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProbeEffectHandler;
use Tests\Support\WorkflowTestHelpers;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_unauthenticated_request_is_401(): void
    {
        $this->app->instance(\App\Modules\Workflow\Contracts\WorkflowPrincipalResolver::class, new \Tests\Support\FakeWorkflowPrincipalResolver);

        $this->getJson('/v1/cases')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_missing_capability_is_403(): void
    {
        $this->bootWorkflow(capabilities: ['workflow.case.create']);

        $this->getJson('/v1/cases')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN')
            ->assertJsonPath('data.capability', 'workflow.case.read');
    }

    public function test_case_scope_limits_requester_and_approver_but_unrelated_user_gets_404(): void
    {
        $this->bootWorkflow(userId: 10);
        $template = $this->createTemplate();
        $case = $this->submittedCase($template);

        $this->getJson("/v1/cases/{$case->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $case->id)
            ->assertJsonPath('data.application_status', 'SUBMITTED')
            ->assertJsonPath('data.effect_status', 'NONE')
            ->assertJsonMissingPath('data.status');

        $this->actAs(10);
        $this->getJson('/v1/cases')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->actAs(99, ['workflow.case.read', 'workflow.case.create', 'workflow.case.decide']);
        $this->getJson("/v1/cases/{$case->id}")->assertNotFound();
        $this->getJson('/v1/cases')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_tasks_my_returns_only_current_users_pending_tasks_with_sla(): void
    {
        $this->bootWorkflow(userId: 10);
        $template = $this->createTemplate();
        $case = $this->submittedCase($template);

        $response = $this->getJson('/v1/tasks/my')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.case_id', $case->id)
            ->assertJsonPath('data.items.0.application_status', 'SUBMITTED')
            ->assertJsonPath('data.items.0.effect_status', 'NONE')
            ->assertJsonPath('data.items.0.sla_state', 'ON_TRACK');

        $this->assertNotNull($response->json('data.items.0.due_at'));

        $this->actAs(11);
        $this->getJson('/v1/tasks/my')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_admin_override_requires_reason_and_is_audited(): void
    {
        $this->bootWorkflow(userId: 10);
        $template = $this->createTemplate([], $this->singleApprovalDefinition([], 'USER', ['user_id' => 55]));
        $case = $this->submittedCase($template);

        $this->decideThroughApi($case->id, 'override-no-reason')->assertUnprocessable();

        $this->decideThroughApi($case->id, 'override-with-reason', 'APPROVE', [
            'reason' => 'HR admin override because assignee is unavailable.',
        ])->assertOk();

        $this->assertDatabaseHas('decisions', ['case_id' => $case->id, 'actor_user_id' => 10]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'workflow.decision.admin_override',
            'aggregate_type' => 'case',
            'aggregate_id' => (string) $case->id,
        ]);

        $decision = Decision::query()->where('case_id', $case->id)->firstOrFail();
        $this->assertTrue($decision->actor_snapshot_json['admin_override']);
    }

    public function test_effect_retry_requires_admin_capability_and_reason(): void
    {
        $this->bootWorkflow(userId: 10);
        $handler = new ProbeEffectHandler;
        $handler->failuresRemaining = 1;
        $this->bindEffectHandler($handler, 'probe.local');
        $template = $this->createTemplate([], [
            'nodes' => $this->singleApprovalDefinition()['nodes'],
            'effects' => [['type' => 'probe.local', 'execution' => 'LOCAL', 'aggregate_type' => 'case', 'aggregate_id_field' => 'case_id']],
        ]);
        $case = $this->submittedCase($template);
        $this->decideThroughApi($case->id, 'auth-effect-key')->assertOk();
        $effect = Effect::query()->where('case_id', $case->id)->firstOrFail();

        $this->postJson("/v1/effects/{$effect->id}/retry", [], ['Idempotency-Key' => 'retry-no-reason'])
            ->assertUnprocessable();

        $this->actAs(10, ['workflow.case.read', 'workflow.case.create', 'workflow.case.decide']);
        $this->postJson("/v1/effects/{$effect->id}/retry", ['reason' => 'Attempt retry without admin capability.'], ['Idempotency-Key' => 'retry-no-cap'])
            ->assertForbidden();

        $this->actAs(10);
        $this->postJson("/v1/effects/{$effect->id}/retry", ['reason' => 'Retry with admin capability and audit reason.'], ['Idempotency-Key' => 'retry-yes'])
            ->assertSuccessful();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'workflow.effect.retry_requested',
            'aggregate_type' => 'effect',
            'aggregate_id' => (string) $effect->id,
        ]);
    }

    public function test_list_queries_do_not_n_plus_one(): void
    {
        $this->bootWorkflow(userId: 10);
        $template = $this->createTemplate();

        foreach (range(1, 5) as $index) {
            $this->createCaseThroughApi($template, ['title' => "Case {$index}"])->assertCreated();
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->getJson('/v1/cases?page_size=20')->assertOk();
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, count($queries), 'Case list must not N+1; got '.count($queries).' queries.');
    }
}
