<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Services;

use App\Modules\Lifecycle\Models\LifecycleIntent;
use App\Modules\Lifecycle\Models\LifecycleTask;
use App\Modules\Lifecycle\Models\OnboardingChecklistTemplate;
use App\Modules\Workflow\Contracts\DomainEffectCommand;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Shared transaction-scoped effect mechanics. Handlers call alreadyApplied()
 * before touching People and markEffective() only after the write succeeds, so
 * replaying an effect returns the original frozen result and never appends a
 * second assignment, employment or terms version.
 */
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

        if (is_array($payload) && $payload !== []) {
            return $payload;
        }

        // Older persisted effects may carry only the aggregate envelope. The
        // intent payload is immutable and is the authoritative fallback.
        $intent = $this->intent($command->caseId);

        if ($intent === null) {
            return [];
        }

        return (array) ($intent->intent_json ?? []);
    }

    public function intent(int $caseId): ?LifecycleIntent
    {
        return LifecycleIntent::query()->where('case_id', $caseId)->first();
    }

    /**
     * Return the terminal intent when this case's effect already ran. Handlers
     * must return the same outbox event without writing People again.
     */
    public function alreadyApplied(int $caseId): ?LifecycleIntent
    {
        $intent = LifecycleIntent::query()
            ->where('case_id', $caseId)
            ->lockForUpdate()
            ->first();

        if ($intent === null) {
            throw new ConflictException('The lifecycle intent is missing for this case.', [
                'reason' => 'LIFECYCLE_INTENT_MISSING',
                'case_id' => $caseId,
            ]);
        }

        return $intent->status === LifecycleIntent::STATUS_EFFECTIVE ? $intent : null;
    }

    /**
     * Freeze the pre-effect business facts exactly once, inside the same
     * transaction as the People write. Detail reads use this snapshot instead
     * of reinterpreting later People state.
     *
     * @param  array<string, mixed>  $before
     */
    public function freezeBefore(int $caseId, array $before): void
    {
        $intent = LifecycleIntent::query()
            ->where('case_id', $caseId)
            ->lockForUpdate()
            ->first();

        if ($intent === null) {
            throw new ConflictException('The lifecycle intent is missing for this case.', [
                'reason' => 'LIFECYCLE_INTENT_MISSING',
                'case_id' => $caseId,
            ]);
        }

        if ($intent->before_json !== null) {
            return;
        }

        $intent->forceFill([
            'before_json' => $before,
            'detail_frozen_at' => $intent->detail_frozen_at ?? $this->clock->now(),
        ])->save();

        $this->audit->record(
            actorUserId: null,
            action: 'lifecycle.effect.before_frozen',
            aggregateType: 'lifecycle_intent',
            aggregateId: (int) $intent->id,
            after: [
                'case_id' => $caseId,
                'case_type' => (string) $intent->case_type,
                'before_keys' => array_keys($before),
            ],
        );
    }

    /**
     * Persist the effective projection and its frozen result once.
     *
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $result
     */
    public function markEffective(
        int $caseId,
        ?int $employmentId,
        ?int $resultEmploymentId = null,
        ?string $effectiveAt = null,
        array $after = [],
        array $result = [],
    ): void {
        $intent = LifecycleIntent::query()
            ->where('case_id', $caseId)
            ->lockForUpdate()
            ->first();

        if ($intent === null) {
            throw new ConflictException('The lifecycle intent is missing for this case.', [
                'reason' => 'LIFECYCLE_INTENT_MISSING',
                'case_id' => $caseId,
            ]);
        }

        $now = $this->clock->now();
        $intent->forceFill([
            'status' => LifecycleIntent::STATUS_EFFECTIVE,
            'employment_id' => $intent->employment_id ?? $employmentId,
            'result_employment_id' => $resultEmploymentId ?? $employmentId ?? $intent->result_employment_id,
            'effective_at' => $intent->effective_at ?? ($effectiveAt !== null && $effectiveAt !== '' ? $now : null),
            'after_json' => $intent->after_json ?? $after,
            'effect_result_json' => $intent->effect_result_json ?? $result,
            'detail_frozen_at' => $intent->detail_frozen_at ?? $now,
        ])->save();

        $this->audit->record(
            actorUserId: null,
            action: 'lifecycle.effect.applied',
            aggregateType: 'lifecycle_intent',
            aggregateId: (int) $intent->id,
            after: [
                'case_id' => $caseId,
                'employment_id' => $intent->employment_id,
                'result_employment_id' => $intent->result_employment_id,
                'planned_effective_on' => $intent->planned_effective_on,
                'effective_at' => $intent->effective_at !== null ? \Carbon\CarbonImmutable::parse((string) $intent->effective_at)->toIso8601ZuluString() : null,
                'effect_result' => (array) ($intent->effect_result_json ?? []),
            ],
        );
    }

    public function markFailed(int $caseId): void
    {
        LifecycleIntent::query()
            ->where('case_id', $caseId)
            ->where('status', LifecycleIntent::STATUS_DRAFT)
            ->update(['status' => LifecycleIntent::STATUS_FAILED]);
    }

    /**
     * @return list<array{task_code:string,title:string,required:bool,assignee_role:string|null}>
     */
    public function checklist(): array
    {
        $rows = OnboardingChecklistTemplate::query()
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

    public function createOnboardingChecklist(int $caseId, int $employmentId, string $hireDate): void
    {
        foreach ($this->checklist() as $task) {
            $created = LifecycleTask::query()->firstOrCreate(
                ['case_id' => $caseId, 'task_code' => $task['task_code']],
                [
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
     * @return list<array{task_code:string,required:bool,status:string,waiver_reason:string|null}>
     */
    public function checklistSnapshot(int $caseId): array
    {
        return LifecycleTask::query()
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

    public function hasOpenRequiredChecklist(int $employmentId): bool
    {
        return LifecycleTask::query()
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
