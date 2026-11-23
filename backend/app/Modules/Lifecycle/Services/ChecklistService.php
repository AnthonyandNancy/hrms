<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Services;

use App\Modules\Lifecycle\Domain\ChecklistPolicy;
use App\Modules\Lifecycle\Models\LifecycleTask;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

final class ChecklistService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly ChecklistPolicy $policy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function update(int $tenantId, int $taskId, int $actorUserId, string $status, ?string $waiverReason): array
    {
        if (! in_array($status, [LifecycleTask::STATUS_DONE, LifecycleTask::STATUS_WAIVED], true)) {
            throw new ConflictException('Checklist tasks can only be completed or waived.', ['reason' => 'CHECKLIST_INVALID_TRANSITION']);
        }

        return DB::transaction(function () use ($tenantId, $taskId, $actorUserId, $status, $waiverReason): array {
            $task = LifecycleTask::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first();

            if ($task === null) {
                throw NotFoundException::forResource();
            }

            if (! $this->policy->canComplete((bool) $task->required, $status)) {
                throw new ConflictException('A required checklist task cannot be left incomplete.', ['reason' => 'CHECKLIST_REQUIRED']);
            }

            if ($status === LifecycleTask::STATUS_WAIVED && (! is_string($waiverReason) || trim($waiverReason) === '')) {
                throw new ConflictException('A waiver requires a reason.', ['reason' => 'CHECKLIST_WAIVER_REASON_REQUIRED']);
            }

            $before = ['status' => (string) $task->status, 'waiver_reason' => $task->waiver_reason];
            $task->forceFill([
                'status' => $status,
                'waiver_reason' => $waiverReason,
                'completed_at' => $this->clock->now(),
                'completed_by_user_id' => $actorUserId,
            ])->save();

            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                action: 'lifecycle.checklist.updated',
                aggregateType: 'lifecycle_task',
                aggregateId: (int) $task->id,
                before: $before,
                after: ['status' => $status, 'waiver_reason' => $waiverReason],
            );

            return [
                'id' => (int) $task->id,
                'employment_id' => $task->employment_id,
                'task_code' => (string) $task->task_code,
                'status' => (string) $task->status,
                'required' => (bool) $task->required,
                'waiver_reason' => $task->waiver_reason,
                'completed_at' => $task->completed_at?->toIso8601ZuluString(),
            ];
        });
    }
}
