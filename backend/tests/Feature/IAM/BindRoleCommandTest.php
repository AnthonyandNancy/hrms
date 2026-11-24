<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BindRoleCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Clock::class, new FrozenClock('2026-11-24T09:00:00Z'));
    }

    public function test_it_binds_the_system_admin_wildcard_for_a_year(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);

        $this->artisan('iam:bind-role', [
            '--user' => (int) $user->id,
            '--role' => 'SYSTEM_ADMIN',
            '--legal-entity' => 1,
        ])->assertExitCode(0);

        $binding = AuthorityBinding::query()->where('principal_id', $user->id)->sole();

        $this->assertSame('*', $binding->capability_code);
        $this->assertSame('LEGAL_ENTITY', $binding->anchor_type);
        $this->assertSame(1, (int) $binding->anchor_id);
        $this->assertSame('P3_RESTRICTED', $binding->field_profile);
        $this->assertSame(2, (int) $user->fresh()->auth_version);
        $this->assertLessThanOrEqual(366, (int) $binding->valid_from->diffInDays($binding->valid_to));
        $this->assertDatabaseHas('user_roles', ['user_id' => (int) $user->id, 'role_code' => 'SYSTEM_ADMIN']);
    }

    public function test_it_refuses_a_super_admin_binding_longer_than_thirty_days(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);

        $this->artisan('iam:bind-role', [
            '--user' => (int) $user->id,
            '--role' => 'SUPER_ADMIN',
            '--legal-entity' => 1,
            '--days' => 90,
        ])->assertExitCode(1);

        $this->assertSame(0, AuthorityBinding::query()->count());
    }

    public function test_it_defaults_super_admin_to_thirty_days(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);

        $this->artisan('iam:bind-role', [
            '--user' => (int) $user->id,
            '--role' => 'SUPER_ADMIN',
            '--legal-entity' => 1,
        ])->assertExitCode(0);

        $binding = AuthorityBinding::query()->sole();
        $this->assertSame(30, (int) $binding->valid_from->diffInDays($binding->valid_to));
    }

    public function test_it_is_idempotent(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $arguments = ['--user' => (int) $user->id, '--role' => 'SYSTEM_ADMIN', '--legal-entity' => 1];

        $this->artisan('iam:bind-role', $arguments)->assertExitCode(0);
        $this->artisan('iam:bind-role', $arguments)->assertExitCode(0);

        $this->assertSame(1, AuthorityBinding::query()->count());
    }

    public function test_it_refuses_an_unknown_role(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);

        $this->artisan('iam:bind-role', [
            '--user' => (int) $user->id,
            '--role' => 'NOT_A_ROLE',
            '--legal-entity' => 1,
        ])->assertExitCode(1);

        $this->assertSame(0, AuthorityBinding::query()->count());
    }
}
