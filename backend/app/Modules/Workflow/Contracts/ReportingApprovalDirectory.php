<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Contracts;

/**
 * Pending-approval projection for Reporting. No comments, decision payloads or
 * candidate data cross the module boundary.
 */
interface ReportingApprovalDirectory
{
    /**
     * @return list<PendingApprovalRow>
     */
    public function pendingApprovals(int $tenantId): array;
}
