<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\CaseDecisionPort;
use App\Modules\Workflow\Contracts\WorkflowPrincipalResolver;
use App\Modules\Workflow\Enums\DecisionAction;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;

/**
 * Workflow-owned implementation of the narrow decision port. Business modules
 * can advance a case decision without importing Workflow models or services.
 * Authorization and assignee checks remain inside DecisionService.
 */
final class CaseDecisionService implements CaseDecisionPort
{
    public function __construct(
        private readonly WorkflowPrincipalResolver $principals,
        private readonly DecisionService $decisions,
    ) {}

    public function decide(
        int $tenantId,
        int $userId,
        int $caseId,
        string $action,
        string $comment,
        string $businessDate,
    ): array {
        $principal = $this->principals->resolve();

        if ($principal === null || $principal->tenantId !== $tenantId || $principal->userId !== $userId) {
            throw new ConflictException('The workflow principal does not match the request actor.', [
                'reason' => 'WORKFLOW_PRINCIPAL_MISMATCH',
            ]);
        }

        $decision = DecisionAction::tryFrom(strtoupper($action));

        if ($decision === null) {
            throw new ValidationException(
                ['action' => ['The decision action is not supported.']],
                'The given data was invalid.',
                ['reason' => 'WORKFLOW_DECISION_ACTION_INVALID'],
            );
        }

        $case = $this->decisions->decide(
            principal: $principal,
            caseId: $caseId,
            action: $decision,
            comment: $comment,
            targetUserId: null,
            reason: null,
        );

        return [
            'case_id' => (int) $case->id,
            'case_status' => $case->status->value,
            'effect_status' => $case->effect_status->value,
            'business_date' => $businessDate,
        ];
    }
}
