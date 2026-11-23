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

    public function test_exactly_ten_role_templates_exist_and_are_code_owned(): void
    {
        $templates = RoleTemplateCatalog::templates();

        $this->assertCount(10, $templates);
        $this->assertSame([
            'SUPER_ADMIN',
            'SYSTEM_ADMIN',
            'HR_ADMIN',
            'HR_SPECIALIST',
            'RECRUITER',
            'PAYROLL_SPECIALIST',
            'ATTENDANCE_SPECIALIST',
            'LINE_MANAGER',
            'EMPLOYEE',
            'AUDITOR',
        ], array_map(static fn ($template): string => $template->code, $templates));
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
