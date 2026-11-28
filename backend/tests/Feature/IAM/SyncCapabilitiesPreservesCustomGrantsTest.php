<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\RoleCapability;
use App\Modules\IAM\Models\RolePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SyncCapabilitiesPreservesCustomGrantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_capability_sync_upserts_templates_without_deleting_custom_authorization(): void
    {
        Role::query()->create([
            'code' => 'CUSTOM_COMPLIANCE',
            'name' => '自定义合规角色',
            'is_template' => false,
            'owner' => 1,
            'purpose' => 'Synthetic custom role used to prove catalogue sync is non-destructive.',
            'expires_at' => '2027-12-31 00:00:00',
        ]);
        RolePage::query()->create([
            'role_code' => 'CUSTOM_COMPLIANCE',
            'page_code' => 'workspace',
            'created_at' => now('UTC'),
        ]);
        RoleCapability::query()->create([
            'role_code' => 'CUSTOM_COMPLIANCE',
            'capability_code' => 'people.employee.read',
            'anchor_type' => 'COMPANY',
            'anchor_id' => null,
            'field_profile' => 'P1_INTERNAL',
        ]);

        $this->artisan('iam:sync-capabilities')->assertExitCode(0);

        $this->assertDatabaseHas('role_pages', [
            'role_code' => 'CUSTOM_COMPLIANCE',
            'page_code' => 'workspace',
        ]);
        $this->assertDatabaseHas('role_capabilities', [
            'role_code' => 'CUSTOM_COMPLIANCE',
            'capability_code' => 'people.employee.read',
            'anchor_type' => 'COMPANY',
            'field_profile' => 'P1_INTERNAL',
        ]);
    }
}
