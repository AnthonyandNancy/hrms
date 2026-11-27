<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\RoleCapability;
use App\Modules\IAM\Models\UserRole;
use Tests\Feature\Authorization\AuthorizationTestSupport;

/**
 * Volume 3 Task 7 follow-through: the role workbench needs rename and delete to
 * exist server-side, not just in the UI. Code-owned templates must be immutable
 * and a role still held by users must not be deletable.
 */
final class RoleAdminWriteTest extends AuthorizationTestSupport
{
    public function test_it_renames_a_custom_role(): void
    {
        $this->systemAdmin();
        $role = $this->customRole('CUSTOM_RENAME');

        $this->patchJson('/v1/roles/CUSTOM_RENAME', ['name' => '重命名后的角色'], $this->headers('rename-1'))
            ->assertOk()
            ->assertJsonPath('data.name', '重命名后的角色');

        $this->assertSame('重命名后的角色', (string) Role::query()->whereKey('CUSTOM_RENAME')->value('name'));
    }

    public function test_a_code_owned_template_cannot_be_renamed(): void
    {
        $this->systemAdmin();

        $this->patchJson('/v1/roles/HR_ADMIN', ['name' => '试图改名'], $this->headers('rename-template'))
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TEMPLATE_ROLE_IMMUTABLE');
    }

    public function test_it_deletes_an_unused_custom_role_and_its_grants(): void
    {
        $this->systemAdmin();
        $this->customRole('CUSTOM_DELETE');

        $this->deleteJson('/v1/roles/CUSTOM_DELETE', [], $this->headers('delete-1'))
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('roles', ['code' => 'CUSTOM_DELETE']);
        $this->assertSame(0, RoleCapability::query()->where('role_code', 'CUSTOM_DELETE')->count());
    }

    public function test_a_role_still_held_by_a_user_cannot_be_deleted(): void
    {
        $admin = $this->systemAdmin();
        $this->customRole('CUSTOM_IN_USE');

        UserRole::query()->create([
            'user_id' => (int) $admin->id,
            'role_code' => 'CUSTOM_IN_USE',
            'auth_version' => (int) $admin->auth_version,
            'valid_from' => '2025-01-01',
            'valid_to' => '2030-01-01',
            'reason' => '合成角色占用测试',
        ]);

        $this->deleteJson('/v1/roles/CUSTOM_IN_USE', [], $this->headers('delete-in-use'))
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'ROLE_IN_USE');

        $this->assertDatabaseHas('roles', ['code' => 'CUSTOM_IN_USE']);
    }

    public function test_a_code_owned_template_cannot_be_deleted(): void
    {
        $this->systemAdmin();

        $this->deleteJson('/v1/roles/EMPLOYEE', [], $this->headers('delete-template'))
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TEMPLATE_ROLE_IMMUTABLE');
    }

    private function customRole(string $code): Role
    {
        /** @var Role $role */
        $role = Role::query()->create([
            'code' => $code,
            'name' => '合成自定义角色',
            'is_template' => false,
            'owner' => 1,
            'purpose' => '合成测试用途说明',
        ]);

        RoleCapability::query()->create([
            'role_code' => $code,
            'capability_code' => 'people.employee.read',
            'anchor_type' => 'COMPANY',
            'anchor_id' => null,
            'field_profile' => 'P1_INTERNAL',
            'created_at' => $this->clock->now(),
        ]);

        return $role;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $key): array
    {
        return [
            'Idempotency-Key' => $key,
            'X-CSRF-Token' => 'test-csrf',
        ];
    }
}
