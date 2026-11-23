<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Tests\Support\AuthenticatesRequests;

final class SessionRevocationTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    public function test_role_change_increments_auth_version_and_next_request_is_401(): void
    {
        $user = $this->userWithMfaAndBinding('iam.session.manage');
        $auth = $this->loginAs($user);

        $user->forceFill(['auth_version' => (int) $user->auth_version + 1])->save();
        $this->withHeader('X-CSRF-Token', $auth['csrf']);

        $this->getJson('/v1/me')->assertUnauthorized()->assertJsonPath('data.reason', 'AUTH_VERSION_STALE');
    }

    public function test_binding_change_increments_auth_version_and_isolates_session(): void
    {
        $user = $this->userWithMfaAndBinding('iam.session.manage');
        $auth = $this->loginAs($user);

        AuthorityBinding::factory()
            ->forPrincipal(1, (int) $user->id)
            ->anchor(AnchorType::SELF, null, FieldProfile::P0_PUBLIC)
            ->create(['capability_code' => 'org.tree.read']);

        $user->forceFill(['auth_version' => (int) $user->auth_version + 1])->save();
        $this->withHeader('X-CSRF-Token', $auth['csrf']);

        $this->getJson('/v1/me')->assertUnauthorized();
    }

    public function test_logout_all_increments_auth_version_and_revokes_sessions(): void
    {
        $user = $this->userWithMfaAndBinding('iam.session.manage');
        $auth = $this->loginAs($user);

        $this->postJson('/v1/auth/logout-all')->assertOk();

        $this->withHeader('X-CSRF-Token', $auth['csrf']);
        $this->getJson('/v1/me')->assertUnauthorized();

        $this->assertSame((int) $user->fresh()?->auth_version, 2);
        $this->assertSame(0, Session::query()->whereNull('revoked_at')->count());
    }

    public function test_password_change_revokes_other_sessions_and_requires_reauth(): void
    {
        $user = $this->userWithMfaAndBinding('iam.session.manage');
        $first = $this->loginAs($user);
        $second = $this->loginAs($user);

        $this->withHeader('X-CSRF-Token', $second['csrf']);
        $this->postJson('/v1/auth/password', [
            'current_password' => 'Correct-Horse-9!',
            'new_password' => 'New-Correct-Horse-10!',
        ])->assertOk()->assertJsonPath('data.reauth_required', true);

        $this->assertSame(0, Session::query()->whereNull('revoked_at')->count());
        $this->getJson('/v1/me')->assertUnauthorized();
    }

    public function test_standard_idle_expiry_is_30_minutes(): void
    {
        $this->freeze('2026-11-23T09:00:00Z');
        $user = $this->userWithMfaAndBinding('iam.session.manage');
        $auth = $this->loginAs($user);
        $session = $auth['session'];

        $this->assertSame('2026-11-23 09:30:00', $session->idle_expires_at->utc()->toDateTimeString());
        $this->assertSame('2026-11-23 21:00:00', $session->absolute_expires_at->utc()->toDateTimeString());

        $this->advance(1801);

        $this->getJson('/v1/me')->assertUnauthorized()->assertJsonPath('data.reason', 'SESSION_EXPIRED');
    }

    public function test_privileged_timeout_is_15_minutes_and_8_hours(): void
    {
        $this->freeze('2026-11-23T09:00:00Z');
        $user = User::factory()->privileged()->create();
        $auth = $this->loginAs($user);

        $this->assertSame('2026-11-23 09:15:00', $auth['session']->idle_expires_at->utc()->toDateTimeString());
        $this->assertSame('2026-11-23 17:00:00', $auth['session']->absolute_expires_at->utc()->toDateTimeString());

        $this->advance(901);

        $this->getJson('/v1/me')->assertUnauthorized();
    }

    public function test_absolute_expiry_wins_even_after_activity(): void
    {
        $this->freeze('2026-11-23T09:00:00Z');
        $user = $this->userWithMfaAndBinding('iam.session.manage');
        $auth = $this->loginAs($user);

        // Sliding idle expiry would keep extending, but 12h absolute must win.
        $this->advance(43201);
        $this->withHeader('X-CSRF-Token', $auth['csrf']);

        $this->getJson('/v1/me')->assertUnauthorized()->assertJsonPath('data.reason', 'SESSION_EXPIRED');
    }

    public function test_offboarding_increments_auth_version_and_old_session_fails_immediately(): void
    {
        $user = $this->userWithMfaAndBinding('iam.session.manage');
        $auth = $this->loginAs($user);

        $user->forceFill(['status' => User::STATUS_DISABLED, 'auth_version' => (int) $user->auth_version + 1])->save();

        $this->withHeader('X-CSRF-Token', $auth['csrf']);
        $this->getJson('/v1/me')->assertUnauthorized();
    }

    private function userWithMfaAndBinding(string $capability): User
    {
        $user = User::factory()->create(['mfa_enrolled_at' => now()]);
        $this->people->employmentByUser[(int) $user->id] = [1];
        AuthorityBinding::factory()
            ->forPrincipal(1, (int) $user->id)
            ->anchor(AnchorType::SELF, null, FieldProfile::P0_PUBLIC)
            ->create(['capability_code' => $capability, 'valid_from' => '2025-01-01 00:00:00', 'valid_to' => '2030-01-01 00:00:00']);

        return $user;
    }

    private function freeze(string $time): void
    {
        $this->clock = new FrozenClock(CarbonImmutable::parse($time, 'UTC'));
        $this->app->instance(Clock::class, $this->clock);
    }

    private function advance(int $seconds): void
    {
        $this->clock->advanceSeconds($seconds);
    }
}
