<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

/**
 * Narrow Workflow port. Time requests are Cases; Time never stores an
 * approval status of its own and never imports Workflow models.
 */
interface WorkflowCasePort
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function createCase(
        int $requesterUserId,
        string $type,
        string $subjectType,
        ?int $subjectId,
        array $payload,
        string $businessDate,
    ): int;

    /**
     * Record an approval decision through Workflow. The decision action is
     * APPROVE or REJECT; status is never written by the business module.
     */
    public function decide(int $caseId, string $action, string $comment): void;

    public function case(int $caseId): ?WorkflowCaseSnapshot;

    public function requestCancel(int $caseId, int $actorUserId, string $reason): void;

    /**
     * Execute the declared reversal for an already applied case effect.
     * Workflow remains the owner of the effect row and status.
     */
    public function reverseEffect(int $caseId, string $reason, string $effectiveOn): void;
}
