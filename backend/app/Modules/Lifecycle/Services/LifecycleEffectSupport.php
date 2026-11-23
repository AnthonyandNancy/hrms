<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Services;

use App\Modules\Lifecycle\Models\LifecycleIntent;
use App\Modules\Lifecycle\Models\LifecycleTask;
use App\Modules\Lifecycle\Models\OnboardingChecklistTemplate;
use App\Modules\Workflow\Contracts\DomainEffectCommand;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

final class LifecycleEffectSupport
{
    /**
     * @return list<array{task_code:string,title:string,required:bool,assignee_role:string|null}>
     */
    private const DEFAULT_CHECKLIST = [
        ['task_code' => 'ACCOUNT_PROVISION', 'title' => 'Provision user account and access', 'required' => true, 'assignee_role' => 'IT'],
        ['task_code' => 'CONTRACT_SIGNATURE', 'title' => 'Obtain signed employment contract', 'required' => true, 'assignee_role' => 'HR'],
        ['task_code' => 'EQUIPMENT', 'title' => 'Prepare equipment and workstation', 'required' => false, 'assignee_role' => 'IT'],
        ['task_code' => 'PAYROLL_ENROLLMENT', 'title' => 'Enrol in payroll and benefits', 'required' => true, 'assignee_role' => 'PAYROLL'],
        ['task_code' => 'PROBATION_END_DATE', 'title' => 'Record probation end date', 'required' => true, 'assignee_role' => 'HR'],
    ];

    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(DomainEffectCommand $command): array
    {
        $payload = $command->payload['case_payload'] ?? null;

        return is_array($payload) ? $payload : [];
    }

    public function intent(int $tenantId, int $caseId): ?LifecycleIntent
    {
        return LifecycleIntent::query()
            ->where('tenant_id', $tenantId)
            ->where('case_id', $caseId)
            ->first();
    }

    public function alreadyApplied(int $tenantId, int $caseId): ?LifecycleIntent
    {
        $intent = $this->intent($tenantId, $caseId);

        return $intent !== null && $intent->status === LifecycleIntent::STATUS_EFFECTIVE ? $intent : null;
    }

    public function markEffective(int $tenantId, int $caseId, ?int $employmentId, ?int $resultEmploymentId = null, ?string $effectiveAt = null): void
    {
        $intent = $this->intent($tenantId, $caseId);

        if ($intent === null) {
            return;
        }

        $intent->forceFill([
            'status' => LifecycleIntent::STATUS_EFFECTIVE,
            'employment_id' => $employmentId ?? $intent->employment_id,
            'result_employment_id' => $resultEmploymentId ?? $employmentId ?? $intent->result_employment_id,
            'effective_at' => $effectiveAt !== null ? $this->clock->now() : $intent->effective_at,
        ])->save();
    }

    public function markFailed(int $tenantId, int $caseId): void
    {
        $intent = $this->intent($tenantId, $caseId);

        if ($intent !== null && $intent->status !== LifecycleIntent::STATUS_EFFECTIVE) {
            $intent->forceFill(['status' => LifecycleIntent::STATUS_FAILED])->save();
        }
    }

    /**
     * @return list<array{task_code:string,title:string,required:bool,assignee_role:string|null}>
     */
    public function checklistForTenant(int $tenantId): array
    {
        $rows = OnboardingChecklistTemplate::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return self::DEFAULT_CHECKLIST;
        }

        return $rows->map(static fn (OnboardingChecklistTemplate $row): array => [
            'task_code' => (string) $row->task_code,
            'title' => (string) $row->title,
            'required' => (bool) $row->required,
            'assignee_role' => $row->assignee_role !== null ? (string) $row->assignee_role : null,
        ])->all();
    }

    public function createOnboardingChecklist(int $tenantId, int $caseId, int $employmentId, string $hireDate): void
    {
        foreach ($this->checklistForTenant($tenantId) as $index => $task) {
            $created = LifecycleTask::query()->firstOrCreate(
                ['case_id' => $caseId, 'task_code' => $task['task_code']],
                [
                    'tenant_id' => $tenantId,
                    'employment_id' => $employmentId,
                    'title' => $task['title'],
                    'status' => LifecycleTask::STATUS_PENDING,
                    'required' => $task['required'],
                    'assignee_role' => $task['assignee_role'],
                    'due_date' => $hireDate,
                    'recorded_at' => $this->clock->now(),
                    'source_type' => 'CASE',
                    'source_id' => $caseId,
                ],
            );

            if ($created->wasRecentlyCreated) {
                $this->audit->record(
                    tenantId: $tenantId,
                    actorUserId: null,
                    action: 'lifecycle.checklist.created',
                    aggregateType: 'lifecycle_task',
                    aggregateId: (int) $created->id,
                    after: [
                        'case_id' => $caseId,
                        'employment_id' => $employmentId,
                        'task_code' => $task['task_code'],
                        'required' => $task['required'],
                    ],
                );
            }
        }
    }

    /**
     * @return list<array{task_code:string,required:bool,status:string,waiver_reason:?string}>
     */
    public function checklistSnapshot(int $tenantId, int $caseId): array
    {
        return LifecycleTask::query()
            ->where('tenant_id', $tenantId)
            ->where('case_id', $caseId)
            ->orderBy('id')
            ->get()
            ->map(static fn (LifecycleTask $task): array => [
                'task_code' => (string) $task->task_code,
                'required' => (bool) $task->required,
                'status' => (string) $task->status,
                'waiver_reason' => $task->waiver_reason !== null ? (string) $task->waiver_reason : null,
            ])->all();
    }

    public function hasOpenRequiredChecklist(int $tenantId, int $employmentId): bool
    {
        return LifecycleTask::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->where('required', true)
            ->whereNotIn('status', [LifecycleTask::STATUS_DONE, LifecycleTask::STATUS_WAIVED])
            ->exists();
    }

    public function transactional(\Closure $callback): mixed
    {
        /** @var mixed $result */
        $result = DB::transaction(static function () use ($callback): mixed {
            return $callback();
        });

        return $result;
    }
}
