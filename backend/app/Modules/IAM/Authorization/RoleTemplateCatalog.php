<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Authorization\FieldProfile;

/**
 * Fourteen code-owned role templates. Each template expands to authority
 * bindings only; assigning a role never creates a direct capability bypass.
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
        $le = AnchorType::COMPANY;
        $ou = AnchorType::ORG_UNIT;
        $sub = AnchorType::ORG_SUBTREE;
        $self = AnchorType::SELF;
        $managed = AnchorType::MANAGED;
        $case = AnchorType::CASE;
        $run = AnchorType::PAYROLL_RUN;

        $grant = static fn (string $role, string $capability, AnchorType $anchor, FieldProfile $profile): RoleGrant => new RoleGrant($role, $capability, $anchor, $profile);

        $templates = [
            new RoleTemplate('SUPER_ADMIN', 'Super administrator', 'Full authority inside the legal entity, time-boxed and reviewed.', [
                $grant('SUPER_ADMIN', CapabilityWildcard::WILDCARD, $le, FieldProfile::P3_RESTRICTED),
            ]),
            new RoleTemplate('SYSTEM_ADMIN', 'System administrator', 'Full authority inside the legal entity, renewed annually and reviewed quarterly.', [
                $grant('SYSTEM_ADMIN', CapabilityWildcard::WILDCARD, $le, FieldProfile::P3_RESTRICTED),
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
                $grant('HR_ADMIN', 'org.position.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'org.position.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'org.position.export', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'org.unit.export', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'people.employee.export', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'people.contract.export', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'people.document.download', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'workflow.template.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'iam.permission.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'iam.menu.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'iam.scope.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'iam.field.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'time.calendar.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'time.overtime.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'time.overtime.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'time.travel.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'time.travel.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'time.travel.expense', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'performance.calibration.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'performance.report.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'recruitment.requisition.export', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'engagement.notice.publish', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'engagement.notice.export', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'notification.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'notification.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'benefit.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'benefit.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'reporting.report.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'reporting.report.create', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'reporting.subscription.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'reporting.export.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'system.settings.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'workflow.case.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'workflow.case.create', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'time.attendance.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'time.attendance.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'time.punch.import', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'time.attendance.correct.request', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'time.leave.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'time.leave.ledger.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'time.calendar.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'time.shift.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'iam.user.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'iam.user.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'lifecycle.case.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'recruitment.requisition.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'recruitment.requisition.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'recruitment.board.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'recruitment.budget.read', $le, FieldProfile::P1_INTERNAL),
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
                $grant('HR_ADMIN', 'engagement.notice.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'engagement.notice.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'reporting.export', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'org.position.qualification.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'org.position.grade.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'org.position.staff.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'lifecycle.secondment.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'people.document.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'people.contract.template.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'people.contract.amend', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'people.contract.terminate', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'time.attendance.rule.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'time.timeoff.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'time.travel.policy.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'time.travel.policy.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'time.travel.approve', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'time.travel.expense.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'performance.indicator.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'performance.goal.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'performance.feedback.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'performance.result.publish', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'recruitment.candidate.import', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'recruitment.talent-pool.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'recruitment.candidate.convert', $le, FieldProfile::P2_SENSITIVE),
                $grant('HR_ADMIN', 'training.plan.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'training.record.read', $le, FieldProfile::P1_INTERNAL),
                $grant('HR_ADMIN', 'reporting.definition.manage', $le, FieldProfile::P1_INTERNAL),
            ]),
            new RoleTemplate('HR_SPECIALIST', 'HR specialist', 'Day-to-day employee, assignment and contract maintenance.', [
                $grant('HR_SPECIALIST', 'people.employee.read', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'people.employee.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'people.assignment.manage', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'people.contract.read', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'people.contract.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'org.unit.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'org.tree.read', $ou, FieldProfile::P0_PUBLIC),
                $grant('HR_SPECIALIST', 'org.position.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'org.position.manage', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'people.employee.export', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'people.contract.export', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'people.document.download', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'time.overtime.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'time.overtime.manage', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'time.travel.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'time.travel.manage', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'time.travel.expense', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'notification.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'reporting.report.read', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'reporting.report.create', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'reporting.subscription.manage', $ou, FieldProfile::P1_INTERNAL),
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
                $grant('HR_SPECIALIST', 'time.leave.ledger.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'time.calendar.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'time.shift.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'reporting.dashboard.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'org.position.qualification.manage', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'org.position.staff.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('HR_SPECIALIST', 'lifecycle.secondment.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'people.document.read', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'people.contract.amend', $ou, FieldProfile::P2_SENSITIVE),
                $grant('HR_SPECIALIST', 'time.travel.expense.manage', $ou, FieldProfile::P2_SENSITIVE),
            ]),
            new RoleTemplate('RECRUITER', 'Recruiter', 'Recruitment pipeline and candidate case work.', [
                $grant('RECRUITER', 'talent.recruitment.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('RECRUITER', 'recruitment.requisition.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('RECRUITER', 'recruitment.requisition.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('RECRUITER', 'recruitment.board.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('RECRUITER', 'recruitment.budget.read', $ou, FieldProfile::P1_INTERNAL),
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
                $grant('RECRUITER', 'recruitment.requisition.export', $ou, FieldProfile::P1_INTERNAL),
                $grant('RECRUITER', 'people.document.download', $ou, FieldProfile::P2_SENSITIVE),
                $grant('RECRUITER', 'notification.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('RECRUITER', 'reporting.report.read', $ou, FieldProfile::P2_SENSITIVE),
                $grant('RECRUITER', 'reporting.dashboard.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('RECRUITER', 'recruitment.candidate.import', $ou, FieldProfile::P2_SENSITIVE),
                $grant('RECRUITER', 'recruitment.evaluation.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('RECRUITER', 'recruitment.communication.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('RECRUITER', 'recruitment.talent-pool.manage', $ou, FieldProfile::P2_SENSITIVE),
                $grant('RECRUITER', 'recruitment.candidate.convert', $ou, FieldProfile::P2_SENSITIVE),
            ]),
            new RoleTemplate('PAYROLL_SPECIALIST', 'Payroll specialist', 'Prepare payroll runs and read payroll inputs; approval and publication are separate.', [
                $grant('PAYROLL_SPECIALIST', 'payroll.run.read', $run, FieldProfile::P2_SENSITIVE),
                $grant('PAYROLL_SPECIALIST', 'payroll.run.prepare', $run, FieldProfile::P2_SENSITIVE),
                $grant('PAYROLL_SPECIALIST', 'payroll.payslip.read', $run, FieldProfile::P3_RESTRICTED),
                $grant('PAYROLL_SPECIALIST', 'payroll.export', $run, FieldProfile::P3_RESTRICTED),
                $grant('PAYROLL_SPECIALIST', 'payroll.parameters.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('PAYROLL_SPECIALIST', 'payroll.run.correct', $run, FieldProfile::P2_SENSITIVE),
                $grant('PAYROLL_SPECIALIST', 'people.contract.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('PAYROLL_SPECIALIST', 'people.employee.read', $le, FieldProfile::P1_INTERNAL),
                $grant('PAYROLL_SPECIALIST', 'reporting.dashboard.read', $le, FieldProfile::P1_INTERNAL),
                $grant('PAYROLL_SPECIALIST', 'reporting.report.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('PAYROLL_SPECIALIST', 'notification.read', $le, FieldProfile::P1_INTERNAL),
                $grant('PAYROLL_SPECIALIST', 'payroll.compensation.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('PAYROLL_SPECIALIST', 'payroll.compensation.manage', $le, FieldProfile::P2_SENSITIVE),
            ]),
            new RoleTemplate('PAYROLL_APPROVER', 'Payroll approver', 'Review, approve, publish and close payroll runs; preparation remains with the payroll specialist.', [
                $grant('PAYROLL_APPROVER', 'payroll.run.read', $run, FieldProfile::P2_SENSITIVE),
                $grant('PAYROLL_APPROVER', 'payroll.run.review', $run, FieldProfile::P2_SENSITIVE),
                $grant('PAYROLL_APPROVER', 'payroll.run.approve', $run, FieldProfile::P2_SENSITIVE),
                $grant('PAYROLL_APPROVER', 'payroll.run.publish', $run, FieldProfile::P2_SENSITIVE),
                $grant('PAYROLL_APPROVER', 'payroll.run.close', $run, FieldProfile::P2_SENSITIVE),
                $grant('PAYROLL_APPROVER', 'payroll.payslip.read', $run, FieldProfile::P3_RESTRICTED),
                $grant('PAYROLL_APPROVER', 'payroll.parameters.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('PAYROLL_APPROVER', 'people.employee.read', $le, FieldProfile::P1_INTERNAL),
                $grant('PAYROLL_APPROVER', 'reporting.dashboard.read', $le, FieldProfile::P1_INTERNAL),
                $grant('PAYROLL_APPROVER', 'reporting.report.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('PAYROLL_APPROVER', 'notification.read', $le, FieldProfile::P1_INTERNAL),
                $grant('PAYROLL_APPROVER', 'payroll.compensation.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('PAYROLL_APPROVER', 'payroll.salary-adjustment.manage', $le, FieldProfile::P2_SENSITIVE),
            ]),
            new RoleTemplate('ATTENDANCE_SPECIALIST', 'Attendance specialist', 'Attendance correction and leave account administration.', [
                $grant('ATTENDANCE_SPECIALIST', 'time.attendance.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.attendance.manage', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.leave.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.leave.admin', $ou, FieldProfile::P2_SENSITIVE),
                $grant('ATTENDANCE_SPECIALIST', 'time.punch.import', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.attendance.correct.request', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.calendar.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.shift.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.leave.ledger.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'people.employee.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'org.tree.read', $ou, FieldProfile::P0_PUBLIC),
                $grant('ATTENDANCE_SPECIALIST', 'reporting.dashboard.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.overtime.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.overtime.manage', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.travel.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.travel.manage', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.travel.expense', $ou, FieldProfile::P2_SENSITIVE),
                $grant('ATTENDANCE_SPECIALIST', 'time.calendar.manage', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'people.document.download', $ou, FieldProfile::P2_SENSITIVE),
                $grant('ATTENDANCE_SPECIALIST', 'reporting.report.read', $ou, FieldProfile::P2_SENSITIVE),
                $grant('ATTENDANCE_SPECIALIST', 'notification.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.attendance.rule.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.attendance.rule.manage', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.shift.schedule.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.shift.schedule.manage', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.attendance.device.read', $le, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.overtime.confirm', $ou, FieldProfile::P1_INTERNAL),
                $grant('ATTENDANCE_SPECIALIST', 'time.attendance.reopen', $ou, FieldProfile::P1_INTERNAL),
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
                $grant('LINE_MANAGER', 'time.leave.ledger.read', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'time.attendance.correct.request', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'people.employee.read', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'lifecycle.case.read', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'lifecycle.probation.manage', $managed, FieldProfile::P2_SENSITIVE),
                $grant('LINE_MANAGER', 'lifecycle.transfer.manage', $managed, FieldProfile::P2_SENSITIVE),
                $grant('LINE_MANAGER', 'org.tree.read', $managed, FieldProfile::P0_PUBLIC),
                // Notices are company-wide communication: every employed
                // person must be able to read them, not only HR and staff.
                $grant('LINE_MANAGER', 'engagement.notice.read', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'reporting.dashboard.read', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'time.overtime.read', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'time.overtime.request', $managed, FieldProfile::P0_PUBLIC),
                $grant('LINE_MANAGER', 'time.travel.read', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'time.travel.expense', $managed, FieldProfile::P2_SENSITIVE),
                $grant('LINE_MANAGER', 'reporting.report.read', $managed, FieldProfile::P2_SENSITIVE),
                $grant('LINE_MANAGER', 'notification.read', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'org.position.staff.read', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'time.shift.schedule.read', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'time.overtime.confirm', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'time.timeoff.read', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'time.travel.approve', $managed, FieldProfile::P1_INTERNAL),
                $grant('LINE_MANAGER', 'performance.goal.manage', $managed, FieldProfile::P2_SENSITIVE),
                $grant('LINE_MANAGER', 'performance.feedback.manage', $managed, FieldProfile::P2_SENSITIVE),
                $grant('LINE_MANAGER', 'recruitment.evaluation.manage', $managed, FieldProfile::P2_SENSITIVE),
                $grant('LINE_MANAGER', 'training.feedback.manage', $managed, FieldProfile::P1_INTERNAL),
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
                $grant('EMPLOYEE', 'time.leave.request.cancel', $self, FieldProfile::P0_PUBLIC),
                $grant('EMPLOYEE', 'time.leave.ledger.read', $self, FieldProfile::P1_INTERNAL),
                $grant('EMPLOYEE', 'time.attendance.correct.request', $self, FieldProfile::P1_INTERNAL),
                $grant('EMPLOYEE', 'time.calendar.read', $self, FieldProfile::P0_PUBLIC),
                $grant('EMPLOYEE', 'time.shift.read', $self, FieldProfile::P0_PUBLIC),
                $grant('EMPLOYEE', 'payroll.payslip.read.self', $self, FieldProfile::P3_RESTRICTED),
                $grant('EMPLOYEE', 'engagement.notice.read', $self, FieldProfile::P1_INTERNAL),
                $grant('EMPLOYEE', 'org.tree.read', $self, FieldProfile::P0_PUBLIC),
                $grant('EMPLOYEE', 'reporting.dashboard.read', $self, FieldProfile::P1_INTERNAL),
                $grant('EMPLOYEE', 'notification.read', $self, FieldProfile::P1_INTERNAL),
                $grant('EMPLOYEE', 'time.overtime.read', $self, FieldProfile::P1_INTERNAL),
                $grant('EMPLOYEE', 'time.overtime.request', $self, FieldProfile::P0_PUBLIC),
                $grant('EMPLOYEE', 'time.travel.read', $self, FieldProfile::P1_INTERNAL),
                $grant('EMPLOYEE', 'time.travel.request', $self, FieldProfile::P0_PUBLIC),
                $grant('EMPLOYEE', 'time.travel.expense', $self, FieldProfile::P2_SENSITIVE),
                $grant('EMPLOYEE', 'people.document.download', $self, FieldProfile::P2_SENSITIVE),
                $grant('EMPLOYEE', 'benefit.read', $self, FieldProfile::P2_SENSITIVE),
                $grant('EMPLOYEE', 'reporting.report.read', $self, FieldProfile::P2_SENSITIVE),
                $grant('EMPLOYEE', 'people.document.read', $self, FieldProfile::P2_SENSITIVE),
                $grant('EMPLOYEE', 'time.timeoff.read', $self, FieldProfile::P1_INTERNAL),
                $grant('EMPLOYEE', 'time.travel.policy.read', $self, FieldProfile::P1_INTERNAL),
                $grant('EMPLOYEE', 'performance.goal.manage', $self, FieldProfile::P2_SENSITIVE),
                $grant('EMPLOYEE', 'training.record.read', $self, FieldProfile::P1_INTERNAL),
            ]),
            new RoleTemplate('AUDITOR', 'Auditor', 'Read-only audit evidence and organization context; cannot manage grants.', [
                $grant('AUDITOR', 'iam.audit.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('AUDITOR', 'iam.user.read', $le, FieldProfile::P1_INTERNAL),
                $grant('AUDITOR', 'org.tree.read', $le, FieldProfile::P0_PUBLIC),
                $grant('AUDITOR', 'reporting.dashboard.read', $le, FieldProfile::P1_INTERNAL),
                $grant('AUDITOR', 'iam.permission.read', $le, FieldProfile::P1_INTERNAL),
                $grant('AUDITOR', 'reporting.report.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('AUDITOR', 'notification.read', $le, FieldProfile::P1_INTERNAL),
            ]),
            new RoleTemplate('TRAINING_ADMIN', 'Training administrator', 'Manage training programmes, sessions, enrollments, completion records and reports.', [
                $grant('TRAINING_ADMIN', 'training.program.read', $le, FieldProfile::P1_INTERNAL),
                $grant('TRAINING_ADMIN', 'training.program.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('TRAINING_ADMIN', 'training.session.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('TRAINING_ADMIN', 'training.enrollment.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('TRAINING_ADMIN', 'training.completion.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('TRAINING_ADMIN', 'training.report.read', $le, FieldProfile::P1_INTERNAL),
                $grant('TRAINING_ADMIN', 'people.employee.read', $le, FieldProfile::P1_INTERNAL),
                $grant('TRAINING_ADMIN', 'org.tree.read', $le, FieldProfile::P0_PUBLIC),
                $grant('TRAINING_ADMIN', 'reporting.report.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('TRAINING_ADMIN', 'notification.read', $le, FieldProfile::P1_INTERNAL),
                $grant('TRAINING_ADMIN', 'performance.indicator.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('TRAINING_ADMIN', 'training.plan.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('TRAINING_ADMIN', 'training.course.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('TRAINING_ADMIN', 'training.checkin.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('TRAINING_ADMIN', 'training.exam.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('TRAINING_ADMIN', 'training.feedback.manage', $le, FieldProfile::P1_INTERNAL),
                $grant('TRAINING_ADMIN', 'training.record.read', $le, FieldProfile::P1_INTERNAL),
            ]),
            new RoleTemplate('BENEFITS_ADMIN', 'Benefits administrator', 'Maintain statutory base plans, benefit plans, monthly movements and benefit ledgers.', [
                $grant('BENEFITS_ADMIN', 'benefit.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('BENEFITS_ADMIN', 'benefit.manage', $le, FieldProfile::P2_SENSITIVE),
                $grant('BENEFITS_ADMIN', 'payroll.parameters.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('BENEFITS_ADMIN', 'people.employee.read', $le, FieldProfile::P1_INTERNAL),
                $grant('BENEFITS_ADMIN', 'org.tree.read', $le, FieldProfile::P0_PUBLIC),
                $grant('BENEFITS_ADMIN', 'reporting.report.read', $le, FieldProfile::P2_SENSITIVE),
                $grant('BENEFITS_ADMIN', 'notification.read', $le, FieldProfile::P1_INTERNAL),
            ]),
            new RoleTemplate('REPORT_VIEWER', 'Report viewer', 'Read and export authorized reports within a department anchor and field profile.', [
                $grant('REPORT_VIEWER', 'reporting.dashboard.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('REPORT_VIEWER', 'reporting.export', $ou, FieldProfile::P2_SENSITIVE),
                $grant('REPORT_VIEWER', 'reporting.export.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('REPORT_VIEWER', 'reporting.report.read', $ou, FieldProfile::P2_SENSITIVE),
                $grant('REPORT_VIEWER', 'org.tree.read', $ou, FieldProfile::P0_PUBLIC),
                $grant('REPORT_VIEWER', 'people.employee.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('REPORT_VIEWER', 'notification.read', $ou, FieldProfile::P1_INTERNAL),
                $grant('REPORT_VIEWER', 'reporting.definition.manage', $ou, FieldProfile::P1_INTERNAL),
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
