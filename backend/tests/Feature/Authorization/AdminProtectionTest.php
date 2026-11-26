<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Auth\AuthenticatedPrincipal;
use App\Modules\IAM\Contracts\Principal;
use App\Modules\IAM\Http\Middleware\RequireMfaStepUp;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\RoleCapability;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Modules\IAM\Services\AdminProtectionGuard;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Authorization\EnsureCapability;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\AuthenticatesRequests;

/**
 * Last-admin protection and grant-ceiling enforcement for the single-company
 * instance. Admin identity is a live `iam.role.manage` grant held by an ENABLED
 * user; the flag `is_privileged` is never consulted.
 */
final class AdminProtectionTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock(CarbonImmutable::parse('2026-11-23T09:00:00Z', 'UTC'));
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_the_last_enabled_role_manager_cannot_be_disabled(): void
    {
        $admin = $this->admin();
        $guard = app(AdminProtectionGuard::class);

        $this->expectDomainFailure(
            static fn () => $guard->assertNotLastAdminDisable((int) $admin->id),
            'LAST_ENABLED_ADMIN_PROTECTED',
            422,
        );

        // A second enabled manager removes the veto.
        $this->admin();
        $guard->assertNotLastAdminDisable((int) $admin->id);
        $this->addToAssertionCount(1);
    }

    public function test_disabled_candidates_do_not_count_as_remaining_admins(): void
    {
        $admin = $this->admin();
        $disabledPeer = $this->admin();
        $guard = app(AdminProtectionGuard::class);

        // A second, DISABLED admin must not satisfy the invariant: disabling
        // the only remaining ENABLED manager would leave the system without
        // any administrator who can actually log in and recover it.
        User::query()->whereKey($disabledPeer->id)->update(['status' => User::STATUS_DISABLED]);

        $this->expectDomainFailure(
            static fn () => $guard->assertNotLastAdminDisable((int) $admin->id),
            'LAST_ENABLED_ADMIN_PROTECTED',
            422,
        );
    }

    public function test_disabling_an_already_disabled_non_admin_is_a_no_op(): void
    {
        $unbound = User::factory()->create(['status' => User::STATUS_DISABLED]);

        app(AdminProtectionGuard::class)->assertNotLastAdminDisable((int) $unbound->id);
        $this->addToAssertionCount(1);
    }

    public function test_the_last_admin_role_cannot_be_removed_or_self_demoted(): void
    {
        $admin = $this->admin();
        $role = UserRole::query()->where('user_id', $admin->id)->firstOrFail();
        $guard = app(AdminProtectionGuard::class);

        $this->expectDomainFailure(
            static fn () => $guard->assertNotLastAdminRoleRemoval((int) $admin->id, [(int) $role->id]),
            'LAST_ENABLED_ADMIN_PROTECTED',
            422,
        );

        // Self-demotion is the same invariant; the actor id is not a bypass.
        $this->expectDomainFailure(
            static fn () => $guard->assertNotLastAdminRoleRemoval((int) $admin->id, [(int) $role->id], (int) $admin->id),
            'LAST_ENABLED_ADMIN_PROTECTED',
            422,
        );

        $this->admin();
        $guard->assertNotLastAdminRoleRemoval((int) $admin->id, [(int) $role->id], (int) $admin->id);
        $this->addToAssertionCount(1);
    }

    public function test_removing_a_non_admin_role_is_never_blocked(): void
    {
        $admin = $this->admin();
        $nonAdminRow = UserRole::query()->create([
            'user_id' => (int) $admin->id,
            'role_code' => 'EMPLOYEE',
            'valid_from' => '2025-01-01 00:00:00',
            'valid_to' => '2030-01-01 00:00:00',
            'granted_by' => null,
            'reason' => 'Synthetic non-admin role',
            'auth_version' => 1,
            'created_at' => $this->clock->now(),
        ]);

        app(AdminProtectionGuard::class)->assertNotLastAdminRoleRemoval(
            (int) $admin->id,
            [(int) $nonAdminRow->id],
        );
        $this->addToAssertionCount(1);
    }

    public function test_a_role_manager_cannot_grant_a_capability_they_do_not_hold(): void
    {
        $admin = $this->admin();
        $guard = app(AdminProtectionGuard::class);

        $guard->assertNoPrivilegeEscalation(
            (int) $admin->id,
            'iam.role.manage',
            AnchorType::COMPANY->value,
            FieldProfile::P1_INTERNAL->value,
        );

        $this->expectDomainFailure(
            static fn () => $guard->assertNoPrivilegeEscalation(
                (int) $admin->id,
                'payroll.run.approve',
                AnchorType::COMPANY->value,
                FieldProfile::P2_SENSITIVE->value,
            ),
            'PRIVILEGE_ESCALATION_FORBIDDEN',
            403,
        );
    }

    public function test_a_grant_cannot_exceed_the_actors_field_profile_or_anchor(): void
    {
        $admin = $this->admin();
        $guard = app(AdminProtectionGuard::class);

        $this->expectDomainFailure(
            static fn () => $guard->assertNoPrivilegeEscalation(
                (int) $admin->id,
                'iam.role.manage',
                AnchorType::COMPANY->value,
                FieldProfile::P2_SENSITIVE->value,
            ),
            'PRIVILEGE_ESCALATION_FORBIDDEN',
            403,
        );

        // An ORG_UNIT holder may not widen a grant to the whole company, even
        // at the same field profile.
        $unitAdmin = User::factory()->create(['auth_version' => 1]);
        $this->people->employmentByUser[(int) $unitAdmin->id] = [1];
        AuthorityBinding::factory()
            ->forPrincipal((int) $unitAdmin->id, 1)
            ->anchor(AnchorType::ORG_UNIT, 7, FieldProfile::P1_INTERNAL)
            ->create([
                'capability_code' => 'iam.role.manage',
                'valid_from' => '2025-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
            ]);

        $guard->assertNoPrivilegeEscalation(
            (int) $unitAdmin->id,
            'iam.role.manage',
            AnchorType::ORG_UNIT->value,
            FieldProfile::P1_INTERNAL->value,
        );

        $this->expectDomainFailure(
            static fn () => $guard->assertNoPrivilegeEscalation(
                (int) $unitAdmin->id,
                'iam.role.manage',
                AnchorType::COMPANY->value,
                FieldProfile::P1_INTERNAL->value,
            ),
            'PRIVILEGE_ESCALATION_FORBIDDEN',
            403,
        );
    }

    public function test_a_privileged_flag_without_a_binding_is_not_an_admin(): void
    {
        $unbound = User::factory()->create(['is_privileged' => true]);
        $guard = app(AdminProtectionGuard::class);

        $this->expectDomainFailure(
            static fn () => $guard->assertNoPrivilegeEscalation(
                (int) $unbound->id,
                'iam.role.manage',
                AnchorType::COMPANY->value,
                FieldProfile::P1_INTERNAL->value,
            ),
            'PRIVILEGE_ESCALATION_FORBIDDEN',
            403,
        );

        $this->assertFalse($guard->isEnabledRoleManager((int) $unbound->id));
    }

    public function test_an_unbound_last_admin_candidate_is_rejected_rather_than_protected(): void
    {
        $unbound = User::factory()->create(['is_privileged' => true]);

        // No binding means not an admin; disabling it cannot remove the last one.
        app(AdminProtectionGuard::class)->assertNotLastAdminDisable((int) $unbound->id);
        $this->addToAssertionCount(1);
    }

    public function test_a_role_grant_list_that_exceeds_the_actor_ceiling_is_rejected(): void
    {
        $admin = $this->admin();
        $guard = app(AdminProtectionGuard::class);

        $this->expectDomainFailure(
            static function () use ($guard, $admin): void {
                foreach ([
                    ['capability' => 'people.employee.read', 'anchor_type' => AnchorType::COMPANY->value, 'field_profile' => FieldProfile::P2_SENSITIVE->value],
                    ['capability' => 'payroll.run.approve', 'anchor_type' => AnchorType::COMPANY->value, 'field_profile' => FieldProfile::P2_SENSITIVE->value],
                ] as $grant) {
                    $guard->assertNoPrivilegeEscalation(
                        (int) $admin->id,
                        $grant['capability'],
                        $grant['anchor_type'],
                        $grant['field_profile'],
                    );
                }
            },
            'PRIVILEGE_ESCALATION_FORBIDDEN',
            403,
        );
    }

    public function test_super_admin_can_only_be_granted_by_an_existing_super_admin(): void
    {
        $admin = $this->admin();
        $guard = app(AdminProtectionGuard::class);

        $this->expectDomainFailure(
            static fn () => $guard->assertRoleGrantAllowed((int) $admin->id, 'SUPER_ADMIN'),
            'SUPER_ADMIN_GRANT_FORBIDDEN',
            403,
        );

        $super = $this->admin('SUPER_ADMIN');
        $guard->assertRoleGrantAllowed((int) $super->id, 'SUPER_ADMIN');
        $this->addToAssertionCount(1);
    }

    public function test_no_delete_user_route_exists(): void
    {
        $deleteUserRoutes = array_values(array_filter(
            app('router')->getRoutes()->getRoutes(),
            static fn ($route): bool => str_starts_with((string) $route->uri(), 'v1/users')
                && in_array('DELETE', $route->methods(), true)
                && ! str_contains((string) $route->uri(), '/roles/'),
        ));

        $this->assertSame([], $deleteUserRoutes, 'User deletion is not an exposed operation.');
        $this->assertFalse(Route::has('users.destroy'));
    }

    public function test_privileged_but_unbound_principal_is_denied_by_the_production_route_gate(): void
    {
        config(['iam.mfa.enabled' => false]);

        $unbound = User::factory()->create(['is_privileged' => true]);
        $this->loginAs($unbound);

        $this->getJson('/v1/users')
            ->assertForbidden()
            ->assertJsonPath('data.reason', 'CAPABILITY_DENIED')
            ->assertJsonPath('data.capability', 'iam.user.read');
    }

    public function test_ensure_capability_does_not_short_circuit_a_privileged_principal(): void
    {
        $unbound = User::factory()->create(['is_privileged' => true]);
        $principal = $this->actingAsUser($unbound);
        $this->app->instance(Principal::class, $principal);

        $reached = false;
        $response = app(EnsureCapability::class)->handle(
            Request::create('/v1/users', 'GET'),
            static function () use (&$reached): \Illuminate\Http\JsonResponse {
                $reached = true;

                return \App\Shared\ApiResponse::ok([]);
            },
            'iam.user.read',
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($reached, 'The privileged flag must not reach the controller without a grant.');
        $this->assertSame('iam.user.read', $this->responseData($response)['data']['capability']);
    }

    public function test_the_mfa_step_up_gate_does_not_short_circuit_a_privileged_principal(): void
    {
        config(['iam.mfa.enabled' => true]);

        $unbound = User::factory()->create(['is_privileged' => true]);
        $this->actingAsUser($unbound, mfa: false, sessionId: 'test-session');

        $response = app(RequireMfaStepUp::class)->handle(
            Request::create('/v1/users', 'POST'),
            static fn (): \Illuminate\Http\JsonResponse => \App\Shared\ApiResponse::ok([]),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('MFA_STEP_UP_REQUIRED', $this->responseData($response)['data']['reason']);
    }

    public function test_a_stale_authorization_generation_is_rejected_even_for_a_privileged_principal(): void
    {
        $user = $this->admin();
        $stale = new AuthenticatedPrincipal(
            userId: (int) $user->id,
            employmentId: 1,
            authVersion: (int) $user->auth_version + 1,
            privileged: true,
            mfa: true,
            sessionId: 'test-session',
        );

        $this->expectException(NotFoundOrForbiddenException::class);

        app(Authorizer::class)->permits($stale, 'iam.role.manage', $this->clock->now()->toDateString());
    }

    /**
     * @return array<string, mixed>
     */
    private function responseData(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);

        /** @var array<string, mixed> $data */
        $data = $response->getData(true);

        return $data;
    }

    private function admin(string $role = 'ROLE_MANAGER'): User
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->people->employmentByUser[(int) $user->id] = [1];

        UserRole::query()->create([
            'user_id' => (int) $user->id,
            'role_code' => $role,
            'valid_from' => '2025-01-01 00:00:00',
            'valid_to' => '2030-01-01 00:00:00',
            'granted_by' => null,
            'reason' => 'Synthetic admin fixture',
            'auth_version' => 1,
            'created_at' => $this->clock->now(),
        ]);

        $capability = $role === 'SUPER_ADMIN' ? CapabilityWildcard::WILDCARD : 'iam.role.manage';

        RoleCapability::query()->firstOrCreate(
            ['role_code' => $role, 'capability_code' => $capability, 'anchor_type' => AnchorType::COMPANY->value],
            ['field_profile' => FieldProfile::P1_INTERNAL->value],
        );

        AuthorityBinding::factory()
            ->forPrincipal((int) $user->id, 1)
            ->anchor(AnchorType::COMPANY, null, FieldProfile::P1_INTERNAL)
            ->create([
                'capability_code' => $capability,
                'purpose_code' => 'ROLE_'.$role,
                'valid_from' => '2025-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
            ]);

        return $user;
    }

    private function expectDomainFailure(callable $operation, string $reason, int $status): void
    {
        try {
            $operation();
        } catch (\App\Shared\Exceptions\DomainException $exception) {
            $this->assertSame($reason, $exception->details()['reason'] ?? null);
            $this->assertSame($status, $exception->errorCode()->status());

            return;
        }

        $this->fail("Expected {$reason} ({$status}).");
    }
}
