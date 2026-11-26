<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Services;

use App\Modules\Lifecycle\Models\LifecycleIntent;
use App\Modules\Lifecycle\Models\LifecycleTask;
use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Modules\Workflow\Contracts\WorkflowCaseGateway;
use App\Shared\Exceptions\NotFoundException;

final class LifecycleProjectionService
{
    public function __construct(
        private readonly WorkflowCaseGateway $workflow,
        private readonly EmploymentLifecycleDirectory $employments,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function detail(int $caseId): array
    {
        $case = $this->workflow->caseFor($caseId);
        $intent = LifecycleIntent::query()->where('case_id', $caseId)->first();

        if ($case === null || $intent === null) {
            throw NotFoundException::forResource();
        }

        $employmentId = $intent->employment_id ?? $intent->result_employment_id;
        $employment = $employmentId !== null ? $this->employments->employment((int) $employmentId) : null;
        $tasks = LifecycleTask::query()
            ->where('case_id', $caseId)
            ->orderBy('id')
            ->get();

        return [
            'case_id' => $case->id,
            'case_no' => $case->caseNo,
            'case_type' => $case->type,
            'application_status' => $case->applicationStatus,
            'effect_status' => $case->effectStatus,
            'domain_status' => $intent->status,
            'subject_type' => $case->subjectType,
            'subject_id' => $case->subjectId,
            'payload' => $case->payload,
            'employment_id' => $intent->employment_id,
            'result_employment_id' => $intent->result_employment_id,
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
