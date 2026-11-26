<?php

declare(strict_types=1);

namespace App\Modules\People\Authorization;

use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentUserLink;
use App\Shared\Authorization\Contracts\PersonEmploymentDirectory;
use App\Shared\Authorization\EmploymentRef;
use App\Shared\Time\AsOfQuery;

/**
 * Production read adapter for IAM's authorization engine. People owns these
 * tables; IAM depends only on the Shared contract.
 */
final class EloquentPersonEmploymentDirectory implements PersonEmploymentDirectory
{
    /** @return list<int> */
    public function employmentIdsForUser(int $userId, string $businessDate): array
    {
        return EmploymentUserLink::query()
            ->where('user_id', $userId)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $businessDate))
            ->orderBy('employment_id')
            ->pluck('employment_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<int> */
    public function managerEmploymentIds(int $userId, string $businessDate): array
    {
        $employmentIds = $this->employmentIdsForUser($userId, $businessDate);

        if ($employmentIds === []) {
            return [];
        }

        $managerAssignmentIds = Assignment::query()
            ->whereIn('employment_id', $employmentIds)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $businessDate))
            ->pluck('id')
            ->all();

        if ($managerAssignmentIds === []) {
            return [];
        }

        return Assignment::query()
            ->whereIn('manager_assignment_id', $managerAssignmentIds)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $businessDate))
            ->orderBy('employment_id')
            ->pluck('employment_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<int> */
    public function employmentIdsForOrgUnits(array $orgUnitIds, string $businessDate): array
    {
        if ($orgUnitIds === []) {
            return [];
        }

        return Assignment::query()
            ->whereIn('org_unit_id', $orgUnitIds)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $businessDate))
            ->orderBy('employment_id')
            ->pluck('employment_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function findEmployment(int $employmentId): ?EmploymentRef
    {
        $employment = Employment::query()
            ->whereKey($employmentId)
            ->first();

        if ($employment === null) {
            return null;
        }

        $assignment = Assignment::query()
            ->where('employment_id', $employmentId)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        $userId = EmploymentUserLink::query()
            ->where('employment_id', $employmentId)
            ->orderByDesc('valid_from')
            ->value('user_id');

        return new EmploymentRef(
            employmentId: $employmentId,
            personId: (int) $employment->person_id,
            userId: $userId !== null ? (int) $userId : null,
            orgUnitId: $assignment !== null ? (int) $assignment->org_unit_id : null,
            managerEmploymentId: null,
            validFrom: (string) $employment->valid_from,
            validTo: (string) $employment->valid_to,
        );
    }
}
