<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\EffectHandlers;

use App\Modules\IAM\Contracts\SessionRevoker;
use App\Modules\Lifecycle\Contracts\ExportTaskCanceller;
use App\Modules\Lifecycle\Domain\ChecklistPolicy;
use App\Modules\Lifecycle\Domain\LifecycleCaseType;
use App\Modules\Lifecycle\Services\LifecycleEffectSupport;
use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Modules\People\Contracts\PeopleEmploymentWriter;
use App\Modules\Time\Contracts\LeaveBalanceReader;
use App\Modules\Workflow\Contracts\DomainEffectCommand;
use App\Modules\Workflow\Contracts\DomainEffectHandler;
use App\Modules\Workflow\Contracts\DomainEffectResult;
use App\Modules\Workflow\Contracts\WorkflowDelegationRevoker;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\BusinessDate;

final class OffboardEffectHandler implements DomainEffectHandler
{
    public function __construct(
        private readonly LifecycleEffectSupport $support,
        private readonly PeopleEmploymentWriter $people,
        private readonly EmploymentLifecycleDirectory $employments,
        private readonly SessionRevoker $sessions,
        private readonly WorkflowDelegationRevoker $delegations,
        private readonly LeaveBalanceReader $leaveBalances,
        private readonly ExportTaskCanceller $exports,
        private readonly ChecklistPolicy $checklists,
    ) {}

    public function supports(string $effectType): bool
    {
        return $effectType === LifecycleCaseType::OFFBOARD->effectType();
    }

    public function apply(DomainEffectCommand $command): DomainEffectResult
    {
        return $this->support->transactional(function () use ($command): DomainEffectResult {
            $existing = $this->support->alreadyApplied($command->caseId);

            if ($existing !== null) {
                return DomainEffectResult::appliedWithOutbox('employment.terminated.v1', [
                    'employment_id' => (int) $existing->employment_id,
                    'case_id' => $command->caseId,
                ]);
            }

            $payload = $this->support->payload($command);
            $employmentId = (int) ($payload['employment_id'] ?? 0);
            $lastWorkingDate = (string) ($payload['last_working_date'] ?? '');
            $employment = $this->employments->employment($employmentId);

            if ($employment === null || BusinessDate::tryFrom($lastWorkingDate) === null) {
                throw new ValidationException(['last_working_date' => ['A valid employment and last working date are required.']]);
            }

            if ($employment->status === 'TERMINATED') {
                throw new ConflictException('The employment is already terminated.', ['reason' => 'EMPLOYMENT_ALREADY_TERMINATED']);
            }

            $this->checklists->assertResolved($this->support->checklistSnapshot($command->caseId));

            if (($payload['final_payroll_required'] ?? true) === false && empty($payload['final_payroll_waiver_reason'])) {
                throw new ValidationException(['final_payroll_waiver_reason' => ['A final payroll waiver needs a reason.']]);
            }

            $outstanding = $this->leaveBalances->outstanding($employmentId, $lastWorkingDate);
            $payable = [];

            foreach ($outstanding as $balance) {
                if ((int) ($balance['available'] ?? 0) > 0) {
                    $payable[] = [
                        'leave_type_id' => (int) ($balance['leave_type_id'] ?? 0),
                        'unused_minutes' => (int) $balance['available'],
                    ];
                }
            }

            $assignment = $this->employments->assignmentAsOf($employmentId, $lastWorkingDate);

            if ($assignment !== null) {
                $this->people->endAssignment(
                    $assignment->id,
                    BusinessDate::from($lastWorkingDate)->addDays(1)->value(),
                    null,
                    'OFFBOARD',
                    $command->caseId,
                );
            }

            $this->people->endEmployment(
                $employmentId,
                BusinessDate::from($lastWorkingDate)->addDays(1)->value(),
                $lastWorkingDate,
                null,
                'OFFBOARD',
                $command->caseId,
            );

            // Access revocation and the local employment effect share this
            // transaction. Auth versions increment so replayed cookies fail.
            $revocations = [];
            $userIds = $this->employments->linkedUserIds($employmentId, $lastWorkingDate);

            foreach ($userIds as $userId) {
                $result = $this->sessions->revokeForUser($userId, 'OFFBOARD');
                if ($result !== null) {
                    $revocations[] = $result;
                }
                $this->delegations->revokeForUser($userId, 'OFFBOARD');
            }

            $cancelledExports = $this->exports->cancelPendingForEmployment($employmentId, 'OFFBOARD');
            $this->support->markEffective($command->caseId, $employmentId, $employmentId, $lastWorkingDate);

            return DomainEffectResult::appliedWithOutbox('employment.terminated.v1', [
                'employment_id' => $employmentId,
                'case_id' => $command->caseId,
                'last_working_date' => $lastWorkingDate,
                'sessions_revoked' => count($revocations),
                'export_tasks_cancelled' => $cancelledExports,
            ]);
        });
    }

    public function reverse(DomainEffectCommand $command): DomainEffectResult
    {
        throw new \RuntimeException('Offboarding cannot be automatically reversed.');
    }
}
