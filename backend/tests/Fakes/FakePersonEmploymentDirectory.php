<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Shared\Authorization\Contracts\PersonEmploymentDirectory;
use App\Shared\Authorization\EmploymentRef;

/**
 * Explicit in-memory People port for tests. IAM never queries People tables.
 */
final class FakePersonEmploymentDirectory implements PersonEmploymentDirectory
{
    /** @var array<int, list<int>> */
    public array $employmentByUser = [];

    /** @var array<int, EmploymentRef> */
    public array $contexts = [];

    /** @var array<int, list<int>> */
    public array $managedByUser = [];

    /** @var array<int, list<int>> */
    public array $employmentByOrgUnit = [];

    public function findEmployment(int $employmentId): ?EmploymentRef
    {
        return $this->contexts[$employmentId] ?? null;
    }

    public function employmentIdsForUser(int $userId, string $businessDate): array
    {
        return $this->employmentByUser[$userId] ?? [];
    }

    public function employmentIdsForOrgUnits(array $orgUnitIds, string $businessDate): array
    {
        $ids = [];
        foreach ($orgUnitIds as $unitId) {
            foreach ($this->employmentByOrgUnit[$unitId] ?? [] as $employmentId) {
                $ids[] = $employmentId;
            }
        }

        return array_values(array_unique($ids));
    }

    public function managerEmploymentIds(int $userId, string $businessDate): array
    {
        return $this->managedByUser[$userId] ?? [];
    }
}
