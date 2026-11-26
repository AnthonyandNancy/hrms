<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts;

/**
 * Workflow executes the payroll approval effect through this port. Workflow
 * never imports Payroll services or models.
 */
interface PayrollApprovalEffectPort
{
    /**
     * Apply the approval effect. Must be idempotent and fail closed if the run
     * is no longer in a state where approval may be applied.
     */
    public function applyApproval(int $runId, int $actorUserId): void;
}
