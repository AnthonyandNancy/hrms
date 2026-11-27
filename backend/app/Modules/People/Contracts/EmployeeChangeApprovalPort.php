<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * People -> Workflow approval port for employee-profile changes.
 *
 * Implementations may depend only on Workflow Contracts. People never imports
 * Workflow models, services or tables; the returned case snapshot is the frozen
 * approval fact used when recording the local decision state.
 */
interface EmployeeChangeApprovalPort
{
    /**
     * @param  array<string, mixed>  $changes
     * @return array{case_id: int, case_no: string, status: string}
     */
    public function open(
        int $actorUserId,
        int $employmentId,
        int $changeRequestId,
        ?string $resource,
        ?int $recordId,
        array $changes,
        string $reason,
        string $businessDate,
    ): array;

    /**
     * @return array{status: string, case_no: string, subject_type: string, subject_id: int|null}|null
     */
    public function find(int $caseId): ?array;
}
