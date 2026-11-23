<?php

declare(strict_types=1);

namespace Tests\Unit\Workflow;

use App\Modules\Workflow\Domain\WorkflowStateMachine;
use App\Modules\Workflow\Enums\CaseStatus;
use App\Modules\Workflow\Enums\DecisionAction;
use App\Modules\Workflow\Enums\InstanceStatus;
use App\Modules\Workflow\Enums\TaskStatus;
use App\Shared\Exceptions\ConflictException;
use PHPUnit\Framework\TestCase;

class WorkflowStateMachineTest extends TestCase
{
    private WorkflowStateMachine $machine;

    protected function setUp(): void
    {
        $this->machine = new WorkflowStateMachine;
    }

    public function test_case_transitions_follow_the_application_lifecycle(): void
    {
        $this->assertTrue($this->machine->canTransitionCase(CaseStatus::DRAFT, CaseStatus::SUBMITTED));
        $this->assertTrue($this->machine->canTransitionCase(CaseStatus::SUBMITTED, CaseStatus::IN_REVIEW));
        $this->assertTrue($this->machine->canTransitionCase(CaseStatus::IN_REVIEW, CaseStatus::APPROVED));
        $this->assertTrue($this->machine->canTransitionCase(CaseStatus::IN_REVIEW, CaseStatus::REJECTED));
        $this->assertTrue($this->machine->canTransitionCase(CaseStatus::IN_REVIEW, CaseStatus::WITHDRAWN));
        $this->assertTrue($this->machine->canTransitionCase(CaseStatus::DRAFT, CaseStatus::WITHDRAWN));
        $this->assertTrue($this->machine->canTransitionCase(CaseStatus::APPROVED, CaseStatus::CANCELLED));
    }

    public function test_case_transitions_reject_skipping_approval_or_resurrecting_closed_cases(): void
    {
        $this->assertFalse($this->machine->canTransitionCase(CaseStatus::DRAFT, CaseStatus::APPROVED));
        $this->assertFalse($this->machine->canTransitionCase(CaseStatus::REJECTED, CaseStatus::IN_REVIEW));
        $this->assertFalse($this->machine->canTransitionCase(CaseStatus::WITHDRAWN, CaseStatus::APPROVED));
        $this->assertFalse($this->machine->canTransitionCase(CaseStatus::CANCELLED, CaseStatus::DRAFT));
    }

    public function test_invalid_case_transition_throws_conflict_with_stable_reason(): void
    {
        $this->expectException(ConflictException::class);

        try {
            $this->machine->assertCaseTransition(CaseStatus::DRAFT, CaseStatus::APPROVED);
        } catch (ConflictException $exception) {
            $this->assertSame('INVALID_CASE_TRANSITION', $exception->details()['reason'] ?? null);
            $this->assertSame('DRAFT', $exception->details()['from'] ?? null);
            $this->assertSame('APPROVED', $exception->details()['to'] ?? null);

            throw $exception;
        }
    }

    public function test_instance_transitions(): void
    {
        $this->assertTrue($this->machine->canTransitionInstance(InstanceStatus::RUNNING, InstanceStatus::COMPLETED));
        $this->assertTrue($this->machine->canTransitionInstance(InstanceStatus::RUNNING, InstanceStatus::CANCELLED));
        $this->assertFalse($this->machine->canTransitionInstance(InstanceStatus::COMPLETED, InstanceStatus::RUNNING));
    }

    public function test_task_transitions(): void
    {
        foreach ([TaskStatus::APPROVED, TaskStatus::REJECTED, TaskStatus::DELEGATED, TaskStatus::CANCELLED, TaskStatus::EXPIRED] as $target) {
            $this->assertTrue(
                $this->machine->canTransitionTask(TaskStatus::PENDING, $target),
                "PENDING should transition to {$target->value}",
            );
        }

        $this->assertFalse(
            $this->machine->canTransitionTask(TaskStatus::APPROVED, TaskStatus::PENDING),
        );
    }

    public function test_decision_actions_are_allowed_only_while_case_is_open(): void
    {
        foreach ([DecisionAction::APPROVE, DecisionAction::REJECT, DecisionAction::RETURN, DecisionAction::WITHDRAW, DecisionAction::DELEGATE] as $action) {
            $this->assertTrue($this->machine->canDecide(CaseStatus::IN_REVIEW, $action));
        }

        $this->assertFalse($this->machine->canDecide(CaseStatus::APPROVED, DecisionAction::APPROVE));
        $this->assertFalse($this->machine->canDecide(CaseStatus::WITHDRAWN, DecisionAction::REJECT));
    }
}
