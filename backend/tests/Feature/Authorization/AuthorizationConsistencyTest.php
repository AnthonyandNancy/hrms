<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Modules\IAM\Services\SessionManager;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\AuthorizationSnapshot;
use App\Shared\Authorization\Contracts\AuthorityBindingRepository;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthenticatesRequests;

/**
 * Every authority mutation must create exactly one new snapshot generation,
 * revoke every live session for the affected user in the same transaction and
 * leave no stale binding cache reachable. Reads are taken before and after
 * each mutation so the test proves observable invalidation, not row counts.
 */
final class AuthorizationConsistencyTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock(CarbonImmutable::parse('2026-11-23T09:00:00Z', 'UTC'));
        $this->app->instance(Clock::class, $this->clock);
        Cache::flush();
    }

    public function test_role_assignment_and_removal_bump_once_and_cache_keys_follow_auth_version(): void
    {
        $user = $this->userWithSessionCapability();
        $repository = app(AuthorityBindingRepository::class);
        $businessDate = '2026-11-23';

        $this->assertSame([], $repository->effectiveBindings((int) $user->id, 'people.employee.read', 1, $businessDate));
        $this->assertTrue(Cache::has($this->cacheKey($user, 1, ['people.employee.read', '*'], $businessDate)));

        $this->assignRoleWithBundle($user);

        $this->assertSame(2, (int) $user->fresh()->auth_version);
        $this->assertSame(1, $this->snapshotCount($user, 'role.assigned'));
        $this->assertSame([], $repository->effectiveBindings((int) $user->id, 'people.employee.read', 1, $businessDate));
        $this->assertCount(1, $repository->effectiveBindings((int) $user->id, 'people.employee.read', 2, $businessDate));
        $this->assertTrue(Cache::has($this->cacheKey($user, 2, ['people.employee.read', '*'], $businessDate)));

        $this->revokeRoleWithBundle($user);

        $this->assertSame(3, (int) $user->fresh()->auth_version);
        $this->assertSame(1, $this->snapshotCount($user, 'role.revoked'));
        // Version 2's cache entry is now unreachable: a request can only ask
        // for the user's current generation (3), so the stale entry cannot be
        // served even though the answer was cached before the bump.
        $this->assertSame([], $repository->effectiveBindings((int) $user->id, 'people.employee.read', 3, $businessDate));
        $this->assertTrue(Cache::has($this->cacheKey($user, 3, ['people.employee.read', '*'], $businessDate)));
        $this->assertNotSame(
            $this->cacheKey($user, 2, ['people.employee.read', '*'], $businessDate),
            $this->cacheKey($user, 3, ['people.employee.read', '*'], $businessDate),
        );
    }

    public function test_page_and_capability_grants_are_served_only_at_the_new_generation(): void
    {
        $user = $this->userWithSessionCapability();
        $this->createRole('CONSISTENCY_ROLE');
        $this->insertRole($user, 'CONSISTENCY_ROLE', (int) $user->auth_version);
        $this->insertBinding($user, 'people.employee.read', AnchorType::COMPANY, null, FieldProfile::P1_INTERNAL, (int) $user->auth_version, 'ROLE_CONSISTENCY_ROLE');
        $projection = app(\App\Modules\IAM\Authorization\PermissionProjection::class);

        $before = $projection->forUser((int) $user->id, (int) $user->auth_version);
        $this->assertNotContains('hr-employees', array_column($before['pages'], 'page_code'));

        DB::transaction(function () use ($user): void {
            RolePage::query()->create([
                'role_code' => 'CONSISTENCY_ROLE',
                'page_code' => 'hr-employees',
                'created_at' => $this->clock->now(),
            ]);
            $this->replay($user, 'role.pages.changed');
        });

        $this->assertSame(2, (int) $user->fresh()->auth_version);
        $afterPage = $projection->forUser((int) $user->id, 2);
        $this->assertContains('hr-employees', array_column($afterPage['pages'], 'page_code'));

        DB::transaction(function () use ($user): void {
            $this->insertBinding($user, 'people.employee.manage', AnchorType::COMPANY, null, FieldProfile::P1_INTERNAL, (int) $user->fresh()->auth_version, 'ROLE_CONSISTENCY_ROLE');
            $this->replay($user, 'role.capabilities.changed');
        });

        $this->assertSame(3, (int) $user->fresh()->auth_version);
        $afterCapability = $projection->forUser((int) $user->id, 3);
        $this->assertContains('people.employee.manage', $afterCapability['action_codes']);
    }

    public function test_scope_and_field_mutations_are_visible_only_after_one_replay_each(): void
    {
        $user = $this->userWithSessionCapability();
        $binding = $this->insertBinding($user, 'people.employee.read', AnchorType::ORG_UNIT, 7, FieldProfile::P1_INTERNAL, (int) $user->auth_version);
        $projection = app(\App\Modules\IAM\Authorization\PermissionProjection::class);

        [$versionOneRows] = $projection->effectiveBindings((int) $user->id, 1);
        $before = $this->bindingByCapability($versionOneRows, 'people.employee.read');
        $this->assertSame('ORG_UNIT', $before['anchor_type']);
        $this->assertSame('P1_INTERNAL', $before['field_profile']);

        DB::transaction(function () use ($binding, $user): void {
            $binding->forceFill([
                'anchor_type' => AnchorType::ORG_SUBTREE->value,
                'anchor_id' => 7,
                'anchor_key' => 7,
            ])->save();
            $this->replay($user, 'binding.scope.changed');
        });

        $this->assertSame(2, (int) $user->fresh()->auth_version);
        [$versionTwoRows] = $projection->effectiveBindings((int) $user->id, 2);
        $scopeChanged = $this->bindingSummary($this->bindingByCapability($versionTwoRows, 'people.employee.read'));
        $this->assertSame(['ORG_SUBTREE', 7, 'P1_INTERNAL'], $scopeChanged);

        // Mutate the CURRENT generation row, not the immutable history row:
        // the snapshot copies the latest generation forward on each replay.
        DB::transaction(function () use ($user): void {
            AuthorityBinding::query()
                ->where('principal_id', (int) $user->id)
                ->where('auth_version', 2)
                ->where('capability_code', 'people.employee.read')
                ->firstOrFail()
                ->forceFill([
                    'anchor_type' => AnchorType::COMPANY->value,
                    'anchor_id' => null,
                    'anchor_key' => 0,
                    'field_profile' => FieldProfile::P3_RESTRICTED->value,
                ])->save();
            $this->replay($user, 'binding.field.changed');
        });

        $this->assertSame(3, (int) $user->fresh()->auth_version);
        [$versionThreeRows] = $projection->effectiveBindings((int) $user->id, 3);
        $this->assertSame(['COMPANY', null, 'P3_RESTRICTED'], $this->bindingSummary($this->bindingByCapability($versionThreeRows, 'people.employee.read')));
    }

    public function test_replay_role_bumps_each_affected_user_once_even_with_multiple_live_role_rows(): void
    {
        $this->createRole('CONSISTENCY_ROLE');
        $first = $this->userWithSessionCapability();
        $second = $this->userWithSessionCapability();

        foreach ([$first, $second] as $user) {
            $this->insertRole($user, 'CONSISTENCY_ROLE', (int) $user->auth_version);
            $this->insertRole($user, 'CONSISTENCY_ROLE', (int) $user->auth_version, '2025-06-01 00:00:00', '2025-12-31 00:00:00');
        }

        app(AuthorizationSnapshot::class)->replayRole('CONSISTENCY_ROLE', 'role.capabilities.changed');

        $this->assertSame(2, (int) $first->fresh()->auth_version);
        $this->assertSame(2, (int) $second->fresh()->auth_version);
        $this->assertSame(1, $this->snapshotCount($first, 'role.capabilities.changed'));
        $this->assertSame(1, $this->snapshotCount($second, 'role.capabilities.changed'));
    }

    public function test_disabling_a_user_revokes_the_live_cookie_and_next_request_is_401(): void
    {
        $user = $this->userWithSessionCapability();
        $auth = $this->loginAs($user);
        $this->getJson('/v1/me')->assertOk();

        DB::transaction(function () use ($user): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail()
                ->forceFill(['status' => User::STATUS_DISABLED])->save();
            app(SessionManager::class)->invalidateForAuthorityChange((int) $user->id, 'user.disabled');
        });

        $this->assertSame(2, (int) $user->fresh()->auth_version);
        $this->assertNotNull($auth['session']->fresh()?->revoked_at);
        $this->assertSame(0, Session::query()->where('user_id', $user->id)->whereNull('revoked_at')->count());
        $this->assertSame(1, $this->snapshotCount($user, 'user.disabled'));

        // Real cookie request through AuthenticateSession/PrincipalResolver.
        $this->getJson('/v1/me')->assertUnauthorized();
    }

    public function test_an_authority_change_revokes_the_live_cookie_and_old_generation_bindings(): void
    {
        $user = $this->userWithSessionCapability();
        $this->createRole('CONSISTENCY_ROLE');
        DB::transaction(function () use ($user): void {
            $this->insertRole($user, 'CONSISTENCY_ROLE', (int) $user->auth_version);
            $this->insertBinding($user, 'people.employee.read', AnchorType::COMPANY, null, FieldProfile::P1_INTERNAL, (int) $user->auth_version, 'ROLE_CONSISTENCY_ROLE');
        });
        $this->replay($user, 'role.assigned');

        $auth = $this->loginAs($user);
        $this->getJson('/v1/me')->assertOk();
        $repository = app(AuthorityBindingRepository::class);
        $this->assertCount(1, $repository->effectiveBindings((int) $user->id, 'people.employee.read', 2, '2026-11-23'));

        DB::transaction(function () use ($user): void {
            UserRole::query()->where('user_id', $user->id)->where('auth_version', 2)->delete();
            AuthorityBinding::query()->where('principal_id', $user->id)->where('auth_version', 2)
                ->where('purpose_code', 'ROLE_CONSISTENCY_ROLE')->delete();
            app(SessionManager::class)->invalidateForAuthorityChange((int) $user->id, 'role.revoked');
        });

        $this->assertSame(3, (int) $user->fresh()->auth_version);
        $this->assertNotNull($auth['session']->fresh()?->revoked_at);
        $this->assertSame([], $repository->effectiveBindings((int) $user->id, 'people.employee.read', 3, '2026-11-23'));

        // A real cookie request can no longer use the revoked authority.
        $this->getJson('/v1/me')->assertUnauthorized();
    }

    private function userWithSessionCapability(): User
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->people->employmentByUser[(int) $user->id] = [1];
        $this->insertBinding($user, 'iam.session.manage', AnchorType::SELF, null, FieldProfile::P0_PUBLIC, 1, 'MANUAL_SESSION');

        return $user;
    }

    private function createRole(string $code): void
    {
        Role::query()->create([
            'code' => $code,
            'name' => 'Synthetic consistency role',
            'is_template' => false,
            'owner' => 1,
            'purpose' => 'Synthetic consistency fixture.',
            'expires_at' => '2030-01-01 00:00:00',
        ]);
    }

    private function insertRole(User $user, string $roleCode, int $authVersion, string $from = '2025-01-01 00:00:00', string $to = '2030-01-01 00:00:00'): UserRole
    {
        return UserRole::query()->create([
            'user_id' => (int) $user->id,
            'role_code' => $roleCode,
            'valid_from' => $from,
            'valid_to' => $to,
            'granted_by' => 1,
            'reason' => 'Synthetic consistency assignment',
            'auth_version' => $authVersion,
            'created_at' => $this->clock->now(),
        ]);
    }

    private function insertBinding(
        User $user,
        string $capability,
        AnchorType $anchor,
        ?int $anchorId,
        FieldProfile $profile,
        int $authVersion,
        string $purpose = 'MANUAL_TEST',
    ): AuthorityBinding {
        return AuthorityBinding::query()->create([
            'principal_id' => (int) $user->id,
            'capability_code' => $capability,
            'anchor_type' => $anchor->value,
            'anchor_id' => $anchorId,
            'anchor_key' => $anchorId ?? 0,
            'field_profile' => $profile->value,
            'purpose_code' => $purpose,
            'valid_from' => '2025-01-01 00:00:00',
            'valid_to' => '2030-01-01 00:00:00',
            'source_type' => 'TEST',
            'source_id' => null,
            'auth_version' => $authVersion,
            'created_at' => $this->clock->now(),
        ]);
    }

    private function assignRoleWithBundle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $current = (int) $user->fresh()->auth_version;
            $this->insertRole($user, 'CONSISTENCY_ROLE', $current);
            $this->insertBinding($user, 'people.employee.read', AnchorType::COMPANY, null, FieldProfile::P1_INTERNAL, $current, 'ROLE_CONSISTENCY_ROLE');
            $this->replay($user, 'role.assigned');
        });
    }

    private function revokeRoleWithBundle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $current = (int) $user->fresh()->auth_version;
            UserRole::query()
                ->where('user_id', $user->id)
                ->where('role_code', 'CONSISTENCY_ROLE')
                ->where('auth_version', $current)
                ->delete();
            AuthorityBinding::query()
                ->where('principal_id', $user->id)
                ->where('auth_version', $current)
                ->where('purpose_code', 'ROLE_CONSISTENCY_ROLE')
                ->delete();
            $this->replay($user, 'role.revoked');
        });
    }

    private function replay(User $user, string $reason): int
    {
        return app(AuthorizationSnapshot::class)->replay($user, $reason);
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function cacheKey(User $user, int $authVersion, array $capabilities, string $businessDate): string
    {
        return sprintf(
            'iam:bindings:%d:%d:%d:%s',
            (int) $user->id,
            $authVersion,
            crc32(implode('|', $capabilities)),
            $businessDate,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function bindingByCapability(array $rows, string $capability): array
    {
        foreach ($rows as $row) {
            if ((string) $row['capability'] === $capability) {
                return $row;
            }
        }

        $this->fail("Binding {$capability} was not found.");
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: string, 1: int|null, 2: string}
     */
    private function bindingSummary(array $row): array
    {
        return [(string) $row['anchor_type'], $row['anchor_id'] === null ? null : (int) $row['anchor_id'], (string) $row['field_profile']];
    }

    private function snapshotCount(User $user, string $reason): int
    {
        return (int) DB::table('audit_events')
            ->where('action', 'authorization.snapshot.replayed')
            ->where('aggregate_id', (string) $user->id)
            ->where('metadata_json', 'like', '%"reason":"'.$reason.'"%')
            ->count();
    }
}
