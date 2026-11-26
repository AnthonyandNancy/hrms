<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Tests\Feature\Authorization\AuthorizationTestSupport;
use Tests\Support\AuthenticatesRequests;

final class UserRoleAssignmentApiTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Clock::class, new FrozenClock('2026-11-26T09:00:00Z'));
    }

    public function test_user_list_exposes_effective_roles_instead_of_the_privileged_flag(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        [, $headers] = $this->roleManager();
        $target = User::factory()->create(['is_privileged' => false, 'auth_version' => 1]);
        $this->assign($target, 'HR_SPECIALIST');

        $response = $this->getJson('/v1/users?page=1&page_size=20', $headers)->assertOk();
        $items = collect($response->json('data.items'))->keyBy('id');

        $this->assertSame('HR_SPECIALIST', $items[(int) $target->id]['roles'][0]['role_code']);
        $this->assertFalse((bool) $items[(int) $target->id]['is_privileged']);
    }

    public function test_revoking_a_role_deletes_its_bundle_replays_the_snapshot_and_revokes_sessions(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        [, $headers] = $this->roleManager();
        $target = User::factory()->create(['auth_version' => 1]);
        $this->assign($target, 'HR_SPECIALIST');
        $this->grant($target, 'iam.user.read', 'ROLE_HR_SPECIALIST');

        $this->deleteJson('/v1/users/'.$target->id.'/roles/HR_SPECIALIST', [
            'reason' => 'Synthetic revocation',
        ], $headers)->assertOk()->assertJsonPath('data.revoked', true);

        $this->assertDatabaseMissing('user_roles', ['user_id' => (int) $target->id, 'role_code' => 'HR_SPECIALIST']);
        $this->assertDatabaseMissing('authority_bindings', [
            'principal_id' => (int) $target->id,
            'purpose_code' => 'ROLE_HR_SPECIALIST',
        ]);
        $this->assertSame(2, (int) $target->fresh()->auth_version);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('auth_events')
            ->where('event_type', 'role.revoked')
            ->count());
    }

    public function test_revoking_a_role_the_user_does_not_hold_is_not_found(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        [, $headers] = $this->roleManager();
        $target = User::factory()->create(['auth_version' => 1]);

        $this->deleteJson('/v1/users/'.$target->id.'/roles/HR_SPECIALIST', [
            'reason' => 'Synthetic revocation',
        ], $headers)->assertNotFound();
    }

    /**
     * @return array{0: User, 1: array<string, string>}
     */
    private function roleManager(): array
    {
        $actor = User::factory()->create(['auth_version' => 1]);
        $this->people->employmentByUser[(int) $actor->id] = [1];
        $this->organization->knownLegalEntities = [1];
        foreach (['iam.role.manage', 'iam.user.read'] as $capability) {
            AuthorityBinding::factory()
                ->forPrincipal(1, (int) $actor->id, 1)
                ->anchor(AnchorType::COMPANY, null, FieldProfile::P1_INTERNAL)
                ->create([
                    'capability_code' => $capability,
                    'valid_from' => '2025-01-01 00:00:00',
                    'valid_to' => '2030-01-01 00:00:00',
                ]);
        }
        $this->enableMfa($actor);
        $auth = $this->loginAs($actor);
        $this->stepUp();

        return [$actor, ['X-CSRF-Token' => $auth['csrf']]];
    }

    private function assign(User $target, string $roleCode): void
    {
        UserRole::query()->create([
            'tenant_id' => 1,
            'user_id' => (int) $target->id,
            'role_code' => $roleCode,
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'granted_by' => 10,
            'reason' => 'Synthetic assignment',
            'auth_version' => (int) $target->auth_version,
            'created_at' => now(),
        ]);
    }

    private function grant(User $target, string $capability, string $purpose): void
    {
        AuthorityBinding::factory()
            ->forPrincipal(1, (int) $target->id, (int) $target->auth_version)
            ->anchor(AnchorType::COMPANY, null, FieldProfile::P1_INTERNAL)
            ->create([
                'capability_code' => $capability,
                'purpose_code' => $purpose,
                'valid_from' => '2026-01-01 00:00:00',
                'valid_to' => '2027-01-01 00:00:00',
            ]);
    }
}
