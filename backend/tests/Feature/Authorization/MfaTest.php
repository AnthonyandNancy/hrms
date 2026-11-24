<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\MfaFactor;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\TotpService;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Tests\Support\AuthenticatesRequests;

final class MfaTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    public function test_privileged_user_without_enrollment_cannot_finish_login_or_use_a_capability(): void
    {
        $user = User::factory()->withoutMfa()->create(['is_privileged' => true]);
        $this->grant($user, 'iam.user.read', FieldProfile::P1_INTERNAL);
        $this->organization->knownLegalEntities = [1];

        $login = $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => 'Correct-Horse-9!',
        ]);

        // Password alone is no longer a session for a privileged user: the
        // login stops at the MFA challenge and issues no cookie. Without a
        // confirmed factor the challenge cannot be completed, so no request
        // ever reaches the capability gate.
        $login->assertStatus(202)->assertJsonPath('data.status', 'MFA_REQUIRED');
        $this->assertNull($login->getCookie((string) config('iam.session.cookie'), false));

        $this->getJson('/v1/users', ['X-Legal-Entity-Id' => '1'])
            ->assertUnauthorized();
    }

    public function test_payroll_role_without_enrollment_stops_at_the_login_challenge(): void
    {
        $user = User::factory()->withoutMfa()->create();
        $this->grant($user, 'payroll.run.prepare', FieldProfile::P2_SENSITIVE, AnchorType::PAYROLL_RUN, 5);
        $this->grant($user, 'org.tree.read', FieldProfile::P0_PUBLIC, AnchorType::LEGAL_ENTITY, 1, false);
        $this->organization->knownLegalEntities = [1];
        $this->payroll->runsByUser[(int) $user->id] = [5];
        $this->payroll->memberships[5] = ['payroll.run.prepare'];

        $login = $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => 'Correct-Horse-9!',
        ]);

        $login->assertStatus(202)->assertJsonPath('data.status', 'MFA_REQUIRED');
        $this->assertNull($login->getCookie((string) config('iam.session.cookie'), false));
    }

    public function test_totp_enrollment_confirm_returns_otpauth_uri_and_single_use_recovery_codes(): void
    {
        $this->freeze('2026-11-23T09:00:00Z');
        $user = $this->selfSessionUser();
        $auth = $this->loginAs($user);

        $enroll = $this->postJson('/v1/auth/mfa/totp/enroll');
        $enroll->assertCreated()
            ->assertJsonPath('data.secret', fn ($secret): bool => is_string($secret) && strlen($secret) >= 16)
            ->assertJsonStructure(['data' => ['otpauth_uri', 'secret', 'factor_id']]);

        $secret = (string) $enroll->json('data.secret');
        $this->assertStringStartsWith('otpauth://totp/', (string) $enroll->json('data.otpauth_uri'));
        $this->assertStringNotContainsString($secret, json_encode(MfaFactor::query()->get()->toArray(), JSON_THROW_ON_ERROR));

        $code = app(TotpService::class)->code($secret, $this->clock->now()->getTimestamp());
        $confirm = $this->postJson('/v1/auth/mfa/totp/confirm', [
            'factor_id' => (int) $enroll->json('data.factor_id'),
            'code' => $code,
        ]);

        $confirm->assertOk()->assertJsonPath('data.confirmed', true);
        $codes = (array) $confirm->json('data.recovery_codes');
        $this->assertCount(10, $codes);

        $recovery = (string) $codes[0];

        // Fresh session after the enrollment-triggered auth_version bump.
        $auth = $this->loginAs($user);
        $this->postJson('/v1/auth/mfa/step-up', ['factor_type' => 'RECOVERY_CODE', 'code' => $recovery])->assertOk();
        $this->postJson('/v1/auth/mfa/step-up', ['factor_type' => 'RECOVERY_CODE', 'code' => $recovery])->assertUnprocessable();
    }

    public function test_totp_step_up_marks_mfa_verified_and_expires_after_15_minutes(): void
    {
        $this->freeze('2026-11-23T09:00:00Z');
        $user = $this->selfSessionUser();
        $authenticator = app(TotpService::class);
        $secret = $authenticator->generateSecret();
        $factor = MfaFactor::query()->create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'type' => MfaFactor::TYPE_TOTP,
            'secret_ciphertext' => Crypt::encryptString($secret),
            'label' => 'test',
            'confirmed_at' => $this->clock->now(),
        ]);
        $user->forceFill(['mfa_enrolled_at' => $this->clock->now()])->save();
        $auth = $this->loginAs($user);

        $this->postJson('/v1/auth/mfa/step-up', [
            'factor_type' => 'TOTP',
            'code' => $authenticator->code($secret, $this->clock->now()->getTimestamp()),
        ])->assertOk()->assertJsonPath('data.mfa_verified', true);

        $this->assertTrue((bool) $auth['session']->fresh()?->mfa_verified);

        $this->advance(901);
        $this->getJson('/v1/me')->assertOk()->assertJsonPath('data.mfa_verified', false);

        $factor->delete();
    }

    public function test_sms_and_email_are_rejected_as_second_factors(): void
    {
        $this->freeze('2026-11-23T09:00:00Z');
        $user = $this->selfSessionUser();
        $this->loginAs($user);

        foreach (['SMS', 'EMAIL'] as $type) {
            $response = $this->postJson('/v1/auth/mfa/step-up', ['factor_type' => strtolower($type), 'code' => '123456']);
            $response->assertUnprocessable();
        }
    }

    public function test_invalid_totp_is_rejected_and_audited(): void
    {
        $this->freeze('2026-11-23T09:00:00Z');
        $user = $this->selfSessionUser();
        $this->loginAs($user);

        $this->postJson('/v1/auth/mfa/totp/enroll')->assertCreated();
        $factor = MfaFactor::query()->firstOrFail();
        $response = $this->postJson('/v1/auth/mfa/totp/confirm', ['factor_id' => $factor->id, 'code' => '000000']);

        // 000000 may be a valid TOTP in ~1/1e6 cases; force the invalid path.
        if ($response->status() === 200) {
            $this->markTestSkipped('The random secret produced 000000; rerun.');
        }

        $response->assertUnprocessable()->assertJsonPath('data.reason', 'MFA_CODE_INVALID');
        $this->assertDatabaseHas('auth_events', ['event_type' => 'mfa.enroll.failure', 'result' => 'FAILURE']);
    }

    private function selfSessionUser(): User
    {
        $user = User::factory()->create();
        $this->people->employmentByUser[(int) $user->id] = [1];
        $this->grant($user, 'iam.session.manage', FieldProfile::P0_PUBLIC, AnchorType::SELF, null);

        return $user;
    }

    private function grant(User $user, string $capability, FieldProfile $profile, AnchorType $anchor = AnchorType::SELF, ?int $anchorId = null, bool $active = true): void
    {
        AuthorityBinding::factory()
            ->forPrincipal(1, (int) $user->id)
            ->anchor($anchor, $anchorId, $profile)
            ->create([
                'capability_code' => $capability,
                'valid_from' => $active ? '2025-01-01 00:00:00' : '2025-01-01 00:00:00',
                'valid_to' => $active ? '2030-01-01 00:00:00' : '2025-12-31 00:00:00',
            ]);
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
