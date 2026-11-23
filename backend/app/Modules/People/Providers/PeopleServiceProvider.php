<?php

declare(strict_types=1);

namespace App\Modules\People\Providers;

use App\Modules\People\Commands\PeopleAsOfCheckCommand;
use App\Modules\People\Commands\SeedPeopleDemoCommand;
use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Modules\People\Contracts\OrgAssignmentImpactDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\People\Contracts\PeopleEmploymentWriter;
use App\Modules\People\Services\EmploymentLifecycleDirectoryService;
use App\Modules\People\Services\OrgAssignmentImpactService;
use App\Modules\People\Services\PeopleDirectoryService;
use App\Modules\People\Services\PeopleEmploymentWriterService;
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
        $this->app->bind(OrgAssignmentImpactDirectory::class, OrgAssignmentImpactService::class);

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
