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

    public function snapshot(int $employmentId, string $businessDate): ?EmploymentSnapshot
    {
        $snapshot = $this->people->employmentSnapshot($employmentId, $businessDate);

        if ($snapshot === null) {
            return null;
        }

        return new EmploymentSnapshot(
            employmentId: $snapshot->employmentId,
            personId: $snapshot->personId,
            employeeNo: $snapshot->employeeNo,
            validFrom: '0001-01-01',
            validTo: '9999-12-31',
            seniorityStartDate: '0001-01-01',
            managerEmploymentId: $snapshot->managerEmploymentId,
            orgUnitId: $snapshot->orgUnitId,
            employeeName: (string) ($snapshot->personName ?? ''),
        );
    }

    public function activeEmploymentIds(string $businessDate): array
    {
        // Monthly close needs to distinguish "no applicable employee" from a
        // misconfigured empty month, so this uses People's frozen as-of bulk
        // contract rather than scanning People tables.
        return $this->people->employmentIdsAsOf($businessDate);
    }

    public function employmentForUser(int $userId, string $businessDate): ?int
    {
        return $this->people->employmentForUser($userId);
    }
}
