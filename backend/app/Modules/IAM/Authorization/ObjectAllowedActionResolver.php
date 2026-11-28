<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

use App\Modules\IAM\Contracts\AllowedActionResolver;
use App\Modules\IAM\Contracts\Principal;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\Authorizer;

/**
 * Resolves object actions from the generated catalog, the principal's
 * capability/anchor bindings and server-loaded object state.
 */
final class ObjectAllowedActionResolver implements AllowedActionResolver
{
    /**
     * State transitions are deliberately conservative. A frontend can hide a
     * control, but this table is the source of truth for whether an object
     * action is currently allowed.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_STATES = [
        'payroll.run.detail.lock-inputs' => ['DRAFT'],
        'payroll.run.detail.calculate' => ['INPUT_LOCKED'],
        'payroll.run.detail.review' => ['CALCULATED'],
        'payroll.run.detail.approve' => ['REVIEWED'],
        'payroll.run.detail.publish' => ['APPROVED', 'PAID'],
        'payroll.run.detail.cancel' => ['DRAFT', 'INPUT_LOCKED', 'CALCULATED', 'REVIEWED'],
        'payroll.run.detail.reopen' => ['INPUT_LOCKED', 'CALCULATED', 'REVIEWED'],
        'payroll.run.detail.correct' => ['PUBLISHED', 'PAID', 'CLOSED'],
        'payroll.run.detail.mark-paid' => ['APPROVED', 'PUBLISHED'],
        'payroll.run.detail.close' => ['PUBLISHED', 'PAID'],
        'payroll.run.detail.assign-member' => ['DRAFT', 'INPUT_LOCKED', 'CALCULATED', 'CALCULATED_WITH_BLOCKERS', 'REVIEWED', 'APPROVED'],
        'people.contract.detail.activate' => ['DRAFT', 'PENDING_EFFECTIVE'],
        'people.contract.detail.amend' => ['ACTIVE', 'EXPIRING'],
        'people.contract.detail.terminate' => ['ACTIVE', 'EXPIRING'],
        'leave.request.cancel' => ['DRAFT', 'SUBMITTED', 'IN_REVIEW', 'APPROVED'],
        'leave.request.revoke' => ['APPROVED', 'EFFECTIVE', 'CONFIRMED', 'COMPLETED'],
        'overtime.request.confirm' => ['SUBMITTED', 'IN_REVIEW', 'APPROVED'],
        'travel.request.approve' => ['SUBMITTED', 'IN_REVIEW'],
        'travel.request.cancel' => ['DRAFT', 'SUBMITTED', 'IN_REVIEW', 'APPROVED'],
        'travel.request.change' => ['DRAFT', 'SUBMITTED', 'IN_REVIEW', 'APPROVED'],
    ];

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly PageActionCatalog $catalog,
        private readonly PeopleDirectory $people,
    ) {}

    public function for(
        Principal $principal,
        string $objectType,
        int|string $objectId,
        array $state,
        string $businessDate,
    ): array {
        $allowed = [];

        foreach ($this->catalog->forObject($objectType) as $action) {
            $key = (string) ($action['key'] ?? '');
            $capability = (string) ($action['capability'] ?? '');

            if ($key === '' || $capability === '' || ! $this->stateAllows($key, $state)) {
                continue;
            }

            if ($this->anchorAllows($principal, $capability, $action, $objectType, $objectId, $state, $businessDate)) {
                $allowed[] = $key;
            }
        }

        $allowed = array_values(array_unique($allowed));
        sort($allowed);

        return $allowed;
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $state
     */
    private function anchorAllows(
        Principal $principal,
        string $capability,
        array $action,
        string $objectType,
        int|string $objectId,
        array $state,
        string $businessDate,
    ): bool {
        $subjectEmploymentId = isset($state['subject_employment_id'])
            ? (int) $state['subject_employment_id']
            : null;
        $orgUnitId = isset($state['org_unit_id']) ? (int) $state['org_unit_id'] : null;
        $caseId = isset($state['case_id'])
            ? (int) $state['case_id']
            : ($objectType === 'leave_request' && is_numeric($objectId) ? (int) $objectId : null);

        foreach ((array) ($action['anchorTypes'] ?? []) as $anchorValue) {
            $anchorType = AnchorType::tryFrom((string) $anchorValue);

            if ($anchorType === null) {
                continue;
            }

            $anchor = match ($anchorType) {
                AnchorType::COMPANY => AnchorRef::company(),
                AnchorType::SELF => $this->selfAnchor($principal, $subjectEmploymentId),
                AnchorType::MANAGED => $this->managedAnchor($principal, $subjectEmploymentId, $businessDate),
                AnchorType::ORG_UNIT => $orgUnitId !== null ? AnchorRef::orgUnit($orgUnitId) : null,
                AnchorType::ORG_SUBTREE => $orgUnitId !== null ? AnchorRef::orgSubtree($orgUnitId) : null,
                AnchorType::CASE => $caseId !== null ? AnchorRef::case($caseId) : null,
                AnchorType::PAYROLL_RUN => $objectType === 'payroll_run' && is_numeric($objectId)
                    ? AnchorRef::payrollRun((int) $objectId)
                    : null,
            };

            if ($anchor !== null && $this->authorizer->allows($principal, $capability, $anchor, $businessDate)) {
                return true;
            }
        }

        return false;
    }

    private function selfAnchor(Principal $principal, ?int $subjectEmploymentId): ?AnchorRef
    {
        if ($subjectEmploymentId === null || $principal->employmentId() !== $subjectEmploymentId) {
            return null;
        }

        return AnchorRef::self();
    }

    private function managedAnchor(Principal $principal, ?int $subjectEmploymentId, string $businessDate): ?AnchorRef
    {
        if ($subjectEmploymentId === null || $principal->employmentId() === null) {
            return null;
        }

        $managed = $this->people->managedEmploymentIds($principal->employmentId(), $businessDate);

        return in_array($subjectEmploymentId, $managed, true) ? AnchorRef::managed() : null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function stateAllows(string $actionKey, array $state): bool
    {
        $payslipStatus = strtoupper((string) ($state['payslip_status'] ?? ''));
        $disbursementStatus = strtoupper((string) ($state['disbursement_status'] ?? ''));

        if ($actionKey === 'payroll.run.detail.publish') {
            return $payslipStatus !== 'PUBLISHED' && in_array(strtoupper((string) ($state['status'] ?? '')), ['APPROVED', 'PAID'], true);
        }

        if ($actionKey === 'payroll.run.detail.mark-paid') {
            return $disbursementStatus !== 'REGISTERED' && in_array(strtoupper((string) ($state['status'] ?? '')), ['APPROVED', 'PUBLISHED'], true);
        }

        if ($actionKey === 'payroll.run.detail.close') {
            return $payslipStatus === 'PUBLISHED'
                && $disbursementStatus === 'REGISTERED'
                && in_array(strtoupper((string) ($state['status'] ?? '')), ['PUBLISHED', 'PAID'], true);
        }

        $statuses = self::ALLOWED_STATES[$actionKey] ?? null;

        if ($statuses === null) {
            return true;
        }

        $status = strtoupper((string) ($state['status'] ?? $state['application_status'] ?? ''));

        return $status !== '' && in_array($status, $statuses, true);
    }
}
