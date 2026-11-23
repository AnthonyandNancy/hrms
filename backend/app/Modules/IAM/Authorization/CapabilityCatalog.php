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
            new CapabilityDefinition('iam.session.manage', 'session', ['read', 'revoke'], [AnchorType::SELF], FieldProfile::P0_PUBLIC, 'low', null, 'Intrinsic self-service access to the caller own session; cannot be delegated.'),
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
            new CapabilityDefinition('lifecycle.case.read', 'lifecycle_case', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::CASE, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read lifecycle cases and onboarding checklists.'),
            new CapabilityDefinition('lifecycle.onboarding.manage', 'lifecycle_case', ['create'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', 'lifecycle_people', 'Start onboarding cases.'),
            new CapabilityDefinition('lifecycle.probation.manage', 'employment', ['confirm'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', 'lifecycle_people', 'Confirm probation cases.'),
            new CapabilityDefinition('lifecycle.transfer.manage', 'employment', ['transfer'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', 'lifecycle_people', 'Request assignment changes and transfers.'),
            new CapabilityDefinition('lifecycle.terms.manage', 'employment_terms', ['version'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P3_RESTRICTED, 'critical', 'lifecycle_people', 'Request salary and terms changes.'),
            new CapabilityDefinition('lifecycle.offboard.manage', 'employment', ['terminate'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'critical', 'lifecycle_people', 'Start offboarding cases with access revocation.'),
            new CapabilityDefinition('lifecycle.contract.manage', 'contract', ['renew'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', 'lifecycle_people', 'Renew or change employment contracts.'),
            new CapabilityDefinition('lifecycle.rehire.manage', 'employment', ['rehire'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', 'lifecycle_people', 'Rehire a former employee as a new employment.'),
            new CapabilityDefinition('lifecycle.checklist.manage', 'lifecycle_task', ['update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Complete or waive onboarding/offboarding checklist tasks.'),
            new CapabilityDefinition('workflow.case.create', 'case', ['create'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Create a workflow case.'),
            new CapabilityDefinition('workflow.case.decide', 'case', ['decide'], [AnchorType::CASE, AnchorType::MANAGED], FieldProfile::P1_INTERNAL, 'high', null, 'Record a decision on an assigned case.'),
            new CapabilityDefinition('workflow.case.admin', 'case', ['admin', 'reassign'], [AnchorType::CASE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'critical', null, 'Administer workflow cases.'),

            new CapabilityDefinition('time.attendance.read', 'attendance_day', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read attendance days.'),
            new CapabilityDefinition('time.punch.import', 'punch_record', ['import'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'high', null, 'Import raw device punches idempotently.'),
            new CapabilityDefinition('time.attendance.correct.request', 'attendance_correction', ['request'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Request an attendance correction Case.'),
            new CapabilityDefinition('time.calendar.read', 'legal_entity_calendar', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'low', null, 'Read legal entity calendars and holidays.'),
            new CapabilityDefinition('time.shift.read', 'shift_template', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'low', null, 'Read shift templates.'),
            new CapabilityDefinition('time.attendance.manage', 'attendance_day', ['create', 'update', 'correct'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'high', null, 'Manage and correct attendance.'),
            new CapabilityDefinition('time.leave.read', 'leave_account', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read leave balances and records.'),
            new CapabilityDefinition('time.leave.request', 'leave_request', ['create', 'withdraw'], [AnchorType::SELF], FieldProfile::P0_PUBLIC, 'low', null, 'Submit or withdraw own leave requests.'),
            new CapabilityDefinition('time.leave.admin', 'leave_account', ['grant', 'adjust', 'admin'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', null, 'Administer leave accounts and entries.'),
            new CapabilityDefinition('time.leave.request.cancel', 'leave_request', ['cancel'], [AnchorType::SELF], FieldProfile::P0_PUBLIC, 'low', null, 'Cancel own leave request and release its reservation.'),
            new CapabilityDefinition('time.leave.ledger.read', 'leave_ledger', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read append-only leave ledger entries and reconciliation summary.'),

            new CapabilityDefinition('payroll.run.read', 'payroll_run', ['read'], [AnchorType::PAYROLL_RUN, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', 'payroll_prepare', 'Read payroll run metadata and inputs.'),
            new CapabilityDefinition('payroll.run.prepare', 'payroll_run', ['prepare'], [AnchorType::PAYROLL_RUN, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_prepare', 'Prepare payroll runs and inputs.'),
            new CapabilityDefinition('payroll.run.review', 'payroll_run', ['review'], [AnchorType::PAYROLL_RUN, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_review', 'Review calculated payroll runs.'),
            new CapabilityDefinition('payroll.run.approve', 'payroll_run', ['approve'], [AnchorType::PAYROLL_RUN, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_approve', 'Approve reviewed payroll runs.'),
            new CapabilityDefinition('payroll.run.publish', 'payroll_run', ['publish'], [AnchorType::PAYROLL_RUN, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_publish', 'Publish approved payroll runs.'),
            new CapabilityDefinition('payroll.payslip.read', 'payslip', ['read'], [AnchorType::SELF, AnchorType::PAYROLL_RUN, AnchorType::LEGAL_ENTITY], FieldProfile::P3_RESTRICTED, 'high', 'payroll_prepare', 'Read payslips; SELF resolves to the caller employment.'),
            new CapabilityDefinition('payroll.payslip.read.self', 'payslip', ['read'], [AnchorType::SELF], FieldProfile::P3_RESTRICTED, 'medium', null, 'Read own payslips.'),
            new CapabilityDefinition('payroll.export', 'payroll_run', ['export'], [AnchorType::PAYROLL_RUN, AnchorType::LEGAL_ENTITY], FieldProfile::P3_RESTRICTED, 'critical', 'payroll_publish', 'Export payroll data with an explicit export capability.'),
            new CapabilityDefinition('payroll.run.close', 'payroll_run', ['close'], [AnchorType::PAYROLL_RUN, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_publish', 'Mark a published run paid and close it.'),
            new CapabilityDefinition('payroll.run.correct', 'payroll_run', ['create', 'correct'], [AnchorType::PAYROLL_RUN, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_approve', 'Create correction runs referencing a published original.'),
            new CapabilityDefinition('payroll.run.reopen', 'payroll_run', ['reopen'], [AnchorType::PAYROLL_RUN, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_approve', 'Reopen a pre-publication run with dual approval.'),
            new CapabilityDefinition('payroll.parameters.read', 'payroll_parameter', ['read'], [AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', 'payroll_parameters', 'Read effective-dated statutory parameters and evidence.'),
            new CapabilityDefinition('payroll.parameters.manage', 'payroll_parameter', ['create', 'approve'], [AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_parameters', 'Create approved statutory parameter versions with evidence.'),

            new CapabilityDefinition('talent.recruitment.manage', 'recruitment', ['create', 'update', 'admin'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::CASE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', null, 'Manage recruitment requisitions and candidates.'),
            new CapabilityDefinition('talent.performance.manage', 'performance', ['create', 'update', 'admin'], [AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', null, 'Manage performance cycles and reviews.'),
            new CapabilityDefinition('talent.training.manage', 'training', ['create', 'update', 'admin'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Manage training catalogues and attendance.'),
            new CapabilityDefinition('recruitment.requisition.read', 'recruitment_requisition', ['read'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read recruitment requisitions.'),
            new CapabilityDefinition('recruitment.requisition.manage', 'recruitment_requisition', ['create', 'approve'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', null, 'Create and approve requisition cases.'),
            new CapabilityDefinition('recruitment.candidate.read', 'candidate', ['read'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', null, 'Read candidate pipeline records with minimized PII.'),
            new CapabilityDefinition('recruitment.candidate.manage', 'candidate', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', null, 'Create and update candidate records.'),
            new CapabilityDefinition('recruitment.interview.manage', 'interview', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Schedule interviews and record feedback.'),
            new CapabilityDefinition('recruitment.offer.manage', 'offer', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P3_RESTRICTED, 'high', 'recruitment_offer', 'Draft and submit versioned offers.'),
            new CapabilityDefinition('recruitment.offer.accept', 'offer', ['accept'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P3_RESTRICTED, 'high', 'recruitment_offer', 'Accept an approved offer and start onboarding.'),
            new CapabilityDefinition('performance.cycle.read', 'performance_cycle', ['read'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read performance cycles.'),
            new CapabilityDefinition('performance.cycle.manage', 'performance_cycle', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Create performance cycles.'),
            new CapabilityDefinition('performance.review.manage', 'performance_review', ['create', 'update'], [AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', null, 'Create performance reviews.'),
            new CapabilityDefinition('performance.review.submit', 'performance_review', ['submit'], [AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', null, 'Submit performance reviews.'),
            new CapabilityDefinition('performance.appeal.manage', 'performance_appeal', ['create'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'medium', null, 'Raise a performance appeal as a Workflow case.'),
            new CapabilityDefinition('training.program.read', 'training_program', ['read'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'low', null, 'Read training programs.'),
            new CapabilityDefinition('training.program.manage', 'training_program', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Manage training programs.'),
            new CapabilityDefinition('training.session.manage', 'training_session', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Schedule training sessions with capacity.'),
            new CapabilityDefinition('training.enrollment.manage', 'training_enrollment', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Enrol employees in training sessions.'),
            new CapabilityDefinition('training.completion.manage', 'training_enrollment', ['complete'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Record training attendance and completion.'),
            new CapabilityDefinition('training.report.read', 'training_enrollment', ['read'], [AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read training attendance reports.'),

            new CapabilityDefinition('engagement.notice.read', 'notice', ['read'], [AnchorType::SELF, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'low', null, 'Read engagement notices.'),
            new CapabilityDefinition('engagement.notice.manage', 'notice', ['create', 'update', 'publish'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Manage engagement notices.'),

            new CapabilityDefinition('reporting.dashboard.read', 'dashboard', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read role-scoped dashboards.'),
            new CapabilityDefinition('reporting.export', 'report', ['export'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::LEGAL_ENTITY], FieldProfile::P2_SENSITIVE, 'high', null, 'Export reports with a field whitelist and row cap.'),
        ];
    }
}
