<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\MfaFactor;
use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\PasswordHasher;
use App\Modules\IAM\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * ADR-0038: username + password is the only required login flow. A factor may
 * exist on the account, but IAM_MFA_ENABLED=false means it is never challenged.
 */
final class PasswordOnlyLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_privileged_user_with_a_real_totp_factor_logs_in_with_password_only(): void
    {
        config(['iam.mfa.enabled' => false]);

        $user = User::factory()->create([
            'username' => 'password.only.admin',
            'email' => 'password.only.admin@example.invalid',
            'password_hash' => app(PasswordHasher::class)->hash('CorrectHorse!2026'),
            'is_privileged' => true,
            'status' => User::STATUS_ACTIVE,
            'auth_version' => 1,
        ]);

        MfaFactor::query()->create([
            'user_id' => (int) $user->id,
            'type' => MfaFactor::TYPE_TOTP,
            'secret_ciphertext' => Crypt::encryptString(app(TotpService::class)->generateSecret()),
            'label' => 'TOTP',
            'confirmed_at' => now(),
        ]);
        $user->forceFill(['mfa_enrolled_at' => now()])->save();

        $response = $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => 'CorrectHorse!2026',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.username', $user->username)
            ->assertJsonMissingPath('data.status')
            ->assertJsonStructure(['data' => ['user', 'csrf_token', 'session' => ['id', 'mfa_verified']]]);

        $this->assertNotSame(202, $response->status());
        $this->assertNotSame(403, $response->status());
        $this->assertSame(1, Session::query()->where('user_id', (int) $user->id)->whereNull('revoked_at')->count());
        $this->assertSame(0, Session::query()->whereNotNull('mfa_challenge_hash')->count());
        $this->assertDatabaseHas('auth_events', ['event_type' => 'login.succeeded', 'result' => 'SUCCESS']);

        $cookie = $response->getCookie((string) config('iam.session.cookie'), false);
        $this->assertNotNull($cookie);

        $this->withCredentials()->withUnencryptedCookie(
            (string) config('iam.session.cookie'),
            (string) $cookie->getValue(),
        );

        $this->getJson('/v1/me')
            ->assertOk()
            ->assertJsonPath('data.username', $user->username)
            ->assertJsonPath('data.is_privileged', true);
    }

    public function test_the_default_switch_is_off_and_me_reports_no_mfa_requirement(): void
    {
        $this->assertFalse((bool) config('iam.mfa.enabled'));

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

        $this->getJson('/v1/me/capabilities')
            ->assertOk()
            ->assertJsonPath('data.mfa_required', false)
            ->assertJsonPath('data.mfa_verified', false);
    }

    public function test_login_does_not_require_or_interpret_mfa_and_captcha_fields(): void
    {
        config(['iam.mfa.enabled' => false]);

        $user = User::factory()->create([
            'password_hash' => app(PasswordHasher::class)->hash('CorrectHorse!2026'),
            'is_privileged' => true,
            'auth_version' => 1,
        ]);

        // The endpoint validates only username + password; extra fields must not
        // turn a normal password login into a challenge or an error.
        $response = $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => 'CorrectHorse!2026',
            'mfa_code' => '123456',
            'captcha' => 'not-required',
        ]);

        $response->assertOk()->assertJsonPath('data.user.username', $user->username);
    }
}
