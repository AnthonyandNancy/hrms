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
    public function employmentIdsForUser(int $tenantId, int $userId): array
    {
        // Authorization must be deterministic relative to the injected clock
        // and the tenant's business date, never the host wall clock.
        $today = app(\App\Shared\Time\TenantBusinessDate::class)->today($tenantId)->value();

        return EmploymentUserLink::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $today))
            ->orderBy('employment_id')
            ->pluck('employment_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function userIdForEmployment(int $tenantId, int $employmentId): ?int
    {
        $today = app(\App\Shared\Time\TenantBusinessDate::class)->today($tenantId)->value();
        $userId = EmploymentUserLink::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $today))
            ->orderBy('user_id')
            ->value('user_id');

        return $userId === null ? null : (int) $userId;
    }

    /** @return list<int> */
    public function managedEmploymentIds(int $tenantId, int $managerUserId, string $businessDate): array
    {
        $employmentIds = $this->employmentIdsForUser($tenantId, $managerUserId);

        if ($employmentIds === []) {
            return [];
        }

        $managerAssignmentIds = Assignment::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('employment_id', $employmentIds)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $businessDate))
            ->pluck('id')
            ->all();

        if ($managerAssignmentIds === []) {
            return [];
        }

        return Assignment::query()
            ->where('tenant_id', $tenantId)
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
    public function employmentIdsForOrgUnits(int $tenantId, array $orgUnitIds, string $businessDate): array
    {
        if ($orgUnitIds === []) {
            return [];
        }

        return Assignment::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('org_unit_id', $orgUnitIds)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $businessDate))
            ->orderBy('employment_id')
            ->pluck('employment_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function employmentContext(int $tenantId, int $employmentId, string $businessDate): ?EmploymentRef
    {
        $employment = Employment::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($employmentId)
            ->first();

        if ($employment === null) {
            return null;
        }

        $assignment = Assignment::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $businessDate))
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        return new EmploymentRef(
            tenantId: $tenantId,
            employmentId: $employmentId,
            orgUnitId: $assignment !== null ? (int) $assignment->org_unit_id : null,
            legalEntityId: (int) $employment->legal_entity_id,
        );
    }
}
