<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Auth\CapabilityAuthorizer;
use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Authorization\ResourceRef;

final class SuperuserFullAuthorityTest extends AuthorizationTestSupport
{
    public function test_a_superuser_wildcard_permits_every_catalogued_capability_including_payroll(): void
    {
        $user = User::factory()->create(['auth_version' => 1, 'is_privileged' => true]);
        $this->organization->knownLegalEntities = [1];
        $this->grantWildcard($user);

        $authorizer = app(CapabilityAuthorizer::class);
        $principal = $this->actingAsUser($user);
        $date = '2026-11-23';

        foreach ((new CapabilityCatalog)->codes() as $code) {
            $this->assertTrue($authorizer->permits($principal, $code, $date), "Wildcard must permit {$code}.");
        }

        $this->assertTrue($authorizer->allows(
            $principal,
            'payroll.run.read',
            \App\Shared\Authorization\AnchorRef::legalEntity(1),
            $date,
        ));
    }

    public function test_p3_payroll_read_is_granted_and_writes_sensitive_access_audit_evidence(): void
    {
        $user = User::factory()->create(['auth_version' => 1, 'is_privileged' => true]);
        $this->organization->knownLegalEntities = [1];
        $this->people->employmentByUser[(int) $user->id] = [501];
        $this->grantWildcard($user);

        $set = app(CapabilityAuthorizer::class)->fields(
            $this->actingAsUser($user),
            'payroll.payslip.read',
            new ResourceRef(
                resourceType: 'payslip',
                id: 9001,
                row: ['gross' => '100000', 'net' => '80000'],
                tenantId: 1,
                employmentId: 501,
                legalEntityId: 1,
                payrollRunId: 77,
            ),
        );

        $this->assertTrue($set->isVisible('gross'), 'A P3 wildcard holder must see payroll amounts.');
        $this->assertTrue($set->isVisible('net'));

        $this->assertNotEmpty($this->sensitiveAuditor->records, 'P3 reads must leave audit evidence.');
        $record = $this->sensitiveAuditor->records[0];
        $this->assertSame('payroll.payslip.read', $record['capability']);
        $this->assertContains('gross', $record['fields']);
        $this->assertContains('net', $record['fields']);
        $this->assertStringNotContainsString('100000', json_encode($record, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('80000', json_encode($record, JSON_THROW_ON_ERROR));
    }

    public function test_p3_payroll_export_capability_is_granted_and_export_scope_is_audited(): void
    {
        $user = User::factory()->create(['auth_version' => 1, 'is_privileged' => true]);
        $this->organization->knownLegalEntities = [1];
        $this->people->employmentByUser[(int) $user->id] = [501];
        $this->grantWildcard($user);

        $authorizer = app(CapabilityAuthorizer::class);
        $principal = $this->actingAsUser($user);
        $date = '2026-11-23';

        $this->assertTrue($authorizer->permits($principal, 'payroll.export', $date));

        $set = $authorizer->fields($principal, 'payroll.export', new ResourceRef(
            resourceType: 'payslip',
            id: 9001,
            row: ['gross' => '100000'],
            tenantId: 1,
            employmentId: 501,
            legalEntityId: 1,
            payrollRunId: 77,
        ));

        $this->assertFalse($set->isExportable('gross'), 'P3 itself remains non-exportable; a separate export capability governs the job.');
        $this->assertNotEmpty($this->sensitiveAuditor->records);
        $this->assertSame('payroll.export', $this->sensitiveAuditor->records[0]['capability']);
    }

    public function test_hr_admin_stays_without_payroll_authority(): void
    {
        $catalog = new CapabilityCatalog;
        $hr = \App\Modules\IAM\Authorization\RoleTemplateCatalog::find('HR_ADMIN');

        $this->assertNotNull($hr);

        foreach ($hr->capabilityCodes() as $code) {
            $definition = $catalog->find($code);
            $this->assertNotNull($definition);

            if (str_starts_with($code, 'payroll.')) {
                $this->fail("HR_ADMIN must not grant {$code}.");
            }
        }
    }

    private function grantWildcard(User $user): AuthorityBinding
    {
        return AuthorityBinding::factory()
            ->forPrincipal(1, (int) $user->id, (int) $user->auth_version)
            ->anchor(AnchorType::LEGAL_ENTITY, 1, FieldProfile::P3_RESTRICTED)
            ->create(['capability_code' => CapabilityWildcard::WILDCARD]);
    }
}
