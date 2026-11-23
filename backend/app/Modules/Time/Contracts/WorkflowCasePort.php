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
        int $tenantId,
        int $requesterUserId,
        string $type,
        string $subjectType,
        ?int $subjectId,
        array $payload,
        string $businessDate,
    ): int;

    public function case(int $tenantId, int $caseId): ?WorkflowCaseSnapshot;

    public function requestCancel(int $tenantId, int $caseId, int $actorUserId, string $reason): void;
}
