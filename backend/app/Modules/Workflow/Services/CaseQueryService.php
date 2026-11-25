<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\Effect;
use App\Shared\Exceptions\NotFoundException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read model for case detail, scoped list queries and the unified timeline.
 * Application and effect state are always returned as separate fields.
 */
final class CaseQueryService
{
    /**
     * @return array<string, mixed>
     */
    public function detail(WorkflowPrincipal $principal, int $caseId): array
    {
        $case = $this->visibleQuery($principal)
            ->with(['instance', 'effects', 'tasks'])
            ->whereKey($caseId)
            ->first();

        if ($case === null) {
            throw NotFoundException::forResource();
        }

        return $this->caseData($case, $principal);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, page: int, page_size: int, total: int}
     */
    public function list(WorkflowPrincipal $principal, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($filters['page_size'] ?? 20)));

        $query = $this->visibleQuery($principal)
            ->with(['instance', 'effects'])
            // Computed in SQL so the list keeps its fixed query budget; an
            // eager load here would add a query per page for one boolean.
            ->withExists(['tasks as is_awaiting_me' => function (Builder $taskQuery) use ($principal): void {
                $taskQuery->where('assignee_user_id', $principal->userId)
                    ->where('status', \App\Modules\Workflow\Enums\TaskStatus::PENDING->value);
            }]);

        if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['effect_status']) && is_string($filters['effect_status']) && $filters['effect_status'] !== '') {
            $query->where('effect_status', $filters['effect_status']);
        }

        if (isset($filters['type']) && is_string($filters['type']) && $filters['type'] !== '') {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['business_date']) && is_string($filters['business_date']) && $filters['business_date'] !== '') {
            $query->where('business_date', $filters['business_date']);
        }

        if (isset($filters['view']) && $filters['view'] === 'my_approvals') {
            $query->whereHas('tasks', function (Builder $taskQuery) use ($principal): void {
                $taskQuery->where('assignee_user_id', $principal->userId)
                    ->where('status', \App\Modules\Workflow\Enums\TaskStatus::PENDING->value);
            });
        }

        if (isset($filters['view']) && $filters['view'] === 'my_requests') {
            $query->where('requester_user_id', $principal->userId);
        }

        $total = (clone $query)->count();
        $cases = $query->orderByDesc('id')->forPage($page, $pageSize)->get();

        $items = $cases->map(fn (CaseModel $case): array => $this->caseData($case, $principal))->all();

        return ['items' => $items, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
    }

    /**
     * @return array<string, mixed>
     */
    public function timeline(WorkflowPrincipal $principal, int $caseId): array
    {
        $case = $this->visibleQuery($principal)->whereKey($caseId)->first();

        if ($case === null) {
            throw NotFoundException::forResource();
        }

        $decisions = $case->decisions()->orderBy('id')->get();
        $events = $case->caseEvents()->orderBy('id')->get();
        $effects = $case->effects()->orderBy('id')->get();

        $items = [];

        foreach ($events as $event) {
            $items[] = [
                'type' => 'WORKFLOW_EVENT',
                'event_type' => $event->event_type,
                'occurred_at' => $event->occurred_at?->toIso8601ZuluString(),
                'actor_user_id' => $event->actor_user_id,
                'payload' => $this->sanitizeEventPayload((array) $event->payload_json),
            ];
        }

        foreach ($decisions as $decision) {
            $items[] = [
                'type' => 'DECISION',
                'decision_id' => $decision->id,
                'action' => $decision->action->value,
                'node_key' => $decision->node_key,
                'actor_user_id' => $decision->actor_user_id,
                'comment' => $decision->comment,
                'occurred_at' => $decision->decided_at?->toIso8601ZuluString(),
            ];
        }

        foreach ($effects as $effect) {
            $items[] = [
                'type' => 'EFFECT',
                'effect_id' => $effect->id,
                'effect_type' => $effect->effect_type,
                'status' => $effect->status->value,
                'attempts' => $effect->attempts,
                'requires_escalation' => $effect->requires_escalation,
                'scheduled_for' => $effect->scheduled_for?->toDateString(),
                'occurred_at' => ($effect->applied_at ?? $effect->failed_at ?? $effect->updated_at)?->toIso8601ZuluString(),
            ];
        }

        usort($items, static fn (array $a, array $b): int => [(string) $a['occurred_at'], $a['type']] <=> [(string) $b['occurred_at'], $b['type']]);

        return [
            'case_id' => $case->id,
            'application' => [
                'status' => $case->status->value,
                'submitted_at' => $case->submitted_at?->toIso8601ZuluString(),
                'closed_at' => $case->closed_at?->toIso8601ZuluString(),
            ],
            'effect' => [
                'status' => $case->effect_status->value,
                'effects' => $effects->map(static fn (Effect $effect): array => [
                    'effect_id' => $effect->id,
                    'effect_type' => $effect->effect_type,
                    'status' => $effect->status->value,
                    'attempts' => $effect->attempts,
                    'requires_escalation' => $effect->requires_escalation,
                ])->all(),
            ],
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function caseData(CaseModel $case, ?WorkflowPrincipal $principal = null): array
    {
        $case->loadMissing(['instance', 'effects']);

        return [
            'id' => $case->id,
            'case_no' => $case->case_no,
            'type' => $case->type,
            'subject_type' => $case->subject_type,
            'subject_id' => $case->subject_id,
            'title' => $case->title,
            'payload' => $case->payload_json,
            'application_status' => $case->status->value,
            'effect_status' => $case->effect_status->value,
            'requester_user_id' => $case->requester_user_id,
            'template_id' => $case->template_id,
            'template_version' => $case->template_version,
            'business_date' => $case->business_date,
            'submitted_at' => $case->submitted_at?->toIso8601ZuluString(),
            'closed_at' => $case->closed_at?->toIso8601ZuluString(),
            'current_node_key' => $case->instance?->current_node_key,
            'is_mine' => $principal !== null && (int) $case->requester_user_id === $principal->userId,
            'is_awaiting_me' => $principal !== null && $this->isAwaitingPrincipal($case, $principal),
            'effects' => $case->effects->map(static fn (Effect $effect): array => [
                'effect_id' => $effect->id,
                'effect_type' => $effect->effect_type,
                'status' => $effect->status->value,
                'attempts' => $effect->attempts,
                'requires_escalation' => $effect->requires_escalation,
            ])->all(),
        ];
    }

    /**
     * The list query computes this in SQL (withExists) to keep its fixed query
     * budget; the detail query eager loads tasks. Reading the relation must
     * never fall back to a lazy load, or the list regains an N+1.
     */
    private function isAwaitingPrincipal(CaseModel $case, WorkflowPrincipal $principal): bool
    {
        if ((bool) ($case->is_awaiting_me ?? false)) {
            return true;
        }

        if (! $case->relationLoaded('tasks')) {
            return false;
        }

        return $case->tasks->contains(
            static fn ($task): bool => (int) $task->assignee_user_id === $principal->userId
                && $task->status === \App\Modules\Workflow\Enums\TaskStatus::PENDING,
        );
    }

    public function resolveWritableCase(WorkflowPrincipal $principal, int $caseId): CaseModel
    {
        $case = CaseModel::query()
            ->where('tenant_id', $principal->tenantId)
            ->whereKey($caseId)
            ->first();

        if ($case === null) {
            throw NotFoundException::forResource();
        }

        if ($case->requester_user_id !== $principal->userId && ! $principal->can('workflow.case.admin')) {
            throw NotFoundException::forResource();
        }

        return $case;
    }

    /**
     * @return Builder<CaseModel>
     */
    private function visibleQuery(WorkflowPrincipal $principal): Builder
    {
        return CaseModel::query()
            ->where('tenant_id', $principal->tenantId)
            ->where(function (Builder $query) use ($principal): void {
                if ($principal->can('workflow.case.admin')) {
                    return;
                }

                $query->where('requester_user_id', $principal->userId)
                    ->orWhereHas('tasks', fn (Builder $tasks): Builder => $tasks->where('assignee_user_id', $principal->userId));
            });
    }

    /**
     * Strip free-text comments and request payload fragments from event
     * projections; events are for state transitions, not PII storage.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizeEventPayload(array $payload): array
    {
        unset($payload['comment'], $payload['payload_json']);

        return $payload;
    }
}
