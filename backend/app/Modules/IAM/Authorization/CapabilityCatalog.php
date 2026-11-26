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
        $allFields = [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::CASE, AnchorType::PAYROLL_RUN, AnchorType::COMPANY];

        return [
            new CapabilityDefinition('iam.user.read', 'user', ['read'], [AnchorType::SELF, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'identity_governance', 'Read user accounts within an anchor.'),
            new CapabilityDefinition('iam.user.manage', 'user', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'critical', 'identity_governance', 'Create and update user accounts.'),
            new CapabilityDefinition('iam.role.manage', 'role', ['read', 'create', 'update'], [AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'critical', 'identity_governance', 'Manage role assignments and templates.'),
            new CapabilityDefinition('iam.binding.manage', 'authority_binding', ['read', 'create', 'delete'], [AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'critical', 'identity_governance', 'Manage authority bindings.'),
            new CapabilityDefinition('iam.session.manage', 'session', ['read', 'revoke'], [AnchorType::SELF], FieldProfile::P0_PUBLIC, 'low', null, 'Intrinsic self-service access to the caller own session; cannot be delegated.'),
            new CapabilityDefinition('iam.audit.read', 'auth_event', ['read', 'export'], [AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'identity_audit', 'Read authentication and authorization audit evidence.'),

            new CapabilityDefinition('iam.menu.manage', 'menu', ['create', 'update', 'delete', 'sort'], [AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'identity_governance', 'Manage the code-declared menu tree, ordering and enabled state.'),
            new CapabilityDefinition('iam.permission.read', 'permission_resource', ['read'], [AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', 'identity_governance', 'Read permission resources and detect role/page coverage gaps.'),
            new CapabilityDefinition('iam.scope.manage', 'data_scope', ['create', 'update', 'delete'], [AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', 'identity_governance', 'Configure whitelisted data scopes and preview their membership.'),
            new CapabilityDefinition('iam.field.manage', 'field_policy', ['create', 'update'], [AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', 'identity_governance', 'Configure P0-P3 field visibility, masking and export policies.'),

            new CapabilityDefinition('org.unit.read', 'org_unit', ['read'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read organization units.'),
            new CapabilityDefinition('org.unit.manage', 'org_unit', ['create', 'update', 'delete'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', null, 'Manage organization units.'),
            new CapabilityDefinition('org.tree.read', 'org_tree', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P0_PUBLIC, 'low', null, 'Read the organization tree.'),

            new CapabilityDefinition('org.unit.export', 'org_unit', ['export'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', 'org_export', 'Export the organization structure with a field whitelist.'),
            new CapabilityDefinition('org.position.read', 'position', ['read'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read position catalogues, grades and headcount occupancy.'),
            new CapabilityDefinition('org.position.manage', 'position', ['create', 'update', 'delete'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'org_position', 'Create, version and disable positions and their headcount limits.'),
            new CapabilityDefinition('org.position.export', 'position', ['export'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', 'org_export', 'Export positions and headcount within the caller anchor.'),
            new CapabilityDefinition('benefit.read', 'benefit_plan', ['read'], [AnchorType::SELF, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', null, 'Read statutory base plans, benefit elections and benefit ledgers.'),
            new CapabilityDefinition('benefit.manage', 'benefit_plan', ['create', 'update', 'approve'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', 'benefit_admin', 'Manage benefit plans, statutory bases and monthly enrollment movements.'),

            new CapabilityDefinition('people.employee.read', 'employee', ['read'], $allFields, FieldProfile::P2_SENSITIVE, 'high', null, 'Read employee records; fields are projected per binding.'),
            new CapabilityDefinition('people.employee.manage', 'employee', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'people_manage', 'Create and update employee records.'),
            new CapabilityDefinition('people.assignment.manage', 'assignment', ['create', 'update', 'end'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', null, 'Manage effective-dated assignments.'),
            new CapabilityDefinition('people.contract.read', 'contract', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', null, 'Read employment contracts.'),
            new CapabilityDefinition('people.contract.manage', 'contract', ['create', 'update', 'terminate'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', null, 'Manage employment contracts.'),
            new CapabilityDefinition('people.contract.delete', 'contract', ['delete'], [AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'people_contract', 'Soft-delete a labour contract while preserving its version history.'),
            new CapabilityDefinition('people.contract.restore', 'contract', ['restore'], [AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'people_contract', 'Restore a soft-deleted labour contract.'),
            new CapabilityDefinition('people.employee.archive', 'employee', ['archive'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'people_employee', 'Archive an employee without deleting historical records.'),
            new CapabilityDefinition('people.employee.restore', 'employee', ['restore'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'people_employee', 'Restore an archived employee.'),

            new CapabilityDefinition('people.employee.export', 'employee', ['export'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'people_export', 'Export employee data with an explicit field whitelist and row cap.'),
            new CapabilityDefinition('people.contract.export', 'contract', ['export'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'people_export', 'Export contract metadata subject to P2/P3 field policy constraints.'),
            new CapabilityDefinition('people.document.download', 'document', ['download'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'people_export', 'Download an employee document through a short-lived audited token.'),

            new CapabilityDefinition('workflow.case.read', 'case', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::CASE, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read workflow cases visible through a membership anchor.'),
            new CapabilityDefinition('lifecycle.case.read', 'lifecycle_case', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::CASE, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read lifecycle cases and onboarding checklists.'),
            new CapabilityDefinition('lifecycle.onboarding.manage', 'lifecycle_case', ['create'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'lifecycle_people', 'Start onboarding cases.'),
            new CapabilityDefinition('lifecycle.probation.manage', 'employment', ['confirm'], [AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'lifecycle_people', 'Confirm probation cases.'),
            new CapabilityDefinition('lifecycle.transfer.manage', 'employment', ['transfer'], [AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'lifecycle_people', 'Request assignment changes and transfers.'),
            new CapabilityDefinition('lifecycle.terms.manage', 'employment_terms', ['version'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P3_RESTRICTED, 'critical', 'lifecycle_people', 'Request salary and terms changes.'),
            new CapabilityDefinition('lifecycle.offboard.manage', 'employment', ['terminate'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', 'lifecycle_people', 'Start offboarding cases with access revocation.'),
            new CapabilityDefinition('lifecycle.contract.manage', 'contract', ['renew'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'lifecycle_people', 'Renew or change employment contracts.'),
            new CapabilityDefinition('lifecycle.rehire.manage', 'employment', ['rehire'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'lifecycle_people', 'Rehire a former employee as a new employment.'),
            new CapabilityDefinition('lifecycle.checklist.manage', 'lifecycle_task', ['update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Complete or waive onboarding/offboarding checklist tasks.'),
            new CapabilityDefinition('workflow.case.create', 'case', ['create'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Create a workflow case.'),
            new CapabilityDefinition('workflow.case.decide', 'case', ['decide'], [AnchorType::CASE, AnchorType::MANAGED], FieldProfile::P1_INTERNAL, 'high', null, 'Record a decision on an assigned case.'),
            new CapabilityDefinition('workflow.case.admin', 'case', ['admin', 'reassign'], [AnchorType::CASE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', null, 'Administer workflow cases.'),

            new CapabilityDefinition('workflow.template.manage', 'workflow_definition', ['create', 'update', 'publish'], [AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'workflow_admin', 'Create, version and publish approval workflow templates and node rules.'),

            new CapabilityDefinition('time.attendance.read', 'attendance_day', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read attendance days.'),
            new CapabilityDefinition('time.punch.import', 'punch_record', ['import'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', null, 'Import raw device punches idempotently.'),
            new CapabilityDefinition('time.attendance.correct.request', 'attendance_correction', ['request'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Request an attendance correction Case.'),
            new CapabilityDefinition('time.calendar.read', 'legal_entity_calendar', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'low', null, 'Read legal entity calendars and holidays.'),
            new CapabilityDefinition('time.shift.read', 'shift_template', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'low', null, 'Read shift templates.'),
            new CapabilityDefinition('time.attendance.manage', 'attendance_day', ['create', 'update', 'correct'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', null, 'Manage and correct attendance.'),
            new CapabilityDefinition('time.leave.read', 'leave_account', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read leave balances and records.'),
            new CapabilityDefinition('time.leave.request', 'leave_request', ['create', 'withdraw'], [AnchorType::SELF], FieldProfile::P0_PUBLIC, 'low', null, 'Submit or withdraw own leave requests.'),
            new CapabilityDefinition('time.leave.admin', 'leave_account', ['grant', 'adjust', 'admin'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', null, 'Administer leave accounts and entries.'),
            new CapabilityDefinition('time.leave.request.cancel', 'leave_request', ['cancel'], [AnchorType::SELF], FieldProfile::P0_PUBLIC, 'low', null, 'Cancel own leave request and release its reservation.'),
            new CapabilityDefinition('time.leave.ledger.read', 'leave_ledger', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read append-only leave ledger entries and reconciliation summary.'),

            new CapabilityDefinition('time.calendar.manage', 'legal_entity_calendar', ['create', 'update', 'publish'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'time_calendar', 'Configure and publish statutory holidays, rest days and workday calendar versions.'),
            new CapabilityDefinition('time.overtime.read', 'overtime_request', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read overtime requests, confirmations and time-off balances.'),
            new CapabilityDefinition('time.overtime.request', 'overtime_request', ['create', 'withdraw'], [AnchorType::SELF, AnchorType::MANAGED], FieldProfile::P0_PUBLIC, 'low', null, 'Submit or withdraw own overtime requests.'),
            new CapabilityDefinition('time.overtime.manage', 'overtime_request', ['create', 'update', 'approve'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'time_overtime', 'Manage overtime policies, confirmations and time-off conversion.'),
            new CapabilityDefinition('time.overtime.settle', 'overtime_settlement', ['settle', 'reverse'], [AnchorType::PAYROLL_RUN, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_publish', 'Settle overtime through pay or time-off and reverse an erroneous settlement.'),
            new CapabilityDefinition('time.travel.read', 'travel_request', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read business-travel requests, changes and reports.'),
            new CapabilityDefinition('time.travel.request', 'travel_request', ['create', 'update', 'cancel'], [AnchorType::SELF], FieldProfile::P0_PUBLIC, 'low', null, 'Create, change or cancel own business-travel requests.'),
            new CapabilityDefinition('time.travel.manage', 'travel_request', ['create', 'update', 'approve'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'time_travel', 'Manage travel policies, approvals and department travel statistics.'),
            new CapabilityDefinition('time.travel.expense', 'travel_expense', ['create', 'update'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'time_travel', 'Register travel expenses and receipts against a budget.'),

            new CapabilityDefinition('payroll.run.read', 'payroll_run', ['read'], [AnchorType::PAYROLL_RUN, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'payroll_prepare', 'Read payroll run metadata and inputs.'),
            new CapabilityDefinition('payroll.run.prepare', 'payroll_run', ['prepare'], [AnchorType::PAYROLL_RUN, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_prepare', 'Prepare payroll runs and inputs.'),
            new CapabilityDefinition('payroll.run.review', 'payroll_run', ['review'], [AnchorType::PAYROLL_RUN, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_review', 'Review calculated payroll runs.'),
            new CapabilityDefinition('payroll.run.approve', 'payroll_run', ['approve'], [AnchorType::PAYROLL_RUN, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_approve', 'Approve reviewed payroll runs.'),
            new CapabilityDefinition('payroll.run.publish', 'payroll_run', ['publish'], [AnchorType::PAYROLL_RUN, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_publish', 'Publish approved payroll runs.'),
            new CapabilityDefinition('payroll.payslip.read', 'payslip', ['read'], [AnchorType::SELF, AnchorType::PAYROLL_RUN, AnchorType::COMPANY], FieldProfile::P3_RESTRICTED, 'high', 'payroll_prepare', 'Read payslips; SELF resolves to the caller employment.'),
            new CapabilityDefinition('payroll.payslip.read.self', 'payslip', ['read'], [AnchorType::SELF], FieldProfile::P3_RESTRICTED, 'medium', null, 'Read own payslips.'),
            new CapabilityDefinition('payroll.export', 'payroll_run', ['export'], [AnchorType::PAYROLL_RUN, AnchorType::COMPANY], FieldProfile::P3_RESTRICTED, 'critical', 'payroll_publish', 'Export payroll data with an explicit export capability.'),
            new CapabilityDefinition('payroll.run.close', 'payroll_run', ['close'], [AnchorType::PAYROLL_RUN, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_publish', 'Mark a published run paid and close it.'),
            new CapabilityDefinition('payroll.run.correct', 'payroll_run', ['create', 'correct'], [AnchorType::PAYROLL_RUN, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_approve', 'Create correction runs referencing a published original.'),
            new CapabilityDefinition('payroll.run.reopen', 'payroll_run', ['reopen'], [AnchorType::PAYROLL_RUN, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_approve', 'Reopen a pre-publication run with dual approval.'),
            new CapabilityDefinition('payroll.parameters.read', 'payroll_parameter', ['read'], [AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'payroll_parameters', 'Read effective-dated statutory parameters and evidence.'),
            new CapabilityDefinition('payroll.parameters.manage', 'payroll_parameter', ['create', 'approve'], [AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_parameters', 'Create approved statutory parameter versions with evidence.'),

            new CapabilityDefinition('talent.recruitment.manage', 'recruitment', ['create', 'update', 'admin'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::CASE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', null, 'Manage recruitment requisitions and candidates.'),
            new CapabilityDefinition('talent.performance.manage', 'performance', ['create', 'update', 'admin'], [AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', null, 'Manage performance cycles and reviews.'),
            new CapabilityDefinition('talent.training.manage', 'training', ['create', 'update', 'admin'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Manage training catalogues and attendance.'),
            new CapabilityDefinition('recruitment.requisition.read', 'recruitment_requisition', ['read'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read recruitment requisitions.'),
            new CapabilityDefinition('recruitment.board.read', 'recruitment_board', ['read'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read the live recruitment funnel board and recruitment statistics.'),
            new CapabilityDefinition('recruitment.budget.read', 'recruitment_budget', ['read'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read recruitment budget, committed offer cost and headcount gaps.'),
            new CapabilityDefinition('recruitment.requisition.manage', 'recruitment_requisition', ['create', 'approve'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', null, 'Create and approve requisition cases.'),

            new CapabilityDefinition('recruitment.requisition.export', 'recruitment_requisition', ['export'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', 'recruitment_export', 'Export requisitions, budgets and recruitment statistics.'),
            new CapabilityDefinition('recruitment.candidate.read', 'candidate', ['read'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', null, 'Read candidate pipeline records with minimized PII.'),
            new CapabilityDefinition('recruitment.candidate.manage', 'candidate', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', null, 'Create and update candidate records.'),
            new CapabilityDefinition('recruitment.interview.manage', 'interview', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Schedule interviews and record feedback.'),
            new CapabilityDefinition('recruitment.offer.manage', 'offer', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P3_RESTRICTED, 'high', 'recruitment_offer', 'Draft and submit versioned offers.'),
            new CapabilityDefinition('recruitment.offer.accept', 'offer', ['accept'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P3_RESTRICTED, 'high', 'recruitment_offer', 'Accept an approved offer and start onboarding.'),
            new CapabilityDefinition('performance.cycle.read', 'performance_cycle', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read performance cycles.'),
            new CapabilityDefinition('performance.cycle.manage', 'performance_cycle', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Create performance cycles.'),
            new CapabilityDefinition('performance.review.manage', 'performance_review', ['create', 'update'], [AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', null, 'Create performance reviews.'),
            new CapabilityDefinition('performance.review.submit', 'performance_review', ['submit'], [AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', null, 'Submit performance reviews.'),
            new CapabilityDefinition('performance.appeal.manage', 'performance_appeal', ['create'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'medium', null, 'Raise a performance appeal as a Workflow case.'),

            new CapabilityDefinition('performance.calibration.manage', 'performance_calibration', ['create', 'update', 'publish'], [AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'performance_calibration', 'Run calibration sessions and publish calibrated performance ratings.'),
            new CapabilityDefinition('performance.report.read', 'performance_cycle', ['read'], [AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read performance distribution, trend and department reports.'),
            new CapabilityDefinition('training.program.read', 'training_program', ['read'], [AnchorType::SELF, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'low', null, 'Read training programs.'),
            new CapabilityDefinition('training.program.manage', 'training_program', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Manage training programs.'),
            new CapabilityDefinition('training.session.manage', 'training_session', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Schedule training sessions with capacity.'),
            new CapabilityDefinition('training.enrollment.manage', 'training_enrollment', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Enrol employees in training sessions.'),
            new CapabilityDefinition('training.completion.manage', 'training_enrollment', ['complete'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Record training attendance and completion.'),
            new CapabilityDefinition('training.report.read', 'training_enrollment', ['read'], [AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read training attendance reports.'),

            new CapabilityDefinition('engagement.notice.read', 'notice', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'low', null, 'Read engagement notices.'),
            new CapabilityDefinition('engagement.notice.manage', 'notice', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Manage engagement notices.'),

            new CapabilityDefinition('engagement.notice.publish', 'notice', ['publish', 'retract'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'notice_publish', 'Schedule, publish, pin or retract an approved notice.'),
            new CapabilityDefinition('engagement.notice.export', 'notice', ['export'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'notice_publish', 'Export notice read rates and acknowledgement details.'),
            new CapabilityDefinition('notification.read', 'notification', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'low', null, 'Read in-app notifications and delivery status.'),
            new CapabilityDefinition('engagement.notification.read', 'notification', ['read'], [AnchorType::SELF], FieldProfile::P1_INTERNAL, 'low', null, 'Legacy route code for reading the caller own in-app notifications; same semantics as notification.read.'),
            new CapabilityDefinition('notification.manage', 'notification_template', ['create', 'update', 'retry'], [AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'notification_admin', 'Manage notification templates and retry failed delivery attempts.'),

            new CapabilityDefinition('reporting.dashboard.read', 'dashboard', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read role-scoped dashboards.'),
            new CapabilityDefinition('reporting.export', 'report', ['export'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', null, 'Export reports with a field whitelist and row cap.'),
            new CapabilityDefinition('reporting.export.read', 'export_job', ['read'], [AnchorType::SELF, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read export job history with hashes, field whitelists and audit trail.'),

            new CapabilityDefinition('reporting.report.read', 'report', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'medium', null, 'Read report definitions and execute authorized report views.'),
            new CapabilityDefinition('reporting.report.create', 'report', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', 'report_create', 'Build and save custom report definitions from approved dimensions and metrics.'),
            new CapabilityDefinition('reporting.subscription.manage', 'report_subscription', ['create', 'update', 'delete'], [AnchorType::SELF, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', 'report_create', 'Create, pause or remove report subscriptions owned by the caller or role.'),
            new CapabilityDefinition('system.settings.manage', 'system_setting', ['create', 'update', 'delete'], [AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', 'system_config', 'Manage dictionaries, global parameters, file classifications and access policy.'),
            // ---- V1.1 gap-completion capabilities (ADR-0028, Spec 5.3.1) ----
            new CapabilityDefinition('org.position.qualification.manage', 'position_qualification', ['create', 'update', 'delete'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', 'org_position', 'Version and retire position qualification requirements.'),
            new CapabilityDefinition('org.position.grade.manage', 'position_grade', ['create', 'update'], [AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', 'org_position', 'Maintain the job-grade catalogue used by positions and compensation bands.'),
            new CapabilityDefinition('org.position.staff.read', 'position_assignment', ['read'], [AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'low', null, 'Read current incumbents of a position within the caller anchor.'),
            new CapabilityDefinition('lifecycle.secondment.manage', 'secondment', ['create', 'update', 'terminate'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'lifecycle_people', 'Start, extend and end temporary secondment arrangements.'),

            new CapabilityDefinition('people.document.read', 'document', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', null, 'List employee documents and their signature state.'),
            new CapabilityDefinition('people.contract.template.manage', 'contract_template', ['create', 'update', 'publish'], [AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'people_manage', 'Version and publish employment-contract templates.'),
            new CapabilityDefinition('people.contract.amend', 'contract', ['amend'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'people_manage', 'Append an effective-dated contract amendment without rewriting history.'),
            new CapabilityDefinition('people.contract.terminate', 'contract', ['terminate'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', 'people_manage', 'Terminate an employment contract with an effective date and reason.'),

            new CapabilityDefinition('time.attendance.rule.read', 'attendance_rule', ['read'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'low', null, 'Read attendance rules and their effective versions.'),
            new CapabilityDefinition('time.attendance.rule.manage', 'attendance_rule', ['create', 'update', 'publish'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'time_calendar', 'Version and publish attendance rules such as grace periods and rounding.'),
            new CapabilityDefinition('time.shift.schedule.read', 'shift_schedule', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'low', null, 'Read published shift schedules.'),
            new CapabilityDefinition('time.shift.schedule.manage', 'shift_schedule', ['create', 'update', 'publish'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'time_calendar', 'Create and publish shift schedules for an org unit.'),
            new CapabilityDefinition('time.attendance.reopen', 'attendance_period', ['reopen'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'time_calendar', 'Reopen a closed attendance period with an audited reason before recalculation.'),
            new CapabilityDefinition('time.attendance.device.read', 'attendance_device', ['read'], [AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Read attendance device inventory and health status.'),

            new CapabilityDefinition('time.overtime.confirm', 'overtime_request', ['confirm'], [AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'time_overtime', 'Confirm actual overtime minutes separately from the declared amount.'),
            new CapabilityDefinition('time.timeoff.read', 'timeoff_balance', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'low', null, 'Read time-off-in-lieu balances derived from confirmed overtime settlements.'),

            new CapabilityDefinition('time.travel.policy.read', 'travel_policy', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'low', null, 'Read travel policy versions and expense limits.'),
            new CapabilityDefinition('time.travel.policy.manage', 'travel_policy', ['create', 'update', 'publish'], [AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'time_travel', 'Version and publish travel policies.'),
            new CapabilityDefinition('time.travel.approve', 'travel_request', ['approve'], [AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'time_travel', 'Approve or reject a business-travel request.'),
            new CapabilityDefinition('time.travel.expense.manage', 'travel_expense', ['create', 'update', 'submit'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'time_travel', 'Register and submit travel expenses against an approved request and budget.'),

            new CapabilityDefinition('payroll.compensation.read', 'employee_compensation', ['read'], [AnchorType::SELF, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', null, 'Read effective-dated compensation plans; P3 amounts stay masked and audited.'),
            new CapabilityDefinition('payroll.compensation.manage', 'employee_compensation', ['create', 'update'], [AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_parameters', 'Create effective-dated compensation versions in integer minor units.'),
            new CapabilityDefinition('payroll.salary-adjustment.manage', 'salary_adjustment', ['create', 'approve'], [AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', 'payroll_approve', 'Record salary adjustments backed by an approved workflow case.'),

            new CapabilityDefinition('performance.indicator.manage', 'performance_indicator', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Maintain performance indicator catalogues.'),
            new CapabilityDefinition('performance.goal.manage', 'performance_goal', ['create', 'update'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'medium', null, 'Set and update performance goals for a review.'),
            new CapabilityDefinition('performance.feedback.manage', 'performance_feedback', ['create', 'update'], [AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'medium', null, 'Record continuous performance feedback.'),
            new CapabilityDefinition('performance.result.publish', 'performance_cycle', ['publish'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'performance_calibration', 'Publish calibrated performance results and freeze the cycle.'),

            new CapabilityDefinition('recruitment.candidate.import', 'candidate_import', ['create'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', null, 'Import candidate rows with validation results and a rejected-row report.'),
            new CapabilityDefinition('recruitment.evaluation.manage', 'candidate_evaluation', ['create', 'update'], [AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'medium', null, 'Record structured candidate evaluations.'),
            new CapabilityDefinition('recruitment.communication.manage', 'candidate_communication', ['create'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'medium', null, 'Log candidate communications for pipeline traceability.'),
            new CapabilityDefinition('recruitment.talent-pool.manage', 'talent_pool_tag', ['create', 'delete'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'medium', null, 'Tag and untag candidates in the talent pool.'),
            new CapabilityDefinition('recruitment.candidate.convert', 'candidate', ['convert'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', 'recruitment_offer', 'Convert an accepted candidate into exactly one employment.'),

            new CapabilityDefinition('training.plan.manage', 'training_plan', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Plan annual training curricula and budgets.'),
            new CapabilityDefinition('training.course.manage', 'training_course', ['create', 'update'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Maintain course catalogues and materials.'),
            new CapabilityDefinition('training.checkin.manage', 'training_checkin', ['create'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Record training attendance check-ins.'),
            new CapabilityDefinition('training.exam.manage', 'training_exam', ['create', 'update', 'publish'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', null, 'Author, publish and grade training exams.'),
            new CapabilityDefinition('training.feedback.manage', 'training_feedback', ['create'], [AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'low', null, 'Record training feedback and effectiveness scores.'),
            new CapabilityDefinition('training.record.read', 'training_record', ['read'], [AnchorType::SELF, AnchorType::MANAGED, AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'low', null, 'Read consolidated training records and certificates.'),

            new CapabilityDefinition('system.dictionary.manage', 'dictionary', ['create', 'update', 'delete'], [AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'system_config', 'Maintain tenant dictionaries through the Platform contract.'),
            new CapabilityDefinition('system.parameter.manage', 'system_parameter', ['create', 'update'], [AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'system_config', 'Maintain typed tenant parameters through the Platform contract.'),
            new CapabilityDefinition('system.backup.manage', 'backup_record', ['create', 'read', 'restore'], [AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'critical', 'system_config', 'Run backup and restore operations and read their evidence.'),
            new CapabilityDefinition('notification.template.manage', 'notification_template', ['create', 'update'], [AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'notification_admin', 'Version notification templates and their variables.'),
            new CapabilityDefinition('notification.delivery.manage', 'notification_delivery', ['retry', 'read'], [AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'notification_admin', 'Read delivery attempts and retry failed notifications.'),

            new CapabilityDefinition('reporting.definition.manage', 'report_definition', ['create', 'update', 'publish'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'medium', 'report_create', 'Create, version and publish report definitions from approved dimensions and metrics.'),

            new CapabilityDefinition('platform.feature.manage', 'feature_flag', ['read', 'update'], [AnchorType::COMPANY], FieldProfile::P1_INTERNAL, 'high', 'system_config', 'Read and change platform feature flags for a legal entity.'),
        ];
    }
}
