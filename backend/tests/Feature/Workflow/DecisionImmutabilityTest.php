<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Enums\CaseStatus;
use App\Modules\Workflow\Enums\DecisionAction;
use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\Decision;
use App\Modules\Workflow\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WorkflowTestHelpers;
use Tests\TestCase;

class DecisionImmutabilityTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_updating_or_deleting_a_decision_throws(): void
    {
        $this->bootWorkflow();
        $template = $this->createTemplate();
        $case = $this->submittedCase($template);

        $response = $this->decideThroughApi($case->id, 'immutable-key-1');
        $response->assertSuccessful();

        $decision = Decision::query()->firstOrFail();

        try {
            $decision->update(['comment' => 'tampered']);
            $this->fail('Decision update must throw.');
        } catch (\DomainException $exception) {
            $this->assertSame('Decisions are append-only.', $exception->getMessage());
        }

        try {
            $decision->delete();
            $this->fail('Decision delete must throw.');
        } catch (\DomainException $exception) {
            $this->assertSame('Decisions are append-only.', $exception->getMessage());
        }

        $this->assertDatabaseHas('decisions', ['id' => $decision->id, 'comment' => 'Approved.']);
    }

    public function test_template_edit_does_not_change_live_or_historical_case_snapshot(): void
    {
        $this->bootWorkflow();
        $v1 = $this->createTemplate(['version' => 1]);
        $case = $this->submittedCase($v1);

        $snapshotBefore = $case->fresh()?->template_snapshot_json;
        $this->assertSame(1, $case->fresh()?->template_version);

        app(TemplateService::class)->create(1, 10, [
            'code' => 'generic.approval',
            'name' => 'Generic approval v2',
            'version' => 2,
            'status' => 'PUBLISHED',
            'definition' => $this->singleApprovalDefinition([], 'USER', ['user_id' => 99]),
        ]);

        $freshCase = $case->refresh();
        $this->assertSame($snapshotBefore, $freshCase->template_snapshot_json);
        $this->assertSame(1, $freshCase->template_version);

        $response = $this->decideThroughApi($case->id, 'snapshot-key-1');
        $response->assertSuccessful();
        $this->assertDatabaseHas('decisions', ['case_id' => $case->id, 'template_version' => 1]);
    }

    public function test_actor_snapshot_is_stored_with_decision(): void
    {
        $this->bootWorkflow(userId: 42);
        $template = $this->createTemplate([], $this->singleApprovalDefinition([], 'USER', ['user_id' => 42]));
        $case = $this->submittedCase($template);

        $this->decideThroughApi($case->id, 'actor-snapshot-key-1')->assertSuccessful();

        $decision = Decision::query()->firstOrFail();
        $snapshot = $decision->actor_snapshot_json;

        $this->assertSame(42, $snapshot['user_id']);
        $this->assertSame(3, $snapshot['auth_version']);
        $this->assertContains('HR_ADMIN', $snapshot['roles']);
        $this->assertContains('workflow.case.decide', $snapshot['capabilities']);
        $this->assertNotNull($decision->evidence_hash);
        $this->assertNotNull($decision->request_id);
    }

    public function test_decisions_are_append_only_and_keep_action_sequence(): void
    {
        $this->bootWorkflow();
        $template = $this->createTemplate();
        $case = $this->submittedCase($template);

        $this->decideThroughApi($case->id, 'append-key-1', 'RETURN', ['comment' => 'Please fix.'])->assertSuccessful();
        $this->actAs(10);
        $this->postJson("/v1/cases/{$case->id}/submit", [], ['Idempotency-Key' => 'resubmit-key-1'])->assertSuccessful();
        $this->decideThroughApi($case->id, 'append-key-2', 'REJECT', ['comment' => 'No.'])->assertSuccessful();

        $decisions = Decision::query()->orderBy('id')->get();

        $this->assertCount(2, $decisions);
        $this->assertSame(DecisionAction::RETURN, $decisions[0]->action);
        $this->assertSame(DecisionAction::REJECT, $decisions[1]->action);
        $this->assertNotSame($decisions[0]->id, $decisions[1]->id);
        $this->assertSame(CaseStatus::REJECTED, $case->fresh()?->status);
    }

    private function submittedCase(\App\Modules\Workflow\Models\WorkflowTemplate $template): CaseModel
    {
        $this->createCaseThroughApi($template, ['submit' => true])->assertCreated();

        return CaseModel::query()->latest('id')->firstOrFail();
    }
}
