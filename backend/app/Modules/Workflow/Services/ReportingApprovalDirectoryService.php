<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\PendingApprovalRow;
use App\Modules\Workflow\Contracts\ReportingApprovalDirectory;
use App\Modules\Workflow\Enums\TaskStatus;
use App\Modules\Workflow\Models\WorkflowTask;

final class ReportingApprovalDirectoryService implements ReportingApprovalDirectory
{
    /**
     * @return list<PendingApprovalRow>
     */
    public function pendingApprovals(int $tenantId): array
    {
        $tasks = WorkflowTask::query()
            ->with('case')
            ->where('tenant_id', $tenantId)
            ->where('status', TaskStatus::PENDING->value)
            ->orderBy('id')
            ->get();
        $rows = [];

        foreach ($tasks as $task) {
            $case = $task->case;

            if ($case === null) {
                continue;
            }

            $rows[] = new PendingApprovalRow(
                caseId: (int) $case->id,
                caseNo: (string) $case->case_no,
                title: (string) $case->title,
                employmentId: $case->subject_type === 'employment' && $case->subject_id !== null
                    ? (int) $case->subject_id
                    : null,
                businessDate: (string) $case->business_date,
                taskStatus: $task->status->value,
                nodeKey: (string) $task->node_key,
                dueAt: $task->due_at?->toIso8601ZuluString(),
            );
        }

        return $rows;
    }
}
