<?php

declare(strict_types=1);

namespace App\Shared\Authorization\Contracts;

/**
 * Payroll run membership port. Payroll uses run-scoped bindings so a
 * specialist cannot see a run they are not assigned to.
 */
interface PayrollRunMembershipDirectory
{
    /**
     * Whether the user participates in the run in a role compatible with the
     * requested capability.
     */
    public function isMember(int $tenantId, int $userId, int $payrollRunId, string $capabilityCode): bool;

    /**
     * Payroll runs the user may touch on the business date.
     *
     * @return list<int>
     */
    public function runIdsForUser(int $tenantId, int $userId, string $businessDate): array;

    /**
     * Legal entity owning a payroll run.
     */
    public function legalEntityForRun(int $tenantId, int $payrollRunId): ?int;
}
