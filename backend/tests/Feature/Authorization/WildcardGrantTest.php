<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Auth\CapabilityAuthorizer;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;

final class WildcardGrantTest extends AuthorizationTestSupport
{
    public function test_a_wildcard_binding_permits_every_catalogued_capability(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->organization->knownLegalEntities = [1];
        $this->grant($user, '*', AnchorType::LEGAL_ENTITY, 1, FieldProfile::P3_RESTRICTED);

        $authorizer = app(CapabilityAuthorizer::class);
        $principal = $this->actingAsUser($user);
        $date = '2026-11-24';

        foreach (['iam.user.read', 'payroll.run.approve', 'workflow.case.admin', 'talent.training.manage'] as $code) {
            $this->assertTrue(
                $authorizer->permits($principal, $code, $date),
                "A wildcard binding must permit {$code}.",
            );
        }
    }

    public function test_a_wildcard_binding_covers_an_anchored_resource(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->organization->knownLegalEntities = [1];
        $this->grant($user, '*', AnchorType::LEGAL_ENTITY, 1, FieldProfile::P3_RESTRICTED);

        $this->assertTrue(app(CapabilityAuthorizer::class)->allows(
            $this->actingAsUser($user),
            'payroll.run.read',
            new AnchorRef(AnchorType::LEGAL_ENTITY, 1),
            '2026-11-24',
        ));
    }

    public function test_an_expired_wildcard_binding_permits_nothing(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->organization->knownLegalEntities = [1];
        AuthorityBinding::query()->create([
            'tenant_id' => 1,
            'principal_id' => (int) $user->id,
            'capability_code' => '*',
            'anchor_type' => 'LEGAL_ENTITY',
            'anchor_id' => 1,
            'anchor_key' => 1,
            'field_profile' => 'P3_RESTRICTED',
            'purpose_code' => 'SYSTEM_ADMIN',
            'valid_from' => '2020-01-01 00:00:00',
            'valid_to' => '2021-01-01 00:00:00',
            'source_type' => 'ROLE',
            'auth_version' => 1,
            'created_at' => '2020-01-01 00:00:00',
        ]);

        $this->assertFalse(app(CapabilityAuthorizer::class)->permits(
            $this->actingAsUser($user),
            'iam.user.read',
            '2026-11-24',
        ));
    }

    private function grant(User $user, string $capability, AnchorType $anchor, ?int $anchorId, FieldProfile $profile): void
    {
        AuthorityBinding::factory()
            ->forPrincipal((int) $user->tenant_id, (int) $user->id, (int) $user->auth_version)
            ->anchor($anchor, $anchorId, $profile)
            ->create(['capability_code' => $capability]);
    }
}
