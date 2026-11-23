<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\IAM\Auth\TimeActorBridge;
use App\Modules\Time\Adapters\PeopleEmploymentDirectory;
use App\Modules\Time\Adapters\WorkflowCaseAdapter;
use App\Modules\Time\Contracts\EmploymentDirectory;
use App\Modules\Time\Contracts\TimeActorResolver;
use App\Modules\Time\Contracts\WorkflowCasePort;
use App\Modules\Time\Services\AttendanceCalculator;
use App\Modules\Time\Services\BusinessCalendar;
use App\Modules\Time\Services\LeaveAccountService;
use App\Modules\Time\Services\LeaveBalanceService;
use App\Modules\Time\Services\LeaveLedgerService;
use App\Modules\Time\Services\PunchService;
use App\Modules\Time\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TimeContainerResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_time_contracts_resolve_to_production_adapters(): void
    {
        $this->assertInstanceOf(TimeActorBridge::class, app(TimeActorResolver::class));
        $this->assertInstanceOf(PeopleEmploymentDirectory::class, app(EmploymentDirectory::class));
        $this->assertInstanceOf(WorkflowCaseAdapter::class, app(WorkflowCasePort::class));
    }

    public function test_time_services_and_dependencies_resolve_from_the_container(): void
    {
        foreach ([
            AttendanceCalculator::class,
            BusinessCalendar::class,
            PunchService::class,
            ShiftService::class,
            LeaveAccountService::class,
            LeaveBalanceService::class,
            LeaveLedgerService::class,
        ] as $class) {
            $this->assertInstanceOf($class, app($class), $class.' did not resolve.');
        }
    }
}
