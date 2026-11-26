<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use Tests\Support\LifecycleTestCase;

final class IamAssigneeDirectoryTest extends LifecycleTestCase
{
    public function test_workflow_role_assignees_resolve_from_effective_iam_user_roles(): void
    {
        Role::query()->create([
            'code' => 'HR_ADMIN',
            'name' => 'HR administrator',
            'is_template' => true,
            'purpose' => 'Test template',
        ]);
        $user = User::factory()->create();
        UserRole::query()->create([
            'user_id' => (int) $user->id,
            'role_code' => 'HR_ADMIN',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'reason' => 'Test role assignment',
            'auth_version' => 1,
            'created_at' => '2026-01-01 00:00:00',
        ]);

        $directory = $this->app->make(\App\Modules\IAM\Services\IamAssigneeDirectory::class);
        $users = $directory->userIdsWithRole('HR_ADMIN', '2026-11-23');

        $this->assertContains((int) $user->id, $users);
        $this->assertSame([], $directory->userIdsWithRole('HR_ADMIN', '2027-01-02'));
    }
}
