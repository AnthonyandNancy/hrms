<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Auth\CapabilityAuthorizer;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Authorization\ResourceRef;
use App\Shared\Exceptions\ValidationException;

/**
 * Field projection is resolved from the same binding as row scope. A P3 field
 * is never in any output; P2 is masked, not filterable and export-gated.
 */
final class FieldProjectionTest extends AuthorizationTestSupport
{
    public function test_p0_visible_and_filterable_p1_visible_but_masked_p2_visible_but_masked_p3_hidden(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'people.employee.read', AnchorType::LEGAL_ENTITY, 1, FieldProfile::P3_RESTRICTED);
        $this->organization->knownLegalEntities = [1];

        $set = app(CapabilityAuthorizer::class)->fields(
            $this->actingAsUser($user),
            'people.employee.read',
            $this->resourceRow(),
        );

        $this->assertTrue($set->isVisible('display_name'));
        $this->assertTrue($set->isFilterable('display_name'));
        $this->assertTrue($set->isVisible('email'));
        $this->assertTrue($set->isFilterable('email'));
        $this->assertTrue($set->isVisible('id_number'));
        $this->assertFalse($set->isFilterable('id_number'));
        $this->assertFalse($set->isVisible('salary'));

        $masked = $set->mask($this->resourceRow()->row());

        $this->assertSame('Ada Lovelace', $masked['display_name']);
        $this->assertNotSame('ada@example.test', $masked['email']);
        $this->assertArrayNotHasKey('salary', $masked);
        $this->assertNotSame('110101199001011234', $masked['id_number']);
    }

    public function test_p2_is_not_filterable_and_throws_domain_422(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'people.employee.read', AnchorType::LEGAL_ENTITY, 1, FieldProfile::P2_SENSITIVE);
        $this->organization->knownLegalEntities = [1];

        $set = app(CapabilityAuthorizer::class)->fields(
            $this->actingAsUser($user),
            'people.employee.read',
            $this->resourceRow(),
        );

        $this->expectException(ValidationException::class);
        $set->assertFilterable('id_number');
    }

    public function test_caller_without_field_coverage_gets_404_not_partial_projection(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'people.employee.read', AnchorType::LEGAL_ENTITY, 2, FieldProfile::P3_RESTRICTED);
        $this->organization->knownLegalEntities = [1, 2];

        $this->expectException(\App\Shared\Exceptions\NotFoundOrForbiddenException::class);

        app(CapabilityAuthorizer::class)->fields(
            $this->actingAsUser($user),
            'people.employee.read',
            $this->resourceRow(),
        );
    }

    public function test_export_flag_only_applies_to_export_capability_and_never_unlocks_p3(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'people.employee.read', AnchorType::LEGAL_ENTITY, 1, FieldProfile::P3_RESTRICTED);
        $this->grant($user, 'reporting.export', AnchorType::LEGAL_ENTITY, 1, FieldProfile::P2_SENSITIVE);
        $this->organization->knownLegalEntities = [1];

        $readSet = app(CapabilityAuthorizer::class)->fields($this->actingAsUser($user), 'people.employee.read', $this->resourceRow());
        $exportSet = app(CapabilityAuthorizer::class)->fields($this->actingAsUser($user), 'reporting.export', $this->resourceRow());

        $this->assertFalse($readSet->isExportable('id_number'));
        $this->assertTrue($exportSet->isExportable('id_number'));
        $this->assertFalse($exportSet->isExportable('salary'));
    }

    public function test_sensitive_field_access_is_audited_without_values(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'people.employee.read', AnchorType::LEGAL_ENTITY, 1, FieldProfile::P2_SENSITIVE);
        $this->organization->knownLegalEntities = [1];

        app(CapabilityAuthorizer::class)->fields($this->actingAsUser($user), 'people.employee.read', $this->resourceRow());

        $this->assertNotEmpty($this->sensitiveAuditor->records);
        $record = $this->sensitiveAuditor->records[0];
        $this->assertContains('id_number', $record['fields']);
        $this->assertArrayNotHasKey('id_number', $record['fields']);
        $this->assertStringNotContainsString('110101199001011234', json_encode($record, JSON_THROW_ON_ERROR));
    }

    public function test_p3_values_never_reach_serialized_output_or_logs(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'people.employee.read', AnchorType::LEGAL_ENTITY, 1, FieldProfile::P3_RESTRICTED);
        $this->organization->knownLegalEntities = [1];

        $set = app(CapabilityAuthorizer::class)->fields($this->actingAsUser($user), 'people.employee.read', $this->resourceRow());
        $output = json_encode($set->mask($this->resourceRow()->row()), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('salary', $output);
        $this->assertStringNotContainsString('999999', $output);
        $this->assertStringNotContainsString('health', $output);

    }

    private function grant(User $user, string $capability, AnchorType $anchor, ?int $anchorId, FieldProfile $profile): void
    {
        AuthorityBinding::factory()
            ->forPrincipal((int) $user->tenant_id, (int) $user->id, (int) $user->auth_version)
            ->anchor($anchor, $anchorId, $profile)
            ->create(['capability_code' => $capability]);
    }

    private function resourceRow(): ResourceRef
    {
        return new ResourceRef(
            resourceType: 'employee',
            id: 17,
            row: [
                'display_name' => 'Ada Lovelace',
                'email' => 'ada@example.test',
                'id_number' => '110101199001011234',
                'salary' => '999999',
                'health' => 'sensitive',
            ],
            tenantId: 1,
            employmentId: 501,
            orgUnitId: 12,
            legalEntityId: 1,
        );
    }
}
