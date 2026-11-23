<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * Frozen read contract consumed by IAM anchor resolvers and other modules.
 * Callers depend only on IDs, snapshots and tenant-scoped as-of semantics.
 */
interface PeopleDirectory
{
    public function employmentForUser(int $tenantId, int $userId): ?int;

    /**
     * @return list<int>
     */
    public function managedEmploymentIds(int $tenantId, int $employmentId, string $on): array;

    /**
     * @param  list<int>  $legalEntityIds
     * @return list<int>
     */
    public function employmentIdsForLegalEntities(int $tenantId, array $legalEntityIds, string $on): array;

    public function employmentSnapshot(int $tenantId, int $employmentId, string $on): ?EmploymentSnapshot;

    public function employmentExistsInTenant(int $tenantId, int $employmentId): bool;

    /**
     * @return list<int>
     */
    public function legalEntityIdsForEmployment(int $tenantId, int $employmentId): array;
}
