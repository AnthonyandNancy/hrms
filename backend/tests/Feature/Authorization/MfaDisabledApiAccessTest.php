<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Authorization\FieldProfile;
use Tests\Support\AuthenticatesRequests;

/**
 * Disabled MFA middleware must be a pure pass-through. It may not turn a valid
 * capability into a grant, so the same suite proves both sides: authorized
 * roles reach their APIs and a user without the capability still gets 403.
 */
final class MfaDisabledApiAccessTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    public function test_admin_hr_and_payroll_capabilities_are_reachable_with_password_only(): void
    {
        config(['iam.mfa.enabled' => false]);

        $admin = User::factory()->create(['is_privileged' => true]);
        $this->grant($admin, CapabilityWildcard::WILDCARD, FieldProfile::P3_RESTRICTED);
        $this->loginAs($admin);
        $this->getJson('/v1/users')->assertOk();
        $this->getJson('/v1/me')->assertOk()->assertJsonPath('data.username', $admin->username);

        $hr = User::factory()->create();
        $this->grant($hr, 'people.employee.read', FieldProfile::P2_SENSITIVE);
        $this->loginAs($hr);
        $this->getJson('/v1/employees?as_of=2026-11-23')->assertOk();

        $payroll = User::factory()->create();
        $this->grant($payroll, 'payroll.run.read', FieldProfile::P2_SENSITIVE);
        $this->loginAs($payroll);
        $this->getJson('/v1/payroll/runs')->assertOk();
    }

    public function test_disabled_enrollment_middleware_does_not_bypass_the_capability_gate(): void
    {
        config(['iam.mfa.enabled' => false]);

        $user = User::factory()->create(['is_privileged' => false]);
        $this->loginAs($user);

        // This route is guarded by ['mfa.enrolled', 'capability:iam.user.read'].
        // MFA passes through, but the missing capability must still deny.
        $this->getJson('/v1/users')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN')
            ->assertJsonPath('data.reason', 'CAPABILITY_DENIED')
            ->assertJsonPath('data.capability', 'iam.user.read');
    }

    public function test_disabled_enrollment_and_step_up_middleware_do_not_bypass_a_write_capability(): void
    {
        config(['iam.mfa.enabled' => false]);

        $user = User::factory()->create(['is_privileged' => false]);
        $auth = $this->loginAs($user);

        // POST /v1/users is guarded by ['mfa.enrolled', 'mfa.step-up',
        // 'capability:iam.user.manage']. Both MFA gates must pass through and
        // the capability gate must still be the thing that returns 403.
        $this->withHeader('X-CSRF-Token', $auth['csrf'])
            ->postJson('/v1/users', [
                'username' => 'mfa.disabled.no.capability',
                'password' => 'Synthetic-Password-2026!',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN')
            ->assertJsonPath('data.reason', 'CAPABILITY_DENIED')
            ->assertJsonPath('data.capability', 'iam.user.manage');

        $this->assertDatabaseMissing('users', ['username' => 'mfa.disabled.no.capability']);
    }

    public function test_a_user_without_the_payroll_capability_is_still_denied(): void
    {
        config(['iam.mfa.enabled' => false]);

        $user = User::factory()->create(['is_privileged' => false]);
        $this->loginAs($user);

        $this->getJson('/v1/payroll/runs')
            ->assertForbidden()
            ->assertJsonPath('data.capability', 'payroll.run.read');
    }

    private function grant(User $user, string $capability, FieldProfile $profile): void
    {
        AuthorityBinding::factory()
            ->forPrincipal((int) $user->id, (int) $user->auth_version)
            ->anchor(AnchorType::COMPANY, null, $profile)
            ->create([
                'capability_code' => $capability,
                'valid_from' => '2025-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
            ]);
    }
}
