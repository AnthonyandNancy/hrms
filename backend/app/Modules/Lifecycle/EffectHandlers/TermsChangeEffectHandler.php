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

final class TermsChangeEffectHandler implements DomainEffectHandler
{
    public function __construct(
        private readonly LifecycleEffectSupport $support,
        private readonly PeopleEmploymentWriter $people,
        private readonly EmploymentLifecycleDirectory $employments,
    ) {}

    public function supports(string $effectType): bool
    {
        return in_array($effectType, [
            LifecycleCaseType::TERMS_CHANGE->effectType(),
            LifecycleCaseType::CONTRACT_RENEW->effectType(),
        ], true);
    }

    public function apply(DomainEffectCommand $command): DomainEffectResult
    {
        return $this->support->transactional(function () use ($command): DomainEffectResult {
            $existing = $this->support->alreadyApplied($command->caseId);

            if ($existing !== null) {
                return DomainEffectResult::appliedWithOutbox('employment.terms.changed.v1', [
                    'employment_id' => (int) $existing->employment_id,
                    'case_id' => $command->caseId,
                ]);
            }

            $payload = $this->support->payload($command);
            $employmentId = (int) ($payload['employment_id'] ?? 0);
            $effectiveDate = (string) ($payload['effective_date'] ?? $payload['effective_from'] ?? '');
            $employment = $this->employments->employment($employmentId);

            if ($employment === null || BusinessDate::tryFrom($effectiveDate) === null) {
                throw new ValidationException(['employment_id' => ['A valid employment and effective date are required.']]);
            }

            if ($employment->status === 'TERMINATED') {
                throw new ConflictException('Cannot change terms of a terminated employment.', ['reason' => 'EMPLOYMENT_TERMINATED']);
            }

            $before = $this->employments->termsAsOf($employmentId, $effectiveDate);

            if ($before === null) {
                $previousDate = BusinessDate::from($effectiveDate)->addDays(-1)->value();
                $before = $this->employments->termsAsOf($employmentId, $previousDate);
            }

            if ($before === null) {
                throw new ConflictException('Cannot append terms without a current terms version.', ['reason' => 'TERMS_VERSION_MISSING']);
            }

            $this->support->freezeBefore($command->caseId, [
                'employment_id' => $employmentId,
                'employment_status' => $employment->status,
                'terms' => $this->termsPayload($before),
            ]);

            $termsId = $this->people->appendTerms($employmentId, [
                'effective_from' => $effectiveDate,
                'effective_to' => $payload['effective_to'] ?? null,
                'probation_end_date' => $payload['probation_end_date'] ?? $before->probationEndDate,
                'work_location' => (string) ($payload['work_location'] ?? $before->workLocation),
                'work_schedule' => (string) ($payload['work_schedule'] ?? $before->workSchedule),
                'salary_scheme_code' => $payload['salary_scheme_code'] ?? $before->salarySchemeCode,
                'base_salary_minor' => $payload['base_salary_minor'] ?? $before->baseSalaryMinor,
                'salary_currency' => (string) ($payload['salary_currency'] ?? $before->salaryCurrency),
                'notice_period_days' => (int) ($payload['notice_period_days'] ?? $before->noticePeriodDays),
                'confidentiality' => (bool) ($payload['confidentiality'] ?? $before->confidentiality),
                'non_compete' => (bool) ($payload['non_compete'] ?? $before->nonCompete),
                'signed_at' => $payload['signed_at'] ?? null,
                'source_type' => $command->effectType === LifecycleCaseType::CONTRACT_RENEW->effectType() ? 'CONTRACT_RENEW' : 'TERMS_CHANGE',
                'source_id' => $command->caseId,
            ], null);

            $terms = null;
            foreach ($this->employments->termsVersions($employmentId) as $candidate) {
                if ($candidate->id === $termsId) {
                    $terms = $candidate;
                    break;
                }
            }

            $contractVersionId = null;

            if ($command->effectType === LifecycleCaseType::CONTRACT_RENEW->effectType()) {
                $previousContractVersion = (int) \App\Modules\Lifecycle\Models\EmploymentContractVersion::query()
                    ->where('employment_id', $employmentId)
                    ->max('document_version');

                $contractVersion = \App\Modules\Lifecycle\Models\EmploymentContractVersion::query()->create([
                    'employment_id' => $employmentId,
                    'terms_version' => $terms instanceof \App\Modules\People\Contracts\EmploymentTermsSnapshot ? $terms->version : 1,
                    'document_version' => $previousContractVersion + 1,
                    'file_object_id' => $payload['file_object_id'] ?? null,
                    'effective_from' => $effectiveDate,
                    'effective_to' => (string) ($payload['effective_to'] ?? $employment->validTo),
                    'expires_at' => $payload['expires_at'] ?? null,
                    'recorded_at' => now('UTC'),
                    'source_type' => 'CONTRACT_RENEW',
                    'source_id' => $command->caseId,
                ]);
                $contractVersionId = (int) $contractVersion->id;
            }

            $after = [
                'employment_id' => $employmentId,
                'terms_id' => $termsId,
                'terms_version' => $terms instanceof \App\Modules\People\Contracts\EmploymentTermsSnapshot ? $terms->version : null,
                'effective_from' => $effectiveDate,
                'base_salary_minor' => (int) ($payload['base_salary_minor'] ?? $before->baseSalaryMinor),
                'salary_currency' => (string) ($payload['salary_currency'] ?? $before->salaryCurrency),
                'contract_version_id' => $contractVersionId,
            ];
            $this->support->markEffective(
                caseId: $command->caseId,
                employmentId: $employmentId,
                resultEmploymentId: $employmentId,
                effectiveAt: $effectiveDate,
                after: $after,
                result: $after,
            );

            return DomainEffectResult::appliedWithOutbox(
                $command->effectType === LifecycleCaseType::CONTRACT_RENEW->effectType() ? 'employment.contract.renewed.v1' : 'employment.terms.changed.v1',
                [
                    'employment_id' => $employmentId,
                    'terms_id' => $termsId,
                    'case_id' => $command->caseId,
                ],
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function termsPayload(\App\Modules\People\Contracts\EmploymentTermsSnapshot $terms): array
    {
        return [
            'terms_id' => $terms->id,
            'version' => $terms->version,
            'effective_from' => $terms->effectiveFrom,
            'effective_to' => $terms->effectiveTo,
            'salary_scheme_code' => $terms->salarySchemeCode,
            'base_salary_minor' => $terms->baseSalaryMinor,
            'salary_currency' => $terms->salaryCurrency,
            'notice_period_days' => $terms->noticePeriodDays,
        ];
    }

    public function reverse(DomainEffectCommand $command): DomainEffectResult
    {
        throw new \RuntimeException('Terms versions cannot be automatically reversed.');
    }
}
