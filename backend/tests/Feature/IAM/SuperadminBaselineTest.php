<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SuperadminBaselineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Clock::class, new FrozenClock('2026-11-26T09:00:00Z'));
    }

    public function test_installer_creates_a_complete_system_admin_baseline(): void
    {
        $this->artisan('hrms:install', [
            '--company-code' => 'ACME-CN',
            '--company-name' => 'Synthetic Company',
            '--timezone' => 'Asia/Shanghai',
            '--currency' => 'CNY',
            '--admin-username' => 'installer',
            '--admin-email' => 'installer@example.test',
            '--admin-password' => 'Synthetic-Password-9!',
            '--annual-days' => '15.00',
            '--legal-basis' => 'Synthetic policy, not legal advice',
        ])->assertExitCode(0);

        $admin = User::query()->where('username', 'installer')->firstOrFail();

        $this->assertSame(160, RolePage::query()->where('role_code', 'SYSTEM_ADMIN')->count());
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $admin->id,
            'role_code' => 'SYSTEM_ADMIN',
            'auth_version' => $admin->auth_version,
        ]);
        $this->assertDatabaseHas('authority_bindings', [
            'principal_id' => $admin->id,
            'capability_code' => '*',
            'anchor_type' => 'COMPANY',
            'anchor_id' => null,
            'anchor_key' => 0,
            'auth_version' => $admin->auth_version,
        ]);

        $this->artisan('iam:verify-superadmin', ['--username' => 'installer'])
            ->expectsOutputToContain('pages=160')
            ->assertExitCode(0);
    }

    public function test_verify_fails_when_the_role_row_is_missing(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $admin = User::factory()->create(['username' => 'installer', 'auth_version' => 1]);
        AuthorityBinding::factory()
            ->forPrincipal((int) $admin->id, 1)
            ->anchor(\App\Shared\Authorization\AnchorType::COMPANY, null, \App\Shared\Authorization\FieldProfile::P3_RESTRICTED)
            ->create([
                'capability_code' => '*',
                'valid_from' => '2026-01-01 00:00:00',
                'valid_to' => '2027-01-01 00:00:00',
            ]);

        $this->artisan('iam:verify-superadmin', ['--username' => 'installer'])->assertExitCode(1);
    }

    public function test_verify_fails_when_the_role_page_template_is_incomplete(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        RolePage::query()->where('role_code', 'SYSTEM_ADMIN')->limit(1)->delete();
        $admin = User::factory()->create(['username' => 'installer', 'auth_version' => 1]);
        UserRole::query()->create([
            'user_id' => (int) $admin->id,
            'role_code' => 'SYSTEM_ADMIN',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'reason' => 'TEST',
            'auth_version' => 1,
            'created_at' => now(),
        ]);
        AuthorityBinding::factory()
            ->forPrincipal((int) $admin->id, 1)
            ->anchor(\App\Shared\Authorization\AnchorType::COMPANY, null, \App\Shared\Authorization\FieldProfile::P3_RESTRICTED)
            ->create([
                'capability_code' => '*',
                'valid_from' => '2026-01-01 00:00:00',
                'valid_to' => '2027-01-01 00:00:00',
            ]);

        $this->artisan('iam:verify-superadmin', ['--username' => 'installer'])->assertExitCode(1);
    }
}
