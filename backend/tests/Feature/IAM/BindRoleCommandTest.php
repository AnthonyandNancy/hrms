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
        ])->assertExitCode(0);

        $version = (int) $user->fresh()->auth_version;
        $binding = AuthorityBinding::query()
            ->where('principal_id', $user->id)
            ->where('auth_version', $version)
            ->sole();

        $this->assertSame('*', $binding->capability_code);
        $this->assertSame('COMPANY', $binding->anchor_type);
        $this->assertNull($binding->anchor_id);
        $this->assertSame(0, (int) $binding->anchor_key);
        $this->assertSame('P3_RESTRICTED', $binding->field_profile);
        $this->assertSame(2, $version);
        $this->assertLessThanOrEqual(366, (int) $binding->valid_from->diffInDays($binding->valid_to));
        // Validity is persisted at the day boundary so a grant created after
        // midnight is already effective on that business day.
        $this->assertSame('2026-11-24 00:00:00', $binding->valid_from->toDateTimeString());
        $this->assertSame('2027-11-25 00:00:00', $binding->valid_to->toDateTimeString());
        $this->assertDatabaseHas('user_roles', ['user_id' => (int) $user->id, 'role_code' => 'SYSTEM_ADMIN']);
    }

    public function test_it_refuses_a_super_admin_binding_longer_than_thirty_days(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);

        $this->artisan('iam:bind-role', [
            '--user' => (int) $user->id,
            '--role' => 'SUPER_ADMIN',
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
        ])->assertExitCode(0);

        $binding = AuthorityBinding::query()
            ->where('auth_version', (int) $user->fresh()->auth_version)
            ->sole();
        $this->assertSame(30, (int) $binding->valid_from->diffInDays($binding->valid_to));
    }

    public function test_it_is_idempotent(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $arguments = ['--user' => (int) $user->id, '--role' => 'SYSTEM_ADMIN'];

        $this->artisan('iam:bind-role', $arguments)->assertExitCode(0);
        $this->artisan('iam:bind-role', $arguments)->assertExitCode(0);

        $this->assertSame(1, AuthorityBinding::query()
            ->where('auth_version', (int) $user->fresh()->auth_version)
            ->count());
        $this->assertSame(2, AuthorityBinding::query()->count());
    }

    public function test_it_refuses_an_unknown_role(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);

        $this->artisan('iam:bind-role', [
            '--user' => (int) $user->id,
            '--role' => 'NOT_A_ROLE',
        ])->assertExitCode(1);

        $this->assertSame(0, AuthorityBinding::query()->count());
    }

    public function test_it_is_idempotent_even_when_the_clock_advances(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $arguments = ['--user' => (int) $user->id, '--role' => 'SYSTEM_ADMIN'];

        $this->artisan('iam:bind-role', $arguments)->assertExitCode(0);
        $versionAfterFirst = (int) $user->fresh()->auth_version;

        $this->app->instance(Clock::class, new FrozenClock('2026-11-26T09:00:00Z'));
        $this->artisan('iam:bind-role', $arguments)->assertExitCode(0);

        $this->assertSame(1, AuthorityBinding::query()
            ->where('auth_version', $versionAfterFirst)
            ->count());
        $this->assertSame(2, AuthorityBinding::query()->count());
        $this->assertSame($versionAfterFirst, (int) $user->fresh()->auth_version);
    }
}
