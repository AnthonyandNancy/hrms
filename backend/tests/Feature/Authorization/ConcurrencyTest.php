<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthenticatesRequests;

final class ConcurrencyTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    public function test_parallel_binding_changes_increment_auth_version_monotonically_without_lost_updates(): void
    {
        $this->freeze();
        $admin = User::factory()->create(['mfa_enrolled_at' => now()]);
        $this->people->employmentByUser[(int) $admin->id] = [1];
        $this->grant($admin, 'iam.binding.manage', AnchorType::COMPANY, null);
        $this->organization->knownLegalEntities = [1];
        $target = User::factory()->create();
        $this->enableMfa($admin);
        $auth = $this->loginAs($admin);
        $this->stepUp($auth['session']);
        $this->artisan('iam:sync-capabilities')->assertSuccessful();

        $versions = [];
        for ($i = 1; $i <= 3; $i++) {
            $response = $this->postJson('/v1/users/'.$target->id.'/bindings', [
                'capability_code' => 'people.employee.read',
                'anchor_type' => 'ORG_UNIT',
                'anchor_id' => $i,
                'field_profile' => FieldProfile::P1_INTERNAL->value,
                'purpose_code' => 'CONCURRENCY_TEST',
            ], ['X-Legal-Entity-Id' => '1', 'X-CSRF-Token' => $auth['csrf']]);

            $response->assertCreated();
            $versions[] = (int) DB::table('users')->where('id', $target->id)->value('auth_version');

            // Re-login is required after each binding grant because the admin
            // auth_version changed; this mirrors a real operator refreshing.
            $auth = $this->loginAs($admin);
            $this->stepUp($auth['session']);
        }

        $this->assertSame([2, 3, 4], $versions);
    }

    private function grant(User $user, string $code, AnchorType $anchor, ?int $anchorId): void
    {
        \App\Modules\IAM\Models\AuthorityBinding::factory()
            ->forPrincipal(1, (int) $user->id)
            ->anchor($anchor, $anchorId, FieldProfile::P1_INTERNAL)
            ->create([
                'capability_code' => $code,
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
