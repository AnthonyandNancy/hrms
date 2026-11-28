<?php

declare(strict_types=1);

namespace Tests\Support\Time;

use App\Modules\Time\Contracts\WorkflowCasePort;
use App\Modules\Time\Contracts\WorkflowCaseSnapshot;

final class FakeWorkflowCasePort implements WorkflowCasePort
{
    /** @var array<int, WorkflowCaseSnapshot> */
    private array $cases = [];

    private int $nextId = 1001;

    /** @var list<array{case_id: int, reason: string, effective_on: string}> */
    public array $reversals = [];

    public function createCase(
        int $requesterUserId,
        string $type,
        string $subjectType,
        ?int $subjectId,
        array $payload,
        string $businessDate,
    ): int {
        $id = $this->nextId++;
        $this->cases[$id] = new WorkflowCaseSnapshot(
            id: $id,
            requesterUserId: $requesterUserId,
            type: $type,
            subjectType: $subjectType,
            subjectId: $subjectId,
            status: 'SUBMITTED',
            effectStatus: 'NONE',
            payload: $payload,
            businessDate: $businessDate,
            caseNo: 'CASE-'.$id,
            title: (string) ($payload['title'] ?? $type),
            currentNode: 'APPROVAL',
            dueAt: null,
        );

        return $id;
    }

    public function case(int $caseId): ?WorkflowCaseSnapshot
    {
        return $this->cases[$caseId] ?? null;
    }

    private function decideCase(int $caseId, string $decision, string $comment = ''): void
    {
        $case = $this->cases[$caseId] ?? null;

        if ($case === null) {
            return;
        }

        $this->cases[$caseId] = new WorkflowCaseSnapshot(
            id: $case->id,
            requesterUserId: $case->requesterUserId,
            type: $case->type,
            subjectType: $case->subjectType,
            subjectId: $case->subjectId,
            status: $decision,
            effectStatus: 'SCHEDULED',
            payload: $comment === '' ? $case->payload : [...$case->payload, 'last_decision_comment' => $comment],
            businessDate: $case->businessDate,
            caseNo: $case->caseNo,
            title: $case->title,
            currentNode: $case->currentNode,
            dueAt: $case->dueAt,
        );
    }

    public function decide(int $caseId, string $action, string $comment): void
    {
        $status = match (strtoupper($action)) {
            'APPROVE' => 'APPROVED',
            'REJECT' => 'REJECTED',
            'RETURN' => 'RETURNED',
            default => strtoupper($action),
        };
        $this->decideCase($caseId, $status, $comment);
    }

    public function requestCancel(int $caseId, int $actorUserId, string $reason): void
    {
        $this->decideCase($caseId, 'CANCELLED');
    }

    public function reverseEffect(int $caseId, string $reason, string $effectiveOn): void
    {
        $this->reversals[] = ['case_id' => $caseId, 'reason' => $reason, 'effective_on' => $effectiveOn];
        $this->decideCase($caseId, 'REVERSED');
    }

    /** Test helper: approve the case so an effect may apply. */
    public function approve(int $caseId): void
    {
        $this->decideCase($caseId, 'APPROVED');
    }
}
