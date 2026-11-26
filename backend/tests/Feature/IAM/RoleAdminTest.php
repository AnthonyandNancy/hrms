<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use Tests\Feature\Authorization\AuthorizationTestSupport;
use Tests\Support\AuthenticatesRequests;

final class RoleAdminTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    public function test_admin_creates_exception_role_and_idempotent_replay_returns_the_same_role(): void
    {
        $actor = $this->roleManager();
        $this->enableMfa($actor);
        $auth = $this->loginAs($actor);
        $this->stepUp();

        $body = [
            'code' => 'TEMP_REPORT_VIEWER',
            'name' => 'Temporary report viewer',
            'purpose' => 'Synthetic quarterly reporting access.',
            'capabilities' => [
                ['capability' => 'reporting.export', 'anchor_type' => 'COMPANY', 'field_profile' => 'P1_INTERNAL'],
            ],
        ];
        $headers = ['Idempotency-Key' => 'role-1', 'X-CSRF-Token' => $auth['csrf']];

        $this->postJson('/v1/roles', $body, $headers)->assertCreated()->assertJsonPath('data.code', 'TEMP_REPORT_VIEWER');
        $this->assertDatabaseHas('roles', ['code' => 'TEMP_REPORT_VIEWER', 'is_template' => false]);
        $this->assertDatabaseHas('role_capabilities', ['role_code' => 'TEMP_REPORT_VIEWER', 'capability_code' => 'reporting.export']);

        $this->postJson('/v1/roles', $body, $headers)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');
    }

    public function test_role_creation_rejects_unknown_capability_anchor_and_field_profile_without_writing(): void
    {
        $actor = $this->roleManager();
        $this->enableMfa($actor);
        $auth = $this->loginAs($actor);
        $this->stepUp();

        $this->postJson('/v1/roles', [
            'code' => 'TEMP_UNKNOWN',
            'name' => 'Temporary unknown',
            'purpose' => 'Synthetic rejected role.',
            'capabilities' => [
                ['capability' => 'not.a.real.capability', 'anchor_type' => 'COMPANY', 'field_profile' => 'P1_INTERNAL'],
            ],
        ], ['Idempotency-Key' => 'role-unknown', 'X-CSRF-Token' => $auth['csrf']])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'UNKNOWN_CAPABILITY');

        $this->postJson('/v1/roles', [
            'code' => 'TEMP_BAD_ANCHOR',
            'name' => 'Temporary bad anchor',
            'purpose' => 'Synthetic rejected role.',
            'capabilities' => [
                ['capability' => 'reporting.export', 'anchor_type' => 'SELF', 'field_profile' => 'P1_INTERNAL'],
            ],
        ], ['Idempotency-Key' => 'role-bad-anchor', 'X-CSRF-Token' => $auth['csrf']])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'CAPABILITY_ANCHOR_NOT_ALLOWED');

        $this->postJson('/v1/roles', [
            'code' => 'TEMP_BAD_FIELD',
            'name' => 'Temporary bad field',
            'purpose' => 'Synthetic rejected role.',
            'capabilities' => [
                ['capability' => 'reporting.export', 'anchor_type' => 'COMPANY', 'field_profile' => 'P3_RESTRICTED'],
            ],
        ], ['Idempotency-Key' => 'role-bad-field', 'X-CSRF-Token' => $auth['csrf']])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'FIELD_PROFILE_EXCEEDS_CAPABILITY');

        $this->assertDatabaseMissing('roles', ['code' => 'TEMP_UNKNOWN']);
        $this->assertDatabaseMissing('roles', ['code' => 'TEMP_BAD_ANCHOR']);
        $this->assertDatabaseMissing('roles', ['code' => 'TEMP_BAD_FIELD']);
    }

    public function test_role_can_be_copied_and_its_capability_overrides_replay_assigned_user_versions(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();

        $actor = $this->roleManager();
        $target = User::factory()->create(['auth_version' => 1]);
        $this->enableMfa($actor);
        $auth = $this->loginAs($actor);
        $this->stepUp();
        $headers = ['X-CSRF-Token' => $auth['csrf']];

        $this->postJson('/v1/roles/HR_SPECIALIST/copy', [
            'code' => 'HR_SPECIALIST_COPY',
            'name' => 'HR specialist copy',
            'purpose' => 'Synthetic copied role.',
        ], ['Idempotency-Key' => 'role-copy-1'] + $headers)->assertCreated();

        $this->assertDatabaseHas('roles', ['code' => 'HR_SPECIALIST_COPY', 'is_template' => false]);
        $this->assertDatabaseHas('role_capabilities', ['role_code' => 'HR_SPECIALIST_COPY', 'capability_code' => 'people.employee.read']);

        Role::query()->create([
            'code' => 'TEMP_OVERRIDE',
            'name' => 'Temporary override',
            'is_template' => false,
            'owner' => (int) $actor->id,
            'purpose' => 'Synthetic override role.',
            'expires_at' => '2027-12-31 00:00:00',
        ]);
        UserRole::query()->create([
            'user_id' => (int) $target->id,
            'role_code' => 'TEMP_OVERRIDE',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-12-31 00:00:00',
            'granted_by' => (int) $actor->id,
            'reason' => 'Synthetic snapshot replay',
            'auth_version' => 1,
            'created_at' => $this->clock->now(),
        ]);

        $this->putJson('/v1/roles/TEMP_OVERRIDE/capabilities', [
            'capabilities' => [
                ['capability' => 'people.employee.read', 'anchor_type' => 'COMPANY', 'field_profile' => 'P1_INTERNAL'],
            ],
        ], ['Idempotency-Key' => 'role-cap-1'] + $headers)->assertOk();

        $this->assertDatabaseHas('role_capabilities', ['role_code' => 'TEMP_OVERRIDE', 'capability_code' => 'people.employee.read']);
        $this->assertSame(2, (int) $target->fresh()->auth_version);
    }

    public function test_template_role_capabilities_are_immutable(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();

        $actor = $this->roleManager();
        $this->enableMfa($actor);
        $auth = $this->loginAs($actor);
        $this->stepUp();

        $this->putJson('/v1/roles/HR_SPECIALIST/capabilities', [
            'capabilities' => [
                ['capability' => 'people.employee.read', 'anchor_type' => 'COMPANY', 'field_profile' => 'P1_INTERNAL'],
            ],
        ], ['Idempotency-Key' => 'role-template-1', 'X-CSRF-Token' => $auth['csrf']])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TEMPLATE_ROLE_IMMUTABLE');
    }

    private function roleManager(): User
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->people->employmentByUser[(int) $user->id] = [1];
        AuthorityBinding::factory()
            ->forPrincipal((int) $user->id, (int) $user->auth_version)
            ->anchor(AnchorType::COMPANY, null, FieldProfile::P1_INTERNAL)
            ->create([
                'capability_code' => 'iam.role.manage',
                'valid_from' => '2025-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
            ]);

        return $user;
    }
}
