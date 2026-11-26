<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Authorization\ResourceRef;
use App\Shared\Exceptions\ValidationException;

/**
 * Field projection is resolved from the same binding as row scope. A P3
 * holder may read restricted values, but P3 is never filterable, sortable or
 * exportable; a P2 holder cannot see P3 at all.
 */
final class FieldProjectionTest extends AuthorizationTestSupport
{
    public function test_a_p3_binding_sees_salary_but_cannot_filter_or_export_it(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'people.employee.read', AnchorType::COMPANY, null, FieldProfile::P3_RESTRICTED);
        $this->organization->knownLegalEntities = [1];

        $set = app(Authorizer::class)->fields(
            $this->actingAsUser($user),
            'people.employee.read',
            $this->resourceRow(),
        );

        $this->assertTrue($set->isVisible('display_name'));
        $this->assertTrue($set->isVisible('salary'));

        // ADR-0024: P3 field reads leave an audit record of field names only.
        $this->assertNotEmpty($this->sensitiveAuditor->records);
        $record = $this->sensitiveAuditor->records[0];
        $this->assertSame('people.employee.read', $record['capability']);
        $this->assertContains('salary', $record['fields']);
        $this->assertArrayNotHasKey('value', $record);
        $this->assertFalse($set->isFilterable('salary'));
        $this->assertFalse($set->isExportable('salary'));

        $masked = $set->mask($this->resourceRow()->row());
        $this->assertSame('999999', $masked['salary']);
        $this->assertSame('sensitive', $masked['health']);
    }

    public function test_a_p2_binding_does_not_see_salary(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'people.employee.read', AnchorType::COMPANY, null, FieldProfile::P2_SENSITIVE);
        $this->organization->knownLegalEntities = [1];

        $set = app(Authorizer::class)->fields(
            $this->actingAsUser($user),
            'people.employee.read',
            $this->resourceRow(),
        );

        $this->assertFalse($set->isVisible('salary'));
        $this->assertArrayNotHasKey('salary', $set->mask($this->resourceRow()->row()));
    }

    public function test_p2_is_not_filterable_and_throws_domain_422(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'people.employee.read', AnchorType::COMPANY, null, FieldProfile::P2_SENSITIVE);
        $this->organization->knownLegalEntities = [1];

        $set = app(Authorizer::class)->fields(
            $this->actingAsUser($user),
            'people.employee.read',
            $this->resourceRow(),
        );

        $this->expectException(ValidationException::class);
        $set->assertFilterable('id_number');
    }

    public function test_caller_without_field_coverage_gets_404_while_same_company_rows_are_covered(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'people.employee.read', AnchorType::COMPANY, null, FieldProfile::P3_RESTRICTED);

        $this->assertTrue(
            app(Authorizer::class)->fields(
                $this->actingAsUser($user),
                'people.employee.read',
                $this->resourceRow(),
            )->isVisible('display_name'),
        );

        $otherTenant = new ResourceRef(
            resourceType: 'employee',
            id: 18,
            row: ['display_name' => 'Grace'],
            tenantId: 2,
            employmentId: 502,
        );

        $this->expectException(\App\Shared\Exceptions\NotFoundOrForbiddenException::class);
        app(Authorizer::class)->fields($this->actingAsUser($user), 'people.employee.read', $otherTenant);
    }

    public function test_export_flag_only_applies_to_export_capability_and_never_unlocks_p3(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'people.employee.read', AnchorType::COMPANY, null, FieldProfile::P3_RESTRICTED);
        $this->grant($user, 'reporting.export', AnchorType::COMPANY, null, FieldProfile::P2_SENSITIVE);
        $this->organization->knownLegalEntities = [1];

        $readSet = app(Authorizer::class)->fields($this->actingAsUser($user), 'people.employee.read', $this->resourceRow());
        $exportSet = app(Authorizer::class)->fields($this->actingAsUser($user), 'reporting.export', $this->resourceRow());

        $this->assertFalse($readSet->isExportable('id_number'));
        $this->assertTrue($exportSet->isExportable('id_number'));
        $this->assertFalse($exportSet->isExportable('salary'));
    }

    public function test_sensitive_field_access_is_audited_without_values(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'people.employee.read', AnchorType::COMPANY, null, FieldProfile::P2_SENSITIVE);
        $this->organization->knownLegalEntities = [1];

        app(Authorizer::class)->fields($this->actingAsUser($user), 'people.employee.read', $this->resourceRow());

        $this->assertNotEmpty($this->sensitiveAuditor->records);
        $record = $this->sensitiveAuditor->records[0];
        $this->assertContains('id_number', $record['fields']);
        $this->assertArrayNotHasKey('id_number', $record['fields']);
        $this->assertStringNotContainsString('110101199001011234', json_encode($record, JSON_THROW_ON_ERROR));
    }

    public function test_p3_values_are_never_exportable_or_filterable_even_when_visible(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'people.employee.read', AnchorType::COMPANY, null, FieldProfile::P3_RESTRICTED);
        $this->grant($user, 'reporting.export', AnchorType::COMPANY, null, FieldProfile::P3_RESTRICTED);
        $this->organization->knownLegalEntities = [1];

        $set = app(Authorizer::class)->fields($this->actingAsUser($user), 'people.employee.read', $this->resourceRow());
        $exportSet = app(Authorizer::class)->fields($this->actingAsUser($user), 'reporting.export', $this->resourceRow());

        $this->assertTrue($set->isVisible('salary'));
        $this->assertFalse($set->isExportable('salary'));
        $this->assertFalse($exportSet->isExportable('salary'));
        $this->assertNotContains('salary', $exportSet->exportable());
        $this->assertNotContains('health', $exportSet->exportable());
    }

    public function test_revealing_a_restricted_field_writes_an_audit_event(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'people.employee.read', AnchorType::COMPANY, null, FieldProfile::P3_RESTRICTED);
        $this->organization->knownLegalEntities = [1];

        app(Authorizer::class)->fields(
            $this->actingAsUser($user),
            'people.employee.read',
            $this->resourceRow(),
        );

        $this->assertNotEmpty($this->sensitiveAuditor->records);
        $this->assertContains('salary', $this->sensitiveAuditor->records[0]['fields']);
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
