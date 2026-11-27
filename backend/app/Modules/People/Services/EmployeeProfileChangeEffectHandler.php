<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\Workflow\Contracts\CaseCommandPort;
use App\Modules\Workflow\Contracts\DomainEffectCommand;
use App\Modules\Workflow\Contracts\DomainEffectHandler;
use App\Modules\Workflow\Contracts\DomainEffectResult;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\RequestContext;

/**
 * Local Workflow effect for an approved employee-profile change.
 *
 * The handler reaches Workflow only through CaseCommandPort. It re-checks that
 * the case is APPROVED and still points at the same employment before People
 * revises a whitelisted sub-profile row. The application status and effect
 * status remain separate Workflow facts.
 */
final class EmployeeProfileChangeEffectHandler implements DomainEffectHandler
{
    public const EFFECT_TYPE = 'people.employee.change.apply';

    private const RESOURCES = ['education', 'experiences', 'contacts', 'certificates'];

    public function __construct(
        private readonly EmployeeProfileChangeApplier $applier,
        private readonly CaseCommandPort $cases,
    ) {}

    public function supports(string $effectType): bool
    {
        return $effectType === self::EFFECT_TYPE;
    }

    public function apply(DomainEffectCommand $command): DomainEffectResult
    {
        $case = $this->cases->find($command->caseId);

        if ($case === null) {
            throw new ConflictException(
                'The employee-profile approval case does not exist.',
                ['reason' => 'CHANGE_CASE_NOT_FOUND'],
            );
        }

        if ((string) ($case['status'] ?? '') !== 'APPROVED') {
            throw new ConflictException(
                'Employee-profile changes apply only after a recorded approval.',
                ['reason' => 'CHANGE_CASE_NOT_APPROVED'],
            );
        }

        $payload = (array) (($command->payload['case_payload'] ?? null) ?? $command->payload);
        $employmentId = (int) ($payload['employment_id'] ?? $case['subject_id'] ?? 0);
        $changeRequestId = (int) ($payload['change_request_id'] ?? 0);
        $recordId = (int) ($payload['record_id'] ?? 0);
        $resource = is_string($payload['resource'] ?? null) ? (string) $payload['resource'] : '';
        $changes = is_array($payload['changes'] ?? null) ? (array) $payload['changes'] : [];

        if ($employmentId <= 0
            || $changeRequestId <= 0
            || $recordId <= 0
            || ! in_array($resource, self::RESOURCES, true)
            || $changes === []) {
            throw new ValidationException(
                ['case_payload' => ['The approval has no valid employee-profile change target.']],
                'The approval has no valid employee-profile change target.',
                ['reason' => 'CHANGE_TARGET_REQUIRED'],
            );
        }

        if ((string) ($case['subject_type'] ?? '') !== 'employment'
            || ($case['subject_id'] ?? null) === null
            || (int) $case['subject_id'] !== $employmentId) {
            throw new ConflictException(
                'The approval case subject does not match the change request.',
                ['reason' => 'CHANGE_CASE_SUBJECT_MISMATCH'],
            );
        }

        $result = $this->applier->apply(
            employmentId: $employmentId,
            changeRequestId: $changeRequestId,
            resource: $resource,
            recordId: $recordId,
            changes: $changes,
            approverUserId: RequestContext::userId(),
            caseId: $command->caseId,
        );

        return DomainEffectResult::appliedWithOutbox('people.employee.change.applied.v1', [
            'employment_id' => $employmentId,
            'change_request_id' => $changeRequestId,
            'case_id' => $command->caseId,
            'replayed' => $result['replayed'],
        ]);
    }

    public function reverse(DomainEffectCommand $command): DomainEffectResult
    {
        throw new \RuntimeException('An applied employee-profile change is append-only and cannot be reversed automatically.');
    }
}
