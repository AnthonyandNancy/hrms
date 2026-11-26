<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Tests\Support\AuthenticatesRequests;

/**
 * I2: auth_version is the generation of the *authorization snapshot*, not a
 * session-revocation counter. Changing a password must not silently erase
 * every effective binding, while an authorization change must copy every
 * currently effective binding and role forward into the new generation.
 */
final class AuthorizationSnapshotReplayTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    public function test_password_change_does_not_revoke_existing_capability_bindings(): void
    {
        $this->freeze();
        $user = $this->userWithSessionCapability();
        $auth = $this->loginAs($user);

        $this->withHeader('X-CSRF-Token', $auth['csrf'])
            ->postJson('/v1/auth/password', [
                'current_password' => 'Correct-Horse-9!',
                'new_password' => 'New-Correct-Horse-10!',
            ])
            ->assertOk();

        $this->assertSame(1, (int) $user->fresh()->auth_version);
        $this->assertSame(1, AuthorityBinding::query()->where('principal_id', $user->id)->count());

        $second = $this->loginAs($user, 'New-Correct-Horse-10!');

        $this->withHeader('X-CSRF-Token', $second['csrf'])
            ->getJson('/v1/me/capabilities')
            ->assertOk()
            ->assertJsonFragment(['capability' => 'iam.session.manage']);
    }

    public function test_logout_all_does_not_change_the_authorization_generation(): void
    {
        $this->freeze();
        $user = $this->userWithSessionCapability();
        $auth = $this->loginAs($user);

        $this->withHeader('X-CSRF-Token', $auth['csrf'])
            ->postJson('/v1/auth/logout-all')
            ->assertOk();

        $this->assertSame(1, (int) $user->fresh()->auth_version);
    }

    public function test_replay_copies_every_effective_binding_and_role_forward(): void
    {
        $this->freeze();
        $user = User::factory()->create([
            'auth_version' => 1,
        ]);
        $binding = AuthorityBinding::factory()
            ->forPrincipal((int) $user->id, (int) $user->auth_version)
            ->anchor(AnchorType::COMPANY, null, FieldProfile::P1_INTERNAL)
            ->create([
                'capability_code' => 'people.employee.read',
                'valid_from' => '2026-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
                'purpose_code' => 'TEST',
            ]);
        UserRole::query()->create([
            'user_id' => (int) $user->id,
            'role_code' => 'HR_SPECIALIST',
            'valid_from' => CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'),
            'valid_to' => CarbonImmutable::parse('2030-01-01 00:00:00', 'UTC'),
            'granted_by' => null,
            'reason' => 'fixture',
            'auth_version' => 1,
            'created_at' => CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'),
        ]);

        $newVersion = app(\App\Shared\Authorization\AuthorizationSnapshot::class)
            ->replay($user->fresh(), 'test.replay');

        $this->assertSame(2, $newVersion);
        $this->assertSame(2, (int) $user->fresh()->auth_version);
        // History is immutable: the originating row keeps generation 1 and a
        // new generation-2 row carries the effective grant forward.
        $this->assertSame(1, (int) $binding->fresh()->auth_version);
        $this->assertDatabaseHas('authority_bindings', [
            'principal_id' => (int) $user->id,
            'capability_code' => 'people.employee.read',
            'auth_version' => 2,
        ]);
        $this->assertDatabaseHas('user_roles', [
            'user_id' => (int) $user->id,
            'role_code' => 'HR_SPECIALIST',
            'auth_version' => 2,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'authorization.snapshot.replayed',
            'aggregate_id' => (string) $user->id,
        ]);
    }

    public function test_replay_does_not_copy_expired_or_future_bindings(): void
    {
        $this->freeze();
        $user = User::factory()->create(['auth_version' => 1]);

        AuthorityBinding::factory()
            ->forPrincipal((int) $user->id, (int) $user->auth_version)
            ->anchor(AnchorType::COMPANY, null, FieldProfile::P1_INTERNAL)
            ->create([
                'capability_code' => 'people.employee.read',
                'valid_from' => '2020-01-01 00:00:00',
                'valid_to' => '2025-01-01 00:00:00',
            ]);
        AuthorityBinding::factory()
            ->forPrincipal((int) $user->id, (int) $user->auth_version)
            ->anchor(AnchorType::COMPANY, null, FieldProfile::P1_INTERNAL)
            ->create([
                'capability_code' => 'org.unit.read',
                'valid_from' => '2027-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
            ]);

        app(\App\Shared\Authorization\AuthorizationSnapshot::class)->replay($user->fresh(), 'test.replay');

        $this->assertDatabaseMissing('authority_bindings', [
            'principal_id' => (int) $user->id,
            'capability_code' => 'people.employee.read',
            'auth_version' => 2,
        ]);
        $this->assertDatabaseMissing('authority_bindings', [
            'principal_id' => (int) $user->id,
            'capability_code' => 'org.unit.read',
            'auth_version' => 2,
        ]);
    }

    private function freeze(): void
    {
        $this->clock = new FrozenClock(CarbonImmutable::parse('2026-11-23T09:00:00Z', 'UTC'));
        $this->app->instance(Clock::class, $this->clock);
    }

    private function userWithSessionCapability(): User
    {
        $user = User::factory()->create([
            'auth_version' => 1,
            'mfa_enrolled_at' => now(),
        ]);

        AuthorityBinding::factory()
            ->forPrincipal((int) $user->id, (int) $user->auth_version)
            ->anchor(AnchorType::SELF, null, FieldProfile::P0_PUBLIC)
            ->create([
                'capability_code' => 'iam.session.manage',
                'valid_from' => '2025-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
            ]);

        return $user;
    }
}
