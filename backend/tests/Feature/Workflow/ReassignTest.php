<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Models\CaseEvent;
use App\Modules\Workflow\Models\WorkflowTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAssigneeDirectory;
use Tests\Support\FakeAssigneeEligibility;
use Tests\Support\WorkflowTestHelpers;
use Tests\TestCase;

class ReassignTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_terminated_assignee_is_replaced_by_next_eligible_manager_with_case_event(): void
    {
        $this->bootWorkflow();
        /** @var FakeAssigneeDirectory $directory */
        $directory = app(\App\Modules\Workflow\Contracts\AssigneeDirectory::class);
        $directory->managers['1:employment:100'] = 10;
        $directory->reassignmentCandidates['1:1:10'] = [10, 11, 12];

        /** @var FakeAssigneeEligibility $eligibility */
        $eligibility = new FakeAssigneeEligibility;
        $eligibility->eligible = [10 => false, 11 => true, 12 => true];
        $this->app->instance(\App\Modules\Workflow\Contracts\AssigneeEligibility::class, $eligibility);

        $template = $this->createTemplate([], $this->singleApprovalDefinition([], 'MANAGER_OF_SUBJECT'));
        $case = $this->submittedCase($template);

        $current = WorkflowTask::query()->where('case_id', $case->id)->firstOrFail();
        $this->assertSame(10, $current->assignee_user_id);

        $this->artisan('workflow:reassign', ['--case' => $case->id])->assertSuccessful();

        $newTask = WorkflowTask::query()
            ->where('case_id', $case->id)
            ->where('status', 'PENDING')
            ->firstOrFail();

        $this->assertSame(11, $newTask->assignee_user_id);
        $this->assertSame(1, WorkflowTask::query()->where('case_id', $case->id)->where('status', 'PENDING')->count());
        $this->assertSame('CANCELLED', $current->fresh()?->status->value);

        $event = CaseEvent::query()
            ->where('case_id', $case->id)
            ->where('event_type', 'task.reassigned')
            ->firstOrFail();

        $this->assertSame(10, $event->payload_json['from_user_id']);
        $this->assertSame(11, $event->payload_json['to_user_id']);
        $this->assertSame(0, \App\Modules\Workflow\Models\Decision::query()->where('case_id', $case->id)->count());
    }

    public function test_eligible_assignee_is_untouched_and_reassignment_is_idempotent(): void
    {
        $this->bootWorkflow();
        /** @var FakeAssigneeDirectory $directory */
        $directory = app(\App\Modules\Workflow\Contracts\AssigneeDirectory::class);
        $directory->managers['1:employment:100'] = 10;
        $directory->reassignmentCandidates['1:1:10'] = [10, 11, 12];

        $eligibility = new FakeAssigneeEligibility;
        $eligibility->eligible = [10 => true, 11 => true, 12 => true];
        $this->app->instance(\App\Modules\Workflow\Contracts\AssigneeEligibility::class, $eligibility);

        $template = $this->createTemplate([], $this->singleApprovalDefinition([], 'MANAGER_OF_SUBJECT'));
        $case = $this->submittedCase($template);

        $this->artisan('workflow:reassign', ['--case' => $case->id])->assertSuccessful();
        $this->artisan('workflow:reassign', ['--case' => $case->id])->assertSuccessful();

        $this->assertSame(1, WorkflowTask::query()->where('case_id', $case->id)->count());
        $this->assertSame(10, WorkflowTask::query()->where('case_id', $case->id)->firstOrFail()->assignee_user_id);
        $this->assertSame(0, CaseEvent::query()->where('case_id', $case->id)->where('event_type', 'task.reassigned')->count());
    }

    public function test_no_eligible_candidate_is_recorded_as_escalation_without_duplicate_tasks(): void
    {
        $this->bootWorkflow();
        /** @var FakeAssigneeDirectory $directory */
        $directory = app(\App\Modules\Workflow\Contracts\AssigneeDirectory::class);
        $directory->managers['1:employment:100'] = 10;
        $directory->reassignmentCandidates['1:1:10'] = [];

        $eligibility = new FakeAssigneeEligibility;
        $eligibility->eligible = [10 => false];
        $this->app->instance(\App\Modules\Workflow\Contracts\AssigneeEligibility::class, $eligibility);

        $template = $this->createTemplate([], $this->singleApprovalDefinition([], 'MANAGER_OF_SUBJECT'));
        $case = $this->submittedCase($template);

        $this->artisan('workflow:reassign', ['--case' => $case->id])->assertSuccessful();

        $this->assertSame(0, WorkflowTask::query()->where('case_id', $case->id)->where('status', 'PENDING')->count());
        $this->assertSame(1, WorkflowTask::query()->where('case_id', $case->id)->where('status', 'CANCELLED')->count());

        $event = CaseEvent::query()
            ->where('case_id', $case->id)
            ->where('event_type', 'task.reassignment_exhausted')
            ->firstOrFail();

        $this->assertSame(10, $event->payload_json['from_user_id']);
    }
}
