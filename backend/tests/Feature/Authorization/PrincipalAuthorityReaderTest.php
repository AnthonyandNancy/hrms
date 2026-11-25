<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Contracts\PrincipalAuthorityReader;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Authorization\FieldProfile;

/**
 * The wildcard is coverage, not a capability code. Workflow and Time build
 * their principal capability sets from this reader, so leaving the literal `*`
 * unexpanded silently denies every admin-only branch to a system administrator.
 */
final class PrincipalAuthorityReaderTest extends AuthorizationTestSupport
{
    public function test_a_wildcard_binding_expands_to_the_catalog_for_workflow_and_time(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->organization->knownLegalEntities = [1];
        $this->grant($user, CapabilityWildcard::WILDCARD, AnchorType::LEGAL_ENTITY, 1);

        $codes = array_column(
            app(PrincipalAuthorityReader::class)->grants($this->actingAsUser($user), '2026-11-23'),
            'capability',
        );

        $this->assertContains('workflow.case.admin', $codes);
        $this->assertContains('time.leave.admin', $codes);
        $this->assertContains('payroll.run.read', $codes);
        $this->assertNotContains(CapabilityWildcard::WILDCARD, $codes);
    }

    public function test_a_wildcard_expansion_inherits_the_wildcard_anchor(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->organization->knownLegalEntities = [1];
        $this->grant($user, CapabilityWildcard::WILDCARD, AnchorType::LEGAL_ENTITY, 1);

        $grants = app(PrincipalAuthorityReader::class)->grants($this->actingAsUser($user), '2026-11-23');
        $workflow = $this->rowFor($grants, 'workflow.case.admin');

        $this->assertSame('LEGAL_ENTITY', $workflow['anchor_type']);
        $this->assertSame(1, $workflow['anchor_id']);
        $this->assertSame(FieldProfile::P3_RESTRICTED->value, $workflow['field_profile']);
    }

    public function test_a_concrete_binding_wins_over_the_wildcard_projection(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->organization->knownLegalEntities = [1];

        $this->grant($user, 'workflow.case.admin', AnchorType::ORG_UNIT, 7, FieldProfile::P1_INTERNAL);
        $this->grant($user, CapabilityWildcard::WILDCARD, AnchorType::LEGAL_ENTITY, 1);

        $grants = app(PrincipalAuthorityReader::class)->grants($this->actingAsUser($user), '2026-11-23');
        $matches = array_values(array_filter(
            $grants,
            static fn (array $grant): bool => $grant['capability'] === 'workflow.case.admin',
        ));

        $this->assertCount(1, $matches, 'The catalog expansion must not duplicate a concrete grant.');
        $this->assertSame('ORG_UNIT', $matches[0]['anchor_type']);
        $this->assertSame(7, $matches[0]['anchor_id']);
    }

    public function test_concrete_bindings_are_returned_without_a_wildcard(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->organization->knownLegalEntities = [1];
        $this->grant($user, 'workflow.case.read', AnchorType::LEGAL_ENTITY, 1);

        $codes = array_column(
            app(PrincipalAuthorityReader::class)->grants($this->actingAsUser($user), '2026-11-23'),
            'capability',
        );

        $this->assertSame(['workflow.case.read'], $codes);
    }

    /**
     * @param  list<array<string, mixed>>  $grants
     * @return array<string, mixed>
     */
    private function rowFor(array $grants, string $capability): array
    {
        foreach ($grants as $grant) {
            if ($grant['capability'] === $capability) {
                return $grant;
            }
        }

        $this->fail("Capability [{$capability}] is missing from the expanded grant set.");
    }

    private function grant(User $user, string $capability, AnchorType $anchor, ?int $anchorId, FieldProfile $profile = FieldProfile::P3_RESTRICTED): void
    {
        AuthorityBinding::factory()
            ->forPrincipal((int) $user->tenant_id, (int) $user->id, (int) $user->auth_version)
            ->anchor($anchor, $anchorId, $profile)
            ->create(['capability_code' => $capability]);
    }
}
