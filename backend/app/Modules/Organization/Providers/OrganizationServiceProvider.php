<?php

declare(strict_types=1);

namespace App\Modules\Organization\Providers;

use App\Modules\Organization\Authorization\EloquentOrganizationScopeDirectory;
use App\Modules\Organization\Commands\RebuildOrgClosureCommand;
use App\Modules\Organization\Contracts\CompanyProfileReader;
use App\Modules\Organization\Contracts\CompanyProfileWriter;
use App\Modules\Organization\Contracts\DemoOrganizationSeeder;
use App\Modules\Organization\Contracts\OrganizationBootstrap;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\Organization\Services\CompanyProfileWriterService;
use App\Modules\Organization\Services\DemoOrganizationSeederService;
use App\Modules\Organization\Services\EloquentCompanyProfileReader;
use App\Modules\Organization\Services\OrganizationBootstrapService;
use App\Modules\Organization\Services\OrganizationDirectoryService;
use App\Modules\Organization\Services\OrganizationFactsService;
use App\Shared\Authorization\Contracts\OrganizationScopeDirectory;
use Illuminate\Support\ServiceProvider;

/**
 * Owns the Organization read contracts. People consumes OrganizationFacts and
 * OrganizationDirectory; it never imports Organization models or services.
 */
final class OrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrganizationDirectory::class, OrganizationDirectoryService::class);
        $this->app->bind(OrganizationFacts::class, OrganizationFactsService::class);
        $this->app->bind(OrganizationBootstrap::class, OrganizationBootstrapService::class);
        $this->app->bind(CompanyProfileReader::class, EloquentCompanyProfileReader::class);
        $this->app->bind(CompanyProfileWriter::class, CompanyProfileWriterService::class);
        if (! $this->app->environment('testing')) {
            $this->app->bind(OrganizationScopeDirectory::class, EloquentOrganizationScopeDirectory::class);
        }
        $this->app->bind(DemoOrganizationSeeder::class, DemoOrganizationSeederService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RebuildOrgClosureCommand::class,
            ]);
        }
    }
}
