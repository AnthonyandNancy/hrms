<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Platform\Models\AuditEvent;
use App\Modules\Workflow\Contracts\ActorDirectory;
use App\Modules\Workflow\Contracts\AssigneeEligibility;
use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Domain\WorkflowStateMachine;
use App\Modules\Workflow\Enums\CaseStatus;
use App\Modules\Workflow\Enums\DecisionAction;
use App\Modules\Workflow\Enums\InstanceStatus;
use App\Modules\Workflow\Enums\TaskStatus;
use App\Modules\Workflow\Models\CaseEvent;
use App\Modules\Workflow\Models\CaseMember;
use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\Decision;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Records immutable decisions and drives the approval instance.
 *
 * Transaction technique for a local effect failure:
 *   1. One outer transaction locks the case and writes the Decision, the
 *      APPROVED case transition and the SCHEDULED effect row.
 *   2. EffectExecutor runs the handler in a nested transaction, which Laravel
 *      compiles to SAVEPOINT on SQLite/MySQL.
 *   3. If the handler throws, only that savepoint is rolled back. The executor
 *      catches the exception and writes FAILED + attempts in the outer
 *      transaction. That failure write is the compensation: it commits with
 *      the Decision, so the observable result is Decision + APPROVED + FAILED
 *      with no handler business writes.
 *   4. A later retry is a separate transaction with a row lock and can turn
 *      the same effect row APPLIED without creating a second Decision.
 *
 * The response never combines approval and effect into one "completed" state.
 */
final class DecisionService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AssigneeEligibility $eligibility,
        private readonly ActorDirectory $actors,
        private readonly WorkflowStateMachine $stateMachine,
        private readonly EffectService $effects,
        private readonly DelegationService $delegations,
    ) {}

    public function decide(
        WorkflowPrincipal $principal,
        int $caseId,
        DecisionAction $action,
        string $comment,
        ?int $targetUserId,
        ?string $reason,
    ): CaseModel {
        return DB::transaction(function () use ($principal, $caseId, $action, $comment, $targetUserId, $reason): CaseModel {
            /** @var CaseModel $case */
            $case = CaseModel::query()
                ->where('tenant_id', $principal->tenantId)
                ->whereKey($caseId)
                ->lockForUpdate()
                ->first();

            if ($case === null) {
                throw NotFoundException::forResource();
            }

            if (! $this->stateMachine->canDecide($case->status, $action)) {
                throw new ConflictException('The decision is not allowed in the current case state.', [
                    'reason' => 'INVALID_CASE_TRANSITION',
                    'from' => $case->status->value,
                    'action' => $action->value,
                ]);
            }

            /** @var WorkflowInstance|null $instance */
            $instance = WorkflowInstance::query()
                ->where('case_id', $case->id)
                ->lockForUpdate()
                ->first();

            if ($instance === null) {
                throw new ConflictException('The workflow instance is not running.', [
                    'reason' => 'INSTANCE_NOT_RUNNING',
                    'case_id' => $case->id,
                ]);
            }

            // RETURN leaves the case SUBMITTED with a CANCELLED instance so the
            // requester can append evidence and resubmit. An approval arrives
            // only after submit() starts a fresh RUNNING cycle.
            if ($instance->status !== InstanceStatus::RUNNING
                && $action !== DecisionAction::RETURN) {
                throw new ConflictException('The workflow instance is not running.', [
                    'reason' => 'INSTANCE_NOT_RUNNING',
                    'case_id' => $case->id,
                ]);
            }

            $node = $this->currentNode($case);
            $grant = $this->resolveActingGrant($principal, $case, $action, $node, $reason);

            $this->assertActorEligible($principal, $case, $grant);

            $approvalTally = $this->approvalTally($case, $node);

            if ($action === DecisionAction::APPROVE && $approvalTally + 1 >= (int) ($node['required_count'] ?? 1)) {
                $this->effects->validateDeclarations((array) (($case->template_snapshot_json ?? [])['effects'] ?? []));
            }

            $now = $this->clock->now();
            $decisionSeq = ((int) Decision::query()
                ->where('case_id', $case->id)
                ->where('node_key', (string) $node['key'])
                ->where('actor_user_id', $principal->userId)
                ->max('decision_seq')) + 1;

            $actorSnapshot = $this->snapshotFor($principal, $grant);
            $decision = Decision::query()->create([
                'tenant_id' => $case->tenant_id,
                'case_id' => $case->id,
                'node_key' => (string) $node['key'],
                'actor_user_id' => $principal->userId,
                'decision_seq' => $decisionSeq,
                'action' => $action,
                'comment' => $comment !== '' ? $comment : null,
                'actor_snapshot_json' => $actorSnapshot,
                'template_version' => $case->template_version,
                'evidence_hash' => $this->evidenceHash($case, $principal, $action, $comment, $now->toIso8601ZuluString()),
                'decided_at' => $now,
                'request_id' => \App\Shared\RequestContext::requestId() ?: null,
            ]);

            $task = $this->lockedTaskFor($principal, $grant, $case, (string) $node['key']);

            $this->applyTaskAndRuntime(
                $principal,
                $grant,
                $case,
                $instance,
                $node,
                $action,
                $targetUserId,
                $decision,
                $approvalTally,
            );

            CaseEvent::query()->create([
                'tenant_id' => $case->tenant_id,
                'case_id' => $case->id,
                'event_type' => 'decision.recorded',
                'actor_user_id' => $principal->userId,
                'payload_json' => [
                    'case_id' => $case->id,
                    'decision_id' => $decision->id,
                    'node_key' => $decision->node_key,
                    'action' => $action->value,
                    'comment' => $decision->comment,
                    'on_behalf_of_user_id' => $grant?->principalUserId,
                    'delegation_id' => $grant?->delegationId,
                    'admin_override' => $grant->adminOverride ?? false,
                    'requester_user_id' => $case->requester_user_id,
                ],
                'occurred_at' => $now,
            ]);

            if ($grant?->isDelegation()) {
                $this->audit($principal, $case, 'workflow.delegation.used', [
                    'delegation_id' => $grant->delegationId,
                    'on_behalf_of_user_id' => $grant->principalUserId,
                    'action' => $action->value,
                ]);
            }

            if ($grant?->adminOverride) {
                $this->audit($principal, $case, 'workflow.decision.admin_override', [
                    'action' => $action->value,
                    'reason' => $reason,
                ]);
            }

            // The dedicated withdraw endpoint records a wall-clock event but
            // no Decision. When WITHDRAW arrives as a decision it is evidence
            // and must be immutable like every other action.
            return $case->fresh() ?? $case;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function currentNode(CaseModel $case): array
    {
        $instance = WorkflowInstance::query()->where('case_id', $case->id)->firstOrFail();
        $nodes = (array) (($case->template_snapshot_json ?? [])['nodes'] ?? []);

        foreach ($nodes as $node) {
            if (is_array($node) && ($node['key'] ?? null) === $instance->current_node_key && ($node['type'] ?? null) === 'APPROVAL') {
                return $node;
            }
        }

        throw new ConflictException('The current workflow node is not an approval node.', [
            'reason' => 'INVALID_CURRENT_NODE',
            'case_id' => $case->id,
            'node_key' => $instance->current_node_key,
        ]);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function resolveActingGrant(
        WorkflowPrincipal $principal,
        CaseModel $case,
        DecisionAction $action,
        array $node,
        ?string $reason,
    ): ?ActingGrant {
        $directTask = WorkflowTask::query()
            ->where('case_id', $case->id)
            ->where('node_key', (string) $node['key'])
            ->where('assignee_user_id', $principal->userId)
            ->where('status', TaskStatus::PENDING->value)
            ->exists();

        if ($directTask) {
            return null;
        }

        $delegation = $this->delegations->grantFor($principal, $case, $action, $node);

        if ($delegation !== null) {
            $principalTask = WorkflowTask::query()
                ->where('case_id', $case->id)
                ->where('node_key', (string) $node['key'])
                ->where('assignee_user_id', $delegation->principalUserId)
                ->where('status', TaskStatus::PENDING->value)
                ->exists();

            if ($principalTask) {
                return $delegation;
            }
        }

        if ($principal->can('workflow.case.admin')) {
            if (! is_string($reason) || mb_strlen(trim($reason)) < 10) {
                throw new ValidationException(
                    ['reason' => ['An admin override requires a reason of at least 10 characters.']],
                    'An admin override requires a reason of at least 10 characters.',
                );
            }

            return ActingGrant::admin($principal->userId, $reason);
        }

        throw NotFoundException::forResource();
    }

    private function assertActorEligible(WorkflowPrincipal $principal, CaseModel $case, ?ActingGrant $grant): void
    {
        $actor = $grant->principalUserId ?? $principal->userId;

        if (! $this->eligibility->isEligible($case->tenant_id, $actor, (string) $case->business_date)) {
            throw new ConflictException('The acting user is no longer eligible to decide this case.', [
                'reason' => 'ASSIGNEE_INELIGIBLE',
                'user_id' => $actor,
            ]);
        }
    }

    /**
     * Approvals after the most recent RETURN belong to the current review
     * round. This supports resubmission without rewriting old decisions.
     *
     * @param  array<string, mixed>  $node
     */
    private function approvalTally(CaseModel $case, array $node): int
    {
        $lastReturnId = (int) Decision::query()
            ->where('case_id', $case->id)
            ->where('node_key', (string) $node['key'])
            ->where('action', DecisionAction::RETURN->value)
            ->max('id');

        return Decision::query()
            ->where('case_id', $case->id)
            ->where('node_key', (string) $node['key'])
            ->where('id', '>', $lastReturnId)
            ->where('action', DecisionAction::APPROVE->value)
            ->count();
    }

    private function lockedTaskFor(
        WorkflowPrincipal $principal,
        ?ActingGrant $grant,
        CaseModel $case,
        string $nodeKey,
    ): ?WorkflowTask {
        $actingUserId = $grant->principalUserId ?? $principal->userId;

        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('case_id', $case->id)
            ->where('node_key', $nodeKey)
            ->where('assignee_user_id', $actingUserId)
            ->where('status', TaskStatus::PENDING->value)
            ->lockForUpdate()
            ->first();

        return $task;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function applyTaskAndRuntime(
        WorkflowPrincipal $principal,
        ?ActingGrant $grant,
        CaseModel $case,
        WorkflowInstance $instance,
        array $node,
        DecisionAction $action,
        ?int $targetUserId,
        Decision $decision,
        int $approvalTally,
    ): void {
        $now = $this->clock->now();
        $task = $this->lockedTaskFor($principal, $grant, $case, (string) $node['key']);
        $requiredCount = (int) ($node['required_count'] ?? 1);

        switch ($action) {
            case DecisionAction::APPROVE:
                if ($task !== null) {
                    $task->forceFill(['status' => TaskStatus::APPROVED, 'acted_at' => $now])->save();
                }

                if ($case->status === CaseStatus::SUBMITTED) {
                    $this->stateMachine->assertCaseTransition($case->status, CaseStatus::IN_REVIEW);
                    $case->forceFill(['status' => CaseStatus::IN_REVIEW])->save();
                }

                if ($approvalTally + 1 >= $requiredCount) {
                    $this->completeNode($case, $instance, $node, $decision, $now);
                }

                break;

            case DecisionAction::REJECT:
                if ($task !== null) {
                    $task->forceFill(['status' => TaskStatus::REJECTED, 'acted_at' => $now])->save();
                }

                $this->cancelPendingTasks($case, (string) $node['key'], $now);
                $this->stateMachine->assertCaseTransition($case->status, CaseStatus::REJECTED);
                $case->forceFill(['status' => CaseStatus::REJECTED, 'closed_at' => $now])->save();
                $instance->forceFill(['status' => InstanceStatus::CANCELLED, 'completed_at' => $now])->save();
                $this->event($case, 'case.rejected', $principal->userId, ['decision_id' => $decision->id], $now);
                break;

            case DecisionAction::RETURN:
                if ($task !== null) {
                    $task->forceFill(['status' => TaskStatus::CANCELLED, 'acted_at' => $now])->save();
                }

                $this->cancelPendingTasks($case, (string) $node['key'], $now);
                if ($case->status !== CaseStatus::SUBMITTED) {
                    $this->stateMachine->assertCaseTransition($case->status, CaseStatus::SUBMITTED);
                }
                $case->forceFill(['status' => CaseStatus::SUBMITTED, 'closed_at' => null])->save();
                $instance->forceFill(['status' => InstanceStatus::CANCELLED, 'completed_at' => $now])->save();
                $this->event($case, 'case.returned', $principal->userId, ['decision_id' => $decision->id], $now);
                break;

            case DecisionAction::WITHDRAW:
                if ($principal->userId !== $case->requester_user_id) {
                    throw NotFoundException::forResource();
                }

                $this->cancelPendingTasks($case, (string) $node['key'], $now);
                $this->stateMachine->assertCaseTransition($case->status, CaseStatus::WITHDRAWN);
                $case->forceFill(['status' => CaseStatus::WITHDRAWN, 'closed_at' => $now])->save();
                $instance->forceFill(['status' => InstanceStatus::CANCELLED, 'completed_at' => $now])->save();
                break;

            case DecisionAction::DELEGATE:
                if ($targetUserId === null || $targetUserId < 1) {
                    throw new ValidationException(['target_user_id' => ['A delegation target is required.']], 'A delegation target is required.');
                }

                if ($task !== null) {
                    $task->forceFill(['status' => TaskStatus::DELEGATED, 'acted_at' => $now])->save();
                }

                WorkflowTask::query()->firstOrCreate(
                    [
                        'case_id' => $case->id,
                        'node_key' => (string) $node['key'],
                        'assignee_user_id' => $targetUserId,
                    ],
                    [
                        'tenant_id' => $case->tenant_id,
                        'status' => TaskStatus::PENDING,
                        'due_at' => $task?->due_at,
                        'acted_at' => null,
                        'created_at' => $now,
                    ],
                );

                CaseMember::query()->firstOrCreate(
                    ['case_id' => $case->id, 'user_id' => $targetUserId, 'role' => 'APPROVER'],
                    ['tenant_id' => $case->tenant_id, 'created_at' => $now],
                );

                $this->event($case, 'task.delegated', $principal->userId, [
                    'case_id' => $case->id,
                    'decision_id' => $decision->id,
                    'node_key' => (string) $node['key'],
                    'to_user_id' => $targetUserId,
                ], $now);
                break;

            case DecisionAction::CC:
                $this->event($case, 'case.cc', $principal->userId, [
                    'decision_id' => $decision->id,
                    'to_user_id' => $targetUserId,
                ], $now);
                break;
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function completeNode(
        CaseModel $case,
        WorkflowInstance $instance,
        array $node,
        Decision $decision,
        \Carbon\CarbonImmutable $now,
    ): void {
        $nodes = (array) (($case->template_snapshot_json ?? [])['nodes'] ?? []);
        $approvalKeys = [];
        foreach ($nodes as $candidate) {
            if (is_array($candidate) && ($candidate['type'] ?? null) === 'APPROVAL') {
                $approvalKeys[] = (string) $candidate['key'];
            }
        }

        $currentIndex = array_search((string) $node['key'], $approvalKeys, true);
        $nextKey = $currentIndex !== false ? ($approvalKeys[$currentIndex + 1] ?? null) : null;

        $this->cancelPendingTasks($case, (string) $node['key'], $now);

        if ($nextKey === null) {
            $this->stateMachine->assertCaseTransition($case->status, CaseStatus::APPROVED);
            $case->forceFill(['status' => CaseStatus::APPROVED, 'closed_at' => $now])->save();
            $instance->forceFill([
                'status' => InstanceStatus::COMPLETED,
                'current_node_key' => (string) $node['key'],
                'completed_at' => $now,
            ])->save();

            $this->event($case, 'case.approved', null, [
                'case_id' => $case->id,
                'decision_id' => $decision->id,
            ], $now);

            $this->effects->materialize(
                $case,
                $decision,
                (array) (($case->template_snapshot_json ?? [])['effects'] ?? []),
            );

            return;
        }

        $nextNode = null;
        foreach ($nodes as $candidate) {
            if (is_array($candidate) && ($candidate['key'] ?? null) === $nextKey) {
                $nextNode = $candidate;
                break;
            }
        }

        if ($nextNode === null) {
            throw new ConflictException('The next approval node is missing from the template snapshot.', [
                'reason' => 'SNAPSHOT_NODE_MISSING',
                'case_id' => $case->id,
                'node_key' => $nextKey,
            ]);
        }

        $instance->forceFill(['current_node_key' => (string) $nextKey])->save();
        app(CaseService::class)->createTasksForNode($case, $instance, $nextNode);

        $this->event($case, 'workflow.node_advanced', null, [
            'case_id' => $case->id,
            'from_node_key' => (string) $node['key'],
            'to_node_key' => (string) $nextKey,
        ], $now);
    }

    private function cancelPendingTasks(CaseModel $case, string $nodeKey, \Carbon\CarbonImmutable $now): void
    {
        WorkflowTask::query()
            ->where('case_id', $case->id)
            ->where('node_key', $nodeKey)
            ->where('status', TaskStatus::PENDING->value)
            ->update(['status' => TaskStatus::CANCELLED->value, 'acted_at' => $now]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(CaseModel $case, string $eventType, ?int $actorUserId, array $payload, \Carbon\CarbonImmutable $now): void
    {
        CaseEvent::query()->create([
            'tenant_id' => $case->tenant_id,
            'case_id' => $case->id,
            'event_type' => $eventType,
            'actor_user_id' => $actorUserId,
            'payload_json' => $payload,
            'occurred_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotFor(WorkflowPrincipal $principal, ?ActingGrant $grant): array
    {
        $snapshot = $this->actors->snapshot($principal->tenantId, $principal->userId)?->toArray()
            ?? $principal->actorSnapshot()->toArray();

        if ($grant?->isDelegation()) {
            $snapshot['on_behalf_of_user_id'] = $grant->principalUserId;
            $snapshot['delegation_id'] = $grant->delegationId;
        }

        if ($grant?->adminOverride) {
            $snapshot['admin_override'] = true;
        }

        return $snapshot;
    }

    private function evidenceHash(
        CaseModel $case,
        WorkflowPrincipal $principal,
        DecisionAction $action,
        string $comment,
        string $decidedAt,
    ): string {
        return hash('sha256', json_encode([
            'case_id' => $case->id,
            'case_no' => $case->case_no,
            'template_version' => $case->template_version,
            'template_snapshot_hash' => hash('sha256', json_encode($case->template_snapshot_json, JSON_THROW_ON_ERROR)),
            'payload_hash' => hash('sha256', json_encode($case->payload_json, JSON_THROW_ON_ERROR)),
            'actor_user_id' => $principal->userId,
            'action' => $action->value,
            'comment' => $comment,
            'decided_at' => $decidedAt,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(WorkflowPrincipal $principal, CaseModel $case, string $action, array $metadata): void
    {
        AuditEvent::query()->create([
            'tenant_id' => $case->tenant_id,
            'actor_user_id' => $principal->userId,
            'actor_type' => 'USER',
            'action' => $action,
            'aggregate_type' => 'case',
            'aggregate_id' => (string) $case->id,
            'metadata_json' => $metadata,
            'request_id' => \App\Shared\RequestContext::requestId() ?: null,
            'occurred_at' => $this->clock->now(),
        ]);
    }
}
