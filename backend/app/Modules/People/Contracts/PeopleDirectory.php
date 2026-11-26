<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * Frozen read contract consumed by IAM anchor resolvers and other modules.
 * Callers depend only on IDs, snapshots and as-of semantics.
 */
interface PeopleDirectory
{
    public function employmentForUser(int $userId): ?int;

    /**
     * @return list<int>
     */
    public function managedEmploymentIds(int $employmentId, string $on): array;

    /**
     * @return list<int>
     */
    public function employmentIdsAsOf(string $on): array;

    public function employmentSnapshot(int $employmentId, string $on): ?EmploymentSnapshot;

    public function employmentExistsInTenant(int $employmentId): bool;
}
