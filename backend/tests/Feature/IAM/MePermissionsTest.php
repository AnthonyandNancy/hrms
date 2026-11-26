<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Auth\AuthenticatedPrincipal;
use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Contracts\Principal;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class MePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock('2026-11-24T09:00:00Z');
        $this->app->instance(Clock::class, $this->clock);

        // routes/api.php is owned by another integration branch. This fixture
        // exercises the exact controller action that the lead will wire at
        // GET /v1/me/permissions.
        Route::middleware(['api', 'auth.session', 'capability:iam.session.manage'])
            ->prefix('v1')
            ->group(function (): void {
                Route::get('/me/permissions', [\App\Modules\IAM\Http\Controllers\MeController::class, 'permissions'])
                    ->name('me.permissions.test');
            });
    }

    public function test_permissions_returns_menu_actions_field_capabilities_scope_labels_and_auth_version(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->grant($user, 'people.employee.read', 'COMPANY', null, 'P1_INTERNAL', 'READ');

        $this->actAs($user)
            ->getJson('/v1/me/permissions')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'menus',
                    'actions',
                    'field_capabilities',
                    'scope_labels',
                    'auth_version',
                    'pages',
                ],
            ])
            ->assertJsonPath('data.auth_version', 1);
    }

    public function test_permissions_filters_pages_and_actions_to_the_callers_capabilities(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->grant($user, 'people.employee.read', 'COMPANY', null, 'P1_INTERNAL', 'READ');
        $this->assignRole($user, 'HR_ADMIN');
        $this->grantPage('HR_ADMIN', 'hr-employees');

        $response = $this->actAs($user)->getJson('/v1/me/permissions')->assertOk();

        $pages = $this->arrayData($response->json('data.pages'));
        $actions = $this->arrayData($response->json('data.actions'));

        $this->assertNotEmpty($pages);
        $this->assertContains('/hr/employees', array_column($pages, 'path'));

        foreach ($pages as $page) {
            $this->assertTrue(
                in_array($page['required_capability'], $actions, true) || $page['required_capability'] === 'public',
                "Page {$page['path']} leaked without its required capability.",
            );
        }

        $this->assertContains('people.employee.read', $actions);
        $this->assertNotContains('payroll.run.read', $actions);

        $payrollPaths = array_column(array_filter(
            $pages,
            static fn (array $page): bool => str_starts_with((string) $page['path'], '/payroll'),
        ), 'path');
        $this->assertSame([], array_values($payrollPaths), 'Payroll pages must not be visible without payroll capability.');

        $profiles = array_column(array_filter(
            $pages,
            static fn (array $page): bool => $page['required_capability'] !== 'public',
        ), 'field_profile_max');

        $this->assertSame(['P1_INTERNAL'], array_values(array_unique($profiles)));
    }

    public function test_parent_menu_visibility_does_not_grant_or_include_a_child_page(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->grant($user, 'people.contract.read', 'COMPANY', null, 'P2_SENSITIVE', 'READ');
        $this->assignRole($user, 'HR_ADMIN');
        $this->grantPage('HR_ADMIN', 'hr-contracts');

        $response = $this->actAs($user)->getJson('/v1/me/permissions')->assertOk();

        $pages = $this->arrayData($response->json('data.pages'));
        $paths = array_column($pages, 'path');

        $this->assertContains('/hr/contracts', $paths);
        $this->assertNotContains('/hr/employees', $paths);
        $this->assertNotContains('/payroll', $paths);

        $menus = $this->arrayData($response->json('data.menus'));
        $this->assertNotEmpty($menus);

        foreach ($menus as $menu) {
            $this->assertNotSame('', (string) $menu['key']);
            $this->assertArrayHasKey('pages', $menu);
            $this->assertNotEmpty($menu['pages'], 'An empty menu group must not be returned.');
        }
    }

    public function test_a_wildcard_principal_receives_every_catalogued_page_and_capability(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->grant($user, '*', 'COMPANY', null, 'P3_RESTRICTED', 'SYSTEM_ADMIN');
        $this->assignRole($user, 'SYSTEM_ADMIN');
        foreach ((new \App\Modules\IAM\Authorization\PermissionResourceCatalog)->pageCodes() as $pageCode) {
            $this->grantPage('SYSTEM_ADMIN', $pageCode);
        }

        $response = $this->actAs($user)->getJson('/v1/me/permissions')->assertOk();

        $this->assertCount(160, $this->arrayData($response->json('data.pages')));
        $this->assertGreaterThanOrEqual(100, count($this->arrayData($response->json('data.actions'))));
        $this->assertContains('P3_RESTRICTED', $this->arrayData($response->json('data.field_capabilities')));
        $this->assertNotEmpty($this->arrayData($response->json('data.scope_labels')));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function arrayData(mixed $value): array
    {
        $this->assertIsArray($value);

        return array_values($value);
    }

    private function actAs(User $user): self
    {
        $principal = new AuthenticatedPrincipal(
            userId: (int) $user->id,
            employmentId: null,
            authVersion: (int) $user->auth_version,
            privileged: true,
            mfa: false,
            sessionId: 'test-session',
        );

        $this->app->instance(Principal::class, $principal);
        $this->app->instance(\App\Shared\Auth\AuthenticatedPrincipal::class, $principal);
        $this->app->make(AuthenticationContext::class)->set($principal);

        return $this;
    }

    private function assignRole(User $user, string $roleCode): void
    {
        UserRole::query()->create([
            'user_id' => (int) $user->id,
            'role_code' => $roleCode,
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'reason' => 'TEST',
            'auth_version' => (int) $user->auth_version,
            'created_at' => now(),
        ]);
    }

    private function grantPage(string $roleCode, string $pageCode): void
    {
        \App\Modules\IAM\Models\RolePage::query()->create([
            'role_code' => $roleCode,
            'page_code' => $pageCode,
            'created_at' => now(),
        ]);
    }

    private function grant(
        User $user,
        string $capability,
        string $anchorType,
        ?int $anchorId,
        string $fieldProfile,
        string $purposeCode,
    ): AuthorityBinding {
        return AuthorityBinding::factory()
            ->forPrincipal((int) $user->id, (int) $user->auth_version)
            ->anchor(AnchorType::from($anchorType), $anchorId, FieldProfile::from($fieldProfile))
            ->create([
                'capability_code' => $capability,
                'purpose_code' => $purposeCode,
            ]);
    }
}
