<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\MfaFactor;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\PasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner decision 2026-11-25: password-only sign-in is the accepted mode.
 * The MFA machinery stays behind IAM_MFA_ENABLED for later re-enablement.
 */
final class PasswordOnlyLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_privileged_user_with_a_totp_factor_can_still_sign_in_with_only_a_password(): void
    {
        config(['iam.mfa.enabled' => false]);

        $user = User::factory()->create([
            'username' => 'password.only.admin',
            'password_hash' => app(PasswordHasher::class)->hash('CorrectHorse!2026'),
            'is_privileged' => true,
            'status' => User::STATUS_ACTIVE,
            'auth_version' => 1,
        ]);

        // Even with a real confirmed factor, MFA is not challenged while the
        // switch is off.
        MfaFactor::query()->create([
            'tenant_id' => 1,
            'user_id' => (int) $user->id,
            'type' => MfaFactor::TYPE_TOTP,
            'secret_ciphertext' => 'x',
            'label' => 'TOTP',
            'confirmed_at' => now(),
        ]);
        $user->forceFill(['mfa_enrolled_at' => now()])->save();

        $response = $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => 'CorrectHorse!2026',
        ]);

        $response->assertOk()->assertJsonStructure(['data' => ['user', 'csrf_token', 'session']]);
        $this->assertNotNull($response->getCookie((string) config('iam.session.cookie'), false));
    }

    public function test_me_reports_mfa_not_required_when_the_switch_is_off(): void
    {
        config(['iam.mfa.enabled' => false]);

        $user = User::factory()->create([
            'password_hash' => app(PasswordHasher::class)->hash('CorrectHorse!2026'),
            'is_privileged' => true,
            'auth_version' => 1,
        ]);

        $login = $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => 'CorrectHorse!2026',
        ])->assertOk();

        $this->withCredentials()->withUnencryptedCookie(
            (string) config('iam.session.cookie'),
            (string) $login->getCookie((string) config('iam.session.cookie'), false)?->getValue(),
        );

        $this->getJson('/v1/me/capabilities')->assertOk()->assertJsonPath('data.mfa_required', false);
    }
}
