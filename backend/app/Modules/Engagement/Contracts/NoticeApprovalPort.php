<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Contracts;

use App\Modules\Workflow\Contracts\WorkflowPrincipal;

/**
 * Engagement -> Workflow bridge for notice approvals. Engagement imports only
 * Workflow Contracts; the Case remains the authoritative approval record.
 */
interface NoticeApprovalPort
{
    /**
     * Ensure the code-owned notice approval template exists and is published.
     */
    public function ensureTemplate(int $tenantId): void;

    /**
     * Open and submit a Notice approval case.
     *
     * @param  list<int>  $recipientUserIds
     * @return array{case_id: int, case_no: string, status: string}
     */
    public function open(
        WorkflowPrincipal $principal,
        int $noticeId,
        string $title,
        int $audienceCount,
        array $recipientUserIds,
        string $businessDate,
    ): array;

    /**
     * Record an APPROVE/REJECT decision against the notice case. The adapter
     * verifies that the case belongs to the notice before delegating.
     *
     * @return array{case_id: int, case_status: string, effect_status: string}
     */
    public function decide(
        WorkflowPrincipal $principal,
        int $noticeId,
        int $caseId,
        string $action,
        string $comment,
        string $businessDate,
    ): array;

    /**
     * @return array{status: string, payload: array<string, mixed>}|null
     */
    public function find(int $tenantId, int $caseId): ?array;
}
