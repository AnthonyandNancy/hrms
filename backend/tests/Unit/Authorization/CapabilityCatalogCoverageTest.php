<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Authorization\RoleTemplateCatalog;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Authorization\FieldProfile;
use PHPUnit\Framework\TestCase;

final class CapabilityCatalogCoverageTest extends TestCase
{
    public function test_every_capability_referenced_by_the_blueprint_is_catalogued(): void
    {
        $blueprint = file_get_contents(dirname(__DIR__, 3).'/../docs/audit/2026-11-25-v11-page-access-blueprint.md');
        $this->assertIsString($blueprint);

        preg_match_all(
            '/`((?:iam|org|people|workflow|lifecycle|time|payroll|performance|recruitment|training|engagement|reporting|benefit|notification|system)\.[a-z_.]+)`/',
            $blueprint,
            $matches,
        );

        $catalog = new CapabilityCatalog;
        $missing = [];

        foreach (array_unique($matches[1]) as $code) {
            if (! $catalog->has($code)) {
                $missing[] = $code;
            }
        }

        $this->assertSame([], $missing, 'Blueprint capabilities missing from CapabilityCatalog: '.implode(', ', $missing));
    }

    public function test_every_section_3a_capability_is_catalogued(): void
    {
        $required = [
            'org.position.read',
            'org.position.manage',
            'org.position.export',
            'time.overtime.read',
            'time.overtime.request',
            'time.overtime.manage',
            'time.overtime.settle',
            'time.travel.read',
            'time.travel.request',
            'time.travel.manage',
            'time.travel.expense',
            'performance.calibration.manage',
            'performance.report.read',
            'iam.menu.manage',
            'iam.permission.read',
            'iam.scope.manage',
            'iam.field.manage',
            'workflow.template.manage',
            'benefit.read',
            'benefit.manage',
            'time.calendar.manage',
            'system.settings.manage',
            'notification.read',
            'notification.manage',
            'engagement.notice.publish',
            'engagement.notice.export',
            'people.employee.export',
            'people.contract.export',
            'people.document.download',
            'org.unit.export',
            'recruitment.requisition.export',
            'reporting.report.read',
            'reporting.report.create',
            'reporting.subscription.manage',
        ];

        $catalog = new CapabilityCatalog;

        foreach ($required as $code) {
            $this->assertTrue($catalog->has($code), "Missing section 3A capability {$code}.");
        }
    }

    public function test_capability_codes_are_unique_and_have_complete_definition_metadata(): void
    {
        $catalog = new CapabilityCatalog;
        $codes = $catalog->codes();

        $this->assertSame(count($codes), count(array_unique($codes)), 'Capability codes must be unique.');

        foreach ($catalog->all() as $definition) {
            $this->assertNotSame('', $definition->code);
            // Segments are lower-case and may contain an internal hyphen
            // (e.g. payroll.salary-adjustment.manage) but never start/end with one.
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*(\.[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?)+$/', $definition->code);
            $this->assertNotSame('', $definition->resource);
            $this->assertNotEmpty($definition->actions);
            $this->assertSame(array_values(array_unique($definition->actions)), $definition->actions);
            $this->assertNotEmpty($definition->anchorTypes);

            foreach ($definition->anchorTypes as $anchorType) {
                $this->assertInstanceOf(AnchorType::class, $anchorType);
            }

            $this->assertInstanceOf(FieldProfile::class, $definition->fieldProfileMax);
            $this->assertContains($definition->risk, ['low', 'medium', 'high', 'critical']);
            $this->assertNotSame('', $definition->description);
        }
    }

    public function test_exactly_fourteen_role_templates_exist_and_each_grant_names_its_template(): void
    {
        $templates = RoleTemplateCatalog::templates();

        $this->assertCount(14, $templates);
        $this->assertSame([
            'SUPER_ADMIN',
            'SYSTEM_ADMIN',
            'HR_ADMIN',
            'HR_SPECIALIST',
            'RECRUITER',
            'PAYROLL_SPECIALIST',
            'PAYROLL_APPROVER',
            'ATTENDANCE_SPECIALIST',
            'LINE_MANAGER',
            'EMPLOYEE',
            'AUDITOR',
            'TRAINING_ADMIN',
            'BENEFITS_ADMIN',
            'REPORT_VIEWER',
        ], array_map(static fn ($template): string => $template->code, $templates));

        $catalog = new CapabilityCatalog;

        foreach ($templates as $template) {
            foreach ($template->grants as $grant) {
                $this->assertSame($template->code, $grant->roleCode);
                $this->assertTrue(
                    CapabilityWildcard::is($grant->capabilityCode) || $catalog->has($grant->capabilityCode),
                    "{$template->code} grants unknown capability {$grant->capabilityCode}.",
                );
            }
        }
    }

    public function test_super_and_system_admin_hold_wildcard_with_p3_profile(): void
    {
        foreach (['SUPER_ADMIN', 'SYSTEM_ADMIN'] as $roleCode) {
            $template = RoleTemplateCatalog::find($roleCode);

            $this->assertNotNull($template);
            $this->assertSame([CapabilityWildcard::WILDCARD], $template->capabilityCodes());
            $this->assertCount(1, $template->grants);
            $this->assertSame(FieldProfile::P3_RESTRICTED, $template->grants[0]->fieldProfile);
            $this->assertSame(AnchorType::LEGAL_ENTITY, $template->grants[0]->anchorType);
        }
    }

    public function test_hr_admin_has_no_payroll_run_or_payroll_export_capability(): void
    {
        $template = RoleTemplateCatalog::find('HR_ADMIN');
        $this->assertNotNull($template);

        $payrollRunCodes = array_values(array_filter(
            $template->capabilityCodes(),
            static fn (string $code): bool => str_starts_with($code, 'payroll.run.'),
        ));

        $this->assertSame([], $payrollRunCodes, 'HR_ADMIN must not receive payroll.run.* capabilities.');
        $this->assertNotContains('payroll.export', $template->capabilityCodes());
    }

    public function test_security_sod_risk_accepted_is_an_audit_action_not_a_capability(): void
    {
        $catalog = new CapabilityCatalog;

        $this->assertFalse(
            $catalog->has('security.sod_risk_accepted'),
            'security.sod_risk_accepted is an audit event action, not a grantable capability.',
        );
    }
}
