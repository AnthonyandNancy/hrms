<?php

declare(strict_types=1);

namespace App\Modules\People\Providers;

use App\Modules\People\Commands\PeopleAsOfCheckCommand;
use App\Modules\People\Commands\SeedPeopleDemoCommand;
use App\Modules\People\Contracts\OrgAssignmentImpactDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\People\Services\OrgAssignmentImpactService;
use App\Modules\People\Services\PeopleDirectoryService;
use App\Shared\Time\Clock;
use App\Shared\Time\SystemClock;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the People read contracts and the fail-closed authorization seam.
 * IAM later rebinds Authorizer to the capability engine.
 */
final class PeopleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Clock::class, SystemClock::class);
        $this->app->bind(PeopleDirectory::class, PeopleDirectoryService::class);
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
