<?php

declare(strict_types=1);

namespace App\Modules\IAM\Providers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Auth\CapabilityAuthorizer;
use App\Modules\IAM\Auth\TimeActorBridge;
use App\Modules\IAM\Auth\WorkflowPrincipalBridge;
use App\Modules\IAM\Authorization\EloquentAccessSubjectStateDirectory;
use App\Modules\IAM\Authorization\EloquentAuthorityBindingRepository;
use App\Modules\IAM\Authorization\EloquentElevationGrantDirectory;
use App\Modules\IAM\Authorization\EloquentPrincipalAuthorityReader;
use App\Modules\IAM\Authorization\PlatformSensitiveAccessAuditor;
use App\Modules\IAM\Contracts\DemoUserDirectory;
use App\Modules\IAM\Contracts\PrincipalAuthorityReader;
use App\Modules\IAM\Contracts\SecurityAuditReader;
use App\Modules\IAM\Contracts\SessionRevoker;
use App\Modules\IAM\Contracts\UserAudienceDirectory;
use App\Modules\IAM\Contracts\UserDirectory;
use App\Modules\IAM\Services\EloquentDemoUserDirectory;
use App\Modules\IAM\Services\EloquentSecurityAuditReader;
use App\Modules\IAM\Services\EloquentUserAudienceDirectory;
use App\Modules\IAM\Services\EloquentUserDirectory;
use App\Modules\IAM\Services\EmploymentSessionRevoker;
use App\Modules\IAM\Services\IamAssigneeDirectory;
use App\Modules\Time\Contracts\TimeActorResolver;
use App\Modules\Workflow\Contracts\AssigneeDirectory;
use App\Modules\Workflow\Contracts\WorkflowPrincipalResolver;
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
        \App\Modules\IAM\Console\Commands\BindRoleCommand::class,
        \App\Modules\IAM\Console\Commands\InstallCommand::class,
        \App\Modules\IAM\Console\Commands\VerifyMfaEnrollmentCommand::class,
        \App\Modules\IAM\Console\Commands\VerifySuperadminCommand::class,
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
        $this->app->bind(PrincipalAuthorityReader::class, EloquentPrincipalAuthorityReader::class);
        $this->app->bind(SecurityAuditReader::class, EloquentSecurityAuditReader::class);
        $this->app->bind(WorkflowPrincipalResolver::class, WorkflowPrincipalBridge::class);
        $this->app->bind(TimeActorResolver::class, TimeActorBridge::class);
        $this->app->bind(SessionRevoker::class, EmploymentSessionRevoker::class);
        $this->app->bind(UserDirectory::class, EloquentUserDirectory::class);
        $this->app->bind(AssigneeDirectory::class, IamAssigneeDirectory::class);
        $this->app->bindIf(UserAudienceDirectory::class, EloquentUserAudienceDirectory::class);
        $this->app->bind(DemoUserDirectory::class, EloquentDemoUserDirectory::class);

        if ($this->app->environment('testing')) {
            $this->app->singleton(PersonEmploymentDirectory::class, FakePersonEmploymentDirectory::class);
            $this->app->singleton(OrganizationScopeDirectory::class, FakeOrganizationScopeDirectory::class);
            $this->app->singleton(CaseMembershipDirectory::class, FakeCaseMembershipDirectory::class);
            $this->app->singleton(PayrollRunMembershipDirectory::class, FakePayrollRunMembershipDirectory::class);
        } else {
            // Production adapters live in the module that owns the tables.
            // IAM names only the Shared contracts; each owner provider binds
            // the Eloquent implementation in the non-testing environment.
        }
    }
}
