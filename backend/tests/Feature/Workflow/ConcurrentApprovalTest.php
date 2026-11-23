<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Models\Decision;
use App\Modules\Workflow\Models\Effect;
use App\Modules\Workflow\Models\WorkflowTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CountingEffectHandler;
use Tests\Support\WorkflowTestHelpers;
use Tests\TestCase;

class ConcurrentApprovalTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_replaying_same_idempotency_key_returns_the_same_decision_and_effect_without_duplicates(): void
    {
        $this->bootWorkflow();
        $handler = new CountingEffectHandler('concurrent.effect');
        $this->bindEffectHandler($handler, 'concurrent.effect');
        $template = $this->createTemplate([], [
            'nodes' => $this->singleApprovalDefinition()['nodes'],
            'effects' => [
                ['type' => 'concurrent.effect', 'execution' => 'LOCAL', 'aggregate_type' => 'case', 'aggregate_id_field' => 'case_id'],
            ],
        ]);
        $case = $this->submittedCase($template);

        $first = $this->decideThroughApi($case->id, 'concurrent-key-1')->assertOk();
        $firstData = $first->json('data');

        $replay = $this->decideThroughApi($case->id, 'concurrent-key-1')->assertOk();
        $this->assertSame($firstData, $replay->json('data'));
        $replay->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, Decision::query()->where('case_id', $case->id)->count());
        $this->assertSame(1, Effect::query()->where('case_id', $case->id)->count());
        $this->assertSame(1, $handler->calls);
    }

    public function test_second_approval_after_case_completed_is_a_conflict_and_does_not_create_another_decision(): void
    {
        $this->bootWorkflow();
        $handler = new CountingEffectHandler('concurrent.effect');
        $this->bindEffectHandler($handler, 'concurrent.effect');
        $template = $this->createTemplate([], [
            'nodes' => $this->singleApprovalDefinition()['nodes'],
            'effects' => [
                ['type' => 'concurrent.effect', 'execution' => 'LOCAL', 'aggregate_type' => 'case', 'aggregate_id_field' => 'case_id'],
            ],
        ]);
        $case = $this->submittedCase($template);

        $this->decideThroughApi($case->id, 'concurrent-key-2')->assertOk();
        $this->decideThroughApi($case->id, 'concurrent-key-3', 'APPROVE', ['comment' => 'Double click.'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'INVALID_CASE_TRANSITION');

        $this->assertSame(1, Decision::query()->where('case_id', $case->id)->count());
        $this->assertSame(1, Effect::query()->where('case_id', $case->id)->count());
        $this->assertSame(1, $handler->calls);
    }

    public function test_required_count_blocks_completion_until_enough_distinct_approvers_decide(): void
    {
        $this->bootWorkflow(userId: 10);
        $handler = new CountingEffectHandler('concurrent.effect');
        $this->bindEffectHandler($handler, 'concurrent.effect');
        $definition = $this->singleApprovalDefinition();
        $definition['nodes'][1]['assignee_rule'] = ['type' => 'ROLE', 'role_code' => 'HR_ADMIN'];
        $definition['nodes'][1]['required_count'] = 2;
        $definition['effects'] = [
            ['type' => 'concurrent.effect', 'execution' => 'LOCAL', 'aggregate_type' => 'case', 'aggregate_id_field' => 'case_id'],
        ];
        $template = $this->createTemplate([], $definition);

        $directory = app(\App\Modules\Workflow\Contracts\AssigneeDirectory::class);
        $this->assertInstanceOf(\Tests\Support\FakeAssigneeDirectory::class, $directory);

        // In the test fake, role resolution returns nothing unless seeded.
        $directory->roleUsers['1:HR_ADMIN'] = [10, 11];
        $this->actorDirectory->add(new \App\Modules\Workflow\Contracts\ActorSnapshot(1, 11, null, 3, false, ['HR_ADMIN'], ['workflow.case.decide']));

        $case = $this->submittedCase($template);
        $this->assertSame(2, WorkflowTask::query()->where('case_id', $case->id)->count());

        $this->decideThroughApi($case->id, 'count-key-1')->assertOk();
        $this->assertSame('IN_REVIEW', $case->fresh()?->status->value);
        $this->assertSame(0, Effect::query()->where('case_id', $case->id)->count());

        $this->actAs(11);
        $directory->roleUsers['1:HR_ADMIN'] = [10, 11];
        $this->decideThroughApi($case->id, 'count-key-2')->assertOk();

        $freshCase = $case->refresh();
        $this->assertSame('APPROVED', $freshCase->status->value);
        $this->assertSame(2, Decision::query()->where('case_id', $case->id)->count());
        $this->assertSame(1, Effect::query()->where('case_id', $case->id)->count());
        $this->assertSame(1, $handler->calls);
    }
}
