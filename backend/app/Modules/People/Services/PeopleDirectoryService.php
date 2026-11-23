<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\People\Contracts\EmploymentSnapshot;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentUserLink;
use App\Shared\Time\AsOfQuery;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use App\Shared\Time\TenantBusinessDate;

final class PeopleDirectoryService implements PeopleDirectory
{
    public function __construct(
        private readonly Clock $clock,
        private readonly OrganizationFacts $organization,
    ) {}

    public function employmentForUser(int $tenantId, int $userId): ?int
    {
        $on = (new TenantBusinessDate($this->clock, $this->organization))->today($tenantId)->value();

        $employmentId = EmploymentUserLink::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $on))
            ->orderByDesc('valid_from')
            ->value('employment_id');

        return $employmentId !== null ? (int) $employmentId : null;
    }

    /**
     * @return list<int>
     */
    public function managedEmploymentIds(int $tenantId, int $employmentId, string $on): array
    {
        $date = BusinessDate::from($on)->value();

        $managerAssignmentIds = Assignment::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $date))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($managerAssignmentIds === []) {
            return [];
        }

        return Assignment::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('manager_assignment_id', $managerAssignmentIds)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $date))
            ->orderBy('employment_id')
            ->pluck('employment_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $legalEntityIds
     * @return list<int>
     */
    public function employmentIdsForLegalEntities(int $tenantId, array $legalEntityIds, string $on): array
    {
        $date = BusinessDate::from($on)->value();

        return Employment::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('legal_entity_id', $legalEntityIds)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $date))
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function employmentSnapshot(int $tenantId, int $employmentId, string $on): ?EmploymentSnapshot
    {
        $date = BusinessDate::from($on)->value();

        $employment = Employment::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($employmentId)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $date))
            ->first();

        if ($employment === null) {
            return null;
        }

        $assignment = Assignment::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $date))
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        $managerEmploymentId = null;

        if ($assignment !== null && $assignment->manager_assignment_id !== null) {
            $managerEmploymentId = Assignment::query()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $assignment->manager_assignment_id)
                ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $date))
                ->value('employment_id');
        }

        $orgUnit = $assignment !== null
            ? $this->organization->orgUnit($tenantId, (int) $assignment->org_unit_id)
            : null;

        return new EmploymentSnapshot(
            employmentId: (int) $employment->id,
            personId: (int) $employment->person_id,
            legalEntityId: (int) $employment->legal_entity_id,
            orgUnitId: $orgUnit !== null ? $orgUnit->id : ($assignment !== null ? (int) $assignment->org_unit_id : null),
            positionId: $assignment?->position_id !== null ? (int) $assignment->position_id : null,
            managerEmploymentId: $managerEmploymentId !== null ? (int) $managerEmploymentId : null,
            status: (string) $employment->status,
            employeeNo: (string) $employment->employee_no,
            assignmentId: $assignment !== null ? (int) $assignment->id : null,
            managerAssignmentId: $assignment?->manager_assignment_id !== null ? (int) $assignment->manager_assignment_id : null,
        );
    }

    public function employmentExistsInTenant(int $tenantId, int $employmentId): bool
    {
        return Employment::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($employmentId)
            ->exists();
    }

    /**
     * @return list<int>
     */
    public function legalEntityIdsForEmployment(int $tenantId, int $employmentId): array
    {
        return Employment::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($employmentId)
            ->pluck('legal_entity_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
