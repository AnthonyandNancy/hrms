<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Models\Decision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WorkflowTestHelpers;
use Tests\TestCase;

class DelegationTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_proxy_within_range_and_scope_can_approve_on_behalf_of_assignee(): void
    {
        $this->bootWorkflow(userId: 20);
        $template = $this->createTemplate();
        $case = $this->submittedCase($template);

        $this->actAs(10);
        $delegation = $this->postJson('/v1/delegations', [
            'proxy_user_id' => 20,
            'valid_from' => '2026-11-20',
            'valid_to' => '2026-11-30',
            'max_amount_minor' => 2000,
            'scope' => ['case_type' => 'leave.request', 'actions' => ['APPROVE']],
        ], ['Idempotency-Key' => 'delegation-key-1'])->assertCreated()->json('data');

        $this->actAs(20, ['workflow.case.read', 'workflow.case.create', 'workflow.case.decide']);
        $this->decideThroughApi($case->id, 'delegated-decision-key-1')->assertSuccessful();

        $decision = Decision::query()->where('case_id', $case->id)->firstOrFail();
        $this->assertSame(20, $decision->actor_user_id);
        $this->assertSame(10, $decision->actor_snapshot_json['on_behalf_of_user_id']);
        $this->assertSame($delegation['id'], $decision->actor_snapshot_json['delegation_id']);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'workflow.delegation.used',
            'aggregate_type' => 'case',
            'aggregate_id' => (string) $case->id,
        ]);
    }

    public function test_expired_delegation_is_rejected(): void
    {
        $this->bootWorkflow(userId: 20);
        $template = $this->createTemplate();
        $case = $this->submittedCase($template);

        $this->actAs(10);
        $this->postJson('/v1/delegations', [
            'proxy_user_id' => 20,
            'valid_from' => '2026-11-01',
            'valid_to' => '2026-11-10',
            'scope' => ['case_type' => 'leave.request'],
        ], ['Idempotency-Key' => 'delegation-key-2'])->assertCreated();

        $this->actAs(20, ['workflow.case.read', 'workflow.case.create', 'workflow.case.decide']);
        $this->decideThroughApi($case->id, 'expired-delegation-key-1')
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');

        $this->assertSame(0, Decision::query()->count());
    }

    public function test_delegation_amount_limit_is_enforced(): void
    {
        $this->bootWorkflow(userId: 20);
        $template = $this->createTemplate();
        $case = $this->submittedCase($template, ['payload' => ['amount_minor' => 5000]]);

        $this->actAs(10);
        $this->postJson('/v1/delegations', [
            'proxy_user_id' => 20,
            'valid_from' => '2026-11-20',
            'valid_to' => '2026-11-30',
            'max_amount_minor' => 1000,
            'scope' => ['case_type' => 'leave.request'],
        ], ['Idempotency-Key' => 'delegation-key-3'])->assertCreated();

        $this->actAs(20, ['workflow.case.read', 'workflow.case.create', 'workflow.case.decide']);
        $this->decideThroughApi($case->id, 'over-limit-key-1')->assertNotFound();
    }

    public function test_delegation_out_of_scope_is_rejected(): void
    {
        $this->bootWorkflow(userId: 20);
        $template = $this->createTemplate(['code' => 'transfer.request']);
        $case = $this->submittedCase($template, ['type' => 'transfer.request']);

        $this->actAs(10);
        $this->postJson('/v1/delegations', [
            'proxy_user_id' => 20,
            'valid_from' => '2026-11-20',
            'valid_to' => '2026-11-30',
            'scope' => ['case_type' => 'leave.request'],
        ], ['Idempotency-Key' => 'delegation-key-4'])->assertCreated();

        $this->actAs(20, ['workflow.case.read', 'workflow.case.create', 'workflow.case.decide']);
        $this->decideThroughApi($case->id, 'wrong-scope-key-1')->assertNotFound();
    }

    public function test_revoked_delegation_cannot_be_used(): void
    {
        $this->bootWorkflow(userId: 20);
        $template = $this->createTemplate();
        $case = $this->submittedCase($template);

        $this->actAs(10);
        $delegationId = $this->postJson('/v1/delegations', [
            'proxy_user_id' => 20,
            'valid_from' => '2026-11-20',
            'valid_to' => '2026-11-30',
        ], ['Idempotency-Key' => 'delegation-key-5'])->assertCreated()->json('data.id');

        $this->deleteJson("/v1/delegations/{$delegationId}")
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->actAs(20, ['workflow.case.read', 'workflow.case.create', 'workflow.case.decide']);
        $this->decideThroughApi($case->id, 'revoked-key-1')->assertNotFound();
    }
}
