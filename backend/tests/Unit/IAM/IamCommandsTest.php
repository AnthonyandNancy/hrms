<?php

declare(strict_types=1);

namespace Tests\Unit\IAM;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\Capability;
use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class IamCommandsTest extends TestCase
{
    protected FrozenClock $clock;

    use RefreshDatabase;

    public function test_sync_capabilities_is_idempotent_and_refuses_referenced_removal(): void
    {
        $this->freeze();
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $firstCount = Capability::query()->count();
        $this->artisan('iam:sync-capabilities')->assertSuccessful();

        $this->assertSame($firstCount, Capability::query()->count());
        $this->assertSame(10, Role::query()->where('is_template', true)->count());

        AuthorityBinding::factory()->create([
            'principal_id' => 1,
            'capability_code' => 'people.employee.read',
            'anchor_type' => AnchorType::SELF->value,
            'anchor_id' => null,
            'anchor_key' => 0,
            'field_profile' => FieldProfile::P1_INTERNAL->value,
        ]);

        Capability::query()->where('code', 'people.employee.read')->delete();
        // Sync recreates it, so simulate a future catalog removal via a stale binding code.
        AuthorityBinding::query()->whereKey(1)->update(['capability_code' => 'removed.code']);

        $this->artisan('iam:sync-capabilities')->assertFailed();
    }

    public function test_seed_demo_requires_password_env_and_is_idempotent(): void
    {
        $this->freeze();
        $this->artisan('iam:sync-capabilities')->assertSuccessful();

        config(['iam.demo_seed_password' => 'Correct-Horse-9!']);

        $this->artisan('iam:seed-demo')->assertSuccessful();
        $this->artisan('iam:seed-demo')->assertSuccessful();

        $this->assertSame(4, User::query()->count());
        $this->assertDatabaseHas('users', ['username' => 'demo.admin', 'is_privileged' => true]);
        $this->assertGreaterThan(0, AuthorityBinding::query()->count());

    }

    public function test_reap_sessions_revokes_only_expired_sessions(): void
    {
        $this->freeze();
        $user = User::factory()->create();
        $expired = Session::factory()->create(['user_id' => $user->id, 'idle_expires_at' => $this->clock->now()->subMinute()]);
        $active = Session::factory()->create(['user_id' => $user->id, 'idle_expires_at' => $this->clock->now()->addMinute()]);

        $this->artisan('iam:reap-sessions')->assertSuccessful();

        $this->assertNotNull($expired->fresh()?->revoked_at);
        $this->assertNull($active->fresh()?->revoked_at);
    }

    public function test_access_review_requires_quarter_and_reports_json(): void
    {
        $this->freeze();
        $this->artisan('iam:access-review', ['--quarter' => 'bad'])->assertFailed();

        $this->artisan('iam:access-review', ['--quarter' => '2026-Q4'])
            ->expectsOutputToContain('unused_high_risk_bindings')
            ->assertSuccessful();
    }

    private function freeze(): void
    {
        $this->clock = new FrozenClock(CarbonImmutable::parse('2026-11-23T09:00:00Z', 'UTC'));
        $this->app->instance(Clock::class, $this->clock);
    }
}
