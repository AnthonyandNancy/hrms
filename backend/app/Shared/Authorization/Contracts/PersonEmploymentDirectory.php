<?php

declare(strict_types=1);

namespace App\Shared\Authorization\Contracts;

use App\Shared\Authorization\EmploymentRef;

/**
 * Read-only People port for authorization. Implemented by the People module in
 * a later task; IAM must never query People tables directly.
 */
interface PersonEmploymentDirectory
{
    /**
     * Employment IDs that belong to the person linked to the access subject.
     *
     * @return list<int>
     */
    public function employmentIdsForUser(int $tenantId, int $userId): array;

    /**
     * User ID linked to an employment, if any.
     */
    public function userIdForEmployment(int $tenantId, int $employmentId): ?int;

    /**
     * Employment IDs managed by the given user on the business date.
     *
     * @return list<int>
     */
    public function managedEmploymentIds(int $tenantId, int $managerUserId, string $businessDate): array;

    /**
     * Employment IDs whose current assignment is in one of the given units.
     *
     * @param  list<int>  $orgUnitIds
     * @return list<int>
     */
    public function employmentIdsForOrgUnits(int $tenantId, array $orgUnitIds, string $businessDate): array;

    /**
     * Resolve the legal entity and organization context for an employment on
     * the business date. Null means the employment is outside this tenant.
     */
    public function employmentContext(int $tenantId, int $employmentId, string $businessDate): ?EmploymentRef;
}
