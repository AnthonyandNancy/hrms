<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Tests\Feature\Authorization\AuthorizationTestSupport;
use Tests\Support\AuthenticatesRequests;

final class RolePagesApiTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Clock::class, new FrozenClock('2026-11-26T09:00:00Z'));
    }

    public function test_get_returns_the_catalogued_page_details_for_a_custom_role(): void
    {
        [$actor, $headers] = $this->actor();
        $this->customRole('TEMP_PAGES');
        RolePage::query()->create(['role_code' => 'TEMP_PAGES', 'page_code' => 'hr-employees', 'created_at' => now()]);

        $this->getJson('/v1/roles/TEMP_PAGES/pages', $headers)
            ->assertOk()
            ->assertJsonPath('data.role_code', 'TEMP_PAGES')
            ->assertJsonPath('data.page_codes.0', 'hr-employees')
            ->assertJsonPath('data.pages.0.page_code', 'hr-employees')
            ->assertJsonPath('data.pages.0.path', '/hr/employees');
    }

    public function test_replace_pages_is_atomic_and_replays_affected_users(): void
    {
        [$actor, $headers] = $this->actor();
        $role = $this->customRole('TEMP_PAGES');
        $member = User::factory()->create(['auth_version' => 1]);
        UserRole::query()->create([
            'user_id' => (int) $member->id,
            'role_code' => $role->code,
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'reason' => 'TEST',
            'auth_version' => 1,
            'created_at' => now(),
        ]);

        $this->putJson('/v1/roles/TEMP_PAGES/pages', [
            'pages' => ['hr-employees', 'hr-contracts', 'hr-employees'],
        ], $headers)->assertOk();

        $this->assertSame(['hr-contracts', 'hr-employees'], RolePage::query()
            ->where('role_code', 'TEMP_PAGES')->orderBy('page_code')->pluck('page_code')->all());
        $this->assertSame(2, (int) $member->fresh()->auth_version);
    }

    public function test_unknown_page_code_is_rejected_without_writes(): void
    {
        [$actor, $headers] = $this->actor();
        $this->customRole('TEMP_PAGES');
        RolePage::query()->create(['role_code' => 'TEMP_PAGES', 'page_code' => 'hr-employees', 'created_at' => now()]);

        $this->putJson('/v1/roles/TEMP_PAGES/pages', [
            'pages' => ['not-a-real-page'],
        ], $headers)->assertStatus(422)->assertJsonPath('data.reason', 'UNKNOWN_PAGE_CODE');

        $this->assertSame(1, RolePage::query()->where('role_code', 'TEMP_PAGES')->count());
        $this->assertSame(1, (int) $actor->fresh()->auth_version);
    }

    public function test_template_roles_cannot_be_edited_through_the_api(): void
    {
        [$actor, $headers] = $this->actor();
        $this->artisan('iam:sync-capabilities')->assertSuccessful();

        $this->putJson('/v1/roles/HR_ADMIN/pages', ['pages' => ['hr-employees']], $headers)
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TEMPLATE_ROLE_IMMUTABLE');
    }

    public function test_role_list_exposes_page_and_member_counts(): void
    {
        [$actor, $headers] = $this->actor();
        $this->artisan('iam:sync-capabilities')->assertSuccessful();

        $response = $this->getJson('/v1/roles', $headers)->assertOk();
        $roles = $this->rolesByCode($response->json('data.roles'));

        $this->assertSame(160, (int) $roles['SYSTEM_ADMIN']['page_count']);
        $this->assertGreaterThanOrEqual(0, (int) $roles['SYSTEM_ADMIN']['member_count']);
    }

    /**
     * @return array{0: User, 1: array<string, string>}
     */
    private function actor(): array
    {
        $actor = User::factory()->create(['auth_version' => 1]);
        $this->people->employmentByUser[(int) $actor->id] = [1];
        AuthorityBinding::factory()
            ->forPrincipal((int) $actor->id, 1)
            ->anchor(AnchorType::COMPANY, null, FieldProfile::P1_INTERNAL)
            ->create([
                'capability_code' => 'iam.role.manage',
                'valid_from' => '2025-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
            ]);

        $this->enableMfa($actor);
        $auth = $this->loginAs($actor);
        $this->stepUp();

        return [$actor, ['X-CSRF-Token' => $auth['csrf']]];
    }

    private function customRole(string $code): Role
    {
        return Role::query()->create([
            'code' => $code,
            'name' => 'Synthetic custom role',
            'is_template' => false,
            'owner' => 1,
            'purpose' => 'Synthetic role for page grant tests.',
            'expires_at' => '2027-12-31 00:00:00',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $roles
     * @return array<string, array<string, mixed>>
     */
    private function rolesByCode(array $roles): array
    {
        $byCode = [];
        foreach ($roles as $role) {
            $byCode[(string) $role['code']] = $role;
        }

        return $byCode;
    }
}
