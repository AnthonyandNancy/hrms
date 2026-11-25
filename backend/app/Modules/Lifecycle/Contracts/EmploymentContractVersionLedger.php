<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Contracts;

/**
 * Append-only employment-contract history owned by Lifecycle.
 *
 * People owns the contract ledger but must not write Lifecycle's version table
 * directly. Contract lifecycle commands append through this port so the
 * version table keeps exactly one writer.
 */
interface EmploymentContractVersionLedger
{
    /**
     * Append one immutable history row and return its public representation.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function append(
        int $tenantId,
        int $contractId,
        int $employmentId,
        string $changeType,
        string $effectiveFrom,
        string $effectiveTo,
        ?string $reason,
        array $snapshot,
        ?int $actorUserId,
    ): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $tenantId, int $contractId): array;
}
