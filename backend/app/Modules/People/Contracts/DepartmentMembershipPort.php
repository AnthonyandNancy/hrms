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
    public function moveMember(int $tenantId, int $targetOrgUnitId, int $employmentId, string $effectiveFrom, ?int $actorUserId): array;
}
