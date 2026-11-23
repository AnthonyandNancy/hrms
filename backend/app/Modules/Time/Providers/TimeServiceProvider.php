<?php

declare(strict_types=1);

namespace App\Modules\Time\Providers;

use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\Time\Adapters\PeopleEmploymentDirectory;
use App\Modules\Time\Adapters\WorkflowCaseAdapter;
use App\Modules\Time\Contracts\AttendanceWorkspaceReader;
use App\Modules\Time\Contracts\EmploymentDirectory;
use App\Modules\Time\Contracts\LeaveBalanceReader;
use App\Modules\Time\Contracts\LeaveLiabilityDirectory;
use App\Modules\Time\Contracts\LeaveRequestReader;
use App\Modules\Time\Contracts\LegalEntityCalendarDirectory;
use App\Modules\Time\Contracts\PayrollInputDirectory;
use App\Modules\Time\Contracts\WorkflowCasePort;
use App\Modules\Time\Services\AttendanceWorkspaceReaderService;
use App\Modules\Time\Services\BusinessCalendar;
use App\Modules\Time\Services\EloquentLeaveLiabilityDirectory;
use App\Modules\Time\Services\LeaveBalanceReaderService;
use App\Modules\Time\Services\LeaveRequestReaderService;
use App\Modules\Time\Services\PayrollInputDirectoryService;
use App\Modules\Workflow\Contracts\CaseCommandPort;
use App\Modules\Workflow\Contracts\WorkflowPrincipalResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Time owns attendance and the leave ledger. Payroll and Reporting consume
 * only narrow Time\Contracts ports; they never read Time tables directly.
 */
final class TimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmploymentDirectory::class, function (Application $app): PeopleEmploymentDirectory {
            return new PeopleEmploymentDirectory($app->make(PeopleDirectory::class));
        });

        $this->app->bind(LegalEntityCalendarDirectory::class, BusinessCalendar::class);

        $this->app->bind(WorkflowCasePort::class, function (Application $app): WorkflowCaseAdapter {
            return new WorkflowCaseAdapter(
                $app->make(CaseCommandPort::class),
                $app->make(WorkflowPrincipalResolver::class),
            );
        });

        $this->app->bind(AttendanceWorkspaceReader::class, AttendanceWorkspaceReaderService::class);
        $this->app->bind(LeaveBalanceReader::class, LeaveBalanceReaderService::class);
        $this->app->bind(LeaveRequestReader::class, LeaveRequestReaderService::class);
        $this->app->bind(PayrollInputDirectory::class, PayrollInputDirectoryService::class);
        $this->app->bindIf(LeaveLiabilityDirectory::class, EloquentLeaveLiabilityDirectory::class);
    }
}
