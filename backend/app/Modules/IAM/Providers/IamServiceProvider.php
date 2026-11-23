<?php

declare(strict_types=1);

namespace App\Modules\IAM\Providers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Auth\CapabilityAuthorizer;
use App\Modules\IAM\Authorization\EloquentAccessSubjectStateDirectory;
use App\Modules\IAM\Authorization\EloquentAuthorityBindingRepository;
use App\Modules\IAM\Authorization\EloquentElevationGrantDirectory;
use App\Modules\IAM\Authorization\PlatformSensitiveAccessAuditor;
use App\Modules\IAM\Contracts\UserAudienceDirectory;
use App\Modules\IAM\Services\EloquentUserAudienceDirectory;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\Contracts\AccessSubjectStateDirectory;
use App\Shared\Authorization\Contracts\AuthorityBindingRepository;
use App\Shared\Authorization\Contracts\CaseMembershipDirectory;
use App\Shared\Authorization\Contracts\ElevationGrantDirectory;
use App\Shared\Authorization\Contracts\OrganizationScopeDirectory;
use App\Shared\Authorization\Contracts\PayrollRunMembershipDirectory;
use App\Shared\Authorization\Contracts\PersonEmploymentDirectory;
use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use Illuminate\Support\ServiceProvider;
use Tests\Fakes\FakeCaseMembershipDirectory;
use Tests\Fakes\FakeOrganizationScopeDirectory;
use Tests\Fakes\FakePayrollRunMembershipDirectory;
use Tests\Fakes\FakePersonEmploymentDirectory;

final class IamServiceProvider extends ServiceProvider
{
    /** @var list<class-string> */
    private const COMMANDS = [
        \App\Modules\IAM\Console\Commands\SyncCapabilitiesCommand::class,
        \App\Modules\IAM\Console\Commands\SeedDemoCommand::class,
        \App\Modules\IAM\Console\Commands\ReapSessionsCommand::class,
        \App\Modules\IAM\Console\Commands\AccessReviewCommand::class,
    ];

    public function register(): void
    {
        $this->commands(self::COMMANDS);

        $this->app->scoped(AuthenticationContext::class);

        $this->app->bind(AuthorityBindingRepository::class, EloquentAuthorityBindingRepository::class);
        $this->app->bind(AccessSubjectStateDirectory::class, EloquentAccessSubjectStateDirectory::class);
        $this->app->bind(ElevationGrantDirectory::class, EloquentElevationGrantDirectory::class);
        $this->app->bind(SensitiveAccessAuditor::class, PlatformSensitiveAccessAuditor::class);
        $this->app->bind(Authorizer::class, CapabilityAuthorizer::class);
        $this->app->bindIf(UserAudienceDirectory::class, EloquentUserAudienceDirectory::class);

        if ($this->app->environment('testing')) {
            $this->app->singleton(PersonEmploymentDirectory::class, FakePersonEmploymentDirectory::class);
            $this->app->singleton(OrganizationScopeDirectory::class, FakeOrganizationScopeDirectory::class);
            $this->app->singleton(CaseMembershipDirectory::class, FakeCaseMembershipDirectory::class);
            $this->app->singleton(PayrollRunMembershipDirectory::class, FakePayrollRunMembershipDirectory::class);
        }
    }
}
