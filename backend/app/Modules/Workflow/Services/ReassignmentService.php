<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\AssigneeDirectory;
use App\Modules\Workflow\Contracts\AssigneeEligibility;
use App\Modules\Workflow\Enums\TaskStatus;
use App\Modules\Workflow\Models\CaseEvent;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Re-resolves pending tasks for ineligible assignees without ever changing a
 * Decision. The old task is ended, a new unique task is created, and the move
 * is represented by append-only case events.
 */
final class ReassignmentService
{
    public function __construct(
        private readonly AssigneeDirectory $assignees,
        private readonly AssigneeEligibility $eligibility,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array{reassigned: int, exhausted: int, checked: int}
     */
    public function reassign(?int $caseId = null): array
    {
        $now = $this->clock->now();
        $query = WorkflowTask::query()
            ->where('status', TaskStatus::PENDING->value)
            ->orderBy('id');

        if ($caseId !== null) {
            $query->where('case_id', $caseId);
        }

        $tasks = $query->get();
        $reassigned = 0;
        $exhausted = 0;

        foreach ($tasks as $task) {
            if ($this->eligibility->isEligible($task->tenant_id, $task->assignee_user_id, $now->toDateString())) {
                continue;
            }

            $result = DB::transaction(function () use ($task, $now): string {
                /** @var WorkflowTask $locked */
                $locked = WorkflowTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== TaskStatus::PENDING) {
                    return 'skipped';
                }

                $candidates = $this->assignees->reassignmentCandidates(
                    $locked->tenant_id,
                    $locked->case_id,
                    $locked->assignee_user_id,
                    $now->toDateString(),
                );

                foreach ($candidates as $candidateId) {
                    if ($candidateId === $locked->assignee_user_id) {
                        continue;
                    }

                    if (! $this->eligibility->isEligible($locked->tenant_id, $candidateId, $now->toDateString())) {
                        continue;
                    }

                    $existing = WorkflowTask::query()
                        ->where('case_id', $locked->case_id)
                        ->where('node_key', $locked->node_key)
                        ->where('assignee_user_id', $candidateId)
                        ->first();

                    if ($existing !== null && $existing->status === TaskStatus::PENDING) {
                        $locked->forceFill(['status' => TaskStatus::CANCELLED, 'acted_at' => $now])->save();

                        return 'existing';
                    }

                    if ($existing !== null) {
                        $existing->forceFill([
                            'status' => TaskStatus::PENDING,
                            'due_at' => $locked->due_at,
                            'acted_at' => null,
                        ])->save();
                    } else {
                        WorkflowTask::query()->create([
                            'tenant_id' => $locked->tenant_id,
                            'case_id' => $locked->case_id,
                            'node_key' => $locked->node_key,
                            'assignee_user_id' => $candidateId,
                            'status' => TaskStatus::PENDING,
                            'due_at' => $locked->due_at,
                            'acted_at' => null,
                            'created_at' => $now,
                        ]);
                    }

                    $locked->forceFill(['status' => TaskStatus::CANCELLED, 'acted_at' => $now])->save();

                    CaseEvent::query()->create([
                        'tenant_id' => $locked->tenant_id,
                        'case_id' => $locked->case_id,
                        'event_type' => 'task.reassigned',
                        'actor_user_id' => null,
                        'payload_json' => [
                            'case_id' => $locked->case_id,
                            'node_key' => $locked->node_key,
                            'old_task_id' => $locked->id,
                            'from_user_id' => $locked->assignee_user_id,
                            'to_user_id' => $candidateId,
                            'reason' => 'ASSIGNEE_INELIGIBLE',
                        ],
                        'occurred_at' => $now,
                    ]);

                    return 'reassigned';
                }

                $locked->forceFill(['status' => TaskStatus::CANCELLED, 'acted_at' => $now])->save();

                CaseEvent::query()->create([
                    'tenant_id' => $locked->tenant_id,
                    'case_id' => $locked->case_id,
                    'event_type' => 'task.reassignment_exhausted',
                    'actor_user_id' => null,
                    'payload_json' => [
                        'case_id' => $locked->case_id,
                        'node_key' => $locked->node_key,
                        'old_task_id' => $locked->id,
                        'from_user_id' => $locked->assignee_user_id,
                        'reason' => 'ASSIGNEE_INELIGIBLE',
                    ],
                    'occurred_at' => $now,
                ]);

                return 'exhausted';
            });

            if ($result === 'reassigned' || $result === 'existing') {
                $reassigned++;
            }

            if ($result === 'exhausted') {
                $exhausted++;
            }
        }

        return ['reassigned' => $reassigned, 'exhausted' => $exhausted, 'checked' => $tasks->count()];
    }
}
