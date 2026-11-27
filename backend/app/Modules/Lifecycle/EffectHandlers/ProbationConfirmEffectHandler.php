<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\EffectHandlers;

use App\Modules\Lifecycle\Domain\LifecycleCaseType;
use App\Modules\Lifecycle\Services\LifecycleEffectSupport;
use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Modules\People\Contracts\PeopleEmploymentWriter;
use App\Modules\Workflow\Contracts\DomainEffectCommand;
use App\Modules\Workflow\Contracts\DomainEffectHandler;
use App\Modules\Workflow\Contracts\DomainEffectResult;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\BusinessDate;

final class ProbationConfirmEffectHandler implements DomainEffectHandler
{
    public function __construct(
        private readonly LifecycleEffectSupport $support,
        private readonly PeopleEmploymentWriter $people,
        private readonly EmploymentLifecycleDirectory $employments,
    ) {}

    public function supports(string $effectType): bool
    {
        return $effectType === LifecycleCaseType::PROBATION_CONFIRM->effectType();
    }

    public function apply(DomainEffectCommand $command): DomainEffectResult
    {
        return $this->support->transactional(function () use ($command): DomainEffectResult {
            $existing = $this->support->alreadyApplied($command->caseId);

            if ($existing !== null) {
                return DomainEffectResult::appliedWithOutbox('employment.probation.confirmed.v1', [
                    'employment_id' => (int) $existing->employment_id,
                    'case_id' => $command->caseId,
                ]);
            }

            $payload = $this->support->payload($command);
            $employmentId = (int) ($payload['employment_id'] ?? 0);
            $effectiveDate = (string) ($payload['effective_date'] ?? '');

            if ($employmentId <= 0 || BusinessDate::tryFrom($effectiveDate) === null) {
                throw new ValidationException(['effective_date' => ['A valid employment_id and effective_date are required.']]);
            }

            $employment = $this->employments->employment($employmentId);

            if ($employment === null) {
                throw new ValidationException(['employment_id' => ['The employment does not exist.']]);
            }

            if ($employment->status !== 'PROBATION') {
                throw new ConflictException('Only a probation employment can be confirmed.', [
                    'reason' => 'EMPLOYMENT_NOT_IN_PROBATION',
                    'status' => $employment->status,
                ]);
            }

            $hire = BusinessDate::from($employment->hireDate);
            $effective = BusinessDate::from($effectiveDate);

            if (! $effective->isAfter($hire)) {
                throw new ValidationException(['effective_date' => ['Probation confirmation must be after the hire date.']]);
            }

            $maximumDays = (int) config('lifecycle.probation_max_days', 180);

            if ($effective->compareTo($hire->addDays($maximumDays)) > 0) {
                throw (new ValidationException(['effective_date' => ['Probation confirmation exceeds the configured maximum.']]))
                    ->withReason('PROBATION_MAX_EXCEEDED');
            }

            $this->support->freezeBefore($command->caseId, [
                'employment_id' => $employmentId,
                'employment_status' => $employment->status,
                'hire_date' => $employment->hireDate,
            ]);
            $this->people->setEmploymentStatus($employmentId, 'ACTIVE', null);
            $this->support->markEffective(
                caseId: $command->caseId,
                employmentId: $employmentId,
                resultEmploymentId: $employmentId,
                effectiveAt: $effectiveDate,
                after: [
                    'employment_id' => $employmentId,
                    'employment_status' => 'ACTIVE',
                    'effective_date' => $effectiveDate,
                ],
                result: [
                    'employment_id' => $employmentId,
                    'employment_status' => 'ACTIVE',
                    'effective_date' => $effectiveDate,
                ],
            );

            return DomainEffectResult::appliedWithOutbox('employment.probation.confirmed.v1', [
                'employment_id' => $employmentId,
                'case_id' => $command->caseId,
                'effective_date' => $effectiveDate,
            ]);
        });
    }

    public function reverse(DomainEffectCommand $command): DomainEffectResult
    {
        throw new \RuntimeException('Probation confirmation cannot be reversed automatically.');
    }
}
