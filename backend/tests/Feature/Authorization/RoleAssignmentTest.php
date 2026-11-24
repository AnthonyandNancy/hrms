<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Tests\Support\AuthenticatesRequests;

final class RoleAssignmentTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    public function test_role_code_rejects_free_text_person_or_department_name(): void
    {
        $this->freeze();
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $actor = $this->roleManager();
        $target = User::factory()->create();
        $this->enableMfa($actor);
        $auth = $this->loginAs($actor);
        $this->stepUp();

        $this->postJson('/v1/users/'.$target->id.'/roles', [
            'role_code' => 'Zhang San',
            'reason' => 'Name-shaped codes are forbidden.',
        ], ['X-Legal-Entity-Id' => '1', 'X-CSRF-Token' => $auth['csrf']])->assertUnprocessable();
    }

    public function test_role_assignment_requires_anchor_ids_for_id_bearing_anchors(): void
    {
        $this->freeze();
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $actor = $this->roleManager();
        $target = User::factory()->create();
        $this->enableMfa($actor);
        $auth = $this->loginAs($actor);
        $this->stepUp();

        $this->postJson('/v1/users/'.$target->id.'/roles', [
            'role_code' => 'PAYROLL_SPECIALIST',
            'reason' => 'Payroll onboarding.',
        ], ['X-Legal-Entity-Id' => '1', 'X-CSRF-Token' => $auth['csrf']])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'ROLE_ANCHOR_REQUIRED');
    }

    public function test_role_assignment_expands_to_bindings_with_explicit_anchor(): void
    {
        $this->freeze();
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $actor = $this->roleManager();
        $target = User::factory()->create();
        $this->enableMfa($actor);
        $auth = $this->loginAs($actor);
        $this->stepUp();

        $this->postJson('/v1/users/'.$target->id.'/roles', [
            'role_code' => 'PAYROLL_SPECIALIST',
            'reason' => 'Payroll onboarding.',
            'binding_anchors' => [
                'PAYROLL_RUN' => 77,
                'LEGAL_ENTITY' => 1,
            ],
        ], ['X-Legal-Entity-Id' => '1', 'X-CSRF-Token' => $auth['csrf']])->assertCreated();

        $runBinding = AuthorityBinding::query()
            ->where('principal_id', $target->id)
            ->where('capability_code', 'payroll.run.prepare')
            ->firstOrFail();

        $this->assertSame(AnchorType::PAYROLL_RUN->value, $runBinding->anchor_type);
        $this->assertSame(77, $runBinding->anchor_id);
        $this->assertSame(2, (int) $target->fresh()?->auth_version);
        $this->assertNotNull($runBinding->auth_version);
    }

    public function test_a_risky_role_assignment_requires_explicit_acknowledgement(): void
    {
        $this->freeze();
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $actor = $this->roleManager();
        $target = User::factory()->create();
        $this->enableMfa($actor);
        $this->loginAs($actor);
        $this->stepUp();
        $auth = ['X-Legal-Entity-Id' => '1'];
        $this->withHeader('X-Legal-Entity-Id', '1');

        // Existing preparer binding from the same role assignment.
        AuthorityBinding::factory()->forPrincipal(1, (int) $target->id)->anchor(AnchorType::PAYROLL_RUN, 77, FieldProfile::P2_SENSITIVE)->create([
            'capability_code' => 'payroll.run.approve',
            'valid_from' => '2025-01-01 00:00:00',
            'valid_to' => '2030-01-01 00:00:00',
        ]);

        $this->postJson('/v1/users/'.$target->id.'/roles', [
            'role_code' => 'PAYROLL_SPECIALIST',
            'reason' => 'Conflicting role.',
            'binding_anchors' => [
                'PAYROLL_RUN' => 77,
                'LEGAL_ENTITY' => 1,
            ],
        ], $auth)
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'SOD_RISK_NOT_ACKNOWLEDGED');
    }

    private function roleManager(): User
    {
        $user = User::factory()->create();
        $this->people->employmentByUser[(int) $user->id] = [1];
        $this->organization->knownLegalEntities = [1];
        AuthorityBinding::factory()
            ->forPrincipal(1, (int) $user->id)
            ->anchor(AnchorType::LEGAL_ENTITY, 1, FieldProfile::P1_INTERNAL)
            ->create(['capability_code' => 'iam.role.manage']);

        return $user;
    }

    private function freeze(): void
    {
        $this->clock = new FrozenClock(CarbonImmutable::parse('2026-11-23T09:00:00Z', 'UTC'));
        $this->app->instance(Clock::class, $this->clock);
    }
}
