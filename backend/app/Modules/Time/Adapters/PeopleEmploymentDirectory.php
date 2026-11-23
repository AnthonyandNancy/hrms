<?php

declare(strict_types=1);

namespace App\Modules\Time\Adapters;

use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\Time\Contracts\EmploymentDirectory;
use App\Modules\Time\Contracts\EmploymentSnapshot;

/**
 * People-backed implementation of Time's narrow employment port. Time keeps
 * its own DTO and never reads People tables directly.
 */
final class PeopleEmploymentDirectory implements EmploymentDirectory
{
    public function __construct(private readonly PeopleDirectory $people) {}

    public function snapshot(int $tenantId, int $employmentId, string $businessDate): ?EmploymentSnapshot
    {
        $snapshot = $this->people->employmentSnapshot($tenantId, $employmentId, $businessDate);

        if ($snapshot === null) {
            return null;
        }

        return new EmploymentSnapshot(
            tenantId: $tenantId,
            employmentId: $snapshot->employmentId,
            legalEntityId: $snapshot->legalEntityId,
            personId: $snapshot->personId,
            employeeNo: $snapshot->employeeNo,
            validFrom: '0001-01-01',
            validTo: '9999-12-31',
            seniorityStartDate: '0001-01-01',
            managerEmploymentId: $snapshot->managerEmploymentId,
            orgUnitId: $snapshot->orgUnitId,
        );
    }

    public function activeEmploymentIds(int $tenantId, string $businessDate): array
    {
        // Time never needs a full tenant employment scan for its HTTP reads;
        // scoped reads always carry an employment id. Keep the port fail-closed
        // rather than inventing a cross-module bulk query outside People's API.
        return [];
    }

    public function employmentForUser(int $tenantId, int $userId, string $businessDate): ?int
    {
        return $this->people->employmentForUser($tenantId, $userId);
    }

    public function legalEntityIdForEmployment(int $tenantId, int $employmentId): ?int
    {
        $ids = $this->people->legalEntityIdsForEmployment($tenantId, $employmentId);

        return $ids[0] ?? null;
    }
}
