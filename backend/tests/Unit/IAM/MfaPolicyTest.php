<?php

declare(strict_types=1);

namespace Tests\Unit\IAM;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\MfaPolicy;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MfaPolicyTest extends TestCase
{
    use RefreshDatabase;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock('2026-11-24T09:00:00Z');
        $this->app->instance(Clock::class, $this->clock);
        config(['iam.mfa.enabled' => true]);
    }

    public function test_a_privileged_user_requires_mfa(): void
    {
        $user = User::factory()->create(['is_privileged' => true, 'auth_version' => 1]);

        $this->assertTrue($this->policy()->challengeRequired($user));
        $this->assertSame(MfaPolicy::REASON_PRIVILEGED, $this->policy()->reason($user));
    }

    public function test_a_holder_of_a_p3_capability_requires_mfa(): void
    {
        $user = User::factory()->create(['is_privileged' => false, 'auth_version' => 1]);
        $this->grant($user, 'payroll.payslip.read', FieldProfile::P3_RESTRICTED);

        $this->assertTrue($this->policy()->challengeRequired($user));
        $this->assertSame(MfaPolicy::REASON_RESTRICTED_FIELDS, $this->policy()->reason($user));
    }

    public function test_a_holder_of_only_p1_capabilities_does_not_require_mfa(): void
    {
        $user = User::factory()->create(['is_privileged' => false, 'auth_version' => 1]);
        $this->grant($user, 'iam.user.read', FieldProfile::P1_INTERNAL);

        $this->assertFalse($this->policy()->challengeRequired($user));
        $this->assertNull($this->policy()->reason($user));
    }

    public function test_a_wildcard_holder_requires_mfa(): void
    {
        $user = User::factory()->create(['is_privileged' => false, 'auth_version' => 1]);
        $this->grant($user, CapabilityWildcard::WILDCARD, FieldProfile::P3_RESTRICTED);

        $this->assertTrue($this->policy()->challengeRequired($user));
        $this->assertSame(MfaPolicy::REASON_RESTRICTED_FIELDS, $this->policy()->reason($user));
    }

    public function test_a_binding_from_a_stale_auth_version_does_not_require_mfa(): void
    {
        $user = User::factory()->create(['is_privileged' => false, 'auth_version' => 2]);
        $this->grant($user, 'payroll.payslip.read', FieldProfile::P3_RESTRICTED, authVersion: 1);

        $this->assertFalse($this->policy()->challengeRequired($user));
    }

    public function test_an_expired_sensitive_binding_does_not_require_mfa(): void
    {
        $user = User::factory()->create(['is_privileged' => false, 'auth_version' => 1]);
        $this->grant($user, 'payroll.payslip.read', FieldProfile::P3_RESTRICTED, validTo: '2026-01-01 00:00:00');

        $this->assertFalse($this->policy()->challengeRequired($user));
    }

    private function policy(): MfaPolicy
    {
        return app(MfaPolicy::class);
    }

    private function grant(
        User $user,
        string $code,
        FieldProfile $profile,
        int $authVersion = 1,
        string $validTo = '2030-01-01 00:00:00',
    ): void {
        AuthorityBinding::factory()
            ->forPrincipal((int) $user->id, $authVersion)
            ->anchor(AnchorType::COMPANY, null, $profile)
            ->create([
                'capability_code' => $code,
                'valid_from' => '2025-01-01 00:00:00',
                'valid_to' => $validTo,
            ]);
    }
}
