<?php

declare(strict_types=1);

namespace App\Modules\Time\Adapters;

use App\Modules\Time\Contracts\WorkflowCasePort;
use App\Modules\Time\Contracts\WorkflowCaseSnapshot;
use App\Modules\Workflow\Contracts\CaseCommandPort;
use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Contracts\WorkflowPrincipalResolver;
use App\Shared\Exceptions\ConflictException;

/**
 * Time -> Workflow adapter. Depends only on Workflow Contracts; no Workflow
 * model or service class is imported.
 *
 * Workflow is company-scoped: no tenant id is carried across this boundary.
 */
final class WorkflowCaseAdapter implements WorkflowCasePort
{
    public function __construct(
        private readonly CaseCommandPort $cases,
        private readonly WorkflowPrincipalResolver $principals,
    ) {}

    public function createCase(
        int $requesterUserId,
        string $type,
        string $subjectType,
        ?int $subjectId,
        array $payload,
        string $businessDate,
    ): int {
        $case = $this->cases->open(
            principal: $this->principalFor($requesterUserId),
            type: $type,
            subjectType: $subjectType,
            subjectId: $subjectId,
            title: (string) ($payload['title'] ?? str_replace(['.', '_'], ' ', $type)),
            payload: $payload,
            businessDate: $businessDate,
            submit: true,
        );

        return $case['id'];
    }

    public function case(int $caseId): ?WorkflowCaseSnapshot
    {
        $case = $this->cases->find($caseId);

        if ($case === null) {
            return null;
        }

        return new WorkflowCaseSnapshot(
            id: $case['id'],
            requesterUserId: $case['requester_user_id'],
            type: $case['type'],
            subjectType: $case['subject_type'],
            subjectId: $case['subject_id'],
            status: $case['status'],
            effectStatus: $case['effect_status'],
            payload: $case['payload'],
            businessDate: $case['business_date'],
            caseNo: $case['case_no'],
            title: $case['title'],
            currentNode: $case['current_node'],
            dueAt: $case['due_at'],
        );
    }

    public function decide(int $caseId, string $action, string $comment): void
    {
        $principal = $this->principals->resolve();

        if ($principal === null) {
            throw new ConflictException(
                'The workflow principal does not match the request actor.',
                ['reason' => 'WORKFLOW_PRINCIPAL_MISMATCH'],
            );
        }

        $this->cases->decide($principal, $caseId, $action, $comment);
    }

    public function requestCancel(int $caseId, int $actorUserId, string $reason): void
    {
        $case = $this->case($caseId);

        if ($case === null) {
            throw new ConflictException('The workflow case was not found.', ['reason' => 'CASE_NOT_FOUND']);
        }

        if ($case->requesterUserId !== $actorUserId) {
            throw new ConflictException('Only the requester may cancel this case.', ['reason' => 'CASE_NOT_CANCELLABLE']);
        }

        $this->cases->withdraw($this->principalFor($actorUserId), $caseId, $reason);
    }

    private function principalFor(int $userId): WorkflowPrincipal
    {
        $principal = $this->principals->resolve();

        if ($principal === null || $principal->userId !== $userId) {
            throw new ConflictException(
                'The workflow principal does not match the request actor.',
                ['reason' => 'WORKFLOW_PRINCIPAL_MISMATCH'],
            );
        }

        return $principal;
    }
}
