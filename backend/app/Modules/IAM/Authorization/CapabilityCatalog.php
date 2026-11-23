<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;

/**
 * The complete V1 capability catalog. Codes are frozen product contracts:
 * adding or removing one requires a code review, a negative test, audit and
 * UI metadata. Never add a database-backed capability editor.
 */
final class CapabilityCatalog
{
    /** @var list<CapabilityDefinition>|null */
    private ?array $definitions = null;

    /**
     * @return list<CapabilityDefinition>
     */
    public function all(): array
    {
        return $this->definitions ??= $this->build();
    }

    public function has(string $code): bool
    {
        return $this->find($code) !== null;
    }

    public function find(string $code): ?CapabilityDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->code === $code) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map(static fn (CapabilityDefinition $definition): string => $definition->code, $this->all());
    }

    /**
     * @return list<CapabilityDefinition>
     */
    private function build(): array
    {
        $allFields = [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::CASE, AnchorType::PAYROLL_RUN, AnchorType::LEGAL_ENTITY];

        return [
            new CapabilityDefinition('iam.user.read', 'user', ['read'], [AnchorType::SELF, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'high', 'identity_governance', 'Read user accounts within an anchor.'),
            new CapabilityDefinition('iam.user.manage', 'user', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'critical', 'identity_governance', 'Create and update user accounts.'),
            new CapabilityDefinition('iam.role.manage', 'role', ['read', 'create', 'update'], [AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'critical', 'identity_governance', 'Manage role assignments and templates.'),
            new CapabilityDefinition('iam.binding.manage', 'authority_binding', ['read', 'create', 'delete'], [AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'critical', 'identity_governance', 'Manage authority bindings.'),
            new CapabilityDefinition('iam.audit.read', 'auth_event', ['read', 'export'], [AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', 'identity_audit', 'Read authentication and authorization audit evidence.'),

            new CapabilityDefinition('org.unit.read', 'org_unit', ['read'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read organization units.'),
            new CapabilityDefinition('org.unit.manage', 'org_unit', ['create', 'update', 'delete'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'high', null, 'Manage organization units.'),
            new CapabilityDefinition('org.tree.read', 'org_tree', ['read'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P0_PUBLIC, 'low', null, 'Read the organization tree.'),

            new CapabilityDefinition('people.employee.read', 'employee', ['read', 'export'], $allFields, FieldProfile::P2_SENSITIVE, 'high', null, 'Read employee records; fields are projected per binding.'),
            new CapabilityDefinition('people.employee.manage', 'employee', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', 'people_manage', 'Create and update employee records.'),
            new CapabilityDefinition('people.assignment.manage', 'assignment', ['create', 'update', 'end'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'high', null, 'Manage effective-dated assignments.'),
            new CapabilityDefinition('people.contract.read', 'contract', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', null, 'Read employment contracts.'),
            new CapabilityDefinition('people.contract.manage', 'contract', ['create', 'update', 'terminate'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', null, 'Manage employment contracts.'),

            new CapabilityDefinition('workflow.case.read', 'case', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::CASE, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read workflow cases visible through a membership anchor.'),
            new CapabilityDefinition('workflow.case.create', 'case', ['create'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Create a workflow case.'),
            new CapabilityDefinition('workflow.case.decide', 'case', ['decide'], [AnchorType::CASE, AnchorType::MANAGED], FieldProfile::P1_INTERNAL, 'high', null, 'Record a decision on an assigned case.'),
            new CapabilityDefinition('workflow.case.admin', 'case', ['admin', 'reassign'], [AnchorType::CASE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'critical', null, 'Administer workflow cases.'),

            new CapabilityDefinition('time.attendance.read', 'attendance_day', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read attendance days.'),
            new CapabilityDefinition('time.attendance.manage', 'attendance_day', ['create', 'update', 'correct'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'high', null, 'Manage and correct attendance.'),
            new CapabilityDefinition('time.leave.read', 'leave_account', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read leave balances and records.'),
            new CapabilityDefinition('time.leave.request', 'leave_request', ['create', 'withdraw'], [AnchorType::SELF], FieldProfile::P0_PUBLIC, 'low', null, 'Submit or withdraw own leave requests.'),
            new CapabilityDefinition('time.leave.admin', 'leave_account', ['grant', 'adjust', 'admin'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', null, 'Administer leave accounts and entries.'),

            new CapabilityDefinition('payroll.run.read', 'payroll_run', ['read'], [AnchorType::PAYROLL_RUN, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', 'payroll_prepare', 'Read payroll run metadata and inputs.'),
            new CapabilityDefinition('payroll.run.prepare', 'payroll_run', ['prepare'], [AnchorType::PAYROLL_RUN, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_prepare', 'Prepare payroll runs and inputs.'),
            new CapabilityDefinition('payroll.run.review', 'payroll_run', ['review'], [AnchorType::PAYROLL_RUN, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_review', 'Review calculated payroll runs.'),
            new CapabilityDefinition('payroll.run.approve', 'payroll_run', ['approve'], [AnchorType::PAYROLL_RUN, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_approve', 'Approve reviewed payroll runs.'),
            new CapabilityDefinition('payroll.run.publish', 'payroll_run', ['publish'], [AnchorType::PAYROLL_RUN, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_publish', 'Publish approved payroll runs.'),
            new CapabilityDefinition('payroll.payslip.read', 'payslip', ['read'], [AnchorType::SELF, AnchorType::PAYROLL_RUN, AnchorType::LEGAL_ENTITY], FieldProfile::P3_RESTRICTED, 'high', 'payroll_prepare', 'Read payslips; SELF resolves to the caller employment.'),
            new CapabilityDefinition('payroll.payslip.read.self', 'payslip', ['read'], [AnchorType::SELF], FieldProfile::P3_RESTRICTED, 'medium', null, 'Read own payslips.'),
            new CapabilityDefinition('payroll.export', 'payroll_run', ['export'], [AnchorType::PAYROLL_RUN, AnchorType::LEGAL_ENTITY], FieldProfile::P3_RESTRICTED, 'critical', 'payroll_publish', 'Export payroll data with an explicit export capability.'),

            new CapabilityDefinition('talent.recruitment.manage', 'recruitment', ['create', 'update', 'admin'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::CASE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', null, 'Manage recruitment requisitions and candidates.'),
            new CapabilityDefinition('talent.performance.manage', 'performance', ['create', 'update', 'admin'], [AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', null, 'Manage performance cycles and reviews.'),
            new CapabilityDefinition('talent.training.manage', 'training', ['create', 'update', 'admin'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Manage training catalogues and attendance.'),

            new CapabilityDefinition('engagement.notice.read', 'notice', ['read'], [AnchorType::SELF, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'low', null, 'Read engagement notices.'),
            new CapabilityDefinition('engagement.notice.manage', 'notice', ['create', 'update', 'publish'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Manage engagement notices.'),

            new CapabilityDefinition('reporting.dashboard.read', 'dashboard', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read role-scoped dashboards.'),
            new CapabilityDefinition('reporting.export', 'report', ['export'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', null, 'Export reports with a field whitelist and row cap.'),
        ];
    }
}
