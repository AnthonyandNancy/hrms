<?php

declare(strict_types=1);

namespace App\Modules\People\Providers;

use App\Modules\People\Authorization\EloquentEmploymentManagerDirectory;
use App\Modules\People\Authorization\EloquentPersonEmploymentDirectory;
use App\Modules\People\Commands\PeopleAsOfCheckCommand;
use App\Modules\People\Commands\SeedPeopleDemoCommand;
use App\Modules\People\Contracts\AssignmentDirectory;
use App\Modules\People\Contracts\DepartmentMembershipPort;
use App\Modules\People\Contracts\DepartmentStaffingDirectory;
use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Modules\People\Contracts\EmploymentTermsDirectory;
use App\Modules\People\Contracts\NoticeAudienceDirectory;
use App\Modules\People\Contracts\OrgAssignmentImpactDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\People\Contracts\PeopleEmploymentWriter;
use App\Modules\People\Contracts\PeopleWorkspaceDirectory;
use App\Modules\People\Contracts\PositionOccupancyDirectory;
use App\Modules\People\Contracts\WorkforceIntegrityDirectory;
use App\Modules\People\Contracts\WorkforceReportingDirectory;
use App\Modules\People\Services\AssignmentDirectoryService;
use App\Modules\People\Services\DepartmentMembershipService;
use App\Modules\People\Services\DepartmentStaffingService;
use App\Modules\People\Services\EloquentNoticeAudienceDirectory;
use App\Modules\People\Services\EloquentPeopleWorkspaceDirectory;
use App\Modules\People\Services\EloquentWorkforceIntegrityDirectory;
use App\Modules\People\Services\EloquentWorkforceReportingDirectory;
use App\Modules\People\Services\EmploymentLifecycleDirectoryService;
use App\Modules\People\Services\EmploymentTermsDirectoryService;
use App\Modules\People\Services\OrgAssignmentImpactService;
use App\Modules\People\Services\PeopleDirectoryService;
use App\Modules\People\Services\PeopleEmploymentWriterService;
use App\Modules\People\Services\PositionOccupancyService;
use App\Shared\Authorization\Contracts\EmploymentManagerDirectory;
use App\Shared\Authorization\Contracts\PersonEmploymentDirectory;
use App\Shared\Time\Clock;
use App\Shared\Time\SystemClock;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the People read contracts and the fail-closed authorization seam.
 * Organization contracts are bound by OrganizationServiceProvider so this
 * module never imports another module's concrete services. IAM binds the
 * Authorizer engine; People never rebinds it.
 */
final class PeopleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Clock::class, SystemClock::class);
        $this->app->bind(PeopleDirectory::class, PeopleDirectoryService::class);
        $this->app->bind(EmploymentLifecycleDirectory::class, EmploymentLifecycleDirectoryService::class);
        $this->app->bind(PeopleEmploymentWriter::class, PeopleEmploymentWriterService::class);
        $this->app->bind(EmploymentTermsDirectory::class, EmploymentTermsDirectoryService::class);
        $this->app->bind(PeopleWorkspaceDirectory::class, EloquentPeopleWorkspaceDirectory::class);
        $this->app->bind(OrgAssignmentImpactDirectory::class, OrgAssignmentImpactService::class);
        $this->app->bind(PositionOccupancyDirectory::class, PositionOccupancyService::class);
        $this->app->bind(AssignmentDirectory::class, AssignmentDirectoryService::class);
        $this->app->bind(DepartmentStaffingDirectory::class, DepartmentStaffingService::class);
        $this->app->bind(DepartmentMembershipPort::class, DepartmentMembershipService::class);
        $this->app->bindIf(NoticeAudienceDirectory::class, EloquentNoticeAudienceDirectory::class);
        $this->app->bindIf(WorkforceReportingDirectory::class, EloquentWorkforceReportingDirectory::class);
        $this->app->bindIf(WorkforceIntegrityDirectory::class, EloquentWorkforceIntegrityDirectory::class);
        if (! $this->app->environment('testing')) {
            $this->app->bind(PersonEmploymentDirectory::class, EloquentPersonEmploymentDirectory::class);
        }

        $this->app->bindIf(EmploymentManagerDirectory::class, EloquentEmploymentManagerDirectory::class);

    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SeedPeopleDemoCommand::class,
                PeopleAsOfCheckCommand::class,
            ]);
        }
    }
}
