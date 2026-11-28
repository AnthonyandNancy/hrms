<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\AssigneeDirectory;
use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Enums\CaseStatus;
use App\Modules\Workflow\Enums\InstanceStatus;
use App\Modules\Workflow\Enums\TaskStatus;
use App\Modules\Workflow\Models\CaseEvent;
use App\Modules\Workflow\Models\CaseMember;
use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Modules\Workflow\Models\WorkflowTemplate;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Application lifecycle commands. Effects are deliberately not executed here;
 * DecisionService owns the decision/effect transaction boundary.
 */
final class CaseService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AssigneeDirectory $assignees,
        private readonly EffectService $effects,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(
        WorkflowPrincipal $principal,
        string $type,
        string $subjectType,
        ?int $subjectId,
        string $title,
        array $payload,
        ?int $templateId,
        string $businessDate,
        bool $submit,
    ): CaseModel {
        return DB::transaction(function () use ($principal, $type, $subjectType, $subjectId, $title, $payload, $templateId, $businessDate, $submit): CaseModel {
            $template = $this->resolveTemplate($templateId, $businessDate);

            $now = $this->clock->now();
            $case = CaseModel::query()->create([
                'case_no' => $this->nextCaseNo($now),
                'type' => $type,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'title' => $title,
                'payload_json' => $payload,
                'status' => CaseStatus::DRAFT,
                'effect_status' => \App\Modules\Workflow\Enums\CaseEffectStatus::NONE,
                'requester_user_id' => $principal->userId,
                'template_id' => $template->id,
                'template_version' => (int) $template->version,
                'template_snapshot_json' => $this->snapshot($template),
                'business_date' => $businessDate,
                'submitted_at' => null,
                'closed_at' => null,
            ]);

            CaseMember::query()->create([
                'case_id' => $case->id,
                'user_id' => $principal->userId,
                'role' => 'REQUESTER',
                'created_at' => $now,
            ]);

            $this->event($case, 'case.created', $principal->userId, [
                'case_id' => $case->id,
                'type' => $type,
                'template_version' => $template->version,
            ], $now);

            if ($submit) {
                $case = $this->submit($principal, $case);
            }

            return $case->fresh() ?? $case;
        });
    }

    public function submit(WorkflowPrincipal $principal, CaseModel $case): CaseModel
    {
        return DB::transaction(function () use ($principal, $case): CaseModel {
            /** @var CaseModel $locked */
            $locked = CaseModel::query()
                ->whereKey($case->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->requester_user_id !== $principal->userId) {
                throw NotFoundException::forResource();
            }

            $instance = WorkflowInstance::query()->where('case_id', $locked->id)->first();

            if (in_array($locked->status, [CaseStatus::SUBMITTED, CaseStatus::IN_REVIEW], true)
                && $instance !== null
                && $instance->status === InstanceStatus::RUNNING) {
                return $locked;
            }

            if ($locked->status !== CaseStatus::DRAFT
                && ! ($locked->status === CaseStatus::SUBMITTED && $instance !== null && $instance->status === InstanceStatus::CANCELLED)) {
                throw new ConflictException('Only a draft or returned case can be submitted.', [
                    'reason' => 'CASE_NOT_SUBMITTABLE',
                    'status' => $locked->status->value,
                ]);
            }

            $snapshot = (array) $locked->template_snapshot_json;
            $nodes = $this->approvalNodes($snapshot, (array) $locked->payload_json);

            if ($nodes === []) {
                throw new ValidationException(
                    ['template' => ['Template has no approval node matching the case payload.']],
                    'Template has no approval node matching the case payload.',
                );
            }

            $now = $this->clock->now();
            $locked->forceFill([
                'status' => CaseStatus::SUBMITTED,
                'submitted_at' => $now,
                'closed_at' => null,
            ])->save();

            if ($instance === null) {
                $instance = WorkflowInstance::query()->create([
                    'case_id' => $locked->id,
                    'current_node_key' => $nodes[0]['key'],
                    'status' => InstanceStatus::RUNNING,
                    'started_at' => $now,
                    'completed_at' => null,
                ]);
            } else {
                $instance->forceFill([
                    'current_node_key' => $nodes[0]['key'],
                    'status' => InstanceStatus::RUNNING,
                    'started_at' => $now,
                    'completed_at' => null,
                ])->save();
            }

            $this->createTasksForNode($locked, $instance, $nodes[0]);
            $this->event($locked, 'case.submitted', $principal->userId, [
                'case_id' => $locked->id,
                'node_key' => $nodes[0]['key'],
            ], $now);

            return $locked->fresh() ?? $locked;
        });
    }

    public function withdraw(
        WorkflowPrincipal $principal,
        CaseModel $case,
        string $comment,
        ?string $authorizingCapability = null,
    ): CaseModel {
        return DB::transaction(function () use ($principal, $case, $comment, $authorizingCapability): CaseModel {
            /** @var CaseModel $locked */
            $locked = CaseModel::query()
                ->whereKey($case->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->requester_user_id !== $principal->userId
                && ($authorizingCapability === null || ! $principal->can($authorizingCapability))) {
                throw NotFoundException::forResource();
            }

            $this->assertWithdrawable($locked);

            $now = $this->clock->now();
            $locked->forceFill([
                'status' => CaseStatus::WITHDRAWN,
                'closed_at' => $now,
            ])->save();

            WorkflowTask::query()
                ->where('case_id', $locked->id)
                ->where('status', TaskStatus::PENDING->value)
                ->update(['status' => TaskStatus::CANCELLED->value, 'acted_at' => $now]);

            WorkflowInstance::query()
                ->where('case_id', $locked->id)
                ->update(['status' => InstanceStatus::CANCELLED->value, 'completed_at' => $now]);

            $this->event($locked, 'case.withdrawn', $principal->userId, [
                'case_id' => $locked->id,
                'comment' => $comment,
            ], $now);
            $this->effects->materializeOutcome($locked);

            return $locked->fresh() ?? $locked;
        });
    }

    public function assertWithdrawable(CaseModel $case): void
    {
        if (! in_array($case->status, [CaseStatus::DRAFT, CaseStatus::SUBMITTED, CaseStatus::IN_REVIEW], true)) {
            throw new ConflictException('The case can no longer be withdrawn.', [
                'reason' => 'CASE_NOT_WITHDRAWABLE',
                'status' => $case->status->value,
            ]);
        }
    }

    /**
     * The snapshot prevents template edits from changing a live case. The
     * case-level effect list is also frozen in this snapshot.
     *
     * @return array<string, mixed>
     */
    public function snapshot(WorkflowTemplate $template): array
    {
        $definition = (array) $template->definition_json;
        $nodes = (array) ($definition['nodes'] ?? []);
        $effects = (array) ($definition['effects'] ?? []);

        return [
            'template_id' => $template->id,
            'template_version' => (int) $template->version,
            'code' => $template->code,
            'name' => $template->name,
            'nodes' => $nodes,
            'effects' => $effects,
            'snapshotted_at' => $this->clock->now()->toIso8601ZuluString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    public function approvalNodes(array $snapshot, array $payload = []): array
    {
        $evaluator = app(\App\Modules\Workflow\Domain\ConditionEvaluator::class);
        $nodes = array_values(array_filter(
            (array) ($snapshot['nodes'] ?? []),
            static fn (mixed $node): bool => is_array($node) && ($node['type'] ?? null) === 'APPROVAL'
                && (! isset($node['condition']) || $evaluator->evaluate($node['condition'], $payload)),
        ));

        usort($nodes, static fn (array $a, array $b): int => ((int) ($a['sequence'] ?? 0)) <=> ((int) ($b['sequence'] ?? 0)));

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function createTasksForNode(CaseModel $case, WorkflowInstance $instance, array $node): void
    {
        $assignees = $this->resolveAssignees($case, $node);
        $now = $this->clock->now();
        $dueAt = isset($node['sla_hours']) && is_numeric($node['sla_hours'])
            ? $now->addHours((int) $node['sla_hours'])
            : null;

        foreach ($assignees as $userId) {
            // The unique (case,node,assignee) row is reused across RETURN /
            // resubmit cycles rather than duplicated. A terminal task becomes
            // pending again; a live one keeps its SLA deadline.
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()->firstOrNew([
                'case_id' => $case->id,
                'node_key' => (string) $node['key'],
                'assignee_user_id' => $userId,
            ]);

            if ($task->exists && $task->status === TaskStatus::PENDING) {
                // Already waiting; do not reset the original due date.
            } elseif ($task->exists) {
                $task->forceFill([
                    'status' => TaskStatus::PENDING,
                    'due_at' => $dueAt,
                    'acted_at' => null,
                ])->save();
            } else {
                $task->forceFill([
                    'status' => TaskStatus::PENDING,
                    'due_at' => $dueAt,
                    'acted_at' => null,
                    'created_at' => $now,
                ])->save();
            }

            CaseMember::query()->firstOrCreate(
                [
                    'case_id' => $case->id,
                    'user_id' => $userId,
                    'role' => 'APPROVER',
                ],
                [
                    'created_at' => $now,
                ],
            );
        }

        if (WorkflowTask::query()->where('case_id', $case->id)->where('node_key', (string) $node['key'])->where('status', TaskStatus::PENDING->value)->count() === 0) {
            throw new ValidationException(
                ['assignee' => ['Approval node has no eligible assignee.']],
                'Approval node has no eligible assignee.',
            );
        }

        $instance->forceFill(['current_node_key' => (string) $node['key']])->save();
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<int>
     */
    public function resolveAssignees(CaseModel $case, array $node): array
    {
        $rule = (array) ($node['assignee_rule'] ?? []);
        $type = (string) ($rule['type'] ?? '');
        $subjectId = (int) ($case->subject_id ?? 0);
        $date = (string) $case->business_date;

        $ids = match ($type) {
            'USER' => isset($rule['user_id']) ? [(int) $rule['user_id']] : [],
            'MANAGER_OF_SUBJECT' => array_filter([$this->assignees->managerUserIdForSubject($case->subject_type, $subjectId, $date)]),
            'ORG_HEAD' => $this->assignees->orgHeadUserIdsForSubject($case->subject_type, $subjectId, $date),
            'ROLE' => isset($rule['role_code']) ? $this->assignees->userIdsWithRole((string) $rule['role_code'], $date) : [],
            'CASE_MEMBER' => $this->caseMemberIds($case, $rule),
            default => throw new ValidationException(
                ['assignee_rule' => ['Unsupported assignee rule type.']],
                'Unsupported assignee rule type.',
            ),
        };

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return list<int>
     */
    private function caseMemberIds(CaseModel $case, array $rule): array
    {
        $role = isset($rule['role']) ? (string) $rule['role'] : null;

        return CaseMember::query()
            ->where('case_id', $case->id)
            ->when($role !== null, fn ($query) => $query->where('role', $role))
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function resolveTemplate(?int $templateId, string $businessDate): WorkflowTemplate
    {
        if ($templateId !== null) {
            $template = WorkflowTemplate::query()
                ->whereKey($templateId)
                ->where('status', 'PUBLISHED')
                ->first();

            if ($template === null) {
                throw NotFoundException::forResource();
            }

            return $template;
        }

        throw new ValidationException(['template_id' => ['A published workflow template is required.']], 'A template is required.');
    }

    private function nextCaseNo(CarbonImmutable $now): string
    {
        $prefix = 'CASE-'.$now->format('Ymd').'-';
        $count = CaseModel::query()->where('case_no', 'like', $prefix.'%')->count();

        return $prefix.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(CaseModel $case, string $eventType, ?int $actorUserId, array $payload, CarbonImmutable $now): CaseEvent
    {
        return CaseEvent::query()->create([
            'case_id' => $case->id,
            'event_type' => $eventType,
            'actor_user_id' => $actorUserId,
            'payload_json' => $payload,
            'occurred_at' => $now,
        ]);
    }
}
