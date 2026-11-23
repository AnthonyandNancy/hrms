<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

/**
 * Narrow port owned by Time. People implements it later; Time must never read
 * People tables directly.
 */
interface EmploymentDirectory
{
    public function snapshot(int $tenantId, int $employmentId, string $businessDate): ?EmploymentSnapshot;

    /**
     * @return list<int>
     */
    public function activeEmploymentIds(int $tenantId, string $businessDate): array;

    public function employmentForUser(int $tenantId, int $userId, string $businessDate): ?int;

    public function legalEntityIdForEmployment(int $tenantId, int $employmentId): ?int;
}
