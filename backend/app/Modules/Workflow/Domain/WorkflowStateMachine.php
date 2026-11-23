<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Domain;

use App\Modules\Workflow\Enums\CaseStatus;
use App\Modules\Workflow\Enums\DecisionAction;
use App\Modules\Workflow\Enums\InstanceStatus;
use App\Modules\Workflow\Enums\TaskStatus;
use App\Shared\Exceptions\ConflictException;

/**
 * Declarative state machines for the application lifecycle. Effect state is
 * intentionally absent: APPROVED never implies EFFECTIVE.
 */
final class WorkflowStateMachine
{
    /** @var array<string, list<CaseStatus>> */
    private const CASE_TRANSITIONS = [
        'DRAFT' => [CaseStatus::SUBMITTED, CaseStatus::WITHDRAWN, CaseStatus::CANCELLED],
        'SUBMITTED' => [CaseStatus::IN_REVIEW, CaseStatus::APPROVED, CaseStatus::REJECTED, CaseStatus::WITHDRAWN, CaseStatus::CANCELLED],
        'IN_REVIEW' => [CaseStatus::APPROVED, CaseStatus::REJECTED, CaseStatus::WITHDRAWN, CaseStatus::CANCELLED],
        'APPROVED' => [CaseStatus::CANCELLED],
        'REJECTED' => [],
        'WITHDRAWN' => [],
        'CANCELLED' => [],
    ];

    /** @var array<string, list<InstanceStatus>> */
    private const INSTANCE_TRANSITIONS = [
        'RUNNING' => [InstanceStatus::COMPLETED, InstanceStatus::CANCELLED],
        'COMPLETED' => [],
        'CANCELLED' => [],
    ];

    /** @var array<string, list<TaskStatus>> */
    private const TASK_TRANSITIONS = [
        'PENDING' => [TaskStatus::APPROVED, TaskStatus::REJECTED, TaskStatus::DELEGATED, TaskStatus::CANCELLED, TaskStatus::EXPIRED],
        'APPROVED' => [],
        'REJECTED' => [],
        'DELEGATED' => [],
        'CANCELLED' => [],
        'EXPIRED' => [],
    ];

    public function canTransitionCase(CaseStatus $from, CaseStatus $to): bool
    {
        return in_array($to, self::CASE_TRANSITIONS[$from->value], true);
    }

    public function assertCaseTransition(CaseStatus $from, CaseStatus $to): void
    {
        if (! $this->canTransitionCase($from, $to)) {
            throw new ConflictException(
                "Case cannot transition from {$from->value} to {$to->value}.",
                ['reason' => 'INVALID_CASE_TRANSITION', 'from' => $from->value, 'to' => $to->value],
            );
        }
    }

    public function canTransitionInstance(InstanceStatus $from, InstanceStatus $to): bool
    {
        return in_array($to, self::INSTANCE_TRANSITIONS[$from->value], true);
    }

    public function assertInstanceTransition(InstanceStatus $from, InstanceStatus $to): void
    {
        if (! $this->canTransitionInstance($from, $to)) {
            throw new ConflictException(
                "Workflow instance cannot transition from {$from->value} to {$to->value}.",
                ['reason' => 'INVALID_INSTANCE_TRANSITION', 'from' => $from->value, 'to' => $to->value],
            );
        }
    }

    public function canTransitionTask(TaskStatus $from, TaskStatus $to): bool
    {
        return in_array($to, self::TASK_TRANSITIONS[$from->value], true);
    }

    public function canDecide(CaseStatus $caseStatus, DecisionAction $action): bool
    {
        if (! $caseStatus->isOpen()) {
            return false;
        }

        return match ($action) {
            DecisionAction::WITHDRAW => in_array($caseStatus, [CaseStatus::DRAFT, CaseStatus::SUBMITTED, CaseStatus::IN_REVIEW], true),
            DecisionAction::CC => true,
            default => in_array($caseStatus, [CaseStatus::SUBMITTED, CaseStatus::IN_REVIEW], true),
        };
    }
}
