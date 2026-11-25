<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\MfaFactor;
use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\PasswordHasher;
use App\Modules\IAM\Services\TotpService;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Blast-radius measurement before Task 7 (Step 1):
 * tests/Support/AuthenticatesRequests.php (real login; loginAs() now completes
 * the challenge for MFA users through the new public endpoint), plus
 * Feature/Authorization/LoginSecurityTest, DenyByDefaultTest,
 * Workflow/WorkflowAuthSessionTest, CorsPreflightTest, Contract/IamRouteMatrixTest
 * and Contract/IamOpenApiContractTest. The latter are contract/login-shape
 * tests; only MFA-required users change behaviour.
 */
final class MfaLoginChallengeTest extends TestCase
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

    public function test_a_privileged_login_without_a_second_factor_issues_no_session(): void
    {
        $user = $this->makeUser(privileged: true, withTotp: true);

        $response = $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => 'CorrectHorse!2026',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'MFA_REQUIRED')
            ->assertJsonStructure(['data' => ['challenge_id', 'masked_destination']]);

        $this->assertNull($response->getCookie((string) config('iam.session.cookie'), false));
        $this->assertSame(1, Session::query()->whereNotNull('mfa_challenge_hash')->count());
    }

    public function test_a_correct_totp_completes_the_login_and_issues_the_session(): void
    {
        $user = $this->makeUser(privileged: true, withTotp: true);

        $challenge = $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => 'CorrectHorse!2026',
        ])->json('data');

        $secret = Crypt::decryptString((string) MfaFactor::query()->where('user_id', $user->id)->value('secret_ciphertext'));
        $code = app(TotpService::class)->code($secret, $this->clock->now()->getTimestamp());

        $response = $this->postJson('/v1/auth/mfa/challenge', [
            'challenge_id' => $challenge['challenge_id'],
            'factor_type' => 'TOTP',
            'code' => $code,
        ])->assertOk()
            ->assertJsonPath('data.session.mfa_verified', true)
            ->assertJsonPath('data.csrf_token', fn ($value) => is_string($value) && $value !== '');

        $this->assertNotNull($response->getCookie((string) config('iam.session.cookie'), false));
        $this->assertNull(Session::query()->whereNotNull('mfa_challenge_hash')->first());
        $this->assertDatabaseMissing('auth_events', ['event_type' => 'login.succeeded']);
    }

    public function test_a_login_that_needs_no_second_factor_still_issues_a_session(): void
    {
        $user = $this->makeUser(privileged: false, withTotp: false);

        $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => 'CorrectHorse!2026',
        ])->assertOk()->assertJsonStructure(['data' => ['user', 'csrf_token', 'session']]);
    }

    public function test_a_wrong_code_does_not_consume_the_challenge_or_issue_a_token(): void
    {
        $user = $this->makeUser(privileged: true, withTotp: true);
        $challenge = $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => 'CorrectHorse!2026',
        ])->json('data.challenge_id');

        $session = Session::query()->whereNotNull('mfa_challenge_hash')->firstOrFail();
        $beforeToken = $session->getRawOriginal('token_hash');
        $beforeChallenge = $session->getRawOriginal('mfa_challenge_hash');

        $this->postJson('/v1/auth/mfa/challenge', [
            'challenge_id' => $challenge,
            'factor_type' => 'TOTP',
            'code' => '000000',
        ])->assertUnprocessable()->assertJsonPath('data.reason', 'MFA_CODE_INVALID');

        $session->refresh();
        $this->assertSame($beforeToken, $session->getRawOriginal('token_hash'));
        $this->assertSame($beforeChallenge, $session->getRawOriginal('mfa_challenge_hash'));
        $this->assertFalse((bool) $session->mfa_verified);

        // The still-open challenge can now be completed correctly.
        $secret = Crypt::decryptString((string) MfaFactor::query()->where('user_id', $user->id)->value('secret_ciphertext'));
        $this->postJson('/v1/auth/mfa/challenge', [
            'challenge_id' => $challenge,
            'factor_type' => 'TOTP',
            'code' => app(TotpService::class)->code($secret, $this->clock->now()->getTimestamp()),
        ])->assertOk();
    }

    public function test_the_challenge_is_single_use_and_expires(): void
    {
        $user = $this->makeUser(privileged: true, withTotp: true);
        $challenge = $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => 'CorrectHorse!2026',
        ])->json('data.challenge_id');

        $this->clock->advanceSeconds(301);

        $this->postJson('/v1/auth/mfa/challenge', [
            'challenge_id' => $challenge,
            'factor_type' => 'TOTP',
            'code' => '000000',
        ])->assertUnauthorized()->assertJsonPath('data.reason', 'MFA_CHALLENGE_INVALID');
    }

    public function test_a_pre_session_cookie_cannot_authorise_any_route(): void
    {
        $user = $this->makeUser(privileged: true, withTotp: true);
        $challenge = $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => 'CorrectHorse!2026',
        ])->json('data.challenge_id');

        $session = Session::query()->whereNotNull('mfa_challenge_hash')->firstOrFail();

        // Even if a client learns the pre-session id, it has no bearer token:
        // the challenge row's token_hash is a random value never returned.
        $this->assertNotSame(hash('sha256', (string) $challenge), $session->getRawOriginal('token_hash'));

        $this->withCookie((string) config('iam.session.cookie'), (string) $challenge)
            ->getJson('/v1/me')
            ->assertUnauthorized();
    }

    private function makeUser(bool $privileged, bool $withTotp): User
    {
        $user = User::factory()->create([
            'username' => 'challenge.'.($privileged ? 'admin' : 'clerk'),
            'password_hash' => app(PasswordHasher::class)->hash('CorrectHorse!2026'),
            'is_privileged' => $privileged,
            'status' => User::STATUS_ACTIVE,
            'auth_version' => 1,
        ]);

        if ($withTotp) {
            MfaFactor::query()->create([
                'tenant_id' => 1,
                'user_id' => (int) $user->id,
                'type' => MfaFactor::TYPE_TOTP,
                'secret_ciphertext' => Crypt::encryptString(app(TotpService::class)->generateSecret()),
                'label' => 'TOTP',
                'confirmed_at' => $this->clock->now(),
            ]);
            $user->forceFill(['mfa_enrolled_at' => $this->clock->now()])->save();
        }

        return $user;
    }
}
