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
    public function isMember(int $tenantId, int $userId, int $caseId, string $capabilityCode): bool
    {
        $case = CaseModel::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($caseId)
            ->first();

        if ($case === null) {
            return false;
        }

        if ((int) $case->requester_user_id === $userId) {
            return true;
        }

        return WorkflowTask::query()
            ->where('tenant_id', $tenantId)
            ->where('case_id', $caseId)
            ->where('assignee_user_id', $userId)
            ->exists();
    }

    /** @return list<int> */
    public function caseIdsForUser(int $tenantId, int $userId, string $businessDate): array
    {
        $requested = CaseModel::query()
            ->where('tenant_id', $tenantId)
            ->where('requester_user_id', $userId)
            ->pluck('id')
            ->all();

        $assigned = WorkflowTask::query()
            ->where('tenant_id', $tenantId)
            ->where('assignee_user_id', $userId)
            ->pluck('case_id')
            ->all();

        return array_values(array_unique(array_map('intval', [...$requested, ...$assigned])));
    }

    public function legalEntityForCase(int $tenantId, int $caseId): ?int
    {
        $value = CaseModel::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($caseId)
            ->value('legal_entity_id');

        return $value === null ? null : (int) $value;
    }
}
