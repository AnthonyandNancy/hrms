<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SyncCapabilitiesWildcardTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_capabilities_keeps_wildcard_bindings(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);

        AuthorityBinding::query()->create([
            'tenant_id' => 1,
            'principal_id' => (int) $user->id,
            'capability_code' => '*',
            'anchor_type' => 'LEGAL_ENTITY',
            'anchor_id' => 1,
            'anchor_key' => 1,
            'field_profile' => 'P3_RESTRICTED',
            'purpose_code' => 'SYSTEM_ADMIN',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'source_type' => 'ROLE',
            'auth_version' => 1,
            'created_at' => '2026-01-01 00:00:00',
        ]);

        $this->artisan('iam:sync-capabilities')->assertExitCode(0);

        $this->assertSame(
            1,
            AuthorityBinding::query()->where('capability_code', '*')->count(),
            'Syncing the catalog must not treat the wildcard as an unknown capability.',
        );
    }
}
