<?php

declare(strict_types=1);

namespace Tests\Unit\IAM;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\Capability;
use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Authorization\SodRules;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
        $this->assertSame(14, Role::query()->where('is_template', true)->count());

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

        // ADR-0034 §10B: seeding must write user_roles as well as bindings.
        foreach (['demo.admin', 'demo.hr', 'demo.manager', 'demo.employee'] as $username) {
            $user = User::query()->where('username', $username)->firstOrFail();
            $this->assertGreaterThan(
                0,
                UserRole::query()->where('user_id', (int) $user->id)->count(),
                $username.' must have at least one effective user_roles row',
            );
        }

        $this->assertGreaterThan(0, RolePage::query()->count());

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

    public function test_access_review_requires_quarter_and_reports_sod_risks(): void
    {
        $this->freeze();
        $this->artisan('iam:access-review', ['--quarter' => 'bad'])->assertFailed();

        // A real cumulative pair, plus a wildcard holder that necessarily
        // carries every rule. Asserting only the JSON key would not prove that
        // the report evaluates bindings at all.
        $this->bind(1, 'iam.binding.manage');
        $this->bind(1, 'iam.audit.read');
        $this->bind(2, '*');

        $exit = Artisan::call('iam:access-review', ['--quarter' => '2026-Q4']);
        $this->assertSame(0, $exit);

        /** @var array<string, mixed> $report */
        $report = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('2026-Q4', $report['quarter']);
        $this->assertArrayHasKey('unused_high_risk_bindings', $report);
        $this->assertArrayNotHasKey('sod_conflicts', $report);
        $this->assertArrayHasKey('sod_risks', $report);

        /** @var list<array{principal_id: int, capabilities: array{0: string, 1: string}, kind: string}> $risks */
        $risks = $report['sod_risks'];
        $this->assertCount(7, $risks);

        $concrete = array_values(array_filter($risks, static fn (array $risk): bool => $risk['principal_id'] === 1));
        $this->assertSame([
            [
                'principal_id' => 1,
                'capabilities' => ['iam.audit.read', 'iam.binding.manage'],
                'kind' => SodRules::KIND_CUMULATIVE,
            ],
        ], $concrete);

        $wildcard = array_values(array_filter($risks, static fn (array $risk): bool => $risk['principal_id'] === 2));
        $this->assertCount(6, $wildcard);
        $this->assertContains([
            'principal_id' => 2,
            'capabilities' => ['iam.audit.read', 'iam.binding.manage'],
            'kind' => SodRules::KIND_CUMULATIVE,
        ], $wildcard);
        $this->assertContains([
            'principal_id' => 2,
            'capabilities' => ['payroll.run.approve', 'payroll.run.prepare'],
            'kind' => SodRules::KIND_OBJECT_LEVEL,
        ], $wildcard);
    }

    private function bind(int $principalId, string $capabilityCode): void
    {
        AuthorityBinding::factory()
            ->forPrincipal($principalId)
            ->anchor(AnchorType::COMPANY, null)
            ->create([
                'capability_code' => $capabilityCode,
                'valid_from' => '2025-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
            ]);
    }

    private function freeze(): void
    {
        $this->clock = new FrozenClock(CarbonImmutable::parse('2026-11-23T09:00:00Z', 'UTC'));
        $this->app->instance(Clock::class, $this->clock);
    }
}
