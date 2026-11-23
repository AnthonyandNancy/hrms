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

    public function employmentIdsForUser(int $tenantId, int $userId): array
    {
        return $this->employmentByUser[$userId] ?? [];
    }

    public function employmentIdsForOrgUnits(int $tenantId, array $orgUnitIds, string $businessDate): array
    {
        $ids = [];
        foreach ($orgUnitIds as $unitId) {
            foreach ($this->employmentByOrgUnit[$unitId] ?? [] as $employmentId) {
                $ids[] = $employmentId;
            }
        }

        return array_values(array_unique($ids));
    }

    public function userIdForEmployment(int $tenantId, int $employmentId): ?int
    {
        foreach ($this->employmentByUser as $userId => $employmentIds) {
            if (in_array($employmentId, $employmentIds, true)) {
                return $userId;
            }
        }

        return null;
    }

    public function managedEmploymentIds(int $tenantId, int $managerUserId, string $businessDate): array
    {
        return $this->managedByUser[$managerUserId] ?? [];
    }

    public function employmentContext(int $tenantId, int $employmentId, string $businessDate): ?EmploymentRef
    {
        $context = $this->contexts[$employmentId] ?? null;

        return $context !== null && $context->tenantId === $tenantId ? $context : null;
    }
}
