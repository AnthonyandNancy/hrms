<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;

/**
 * Ten code-owned role templates. Each template expands to authority bindings
 * only; assigning a role never creates a direct capability bypass.
 */
final class RoleTemplateCatalog
{
    /** @var list<RoleTemplate>|null */
    private static ?array $templates = null;

    /**
     * @return list<RoleTemplate>
     */
    public static function templates(): array
    {
        return self::$templates ??= self::build();
    }

    private const ROLE_CODE_PATTERN = '/^[A-Z][A-Z0-9_]{2,63}$/';

    public static function validateCode(string $code): bool
    {
        if (preg_match(self::ROLE_CODE_PATTERN, $code) !== 1) {
            return false;
        }

        // Codes are structural role names. Person and department names are
        // data, never role identifiers; the shape check rejects free text.
        return ! str_contains($code, ' ');
    }

    public static function find(string $code): ?RoleTemplate
    {
        foreach (self::templates() as $template) {
            if ($template->code === $code) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @return list<RoleTemplate>
     */
    private static function build(): array
    {
        $le = AnchorType::LEGAL_ENTITY;
        $ou = AnchorType::ORG_UNIT;
        $sub = AnchorType::ORG_SUBTREE;
        $self = AnchorType::SELF;
        $managed = AnchorType::MANAGED;
        $case = AnchorType::CASE;
        $run = AnchorType::PAYROLL_RUN;

        $grant = static fn (string $role, string $capability, AnchorType $anchor, FieldProfile $profile): RoleGrant => new RoleGrant($role, $capability, $anchor, $profile);

        $templates = [
            new RoleTemplate('SUPER_ADMIN', 'Super administrator (time-boxed)', 'Emergency administration; assignments must be time-boxed and reviewed.', [
                $grant('SUPER_ADMIN', 'iam.user.read', $le, FieldProfile::P1_INTERNAL),
                $grant('SUPER_ADMIN', 'iam.user.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('SUPER_ADMIN', 'iam.role.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('SUPER_ADMIN', 'iam.binding.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('SUPER_ADMIN', 'org.unit.read', $le, FieldProfile::P1_INTERNAL),
                $grant('SUPER_ADMIN', 'org.unit.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('SUPER_ADMIN', 'org.tree.read', $le, FieldProfile::P0_PUBLIC),
                $grant('SUPER_ADMIN', 'people.employee.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('SUPER_ADMIN', 'people.employee.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('SUPER_ADMIN', 'people.assignment.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('SUPER_ADMIN', 'reporting.dashboard.read', $le, FieldProfile::P1_INTERNAL),
            ]),
            new RoleTemplate('SYSTEM_ADMIN', 'System administrator', 'Operate accounts, roles and organization metadata without payroll or audit.', [
                $grant('SYSTEM_ADMIN', 'iam.user.read', $le, FieldProfile::P1_INTERNAL),
                $grant('SYSTEM_ADMIN', 'iam.user.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('SYSTEM_ADMIN', 'iam.role.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('SYSTEM_ADMIN', 'iam.binding.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('SYSTEM_ADMIN', 'org.unit.read', $le, FieldProfile::P1_INTERNAL),
                $grant('SYSTEM_ADMIN', 'org.unit.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('SYSTEM_ADMIN', 'org.tree.read', $le, FieldProfile::P0_PUBLIC),
                $grant('SYSTEM_ADMIN', 'people.employee.read', $le, FieldProfile::P1_INTERNAL),
                $grant('SYSTEM_ADMIN', 'reporting.dashboard.read', $le, FieldProfile::P1_INTERNAL),
            ]),
            new RoleTemplate('HR_ADMIN', 'HR administrator', 'Own people data, organization structure and HR policy execution.', [
                $grant('HR_ADMIN', 'people.employee.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'people.employee.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'people.assignment.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'people.contract.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'people.contract.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'org.unit.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'org.unit.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'org.tree.read', $le, FieldProfile::P0_PUBLIC),
                $grant('HR_ADMIN', 'workflow.case.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'workflow.case.create', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'iam.user.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'iam.user.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'lifecycle.case.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'recruitment.requisition.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'recruitment.requisition.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'recruitment.candidate.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'recruitment.candidate.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'recruitment.interview.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'recruitment.offer.manage', $le, FieldProfile::P3_RESTRICTED),
                $grant('HR_ADMIN', 'recruitment.offer.accept', $le, FieldProfile::P3_RESTRICTED),
                $grant('HR_ADMIN', 'performance.cycle.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'performance.cycle.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'performance.review.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'performance.review.submit', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'performance.appeal.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'training.program.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'training.program.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'training.session.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'training.enrollment.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'training.completion.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'training.report.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'lifecycle.onboarding.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'lifecycle.probation.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'lifecycle.transfer.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'lifecycle.terms.manage', $le, FieldProfile::P3_RESTRICTED),
                $grant('HR_ADMIN', 'lifecycle.offboard.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'lifecycle.contract.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'lifecycle.rehire.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'lifecycle.checklist.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'reporting.dashboard.read', $le, FieldProfile::P1_INTERNAL),
            ]),
            new RoleTemplate('HR_SPECIALIST', 'HR specialist', 'Day-to-day employee, assignment and contract maintenance.', [
                $grant('HR_SPECIALIST', 'people.employee.read', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'people.employee.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'people.assignment.manage', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'people.contract.read', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'people.contract.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'org.unit.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'org.tree.read', $ou, FieldProfile::P0_PUBLIC),
                $grant('HR_SPECIALIST', 'workflow.case.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'workflow.case.create', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'lifecycle.case.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'lifecycle.onboarding.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'lifecycle.probation.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'lifecycle.transfer.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'lifecycle.terms.manage', $ou, FieldProfile::P3_RESTRICTED),
                $grant('HR_SPECIALIST', 'lifecycle.offboard.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'lifecycle.contract.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'lifecycle.rehire.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'lifecycle.checklist.manage', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'time.leave.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'time.attendance.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'reporting.dashboard.read', $ou, FieldProfile::P1_INTERNAL),
            ]),
            new RoleTemplate('RECRUITER', 'Recruiter', 'Recruitment pipeline and candidate case work.', [
                $grant('RECRUITER', 'talent.recruitment.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('RECRUITER', 'recruitment.requisition.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('RECRUITER', 'recruitment.requisition.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('RECRUITER', 'recruitment.candidate.read', $ou, FieldProfile::P2_SENSITIVE),
                $grant('RECRUITER', 'recruitment.candidate.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('RECRUITER', 'recruitment.interview.manage', $ou, FieldProfile::P1_INTERNAL),
                $grant('RECRUITER', 'recruitment.offer.manage', $ou, FieldProfile::P3_RESTRICTED),
                $grant('RECRUITER', 'recruitment.offer.accept', $ou, FieldProfile::P3_RESTRICTED),
                $grant('RECRUITER', 'lifecycle.case.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('RECRUITER', 'lifecycle.onboarding.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('RECRUITER', 'workflow.case.read', $case, FieldProfile::P1_INTERNAL),
                $grant('RECRUITER', 'workflow.case.create', $ou, FieldProfile::P1_INTERNAL),
                $grant('RECRUITER', 'people.employee.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('RECRUITER', 'org.tree.read', $ou, FieldProfile::P0_PUBLIC),
                $grant('RECRUITER', 'reporting.dashboard.read', $ou, FieldProfile::P1_INTERNAL),
            ]),
            new RoleTemplate('PAYROLL_SPECIALIST', 'Payroll specialist', 'Prepare payroll runs and read payroll inputs; approval and publication are separate.', [
                $grant('PAYROLL_SPECIALIST', 'payroll.run.read', $run, FieldProfile::P2_SENSITIVE),
                $grant('PAYROLL_SPECIALIST', 'payroll.run.prepare', $run, FieldProfile::P2_SENSITIVE),
                $grant('PAYROLL_SPECIALIST', 'payroll.payslip.read', $run, FieldProfile::P3_RESTRICTED),
                $grant('PAYROLL_SPECIALIST', 'people.contract.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('PAYROLL_SPECIALIST', 'people.employee.read', $le, FieldProfile::P1_INTERNAL),
                $grant('PAYROLL_SPECIALIST', 'reporting.dashboard.read', $le, FieldProfile::P1_INTERNAL),
            ]),
            new RoleTemplate('ATTENDANCE_SPECIALIST', 'Attendance specialist', 'Attendance correction and leave account administration.', [
                $grant('ATTENDANCE_SPECIALIST', 'time.attendance.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.attendance.manage', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.leave.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.leave.admin', $ou, FieldProfile::P2_SENSITIVE),
                $grant('ATTENDANCE_SPECIALIST', 'people.employee.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'org.tree.read', $ou, FieldProfile::P0_PUBLIC),
                $grant('ATTENDANCE_SPECIALIST', 'reporting.dashboard.read', $ou, FieldProfile::P1_INTERNAL),
            ]),
            new RoleTemplate('LINE_MANAGER', 'Line manager', 'Team approvals, attendance visibility and team dashboards.', [
                $grant('LINE_MANAGER', 'workflow.case.read', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'workflow.case.decide', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'performance.cycle.read', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'performance.review.manage', $managed, FieldProfile::P2_SENSITIVE),
                $grant('LINE_MANAGER', 'performance.review.submit', $managed, FieldProfile::P2_SENSITIVE),
                $grant('LINE_MANAGER', 'training.report.read', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'time.attendance.read', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'time.leave.read', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'people.employee.read', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'lifecycle.case.read', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'lifecycle.probation.manage', $managed, FieldProfile::P2_SENSITIVE),
                $grant('LINE_MANAGER', 'lifecycle.transfer.manage', $managed, FieldProfile::P2_SENSITIVE),
                $grant('LINE_MANAGER', 'org.tree.read', $managed, FieldProfile::P0_PUBLIC),
                $grant('LINE_MANAGER', 'reporting.dashboard.read', $managed, FieldProfile::P1_INTERNAL),
            ]),
            new RoleTemplate('EMPLOYEE', 'Employee self-service', 'Own profile, leave, attendance, payslips and notices.', [
                $grant('EMPLOYEE', 'people.employee.read', $self, FieldProfile::P1_INTERNAL),
                $grant('EMPLOYEE', 'people.contract.read', $self, FieldProfile::P1_INTERNAL),
                $grant('EMPLOYEE', 'performance.cycle.read', $self, FieldProfile::P1_INTERNAL),
                $grant('EMPLOYEE', 'performance.appeal.manage', $self, FieldProfile::P2_SENSITIVE),
                $grant('EMPLOYEE', 'training.program.read', $self, FieldProfile::P1_INTERNAL),
                $grant('EMPLOYEE', 'time.attendance.read', $self, FieldProfile::P1_INTERNAL),
                $grant('EMPLOYEE', 'time.leave.read', $self, FieldProfile::P1_INTERNAL),
                $grant('EMPLOYEE', 'time.leave.request', $self, FieldProfile::P0_PUBLIC),
                $grant('EMPLOYEE', 'payroll.payslip.read.self', $self, FieldProfile::P3_RESTRICTED),
                $grant('EMPLOYEE', 'engagement.notice.read', $self, FieldProfile::P1_INTERNAL),
                $grant('EMPLOYEE', 'org.tree.read', $self, FieldProfile::P0_PUBLIC),
                $grant('EMPLOYEE', 'reporting.dashboard.read', $self, FieldProfile::P1_INTERNAL),
            ]),
            new RoleTemplate('AUDITOR', 'Auditor', 'Read-only audit evidence and organization context; cannot manage grants.', [
                $grant('AUDITOR', 'iam.audit.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('AUDITOR', 'iam.user.read', $le, FieldProfile::P1_INTERNAL),
                $grant('AUDITOR', 'org.tree.read', $le, FieldProfile::P0_PUBLIC),
                $grant('AUDITOR', 'reporting.dashboard.read', $le, FieldProfile::P1_INTERNAL),
            ]),
        ];

        foreach ($templates as $template) {
            array_map(static function (RoleGrant $grant) use ($template): void {
                if ($grant->roleCode !== $template->code) {
                    throw new \LogicException("Role grant {$grant->capabilityCode} is attached to the wrong template.");
                }
            }, $template->grants);
        }

        return $templates;
    }
}
