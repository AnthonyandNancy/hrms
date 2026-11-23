<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Auth\AuthenticatedPrincipal;
use App\Modules\IAM\Contracts\Principal;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\Contracts\AuthorityBindingRepository;
use App\Shared\Authorization\Contracts\CaseMembershipDirectory;
use App\Shared\Authorization\Contracts\OrganizationScopeDirectory;
use App\Shared\Authorization\Contracts\PayrollRunMembershipDirectory;
use App\Shared\Authorization\Contracts\PersonEmploymentDirectory;
use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeCaseMembershipDirectory;
use Tests\Fakes\FakeOrganizationScopeDirectory;
use Tests\Fakes\FakePayrollRunMembershipDirectory;
use Tests\Fakes\FakePersonEmploymentDirectory;
use Tests\Fakes\SpySensitiveAccessAuditor;
use Tests\TestCase;

/**
 * Shared fixture wiring for authorization feature tests. Uses real bindings
 * from SQLite plus explicit in-memory cross-module ports.
 */
abstract class AuthorizationTestSupport extends TestCase
{
    use RefreshDatabase;

    protected FakePersonEmploymentDirectory $people;
    protected FakeOrganizationScopeDirectory $organization;
    protected FakeCaseMembershipDirectory $cases;
    protected FakePayrollRunMembershipDirectory $payroll;
    protected SpySensitiveAccessAuditor $sensitiveAuditor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->people = new FakePersonEmploymentDirectory();
        $this->organization = new FakeOrganizationScopeDirectory();
        $this->cases = new FakeCaseMembershipDirectory();
        $this->payroll = new FakePayrollRunMembershipDirectory();
        $this->sensitiveAuditor = new SpySensitiveAccessAuditor();

        $this->app->instance(PersonEmploymentDirectory::class, $this->people);
        $this->app->instance(OrganizationScopeDirectory::class, $this->organization);
        $this->app->instance(CaseMembershipDirectory::class, $this->cases);
        $this->app->instance(PayrollRunMembershipDirectory::class, $this->payroll);
        $this->app->instance(SensitiveAccessAuditor::class, $this->sensitiveAuditor);
    }

    protected function actingAsUser(User $user, ?int $employmentId = null, bool $mfa = false): Principal
    {
        $principal = new AuthenticatedPrincipal(
            userId: (int) $user->id,
            tenantId: (int) $user->tenant_id,
            employmentId: $employmentId,
            authVersion: (int) $user->auth_version,
            privileged: (bool) $user->is_privileged,
            mfa: $mfa,
        );

        $this->app->make(AuthenticationContext::class)->set($principal);

        return $principal;
    }
}
