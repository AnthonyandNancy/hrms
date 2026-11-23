<?php

declare(strict_types=1);

namespace App\Modules\People\Providers;

use App\Modules\Organization\Commands\RebuildOrgClosureCommand;
use App\Modules\Organization\Contracts\DemoOrganizationSeeder;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\Organization\Services\DemoOrganizationSeederService;
use App\Modules\Organization\Services\OrganizationDirectoryService;
use App\Modules\Organization\Services\OrganizationFactsService;
use App\Modules\People\Commands\PeopleAsOfCheckCommand;
use App\Modules\People\Commands\SeedPeopleDemoCommand;
use App\Modules\People\Contracts\OrgAssignmentImpactDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\People\Services\OrgAssignmentImpactService;
use App\Modules\People\Services\PeopleDirectoryService;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\DenyAllAuthorizer;
use App\Shared\Time\Clock;
use App\Shared\Time\SystemClock;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the frozen directory contracts and shared time seam. IAM replaces
 * the fail-closed Authorizer binding when its capability engine is installed.
 */
final class PeopleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Clock::class, SystemClock::class);
        $this->app->bind(PeopleDirectory::class, PeopleDirectoryService::class);
        $this->app->bind(OrganizationDirectory::class, OrganizationDirectoryService::class);
        $this->app->bind(OrganizationFacts::class, OrganizationFactsService::class);
        $this->app->bind(OrgAssignmentImpactDirectory::class, OrgAssignmentImpactService::class);
        $this->app->bind(DemoOrganizationSeeder::class, DemoOrganizationSeederService::class);
        $this->app->singleton(Authorizer::class, DenyAllAuthorizer::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SeedPeopleDemoCommand::class,
                RebuildOrgClosureCommand::class,
                PeopleAsOfCheckCommand::class,
            ]);
        }
    }
}
