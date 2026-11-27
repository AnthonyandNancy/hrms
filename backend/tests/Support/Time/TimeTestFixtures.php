<?php

declare(strict_types=1);

namespace Tests\Support\Time;

use App\Modules\Time\Contracts\EmploymentSnapshot;
use App\Modules\Time\Contracts\TimeActor;

final class TimeTestFixtures
{
    public static function employment(
        int $employmentId = 1,
        string $validFrom = '2020-01-01',
        string $validTo = '9999-12-31',
        string $seniorityStartDate = '2020-01-01',
        ?int $managerEmploymentId = null,
    ): EmploymentSnapshot {
        return new EmploymentSnapshot(
            employmentId: $employmentId,
            personId: $employmentId,
            employeeNo: 'E'.str_pad((string) $employmentId, 4, '0', STR_PAD_LEFT),
            validFrom: $validFrom,
            validTo: $validTo,
            seniorityStartDate: $seniorityStartDate,
            managerEmploymentId: $managerEmploymentId,
            orgUnitId: null,
        );
    }

    /**
     * @param  list<string>  $capabilities
     * @param  list<int>  $managedEmploymentIds
     */
    public static function actor(
        int $userId = 10,
        ?int $employmentId = 1,
        string $scope = 'SELF',
        array $capabilities = [
            'time.attendance.read',
            'time.attendance.manage',
            'time.leave.read',
            'time.leave.request',
            'time.leave.admin',
            'time.punch.import',
            'time.attendance.correct.request',
            'time.calendar.read',
            'time.shift.read',
            'time.leave.request.cancel',
            'time.leave.ledger.read',
        ],
        array $managedEmploymentIds = [],
    ): TimeActor {
        return new TimeActor(
            userId: $userId,
            employmentId: $employmentId,
            scope: $scope,
            capabilities: $capabilities,
            managedEmploymentIds: $managedEmploymentIds,
        );
    }
}
