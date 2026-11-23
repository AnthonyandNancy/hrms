<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\LoginAttempt;
use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Tests\Support\AuthenticatesRequests;

final class LoginSecurityTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    public function test_login_sets_hardened_cookie_and_returns_csrf_once(): void
    {
        $user = User::factory()->create();
        $response = $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => 'Correct-Horse-9!',
        ]);

        $response->assertOk()->assertJsonStructure([
            'data' => ['user', 'csrf_token', 'session' => ['id', 'mfa_verified', 'idle_expires_at', 'absolute_expires_at']],
        ]);

        $cookie = $response->getCookie((string) config('iam.session.cookie'), false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame((bool) config('iam.session.secure'), $cookie->isSecure());
        $this->assertNotSame('', (string) $cookie->getValue());

        $session = Session::query()->firstOrFail();
        $this->assertSame(hash('sha256', (string) $cookie->getValue()), $session->getRawOriginal('token_hash'));
        $this->assertNotSame((string) $response->json('data.csrf_token'), $session->getRawOriginal('csrf_hash'));
        $this->assertStringNotContainsString((string) $cookie->getValue(), (string) $response->getContent());
    }

    public function test_bad_password_is_401_and_never_issues_session(): void
    {
        $user = User::factory()->create();

        $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => 'Wrong-Password-123!',
        ])->assertUnauthorized()->assertJsonPath('data.reason', 'BAD_CREDENTIALS');

        $this->assertSame(0, Session::query()->count());
        $this->assertDatabaseHas('auth_events', ['event_type' => 'login.failure', 'result' => 'FAILURE']);
    }

    public function test_login_is_rate_limited_after_five_attempts_per_minute(): void
    {
        $this->freeze();
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/login', ['username' => $user->username, 'password' => 'Wrong-Password-123!'])
                ->assertUnauthorized();
            $this->advanceMinutes(1);
        }

        $this->postJson('/v1/auth/login', ['username' => $user->username, 'password' => 'Correct-Horse-9!'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMITED');
    }

    public function test_account_locks_after_ten_failures_in_window(): void
    {
        $this->freeze();
        $user = User::factory()->create();

        // Ten failures spread across the 15-minute lock window. Advancing the
        // clock between attempts also clears the 5/min transient limiter.
        for ($i = 0; $i < 10; $i++) {
            // Vary the source IP so Laravel's per-IP transient throttle does
            // not mask the persistent per-username lockout under test.
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.($i + 1)])
                ->postJson('/v1/auth/login', ['username' => $user->username, 'password' => 'Wrong-Password-123!'])
                ->assertUnauthorized();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.250'])
            ->postJson('/v1/auth/login', ['username' => $user->username, 'password' => 'Correct-Horse-9!'])
            ->assertUnauthorized()
            ->assertJsonPath('data.reason', 'ACCOUNT_LOCKED');

        $this->assertSame(0, Session::query()->count());
    }

    public function test_successful_login_records_last_login_and_audit_event(): void
    {
        $this->freeze();
        $user = User::factory()->create();

        $this->loginAs($user);

        $this->assertSame('2026-11-23 09:00:00', $user->fresh()?->last_login_at?->utc()->toDateTimeString());
        $this->assertDatabaseHas('auth_events', ['event_type' => 'login.succeeded', 'result' => 'SUCCESS']);
        $this->assertSame(1, LoginAttempt::query()->where('succeeded', true)->count());
    }

    private function freeze(): void
    {
        $this->clock = new FrozenClock(CarbonImmutable::parse('2026-11-23T09:00:00Z', 'UTC'));
        $this->app->instance(Clock::class, $this->clock);
    }

    private function advanceMinutes(int $minutes): void
    {
        $this->clock->advanceSeconds($minutes * 60);
    }
}
