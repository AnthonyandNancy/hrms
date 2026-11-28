<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\IAM\Contracts\UserAudienceDirectory;
use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Enums\TaskStatus;
use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\Decision;
use App\Modules\Workflow\Models\Effect;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Shared\Exceptions\NotFoundException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read model for case detail, scoped list queries and the unified timeline.
 * Application and effect state are always returned as separate fields.
 *
 * Display names are resolved through IAM's UserAudienceDirectory read port.
 * The projection never queries IAM tables and never accepts an id-to-name map
 * from the client. A batch-aware directory can expose displayNames(); when it
 * does not, the per-request cache keeps repeated participants to one lookup.
 */
final class CaseQueryService
{
    /** @var array<int, string|null> */
    private array $displayNameCache = [];

    public function __construct(private readonly UserAudienceDirectory $users) {}

    /**
     * @return array<string, mixed>
     */
    public function detail(WorkflowPrincipal $principal, int $caseId): array
    {
        $case = $this->visibleQuery($principal)
            ->with(['instance', 'effects', 'tasks', 'decisions'])
            ->whereKey($caseId)
            ->first();

        if ($case === null) {
            throw NotFoundException::forResource();
        }

        /** @var Decision|null $latestDecision */
        $latestDecision = $case->decisions->sortByDesc('id')->first();
        $case->setAttribute('latest_decision_user_id', $latestDecision?->actor_user_id);

        return $this->caseData($case, $principal, null, true);
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
            ->select('cases.*')
            ->with(['instance', 'effects'])
            // These correlated scalar projections keep the list page at a
            // fixed query count while still exposing the current assignee and
            // the actual latest decision actor.
            ->addSelect([
                'current_assignee_user_id' => WorkflowTask::query()
                    ->select('assignee_user_id')
                    ->whereColumn('workflow_tasks.case_id', 'cases.id')
                    ->where('status', TaskStatus::PENDING->value)
                    ->orderBy('id')
                    ->limit(1),
                'latest_decision_user_id' => Decision::query()
                    ->select('actor_user_id')
                    ->whereColumn('decisions.case_id', 'cases.id')
                    ->orderByDesc('id')
                    ->limit(1),
            ])
            // Computed in SQL so the list keeps its fixed query budget; an
            // eager load here would add a query per page for one boolean.
            ->withExists(['tasks as is_awaiting_me' => function (Builder $taskQuery) use ($principal): void {
                $taskQuery->where('assignee_user_id', $principal->userId)
                    ->where('status', TaskStatus::PENDING->value);
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
                    ->where('status', TaskStatus::PENDING->value);
            });
        }

        if (isset($filters['view']) && $filters['view'] === 'my_requests') {
            $query->where('requester_user_id', $principal->userId);
        }

        // "我审批的" is the caller's own decision history, including delegated
        // and admin-override records. It is distinct from "visible" because a
        // case can be visible through a request or a task the caller never
        // acted on.
        if (isset($filters['view']) && $filters['view'] === 'my_decided') {
            $query->whereHas('decisions', function (Builder $decisionQuery) use ($principal): void {
                $decisionQuery->where('actor_user_id', $principal->userId);
            });
        }

        // "抄送我的" comes from the durable OBSERVER case-member row written
        // when a CC decision names a recipient. Event-only CC would make this
        // view look complete while losing the recipient on refresh.
        if (isset($filters['view']) && $filters['view'] === 'my_cc') {
            $query->whereHas('caseMembers', function (Builder $memberQuery) use ($principal): void {
                $memberQuery->where('user_id', $principal->userId)
                    ->where('role', 'OBSERVER');
            });
        }

        $total = (clone $query)->count();
        $cases = $query->orderByDesc('id')->forPage($page, $pageSize)->get();

        $nameIds = [];
        foreach ($cases as $case) {
            $nameIds[] = (int) $case->requester_user_id;
            $currentAssignee = $case->getAttribute('current_assignee_user_id');
            $latestDecisionActor = $case->getAttribute('latest_decision_user_id');
            if ($currentAssignee !== null) {
                $nameIds[] = (int) $currentAssignee;
            }
            if ($latestDecisionActor !== null) {
                $nameIds[] = (int) $latestDecisionActor;
            }
        }
        $names = $this->displayNames($nameIds);

        $items = $cases->map(fn (CaseModel $case): array => $this->caseData($case, $principal, $names))->all();

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

        $nameIds = [];
        foreach ($events as $event) {
            if ($event->actor_user_id !== null) {
                $nameIds[] = (int) $event->actor_user_id;
            }
        }
        foreach ($decisions as $decision) {
            $nameIds[] = (int) $decision->actor_user_id;
            $onBehalfOf = (int) ($decision->actor_snapshot_json['on_behalf_of_user_id'] ?? 0);
            if ($onBehalfOf > 0) {
                $nameIds[] = $onBehalfOf;
            }
        }
        $names = $this->displayNames($nameIds);

        $items = [];

        foreach ($events as $event) {
            $items[] = [
                'type' => 'WORKFLOW_EVENT',
                'event_type' => $event->event_type,
                'occurred_at' => $event->occurred_at?->toIso8601ZuluString(),
                'actor_user_id' => $event->actor_user_id,
                'actor_display_name' => $this->nameFor($event->actor_user_id, $names),
                'payload' => $this->sanitizeEventPayload((array) $event->payload_json),
            ];
        }

        foreach ($decisions as $decision) {
            $snapshot = (array) $decision->actor_snapshot_json;
            $onBehalfOf = isset($snapshot['on_behalf_of_user_id']) ? (int) $snapshot['on_behalf_of_user_id'] : null;
            $adminOverride = ($snapshot['admin_override'] ?? false) === true;
            $adminReason = $adminOverride && is_string($snapshot['admin_override_reason'] ?? null)
                ? (string) $snapshot['admin_override_reason']
                : null;

            $items[] = [
                'type' => 'DECISION',
                'decision_id' => $decision->id,
                'action' => $decision->action->value,
                'node_key' => $decision->node_key,
                'actor_user_id' => $decision->actor_user_id,
                'decided_by_user_id' => $decision->actor_user_id,
                'decided_by_display_name' => $this->nameFor($decision->actor_user_id, $names),
                'on_behalf_of_user_id' => $onBehalfOf,
                'on_behalf_of_display_name' => $this->nameFor($onBehalfOf, $names),
                'delegation_id' => isset($snapshot['delegation_id']) ? (int) $snapshot['delegation_id'] : null,
                'admin_override' => $adminOverride,
                'reason' => $adminReason,
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
                'failure_summary' => $this->effectFailureSummary($effect),
                'failed_at' => $effect->failed_at?->toIso8601ZuluString(),
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
                'effects' => $effects->map(fn (Effect $effect): array => $this->effectData($effect))->all(),
            ],
            'items' => $items,
        ];
    }

    /**
     * @param  array<int, string|null>|null  $names
     * @return array<string, mixed>
     */
    public function caseData(
        CaseModel $case,
        ?WorkflowPrincipal $principal = null,
        ?array $names = null,
        bool $loadAssignmentRelations = false,
    ): array {
        $case->loadMissing(['instance', 'effects']);
        if ($loadAssignmentRelations) {
            $case->loadMissing(['tasks', 'decisions']);
        }

        $currentAssigneeUserId = $case->getAttribute('current_assignee_user_id');
        if ($currentAssigneeUserId === null && $case->relationLoaded('tasks')) {
            /** @var WorkflowTask|null $pendingTask */
            $pendingTask = $case->tasks
                ->filter(static fn (WorkflowTask $task): bool => $task->status === TaskStatus::PENDING)
                ->sortBy('id')
                ->first();
            $currentAssigneeUserId = $pendingTask?->assignee_user_id;
        }

        $latestDecisionUserId = $case->getAttribute('latest_decision_user_id');
        if ($latestDecisionUserId === null && $case->relationLoaded('decisions')) {
            /** @var Decision|null $latestDecision */
            $latestDecision = $case->decisions->sortByDesc('id')->first();
            $latestDecisionUserId = $latestDecision?->actor_user_id;
        }

        $ids = [(int) $case->requester_user_id];
        if (is_numeric($currentAssigneeUserId)) {
            $ids[] = (int) $currentAssigneeUserId;
        }
        if (is_numeric($latestDecisionUserId)) {
            $ids[] = (int) $latestDecisionUserId;
        }
        $names ??= $this->displayNames($ids);

        $isMine = $principal !== null && (int) $case->requester_user_id === $principal->userId;
        $isAwaitingMe = $principal !== null && $this->isAwaitingPrincipal($case, $principal);
        // ADMIN_OVERRIDE is deliberately narrower than read-all: the caller
        // must still be able to read the case through the visible query before
        // the entry point is returned.
        $canAdminOverride = $principal?->can('workflow.case.admin') === true && $case->status->isOpen();

        return [
            'id' => $case->id,
            'case_no' => $case->case_no,
            'type' => $case->type,
            'subject_type' => $case->subject_type,
            'subject_id' => $case->subject_id,
            'title' => $case->title,
            // A bounded business-summary projection: never echo arbitrary
            // request payload JSON, nested PII, or module-private fields.
            'payload' => $this->sanitizeCasePayload((array) $case->payload_json),
            'application_status' => $case->status->value,
            'effect_status' => $case->effect_status->value,
            'requester_user_id' => $case->requester_user_id,
            'requester_display_name' => $this->nameFor((int) $case->requester_user_id, $names),
            'current_assignee_user_id' => is_numeric($currentAssigneeUserId) ? (int) $currentAssigneeUserId : null,
            'current_assignee_display_name' => $this->nameFor(
                is_numeric($currentAssigneeUserId) ? (int) $currentAssigneeUserId : null,
                $names,
            ),
            'decided_by_user_id' => is_numeric($latestDecisionUserId) ? (int) $latestDecisionUserId : null,
            'decided_by_display_name' => $this->nameFor(
                is_numeric($latestDecisionUserId) ? (int) $latestDecisionUserId : null,
                $names,
            ),
            'template_id' => $case->template_id,
            'template_version' => $case->template_version,
            'business_date' => $case->business_date,
            'submitted_at' => $case->submitted_at?->toIso8601ZuluString(),
            'closed_at' => $case->closed_at?->toIso8601ZuluString(),
            'current_node_key' => $case->instance?->current_node_key,
            'is_mine' => $isMine,
            'is_awaiting_me' => $isAwaitingMe,
            'decision_context' => [
                'is_awaiting_me' => $isAwaitingMe,
                'can_admin_override' => $canAdminOverride,
                // A direct assignee acts as themselves. The reason gate only
                // applies when an administrator is overriding the assignment.
                'requires_reason' => $canAdminOverride && ! $isAwaitingMe,
            ],
            'effects' => $case->effects->map(fn (Effect $effect): array => $this->effectData($effect))->all(),
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
                && $task->status === TaskStatus::PENDING,
        );
    }

    public function resolveWritableCase(WorkflowPrincipal $principal, int $caseId): CaseModel
    {
        $case = CaseModel::query()
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
            ->where(function (Builder $query) use ($principal): void {
                if ($principal->can('workflow.case.admin')) {
                    return;
                }

                $query->where('requester_user_id', $principal->userId)
                    ->orWhereHas('tasks', fn (Builder $tasks): Builder => $tasks->where('assignee_user_id', $principal->userId))
                    // CC observers and delegated/historical decision actors
                    // keep access without manufacturing a pending task.
                    ->orWhereHas('caseMembers', function (Builder $members) use ($principal): void {
                        $members->where('user_id', $principal->userId)
                            ->where('role', 'OBSERVER');
                    })
                    ->orWhereHas('decisions', fn (Builder $decisions): Builder => $decisions->where('actor_user_id', $principal->userId));
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
        // A closed key list, not a blacklist: business payloads and free text
        // are outside the timeline projection contract.
        $allowed = [
            'case_id',
            'decision_id',
            'node_key',
            'action',
            'from_node_key',
            'to_node_key',
            'effect_id',
            'effect_type',
            'status',
            'attempts',
            'requires_escalation',
            'scheduled_for',
            'delegation_id',
            'on_behalf_of_user_id',
            'admin_override',
            'to_user_id',
            'requester_user_id',
            'template_version',
            'type',
            'execution',
        ];

        $safe = [];
        foreach ($allowed as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) || is_int($value) || is_bool($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, scalar|null>
     */
    private function sanitizeCasePayload(array $payload): array
    {
        $allowed = [
            'title',
            'reason',
            'destination',
            'purpose',
            'from_date',
            'to_date',
            'leave_type_code',
            'minutes',
            'half_day',
            'notice_id',
            'audience_count',
            'employment_id',
            'employee_no',
            'onboarding_draft_id',
            'effective_from',
            'effective_to',
            'valid_from',
            'valid_to',
            'amount_minor',
            'total_amount_minor',
            'base_salary_cents',
            'currency_code',
        ];

        $summary = [];
        foreach ($allowed as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) || is_int($value) || is_bool($value) || $value === null) {
                $summary[$key] = $value;
            }
        }

        return $summary;
    }

    /**
     * @return array{effect_id:int,effect_type:string,status:string,attempts:int,requires_escalation:bool,failure_summary:string|null,failed_at:string|null}
     */
    private function effectData(Effect $effect): array
    {
        return [
            'effect_id' => (int) $effect->id,
            'effect_type' => (string) $effect->effect_type,
            'status' => $effect->status->value,
            'attempts' => (int) $effect->attempts,
            'requires_escalation' => (bool) $effect->requires_escalation,
            'failure_summary' => $this->effectFailureSummary($effect),
            'failed_at' => $effect->failed_at?->toIso8601ZuluString(),
        ];
    }

    private function effectFailureSummary(Effect $effect): ?string
    {
        if ($effect->status->value !== 'FAILED') {
            return null;
        }

        $reason = is_string($effect->failure_reason) ? $effect->failure_reason : '';
        $reason = preg_replace('/\s+(?:at|in)\s+.*$/isu', '', $reason) ?? $reason;
        $reason = preg_replace('/(?:SQLSTATE|vendor[\\\\\/]|#\d+|[A-Za-z]:\\\\|[^\s]+\.php:\d+)/iu', '', $reason) ?? $reason;
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        return $reason === '' ? '业务效果执行失败，详情见审计记录。' : mb_substr($reason, 0, 240);
    }

    /**
     * Resolve names through the IAM read port. A future batch method is used
     * when available; otherwise repeated ids are memoised for this request.
     *
     * @param  list<int|string|null>  $userIds
     * @return array<int, string|null>
     */
    private function displayNames(array $userIds): array
    {
        $ids = [];
        foreach ($userIds as $userId) {
            if (is_numeric($userId) && (int) $userId > 0) {
                $ids[(int) $userId] = (int) $userId;
            }
        }

        $missing = array_values(array_filter(
            $ids,
            fn (int $userId): bool => ! array_key_exists($userId, $this->displayNameCache),
        ));

        foreach ($missing as $userId) {
            $value = $this->users->displayName($userId);
            $this->displayNameCache[$userId] = is_string($value) && $value !== '' ? $value : null;
        }

        $names = [];
        foreach (array_keys($ids) as $userId) {
            $names[$userId] = $this->displayNameCache[$userId] ?? null;
        }

        return $names;
    }

    /**
     * @param  array<int, string|null>  $names
     */
    private function nameFor(?int $userId, array $names): ?string
    {
        if ($userId === null) {
            return null;
        }

        return $names[$userId] ?? $this->displayNameCache[$userId] ?? null;
    }
}
