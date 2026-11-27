<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

/**
 * Narrow port owned by Time. People implements it later; Time must never read
 * People tables directly.
 */
interface EmploymentDirectory
{
    public function snapshot(int $employmentId, string $businessDate): ?EmploymentSnapshot;

    /**
     * @return list<int>
     */
    public function activeEmploymentIds(string $businessDate): array;

    public function employmentForUser(int $userId, string $businessDate): ?int;
}
