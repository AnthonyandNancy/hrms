<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IAM\Models\MfaFactor;
use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\TotpService;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\Crypt;

/**
 * Authenticates test requests through the real login endpoint, preserving the
 * session cookie and CSRF token for subsequent JSON calls.
 */
trait AuthenticatesRequests
{
    /**
     * @return array{cookie: string, csrf: string, session: Session}
     */
    protected function loginAs(User $user, string $password = 'Correct-Horse-9!'): array
    {
        $response = $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => $password,
        ]);

        // A privileged or P2+ holder now receives 202 MFA_REQUIRED and no
        // cookie. Complete the challenge with a real TOTP factor so callers
        // still exercise the production login flow end to end.
        if ($response->status() === 202) {
            $factor = MfaFactor::query()
                ->where('user_id', $user->id)
                ->whereNotNull('confirmed_at')
                ->whereNull('disabled_at')
                ->latest('id')
                ->first();

            // Without a confirmed factor the challenge cannot be completed;
            // return the 202 to the caller so it can assert the fail-closed
            // login contract instead of silently bypassing MFA.
            if ($factor !== null) {
                $secret = Crypt::decryptString((string) $factor->secret_ciphertext);

                $response = $this->postJson('/v1/auth/mfa/challenge', [
                    'challenge_id' => (string) $response->json('data.challenge_id'),
                    'factor_type' => 'TOTP',
                    'code' => app(TotpService::class)->code($secret, app(Clock::class)->now()->getTimestamp()),
                ]);
            }
        }

        $response->assertOk();

        $cookie = $response->getCookie((string) config('iam.session.cookie'), false);

        if ($cookie === null) {
            $this->fail('Login did not issue a session cookie.');
        }

        $this->withCredentials()->withUnencryptedCookie(
            (string) config('iam.session.cookie'),
            (string) $cookie->getValue(),
        );

        $csrf = (string) $response->json('data.csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf);

        return [
            'cookie' => (string) $cookie->getValue(),
            'csrf' => $csrf,
            'session' => Session::query()->latest('id')->firstOrFail(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(string $csrf): array
    {
        return ['X-CSRF-Token' => $csrf];
    }

    /**
     * Create a confirmed TOTP factor for a test user. Call before loginAs().
     */
    protected function enableMfa(User $user): void
    {
        $now = app(Clock::class)->now();

        MfaFactor::query()->create([
            'tenant_id' => (int) $user->tenant_id,
            'user_id' => (int) $user->id,
            'type' => MfaFactor::TYPE_TOTP,
            'secret_ciphertext' => Crypt::encryptString(app(TotpService::class)->generateSecret()),
            'label' => 'test',
            'confirmed_at' => $now,
        ]);

        $user->forceFill(['mfa_enrolled_at' => $now])->save();
    }

    /**
     * Mark the current test session MFA-verified through the real step-up
     * endpoint so middleware ordering remains under test.
     */
    protected function stepUp(?Session $session = null): void
    {
        $session ??= Session::query()->latest('id')->firstOrFail();
        $user = User::query()->findOrFail($session->user_id);
        $factor = MfaFactor::query()
            ->where('user_id', $user->id)
            ->whereNotNull('confirmed_at')
            ->latest('id')
            ->first();

        if ($factor === null) {
            $this->fail('No confirmed MFA factor exists for the authenticated test user.');
        }

        $secret = Crypt::decryptString((string) $factor->secret_ciphertext);
        $code = app(TotpService::class)->code($secret, app(Clock::class)->now()->getTimestamp());

        $response = $this->postJson('/v1/auth/mfa/step-up', [
            'factor_type' => 'TOTP',
            'code' => $code,
        ]);

        $response->assertOk();
    }
}
