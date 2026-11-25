<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Auth\AuthenticatedPrincipal;
use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Contracts\Principal;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\MfaFactor;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\TotpService;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Authorization\Contracts\CaseMembershipDirectory;
use App\Shared\Authorization\Contracts\OrganizationScopeDirectory;
use App\Shared\Authorization\Contracts\PayrollRunMembershipDirectory;
use App\Shared\Authorization\Contracts\PersonEmploymentDirectory;
use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\FrozenClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
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

    protected FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->people = new FakePersonEmploymentDirectory;
        $this->organization = new FakeOrganizationScopeDirectory;
        $this->cases = new FakeCaseMembershipDirectory;
        $this->payroll = new FakePayrollRunMembershipDirectory;
        $this->sensitiveAuditor = new SpySensitiveAccessAuditor;
        // Bindings are created relative to the injected clock. Freeze it to
        // the same business date the authorization tests query so the suite
        // does not fail based on the wall-clock day it runs.
        $this->clock = new FrozenClock('2026-11-23T09:00:00Z');
        $this->app->instance(\App\Shared\Time\Clock::class, $this->clock);

        $this->app->instance(PersonEmploymentDirectory::class, $this->people);
        $this->app->instance(OrganizationScopeDirectory::class, $this->organization);
        $this->app->instance(CaseMembershipDirectory::class, $this->cases);
        $this->app->instance(PayrollRunMembershipDirectory::class, $this->payroll);
        $this->app->instance(SensitiveAccessAuditor::class, $this->sensitiveAuditor);
        $this->app->forgetInstance(\App\Shared\Authorization\Authorizer::class);
        $this->app->forgetInstance(\App\Modules\IAM\Auth\CapabilityAuthorizer::class);
    }

    protected function actingAsUser(User $user, ?int $employmentId = null, bool $mfa = false, ?string $sessionId = null): Principal
    {
        $principal = new AuthenticatedPrincipal(
            userId: (int) $user->id,
            tenantId: (int) $user->tenant_id,
            employmentId: $employmentId,
            authVersion: (int) $user->auth_version,
            privileged: (bool) $user->is_privileged,
            mfa: $mfa,
            sessionId: $sessionId,
        );

        $this->app->make(AuthenticationContext::class)->set($principal);

        return $principal;
    }

    /**
     * A legal-entity super administrator with the code-owned wildcard binding
     * and a real confirmed TOTP factor, authenticated through the same
     * explicit-principal seam as every authorization test.
     */
    protected function systemAdmin(): User
    {
        $user = User::factory()->create([
            'tenant_id' => 1,
            'auth_version' => 1,
            'is_privileged' => true,
        ]);

        AuthorityBinding::factory()
            ->forPrincipal(1, (int) $user->id, (int) $user->auth_version)
            ->anchor(AnchorType::LEGAL_ENTITY, 1, FieldProfile::P3_RESTRICTED)
            ->create([
                'capability_code' => CapabilityWildcard::WILDCARD,
                'purpose_code' => 'SYSTEM_ADMIN',
                'valid_from' => '2025-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
            ]);

        $now = app(\App\Shared\Time\Clock::class)->now();
        MfaFactor::query()->create([
            'tenant_id' => 1,
            'user_id' => (int) $user->id,
            'type' => MfaFactor::TYPE_TOTP,
            'secret_ciphertext' => Crypt::encryptString(app(TotpService::class)->generateSecret()),
            'label' => 'test-system-admin',
            'confirmed_at' => $now,
        ]);
        $user->forceFill(['mfa_enrolled_at' => $now])->save();

        $this->organization->knownLegalEntities = [1];
        $this->people->employmentByUser[(int) $user->id] = [1];

        $principal = $this->actingAsUser($user, 1, true, 'test-session');

        $this->app->instance(Principal::class, $principal);
        $this->app->instance(AuthenticatedPrincipal::class, $principal);

        return $user;
    }
}
