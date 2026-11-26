<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * Organization requests a department move; People owns assignments and
 * performs the effective-dated append inside one transaction.
 */
interface DepartmentMembershipPort
{
    /**
     * @return array{assignment_id: int, employment_id: int, org_unit_id: int, valid_from: string}
     */
    public function moveMember(int $targetOrgUnitId, int $employmentId, string $effectiveFrom, ?int $actorUserId): array;

    /**
     * End the member's primary assignment in this department from a business
     * date. Removal is effective-dated, never a destructive delete.
     *
     * @return array{employment_id: int, assignment_id: int, effective_from: string}
     */
    public function removeMember(int $orgUnitId, int $employmentId, string $effectiveFrom, ?int $actorUserId): array;
}
