<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\MfaFactor;
use App\Modules\IAM\Models\RecoveryCode;
use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\TotpService;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\Crypt;
use Tests\Support\AuthenticatesRequests;

/**
 * Volume 4 Task 2: account security against the real auth.session routes.
 *
 * ADR-0038 requires password login as the only mandatory path. These tests
 * therefore run the MFA-disabled posture as the default and separately prove
 * that disabling MFA never turns a missing capability into a grant.
 */
final class AccountSecurityTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    public function test_password_change_revokes_every_session_and_returns_reauth_contract(): void
    {
        config(['iam.mfa.enabled' => false]);

        $user = $this->accountUser();
        $first = $this->loginAs($user);
        $second = $this->loginAs($user);

        $this->withHeader('X-CSRF-Token', $second['csrf'])
            ->postJson('/v1/auth/password', [
                'current_password' => 'Correct-Horse-9!',
                'new_password' => 'New-Correct-Horse-10!',
                'new_password_confirmation' => 'New-Correct-Horse-10!',
            ])
            ->assertOk()
            ->assertJsonPath('data.password_changed', true)
            ->assertJsonPath('data.reauth_required', true)
            ->assertJsonPath('data.sessions_revoked', true);

        $this->assertSame(0, Session::query()->where('user_id', $user->id)->whereNull('revoked_at')->count());

        $this->withUnencryptedCookie((string) config('iam.session.cookie'), $first['cookie'])
            ->withHeader('X-CSRF-Token', $first['csrf'])
            ->getJson('/v1/me')
            ->assertUnauthorized();
    }

    public function test_wrong_current_password_is_422_with_a_mappable_field_error(): void
    {
        config(['iam.mfa.enabled' => false]);

        $user = $this->accountUser();
        $auth = $this->loginAs($user);

        $response = $this->withHeader('X-CSRF-Token', $auth['csrf'])
            ->postJson('/v1/auth/password', [
                'current_password' => 'Not-The-Current-Password!',
                'new_password' => 'New-Correct-Horse-10!',
                'new_password_confirmation' => 'New-Correct-Horse-10!',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('data.reason', 'BAD_CURRENT_PASSWORD');

        $this->assertArrayHasKey('current_password', (array) $response->json('data.fields'));
        $this->assertSame(1, Session::query()->where('user_id', $user->id)->whereNull('revoked_at')->count());

        $this->getJson('/v1/me')->assertOk();
    }

    public function test_session_list_marks_the_current_session_and_returns_readable_devices(): void
    {
        config(['iam.mfa.enabled' => false]);

        $user = $this->accountUser();
        $first = $this->loginAs($user);
        $second = $this->loginAs($user);

        $first['session']->forceFill([
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        ])->save();

        $response = $this->getJson('/v1/auth/sessions')->assertOk();
        $items = (array) $response->json('data.items');

        $this->assertCount(2, $items);
        $current = $this->rowById($items, (string) $second['session']->id);
        $other = $this->rowById($items, (string) $first['session']->id);

        $this->assertTrue($current['current']);
        $this->assertFalse($other['current']);
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', (string) $current['id']);
        $this->assertStringContainsString('Chrome', (string) $other['device']);
        $this->assertStringContainsString('Windows', (string) $other['device']);
        $this->assertArrayHasKey('last_seen_at', $other);
        $this->assertArrayHasKey('ip', $other);
    }

    public function test_revoking_another_session_removes_only_that_session(): void
    {
        config(['iam.mfa.enabled' => false]);

        $user = $this->accountUser();
        $first = $this->loginAs($user);
        $second = $this->loginAs($user);

        $this->withUnencryptedCookie((string) config('iam.session.cookie'), $first['cookie'])
            ->withHeader('X-CSRF-Token', $first['csrf'])
            ->deleteJson('/v1/auth/sessions/'.(string) $second['session']->id)
            ->assertOk()
            ->assertJsonPath('data.revoked', true)
            ->assertJsonPath('data.current_session', false);

        $this->assertSame(1, Session::query()->where('user_id', $user->id)->whereNull('revoked_at')->count());

        $this->withUnencryptedCookie((string) config('iam.session.cookie'), $second['cookie'])
            ->withHeader('X-CSRF-Token', $second['csrf'])
            ->getJson('/v1/me')
            ->assertUnauthorized();
    }

    public function test_revoking_the_current_session_logs_the_caller_out(): void
    {
        config(['iam.mfa.enabled' => false]);

        $user = $this->accountUser();
        $auth = $this->loginAs($user);

        $this->deleteJson('/v1/auth/sessions/'.(string) $auth['session']->id)
            ->assertOk()
            ->assertJsonPath('data.revoked', true)
            ->assertJsonPath('data.current_session', true);

        $this->getJson('/v1/me')->assertUnauthorized();
    }

    public function test_another_users_session_is_a_404_and_stays_live(): void
    {
        config(['iam.mfa.enabled' => false]);

        $owner = $this->accountUser();
        $other = $this->accountUser();
        $ownerAuth = $this->loginAs($owner);
        $otherAuth = $this->loginAs($other);

        $this->withUnencryptedCookie((string) config('iam.session.cookie'), $otherAuth['cookie'])
            ->withHeader('X-CSRF-Token', $otherAuth['csrf'])
            ->deleteJson('/v1/auth/sessions/'.(string) $ownerAuth['session']->id)
            ->assertNotFound();

        $this->assertNull($ownerAuth['session']->fresh()?->revoked_at);

        $this->withUnencryptedCookie((string) config('iam.session.cookie'), $ownerAuth['cookie'])
            ->withHeader('X-CSRF-Token', $ownerAuth['csrf'])
            ->getJson('/v1/me')
            ->assertOk();
    }

    public function test_mfa_disabled_status_comes_from_the_api_and_capability_checks_still_deny(): void
    {
        config(['iam.mfa.enabled' => false]);

        $user = $this->accountUser();
        $this->loginAs($user);

        $this->getJson('/v1/auth/mfa/status')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.enrolled', false);

        // /v1/users requires iam.user.read. Authentication (and the disabled
        // MFA middleware pass-through) must not grant that capability.
        $this->getJson('/v1/users')
            ->assertForbidden()
            ->assertJsonPath('data.reason', 'CAPABILITY_DENIED')
            ->assertJsonPath('data.capability', 'iam.user.read');
    }

    public function test_mfa_status_reports_enabled_and_enrolled_when_deployment_enables_mfa(): void
    {
        config(['iam.mfa.enabled' => true]);

        $user = $this->accountUser();
        $this->enableMfa($user);
        $this->loginAs($user);

        $this->getJson('/v1/auth/mfa/status')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.enrolled', true);
    }

    public function test_disable_requires_a_live_factor_then_revokes_sessions_and_clears_recovery_codes(): void
    {
        config(['iam.mfa.enabled' => true]);

        $user = $this->accountUser();
        $secret = app(TotpService::class)->generateSecret();
        MfaFactor::query()->create([
            'user_id' => (int) $user->id,
            'type' => MfaFactor::TYPE_TOTP,
            'secret_ciphertext' => Crypt::encryptString($secret),
            'label' => 'account-security-test',
            'confirmed_at' => app(Clock::class)->now(),
        ]);
        $user->forceFill(['mfa_enrolled_at' => app(Clock::class)->now()])->save();
        RecoveryCode::query()->create([
            'user_id' => (int) $user->id,
            'code_hash' => hash('sha256', 'SYNTHETIC-RECOVERY'),
            'created_at' => app(Clock::class)->now(),
        ]);

        $this->loginAs($user);

        $this->postJson('/v1/auth/mfa/totp/disable', [
            'factor_type' => 'TOTP',
            'code' => '000000',
        ])->assertUnprocessable()->assertJsonPath('data.reason', 'MFA_CODE_INVALID');

        $this->assertSame(1, MfaFactor::query()->where('user_id', $user->id)->whereNull('disabled_at')->count());

        $code = app(TotpService::class)->code($secret, app(Clock::class)->now()->getTimestamp());

        $this->postJson('/v1/auth/mfa/totp/disable', [
            'factor_type' => 'TOTP',
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonPath('data.disabled', true)
            ->assertJsonPath('data.reauth_required', true);

        $this->assertSame(0, MfaFactor::query()->where('user_id', $user->id)->whereNull('disabled_at')->count());
        $this->assertNull($user->fresh()?->mfa_enrolled_at);
        $this->assertSame(0, RecoveryCode::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, Session::query()->where('user_id', $user->id)->whereNull('revoked_at')->count());
    }

    private function accountUser(): User
    {
        return User::factory()->create([
            'username' => 'account.'.bin2hex(random_bytes(4)),
            'mfa_enrolled_at' => null,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function rowById(array $items, string $id): array
    {
        foreach ($items as $item) {
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }

        $this->fail("Session {$id} was not present in the session list.");
    }
}
