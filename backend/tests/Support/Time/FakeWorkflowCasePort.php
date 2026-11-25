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

    public function createCase(
        int $tenantId,
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
            tenantId: $tenantId,
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

    public function case(int $tenantId, int $caseId): ?WorkflowCaseSnapshot
    {
        $case = $this->cases[$caseId] ?? null;

        return $case !== null && $case->tenantId === $tenantId ? $case : null;
    }

    private function decideCase(int $tenantId, int $caseId, string $decision, string $comment = ''): void
    {
        $case = $this->cases[$caseId] ?? null;

        if ($case === null || $case->tenantId !== $tenantId) {
            return;
        }

        $this->cases[$caseId] = new WorkflowCaseSnapshot(
            id: $case->id,
            tenantId: $case->tenantId,
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

    public function decide(int $tenantId, int $caseId, string $action, string $comment): void
    {
        $this->decideCase($tenantId, $caseId, strtoupper($action), $comment);
    }

    public function requestCancel(int $tenantId, int $caseId, int $actorUserId, string $reason): void
    {
        $this->decideCase($tenantId, $caseId, 'CANCELLED');
    }

    /** Test helper: approve the case so an effect may apply. */
    public function approve(int $tenantId, int $caseId): void
    {
        $this->decideCase($tenantId, $caseId, 'APPROVED');
    }
}
