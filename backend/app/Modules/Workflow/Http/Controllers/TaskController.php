<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Http\Middleware\WorkflowPrincipalContext;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaskController
{
    public function my(Request $request): JsonResponse
    {
        $principal = WorkflowPrincipalContext::from($request);
        $today = now('UTC');

        $tasks = WorkflowTask::query()
            ->where('tenant_id', $principal->tenantId)
            ->where('assignee_user_id', $principal->userId)
            ->where('status', \App\Modules\Workflow\Enums\TaskStatus::PENDING->value)
            ->with(['case'])
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();

        $items = $tasks->map(static function (WorkflowTask $task) use ($today): array {
            $dueAt = $task->due_at;

            return [
                'task_id' => $task->id,
                'case_id' => $task->case_id,
                'case_no' => $task->case?->case_no,
                'title' => $task->case?->title,
                'node_key' => $task->node_key,
                'application_status' => $task->case?->status->value,
                'effect_status' => $task->case?->effect_status->value,
                'due_at' => $dueAt?->toIso8601ZuluString(),
                'sla_state' => $dueAt === null ? 'NONE' : ($dueAt->lessThan($today) ? 'BREACHED' : 'ON_TRACK'),
            ];
        })->all();

        return ApiResponse::ok(ApiResponse::list($items, 1, count($items), count($items)));
    }
}
