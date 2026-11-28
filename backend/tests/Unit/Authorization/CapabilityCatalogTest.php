<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Authorization\RoleTemplateCatalog;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use PHPUnit\Framework\TestCase;

class CapabilityCatalogTest extends TestCase
{
    public function test_capability_codes_are_unique_and_cover_the_frozen_catalog(): void
    {
        $catalog = new CapabilityCatalog;
        $definitions = $catalog->all();

        $this->assertNotEmpty($definitions);
        $codes = array_map(static fn ($definition): string => $definition->code, $definitions);
        $this->assertSame(count($codes), count(array_unique($codes)));

        foreach ([
            'iam.user.read',
            'iam.user.manage',
            'iam.role.manage',
            'iam.binding.manage',
            'iam.audit.read',
            'org.unit.read',
            'org.unit.manage',
            'org.tree.read',
            'people.employee.read',
            'people.employee.manage',
            'people.assignment.manage',
            'people.contract.read',
            'people.contract.manage',
            'workflow.case.read',
            'workflow.case.create',
            'workflow.case.decide',
            'workflow.case.admin',
            'time.attendance.read',
            'time.attendance.manage',
            'time.leave.read',
            'time.leave.request',
            'time.leave.admin',
            'time.attendance.correct.request',
            'time.punch.import',
            'time.calendar.read',
            'time.shift.read',
            'time.leave.request.cancel',
            'time.leave.ledger.read',
            'payroll.run.read',
            'payroll.run.prepare',
            'payroll.run.review',
            'payroll.run.approve',
            'payroll.run.publish',
            'payroll.payslip.read',
            'payroll.payslip.read.self',
            'payroll.export',
            'talent.recruitment.manage',
            'talent.performance.manage',
            'talent.training.manage',
            'engagement.notice.read',
            'engagement.notice.manage',
            'reporting.dashboard.read',
            'reporting.export',
        ] as $required) {
            $this->assertTrue($catalog->has($required), "Missing capability {$required}.");
        }
    }

    public function test_every_definition_uses_known_anchors_and_profiles(): void
    {
        foreach ((new CapabilityCatalog)->all() as $definition) {
            $this->assertNotEmpty($definition->anchorTypes);
            foreach ($definition->anchorTypes as $anchorType) {
                $this->assertInstanceOf(AnchorType::class, $anchorType);
            }
            $this->assertInstanceOf(FieldProfile::class, $definition->fieldProfileMax);
            $this->assertContains($definition->risk, ['low', 'medium', 'high', 'critical']);
        }
    }

    public function test_exactly_fourteen_role_templates_exist_and_are_code_owned(): void
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
    }

    public function test_gap_completion_capabilities_are_issued_by_templates(): void
    {
        $issued = [];

        foreach (RoleTemplateCatalog::templates() as $template) {
            $codes = $template->capabilityCodes();
            // A wildcard template issues every catalogued capability by
            // definition; expand it so this assertion reflects real authority.
            if (in_array(\App\Shared\Authorization\CapabilityWildcard::WILDCARD, $codes, true)) {
                $codes = (new CapabilityCatalog)->codes();
            }
            $issued = [...$issued, ...$codes];
        }

        foreach ([
            'org.position.qualification.manage',
            'people.contract.terminate',
            'time.overtime.confirm',
            'time.attendance.reopen',
            'payroll.compensation.manage',
            'performance.result.publish',
            'recruitment.candidate.convert',
            'training.exam.manage',
            'system.backup.manage',
            'reporting.definition.manage',
        ] as $code) {
            $this->assertContains($code, $issued, "No role template issues {$code}.");
        }
    }

    public function test_gap_completion_catalog_reaches_the_documented_total(): void
    {
        $catalog = new CapabilityCatalog;

        $this->assertCount(171, $catalog->codes(), 'The V2.4 baseline catalog includes the V2.3 closure capabilities plus the release-report capability.');
    }

    public function test_no_template_grant_exceeds_its_capability_field_profile(): void
    {
        $catalog = new CapabilityCatalog;
        $violations = [];

        foreach (RoleTemplateCatalog::templates() as $template) {
            foreach ($template->grants as $grant) {
                if (\App\Shared\Authorization\CapabilityWildcard::is($grant->capabilityCode)) {
                    continue;
                }

                $definition = $catalog->find($grant->capabilityCode);

                if ($definition === null) {
                    continue;
                }

                if (! $grant->fieldProfile->isAtMost($definition->fieldProfileMax)) {
                    $violations[] = "{$template->code} grants {$grant->capabilityCode} at {$grant->fieldProfile->value}, above the {$definition->fieldProfileMax->value} maximum.";
                }
            }
        }

        $this->assertSame([], $violations, implode(' ', $violations));
    }

    public function test_no_template_grant_uses_an_anchor_the_capability_forbids(): void
    {
        $catalog = new CapabilityCatalog;
        $violations = [];

        foreach (RoleTemplateCatalog::templates() as $template) {
            foreach ($template->grants as $grant) {
                if (\App\Shared\Authorization\CapabilityWildcard::is($grant->capabilityCode)) {
                    continue;
                }

                $definition = $catalog->find($grant->capabilityCode);

                if ($definition === null) {
                    continue;
                }

                if (! in_array($grant->anchorType, $definition->anchorTypes, true)) {
                    $violations[] = "{$template->code} grants {$grant->capabilityCode} at {$grant->anchorType->value}, which the capability does not allow.";
                }
            }
        }

        $this->assertSame([], $violations, implode(' ', $violations));
    }

    public function test_system_admin_governance_capabilities_are_reachable_through_its_wildcard(): void
    {
        // ADR-0024: SYSTEM_ADMIN carries exactly one wildcard grant, so every
        // catalogued capability — including platform governance — is reachable
        // without an explicit per-code grant. This asserts reachability rather
        // than demanding grants that would contradict that ADR.
        $template = RoleTemplateCatalog::find('SYSTEM_ADMIN');
        $this->assertNotNull($template);
        $this->assertSame([\App\Shared\Authorization\CapabilityWildcard::WILDCARD], $template->capabilityCodes());

        $catalog = new CapabilityCatalog;

        foreach ([
            'time.attendance.device.read',
            'system.dictionary.manage',
            'system.parameter.manage',
            'system.backup.manage',
            'notification.template.manage',
            'notification.delivery.manage',
        ] as $code) {
            $this->assertTrue($catalog->has($code), "{$code} must be catalogued so the wildcard reaches it.");
        }
    }

    public function test_time_route_capabilities_are_issued_by_role_templates(): void
    {
        $codes = [];

        foreach (RoleTemplateCatalog::templates() as $template) {
            $codes = [...$codes, ...$template->capabilityCodes()];
        }

        foreach (['time.punch.import', 'time.attendance.correct.request', 'time.calendar.read', 'time.shift.read', 'time.leave.request.cancel', 'time.leave.ledger.read'] as $code) {
            $this->assertContains($code, $codes, "No role template issues {$code}.");
        }
    }
}
