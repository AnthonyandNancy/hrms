<?php

declare(strict_types=1);

namespace App\Modules\Time\EffectHandlers;

use App\Modules\Time\Services\LeaveApprovalService;
use App\Modules\Workflow\Contracts\CaseCommandPort;
use App\Modules\Workflow\Contracts\DomainEffectCommand;
use App\Modules\Workflow\Contracts\DomainEffectHandler;
use App\Modules\Workflow\Contracts\DomainEffectResult;
use App\Shared\Exceptions\ConflictException;
use App\Shared\RequestContext;

/**
 * Workflow -> Time bridge for leave approval and reversal.
 *
 * Workflow owns the immutable decision/effect evidence. This handler only
 * translates an approved LEAVE_REQUEST case into Time-owned ledger facts. The
 * Time services remain the sole writers of leave_ledger_entries and keep the
 * source key idempotent, so replaying the same effect never posts a second
 * CONSUME or REVERSE.
 */
final class LeaveApprovalEffectHandler implements DomainEffectHandler
{
    public const APPLY = 'time.leave.apply';

    public const REVERSE = 'time.leave.reverse';

    public const PENDING_RELEASE = 'time.leave.pending.release';

    public function __construct(
        private readonly LeaveApprovalService $leave,
        private readonly CaseCommandPort $cases,
    ) {}

    public function supports(string $effectType): bool
    {
        return in_array($effectType, [self::APPLY, self::REVERSE, self::PENDING_RELEASE], true);
    }

    public function apply(DomainEffectCommand $command): DomainEffectResult
    {
        if ($command->effectType === self::PENDING_RELEASE) {
            $case = $this->approvedCase($command, requireApproved: false);
            $payload = $this->casePayload($command);
            $status = strtoupper((string) ($case['status'] ?? ''));
            $targetState = match ($status) {
                'REJECTED' => 'REJECTED',
                'WITHDRAWN' => 'WITHDRAWN',
                'SUBMITTED' => 'RETURNED',
                default => throw new ConflictException('The leave case is not in a releasable state.', [
                    'reason' => 'LEAVE_CASE_NOT_RELEASABLE',
                    'case_id' => $command->caseId,
                    'status' => $status,
                ]),
            };

            $this->leave->settlePendingCase(
                caseId: $command->caseId,
                targetState: $targetState,
                actorUserId: RequestContext::userId(),
                reason: is_string($payload['reason'] ?? null) && trim((string) $payload['reason']) !== ''
                    ? (string) $payload['reason']
                    : 'Leave request closed',
            );

            return DomainEffectResult::applied();
        }

        if ($command->effectType !== self::APPLY) {
            throw new \RuntimeException('Unsupported leave apply effect type '.$command->effectType.'.');
        }

        $case = $this->approvedCase($command, requireApproved: true);
        $payload = $this->casePayload($command);

        $this->leave->applyApprovedCase(
            caseId: $command->caseId,
            actorUserId: RequestContext::userId(),
            approvedPayload: $payload,
        );

        return DomainEffectResult::applied();
    }

    public function reverse(DomainEffectCommand $command): DomainEffectResult
    {
        if ($command->effectType !== self::REVERSE) {
            throw new \RuntimeException('Unsupported leave reverse effect type '.$command->effectType.'.');
        }

        $this->approvedCase($command, requireApproved: false);
        $payload = $this->casePayload($command);
        $reason = is_string($payload['reason'] ?? null) && trim((string) $payload['reason']) !== ''
            ? (string) $payload['reason']
            : 'Leave approval reversed';

        $this->leave->reverseApprovedCase(
            caseId: $command->caseId,
            actorUserId: RequestContext::userId(),
            reason: $reason,
        );

        return DomainEffectResult::reversed();
    }

    /**
     * @return array<string, mixed>
     */
    private function approvedCase(DomainEffectCommand $command, bool $requireApproved): array
    {
        $case = $this->cases->find($command->caseId);

        if ($case === null) {
            throw new ConflictException('The leave approval case does not exist.', [
                'reason' => 'LEAVE_CASE_NOT_FOUND',
                'case_id' => $command->caseId,
            ]);
        }

        if ((string) ($case['type'] ?? '') !== 'LEAVE_REQUEST') {
            throw new ConflictException('The workflow case is not a leave request.', [
                'reason' => 'LEAVE_CASE_TYPE_MISMATCH',
                'case_id' => $command->caseId,
            ]);
        }

        if ($requireApproved && (string) ($case['status'] ?? '') !== 'APPROVED') {
            throw new ConflictException('The leave request is not approved.', [
                'reason' => 'LEAVE_CASE_NOT_APPROVED',
                'case_id' => $command->caseId,
            ]);
        }

        $payload = $this->casePayload($command);
        $employmentId = (int) ($payload['employment_id'] ?? $case['subject_id'] ?? 0);

        if ((string) ($case['subject_type'] ?? '') !== 'employment'
            || ($case['subject_id'] ?? null) === null
            || (int) $case['subject_id'] !== $employmentId
            || $employmentId < 1) {
            throw new ConflictException('The leave case subject does not match the request.', [
                'reason' => 'LEAVE_CASE_SUBJECT_MISMATCH',
                'case_id' => $command->caseId,
            ]);
        }

        return $case;
    }

    /**
     * @return array<string, mixed>
     */
    private function casePayload(DomainEffectCommand $command): array
    {
        $payload = $command->payload['case_payload'] ?? $command->payload;

        if (! is_array($payload)) {
            throw new ConflictException('The leave effect has no valid case payload.', [
                'reason' => 'LEAVE_CASE_PAYLOAD_INVALID',
                'case_id' => $command->caseId,
            ]);
        }

        return (array) $payload;
    }
}
