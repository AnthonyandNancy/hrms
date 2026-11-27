<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Services;

use App\Modules\Lifecycle\Domain\LifecycleEffectiveDate;
use App\Modules\Lifecycle\Domain\LifecycleMovementKind;
use App\Modules\Lifecycle\Models\LifecycleIntent;
use App\Modules\Lifecycle\Models\LifecycleTask;
use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Modules\Workflow\Contracts\WorkflowCaseGateway;
use App\Shared\Exceptions\NotFoundException;

/**
 * Frozen read model for one lifecycle case. Workflow remains the only source of
 * application status, effect status and the approval timeline; People facts and
 * the pre/post snapshots are read through contracts or from the intent row.
 *
 * Pending approval never mutates People. The before/after JSON is written by
 * the effect handler inside the effect transaction, and a later People change
 * cannot rewrite the history the approver saw.
 */
final class LifecycleProjectionService
{
    public function __construct(
        private readonly WorkflowCaseGateway $workflow,
        private readonly EmploymentLifecycleDirectory $employments,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function detail(int $caseId, ?int $viewerUserId = null): array
    {
        $intent = LifecycleIntent::query()->where('case_id', $caseId)->first();

        if ($intent === null) {
            throw NotFoundException::forResource();
        }

        $case = $this->workflow->projection($caseId);
        $summary = $this->workflow->caseFor($caseId);

        if ($case === null && $summary === null) {
            throw NotFoundException::forResource();
        }

        $applicationStatus = is_array($case) && isset($case['application_status'])
            ? (string) $case['application_status']
            : ($summary !== null ? (string) $summary->applicationStatus : (string) $intent->status);
        $effectStatus = is_array($case) && isset($case['effect_status'])
            ? (string) $case['effect_status']
            : ($summary !== null ? (string) $summary->effectStatus : 'NONE');
        $caseNo = is_array($case) ? ($case['case_no'] ?? $summary?->caseNo) : $summary?->caseNo;
        $caseType = is_array($case) ? ($case['type'] ?? $summary?->type) : $summary?->type;
        $employmentId = $intent->employment_id ?? $intent->result_employment_id;
        $employment = $employmentId !== null ? $this->employments->employment((int) $employmentId) : null;
        $tasks = LifecycleTask::query()
            ->where('case_id', $caseId)
            ->orderBy('id')
            ->get();
        $payload = (array) ($intent->intent_json ?? []);
        $effectiveDate = $intent->planned_effective_on !== null
            ? (string) $intent->planned_effective_on
            : LifecycleEffectiveDate::forPayload((string) $intent->case_type, $payload);
        $currentAssignee = is_array($case) && is_numeric($case['current_assignee_user_id'] ?? null)
            ? (int) $case['current_assignee_user_id']
            : null;
        $isAwaitingMe = is_array($case) && ($case['is_awaiting_me'] ?? false) === true;
        $isMine = is_array($case) && ($case['is_mine'] ?? false) === true;

        return [
            'case_id' => (int) $intent->case_id,
            'case_no' => $caseNo,
            'title' => is_array($case) ? ($case['title'] ?? $summary?->title) : $summary?->title,
            'case_type' => (string) ($caseType ?? $intent->case_type),
            'kind' => LifecycleMovementKind::forCaseType((string) $intent->case_type),
            'application_status' => $applicationStatus,
            'effect_status' => $effectStatus,
            'domain_status' => (string) $intent->status,
            'subject_type' => (string) $intent->subject_type,
            'subject_id' => $intent->subject_id !== null ? (int) $intent->subject_id : null,
            'payload' => $payload,
            'employment_id' => $intent->employment_id,
            'result_employment_id' => $intent->result_employment_id,
            'effective_date' => $effectiveDate,
            'planned_effective_on' => $intent->planned_effective_on !== null ? (string) $intent->planned_effective_on : null,
            'effective_at' => $intent->effective_at !== null ? \Carbon\CarbonImmutable::parse((string) $intent->effective_at)->toIso8601ZuluString() : null,
            'requester_user_id' => is_array($case) ? ($case['requester_user_id'] ?? null) : null,
            'requester_display_name' => is_array($case) ? ($case['requester_display_name'] ?? null) : null,
            'current_assignee_user_id' => $currentAssignee,
            'current_assignee_display_name' => is_array($case) ? ($case['current_assignee_display_name'] ?? null) : null,
            'is_mine' => $isMine,
            'is_awaiting_me' => $isAwaitingMe || ($viewerUserId !== null && $currentAssignee === $viewerUserId),
            'submitted_at' => is_array($case) ? ($case['submitted_at'] ?? null) : null,
            'closed_at' => is_array($case) ? ($case['closed_at'] ?? null) : null,
            'current_node_key' => is_array($case) ? ($case['current_node_key'] ?? null) : null,
            'before' => (array) ($intent->before_json ?? []),
            'after' => (array) ($intent->after_json ?? []),
            'effect_result' => (array) ($intent->effect_result_json ?? []),
            'detail_frozen_at' => $intent->detail_frozen_at !== null ? \Carbon\CarbonImmutable::parse((string) $intent->detail_frozen_at)->toIso8601ZuluString() : null,
            'case' => [
                'id' => (int) $intent->case_id,
                'case_no' => $caseNo,
                'application_status' => $applicationStatus,
                'effect_status' => $effectStatus,
                'current_node_key' => is_array($case) ? ($case['current_node_key'] ?? null) : null,
                'is_mine' => $isMine,
                'is_awaiting_me' => $isAwaitingMe,
            ],
            'intent' => [
                'id' => (int) $intent->id,
                'status' => (string) $intent->status,
                'recorded_at' => $intent->recorded_at !== null ? \Carbon\CarbonImmutable::parse((string) $intent->recorded_at)->toIso8601ZuluString() : null,
                'planned_effective_on' => $intent->planned_effective_on !== null ? (string) $intent->planned_effective_on : null,
            ],
            'employment' => $employment !== null ? [
                'employment_id' => $employment->id,
                'person_id' => $employment->personId,
                'employee_no' => $employment->employeeNo,
                'status' => $employment->status,
                'valid_from' => $employment->validFrom,
                'valid_to' => $employment->validTo,
                'seniority_start_date' => $employment->seniorityStartDate,
            ] : null,
            'checklist' => $tasks->map(static fn (LifecycleTask $task): array => [
                'id' => $task->id,
                'task_code' => $task->task_code,
                'title' => $task->title,
                'status' => $task->status,
                'required' => $task->required,
                'waiver_reason' => $task->waiver_reason,
                'completed_at' => $task->completed_at?->toIso8601ZuluString(),
            ])->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function checklistForEmployment(int $employmentId): array
    {
        return LifecycleTask::query()
            ->where('employment_id', $employmentId)
            ->orderBy('id')
            ->get()
            ->map(static fn (LifecycleTask $task): array => [
                'id' => $task->id,
                'case_id' => $task->case_id,
                'employment_id' => $task->employment_id,
                'task_code' => $task->task_code,
                'title' => $task->title,
                'status' => $task->status,
                'required' => $task->required,
                'assignee_role' => $task->assignee_role,
                'waiver_reason' => $task->waiver_reason,
                'due_date' => $task->due_date,
                'completed_at' => $task->completed_at?->toIso8601ZuluString(),
            ])->all();
    }
}
