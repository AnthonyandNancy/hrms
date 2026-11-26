<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Auth\AuthenticatedPrincipal;
use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Contracts\Principal;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MePermissionsPageGrantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Clock::class, new FrozenClock('2026-11-23T09:00:00Z'));
    }

    public function test_only_pages_granted_to_an_effective_role_are_visible(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->grant($user, 'people.employee.read');
        $this->role($user, 'HR_ADMIN');
        RolePage::query()->create(['role_code' => 'HR_ADMIN', 'page_code' => 'employee-detail', 'created_at' => now()]);

        $pages = $this->permissions($user)['pages'];

        $this->assertContains('/hr/employees/:id', array_column($pages, 'path'));
        $this->assertNotContains('/hr/employees', array_column($pages, 'path'));
    }

    public function test_a_page_grant_without_its_required_capability_is_not_visible(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->role($user, 'HR_ADMIN');
        RolePage::query()->create(['role_code' => 'HR_ADMIN', 'page_code' => 'hr-employees', 'created_at' => now()]);

        $nonPublic = array_values(array_filter(
            $this->permissions($user)['pages'],
            static fn (array $page): bool => $page['required_capability'] !== 'public',
        ));
        $this->assertSame([], $nonPublic);
    }

    public function test_an_expired_role_grant_does_not_authorize_a_page(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->grant($user, 'people.employee.read');
        UserRole::query()->create([
            'tenant_id' => 1,
            'user_id' => (int) $user->id,
            'role_code' => 'HR_ADMIN',
            'valid_from' => '2025-01-01 00:00:00',
            'valid_to' => '2025-12-31 00:00:00',
            'reason' => 'TEST',
            'auth_version' => 1,
            'created_at' => now(),
        ]);
        RolePage::query()->create(['role_code' => 'HR_ADMIN', 'page_code' => 'hr-employees', 'created_at' => now()]);

        $nonPublic = array_values(array_filter(
            $this->permissions($user)['pages'],
            static fn (array $page): bool => $page['required_capability'] !== 'public',
        ));
        $this->assertSame([], $nonPublic);
    }

    /**
     * @return array<string, mixed>
     */
    private function permissions(User $user): array
    {
        $principal = new AuthenticatedPrincipal(
            userId: (int) $user->id,
            tenantId: 1,
            employmentId: 1,
            authVersion: (int) $user->auth_version,
            privileged: false,
            mfa: true,
            sessionId: 'synthetic-session',
        );
        $this->app->instance(Principal::class, $principal);
        $this->app->instance(AuthenticatedPrincipal::class, $principal);
        $this->app->make(AuthenticationContext::class)->set($principal);

        return $this->getJson('/v1/me/permissions')->assertOk()->json('data');
    }

    private function role(User $user, string $roleCode): void
    {
        UserRole::query()->create([
            'tenant_id' => 1,
            'user_id' => (int) $user->id,
            'role_code' => $roleCode,
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'reason' => 'TEST',
            'auth_version' => (int) $user->auth_version,
            'created_at' => now(),
        ]);
    }

    private function grant(User $user, string $capability): void
    {
        AuthorityBinding::factory()
            ->forPrincipal(1, (int) $user->id, (int) $user->auth_version)
            ->anchor(AnchorType::COMPANY, null, FieldProfile::P1_INTERNAL)
            ->create([
                'capability_code' => $capability,
                'valid_from' => '2026-01-01 00:00:00',
                'valid_to' => '2027-01-01 00:00:00',
            ]);
    }
}
