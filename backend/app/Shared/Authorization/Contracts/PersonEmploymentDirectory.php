<?php

declare(strict_types=1);

namespace App\Shared\Authorization\Contracts;

use App\Shared\Authorization\EmploymentRef;

/**
 * Read-only People port for authorization. IAM must never query People tables
 * directly; the People module binds the adapter.
 */
interface PersonEmploymentDirectory
{
    public function findEmployment(int $employmentId): ?EmploymentRef;

    /**
     * Employment IDs managed by the given user on the business date.
     *
     * @return list<int>
     */
    public function managerEmploymentIds(int $userId, string $businessDate): array;

    /**
     * Employment IDs that belong to the given access subject on the business
     * date.
     *
     * @return list<int>
     */
    public function employmentIdsForUser(int $userId, string $businessDate): array;

    /**
     * Employment IDs whose current assignment is in one of the given units.
     *
     * @param  list<int>  $orgUnitIds
     * @return list<int>
     */
    public function employmentIdsForOrgUnits(array $orgUnitIds, string $businessDate): array;
}
