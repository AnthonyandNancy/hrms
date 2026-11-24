<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\CapabilityWildcard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SyncCapabilitiesWildcardTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_capabilities_keeps_wildcard_bindings(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->createBinding($user, CapabilityWildcard::WILDCARD);

        $this->artisan('iam:sync-capabilities')->assertExitCode(0);

        $this->assertSame(
            1,
            AuthorityBinding::query()->where('capability_code', CapabilityWildcard::WILDCARD)->count(),
            'Syncing the catalog must not treat the wildcard as an unknown capability.',
        );
    }

    public function test_sync_capabilities_reports_unknown_codes_without_the_wildcard(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $unknown = 'definitely.unknown.code';

        $this->createBinding($user, CapabilityWildcard::WILDCARD);
        $this->createBinding($user, $unknown);

        $this->artisan('iam:sync-capabilities')
            ->expectsOutputToContain("Refusing to remove referenced capability codes: {$unknown}")
            ->doesntExpectOutputToContain(CapabilityWildcard::WILDCARD)
            ->assertExitCode(1);
    }

    private function createBinding(User $user, string $capabilityCode): void
    {
        AuthorityBinding::query()->create([
            'tenant_id' => 1,
            'principal_id' => (int) $user->id,
            'capability_code' => $capabilityCode,
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
    }
}
