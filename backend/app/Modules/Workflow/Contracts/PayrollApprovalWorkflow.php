<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Contracts;

/**
 * Payroll approval is implemented as a Workflow Case/Decision/Effect. Payroll
 * depends on this contract, never on Workflow models or services.
 */
interface PayrollApprovalWorkflow
{
    public function open(
        int $tenantId,
        int $requesterUserId,
        int $runId,
        string $businessDate,
    ): int;

    /**
     * @return array{case_id: int, application_status: string, effect_status: string}
     */
    public function approve(
        int $tenantId,
        int $caseId,
        int $actorUserId,
        string $comment,
        string $businessDate,
    ): array;

    /**
     * @return array{application_status: string|null, effect_status: string|null}
     */
    public function status(int $tenantId, int $caseId): array;
}
