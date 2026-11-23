<?php

declare(strict_types=1);

namespace Tests\Support\Time;

use App\Modules\Time\Contracts\EmploymentSnapshot;
use App\Modules\Time\Contracts\WorkflowCasePort;
use App\Modules\Time\Contracts\WorkflowCaseSnapshot;
use App\Modules\Time\Contracts\TimeActor;
use App\Modules\Time\Contracts\TimeActorResolver;
use Illuminate\Http\Request;

final class TimeTestFixtures
{
    public static function employment(
        int $tenantId = 1,
        int $employmentId = 1,
        int $legalEntityId = 1,
        string $validFrom = '2020-01-01',
        string $validTo = '9999-12-31',
        string $seniorityStartDate = '2020-01-01',
        ?int $managerEmploymentId = null,
    ): EmploymentSnapshot {
        return new EmploymentSnapshot(
            tenantId: $tenantId,
            employmentId: $employmentId,
            legalEntityId: $legalEntityId,
            personId: $employmentId,
            employeeNo: 'E'.str_pad((string) $employmentId, 4, '0', STR_PAD_LEFT),
            validFrom: $validFrom,
            validTo: $validTo,
            seniorityStartDate: $seniorityStartDate,
            managerEmploymentId: $managerEmploymentId,
            orgUnitId: null,
        );
    }

    public static function actor(
        int $tenantId = 1,
        int $userId = 10,
        ?int $employmentId = 1,
        string $scope = 'SELF',
        array $capabilities = [
            'time.attendance.read',
            'time.attendance.manage',
            'time.leave.read',
            'time.leave.request',
            'time.leave.admin',
        ],
        array $managedEmploymentIds = [],
        array $legalEntityIds = [],
    ): TimeActor {
        return new TimeActor(
            userId: $userId,
            tenantId: $tenantId,
            employmentId: $employmentId,
            scope: $scope,
            capabilities: $capabilities,
            managedEmploymentIds: $managedEmploymentIds,
            legalEntityIds: $legalEntityIds,
        );
    }
}
