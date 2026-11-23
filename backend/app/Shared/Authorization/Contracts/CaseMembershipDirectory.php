<?php

declare(strict_types=1);

namespace App\Shared\Authorization\Contracts;

/**
 * Workflow case membership port. Case membership is data, not a capability
 * grant, but it narrows the CASE anchor to actual participants.
 */
interface CaseMembershipDirectory
{
    /**
     * Whether the capability is intended for a case (e.g. read/decide) and the
     * user is a current member of that case in the tenant.
     */
    public function isMember(int $tenantId, int $userId, int $caseId, string $capabilityCode): bool;

    /**
     * Case IDs of which the user is a member and which are visible on the
     * business date.
     *
     * @return list<int>
     */
    public function caseIdsForUser(int $tenantId, int $userId, string $businessDate): array;

    /**
     * Legal entity that owns the case, used for tenant/legal entity checks.
     */
    public function legalEntityForCase(int $tenantId, int $caseId): ?int;
}
