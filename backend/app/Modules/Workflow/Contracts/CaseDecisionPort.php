<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Contracts;

/**
 * Narrow decision port for modules whose approval is a Workflow Case. The
 * caller does not import Workflow models, state machines or services.
 */
interface CaseDecisionPort
{
    /**
     * @return array{case_id: int, case_status: string, effect_status: string}
     */
    public function decide(
        int $tenantId,
        int $userId,
        int $caseId,
        string $action,
        string $comment,
        string $businessDate,
    ): array;
}
