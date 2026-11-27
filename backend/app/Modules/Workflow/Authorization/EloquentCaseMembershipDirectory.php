<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Authorization;

use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Shared\Authorization\Contracts\CaseMembershipDirectory;

/**
 * Production CASE-anchor membership adapter. A user is a case member when they
 * requested it or hold a task on it.
 */
final class EloquentCaseMembershipDirectory implements CaseMembershipDirectory
{
    public function isMember(int $userId, int $caseId, string $capabilityCode): bool
    {
        $case = CaseModel::query()
            ->whereKey($caseId)
            ->first();

        if ($case === null) {
            return false;
        }

        if ((int) $case->requester_user_id === $userId) {
            return true;
        }

        return WorkflowTask::query()
            ->where('case_id', $caseId)
            ->where('assignee_user_id', $userId)
            ->exists();
    }

    /** @return list<int> */
    public function caseIdsForUser(int $userId, string $businessDate): array
    {
        $requested = CaseModel::query()
            ->where('requester_user_id', $userId)
            ->pluck('id')
            ->all();

        $assigned = WorkflowTask::query()
            ->where('assignee_user_id', $userId)
            ->pluck('case_id')
            ->all();

        return array_values(array_unique(array_map('intval', [...$requested, ...$assigned])));
    }
}
