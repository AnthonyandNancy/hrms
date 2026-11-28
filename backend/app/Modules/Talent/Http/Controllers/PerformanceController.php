<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\Talent\Models\PerformanceBonusMapping;
use App\Modules\Talent\Models\PerformanceCycle;
use App\Modules\Talent\Models\PerformanceGoal;
use App\Modules\Talent\Models\PerformanceReview;
use App\Modules\Talent\Services\PerformanceBonusMappingService;
use App\Modules\Talent\Services\PerformanceService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\Clock;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PerformanceController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly CompanyBusinessDate $businessDates,
        private readonly PerformanceService $performance,
        private readonly PerformanceBonusMappingService $bonusMappings,
        private readonly CommandRunner $commands,
        private readonly PeopleDirectory $people,
        private readonly Clock $clock,
    ) {}

    public function cycles(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today()->value();
        $this->assertFeature($principal, 'performance.cycle.read', $today);

        $page = max(1, (int) $request->query('page', '1'));
        $pageSize = min(100, max(1, (int) $request->query('page_size', '20')));
        $status = is_string($request->query('status')) ? strtoupper(trim($request->query('status'))) : '';
        $needle = is_string($request->query('q')) ? trim($request->query('q')) : '';

        $query = PerformanceCycle::query()
            ->when($status !== '', fn ($builder) => $builder->where('status', $status))
            ->when($needle !== '', function ($builder) use ($needle): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $needle).'%';
                $builder->where('name', 'like', $term);
            })
            ->orderByDesc('id');

        // A cycle is listed when the caller can see at least one review in it.
        // COMPANY-wide readers (HR) also see cycles that have no review yet;
        // SELF and MANAGED readers do not learn about unrelated cycles.
        if (! $this->authorizer->allows($principal, 'performance.cycle.read', \App\Shared\Authorization\AnchorRef::company(), $today)) {
            $scopedCycleIds = $this->authorizer
                ->constrain(PerformanceReview::query(), $principal, 'performance.cycle.read', $today)
                ->distinct()
                ->pluck('cycle_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $query->whereIn('id', $scopedCycleIds);
        }

        $total = (int) (clone $query)->count();
        $cycles = $query->forPage($page, $pageSize)->get();
        $cycleIds = $cycles->map(static fn (PerformanceCycle $cycle): int => (int) $cycle->id)->all();

        // Review rows are constrained before they contribute to any aggregate,
        // so a manager or employee cannot infer out-of-scope participation
        // counts from the cycle list.
        $reviewRows = $this->authorizer
            ->constrain(PerformanceReview::query()->whereIn('cycle_id', $cycleIds), $principal, 'performance.cycle.read', $today)
            ->get(['cycle_id', 'status']);

        /** @var array<int, array{participant_count: int, submitted_count: int, draft_count: int}> $counts */
        $counts = [];
        foreach ($reviewRows as $review) {
            $cycleId = (int) $review->cycle_id;
            $counts[$cycleId] ??= ['participant_count' => 0, 'submitted_count' => 0, 'draft_count' => 0];
            $counts[$cycleId]['participant_count']++;
            if ((string) $review->status === 'DRAFT') {
                $counts[$cycleId]['draft_count']++;
            } else {
                $counts[$cycleId]['submitted_count']++;
            }
        }

        $items = $cycles->map(function (PerformanceCycle $cycle) use ($counts): array {
            $count = $counts[(int) $cycle->id] ?? ['participant_count' => 0, 'submitted_count' => 0, 'draft_count' => 0];
            $participantCount = (int) $count['participant_count'];
            $submittedCount = (int) $count['submitted_count'];
            $progress = $participantCount === 0 ? 0 : intdiv($submittedCount * 100, $participantCount);

            return [
                'cycle_id' => (int) $cycle->id,
                'name' => (string) $cycle->name,
                'period_from' => (string) $cycle->period_from,
                'period_to' => (string) $cycle->period_to,
                'status' => (string) $cycle->status,
                'participant_count' => $participantCount,
                'submitted_count' => $submittedCount,
                'draft_count' => (int) $count['draft_count'],
                'progress' => $progress,
                'published_at' => $cycle->published_at?->toIso8601ZuluString(),
                'published_by' => $cycle->published_by !== null ? (int) $cycle->published_by : null,
            ];
        })->values()->all();

        return ApiResponse::ok(ApiResponse::list($items, $page, $pageSize, $total));
    }

    public function bonusMappings(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertFeature($principal, 'performance.report.read', $this->businessDates->today()->value());
        $data = $request->validate([
            'employment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'period' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'status' => ['sometimes', 'nullable', 'string', 'in:DRAFT,APPROVED,REVOKED'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = (int) ($data['page'] ?? 1);
        $pageSize = (int) ($data['page_size'] ?? 20);
        $query = PerformanceBonusMapping::query()
            ->when(isset($data['employment_id']), fn ($query) => $query->where('employment_id', (int) $data['employment_id']))
            ->when(isset($data['period']), fn ($query) => $query->where('period', (string) $data['period']))
            ->when(isset($data['status']), fn ($query) => $query->where('status', strtoupper((string) $data['status'])))
            ->orderByDesc('id');
        $total = (int) (clone $query)->count();
        $items = $query->forPage($page, $pageSize)->get()
            ->map(fn (PerformanceBonusMapping $mapping): array => $this->bonusMappingRow($mapping))
            ->values()
            ->all();

        return ApiResponse::ok(ApiResponse::list($items, $page, $pageSize, $total));
    }

    public function storeBonusMapping(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'performance.result.publish');
        $data = $request->validate([
            'snapshot_id' => ['required', 'integer', 'min:1'],
            'payroll_item_code' => ['required', 'string', 'max:64'],
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'amount_cents' => ['required', 'integer', 'min:0'],
            'mapping_version' => ['sometimes', 'string', 'max:32'],
        ]);

        return $this->command(
            $request,
            'bonus_mapping.create',
            $data,
            function () use ($principal, $data): array {
                $mapping = $this->bonusMappings->createDraft(
                    $principal->userId(),
                    (int) $data['snapshot_id'],
                    (string) $data['payroll_item_code'],
                    (string) $data['period'],
                    (int) $data['amount_cents'],
                    (string) ($data['mapping_version'] ?? 'V1'),
                );

                return $this->bonusMappingRow($mapping);
            },
            capability: 'performance.result.publish',
        );
    }

    public function approveBonusMapping(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'performance.result.publish');

        return $this->command(
            $request,
            'bonus_mapping.approve',
            ['mapping_id' => $id],
            function () use ($principal, $id): array {
                return $this->bonusMappingRow($this->bonusMappings->approve($principal->userId(), $id));
            },
            successStatus: 200,
            capability: 'performance.result.publish',
        );
    }

    public function reviews(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today()->value();
        $this->assertFeature($principal, 'performance.review.manage', $today);

        $page = max(1, (int) $request->query('page', '1'));
        $pageSize = min(100, max(1, (int) $request->query('page_size', '20')));
        $cycleId = $request->query('cycle_id') !== null ? (int) $request->query('cycle_id') : null;
        $status = is_string($request->query('status')) ? strtoupper(trim($request->query('status'))) : '';

        $query = PerformanceReview::query()
            ->when($cycleId !== null, fn ($builder) => $builder->where('cycle_id', $cycleId))
            ->when($status !== '', fn ($builder) => $builder->where('status', $status))
            ->orderByDesc('id');
        $query = $this->authorizer->constrain($query, $principal, 'performance.review.manage', $today);

        $total = (int) (clone $query)->count();
        $items = $query->forPage($page, $pageSize)->get()
            ->map(fn (PerformanceReview $review): array => $this->reviewRow($review))
            ->values()
            ->all();

        return ApiResponse::ok(ApiResponse::list($items, $page, $pageSize, $total));
    }

    public function storeCycle(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'performance.cycle.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'after:period_from'],
        ]);

        return $this->command($request, 'cycle', $data, fn (): array => $this->performance->createCycle($principal->userId(), $data), 201, 'performance.cycle.manage');
    }

    public function storeReview(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today()->value();
        $this->assertFeature($principal, 'performance.review.manage', $today);

        $data = $request->validate([
            'cycle_id' => ['required', 'integer', 'min:1'],
            'employment_id' => ['required', 'integer', 'min:1'],
            'manager_employment_id' => ['nullable', 'integer', 'min:1'],
            'goals_text' => ['nullable', 'string', 'max:10000'],
            'self_assessment' => ['nullable', 'string', 'max:10000'],
            'self_rating' => ['nullable', 'string', 'max:16'],
            'manager_assessment' => ['nullable', 'string', 'max:10000'],
            'manager_rating' => ['nullable', 'string', 'max:16'],
            'goals' => ['nullable', 'array'],
            'goals.*.title' => ['required_with:goals', 'string', 'max:200'],
            'goals.*.target_value' => ['required_with:goals', 'string', 'max:120'],
            'goals.*.weight' => ['required_with:goals', 'integer', 'min:1', 'max:100'],
            'goals.*.self_score' => ['nullable', 'string', 'max:16'],
            'goals.*.manager_score' => ['nullable', 'string', 'max:16'],
            'goals.*.due_on' => ['nullable', 'date_format:Y-m-d'],
            'goals.*.status' => ['nullable', 'string', 'in:DRAFT,CONFIRMED,IN_PROGRESS,COMPLETED'],
        ]);

        $hasGoals = array_key_exists('goals', $data);
        $goals = $hasGoals && is_array($data['goals']) ? array_values($data['goals']) : [];
        $this->assertGoalWeights($goals);

        $employmentId = (int) $data['employment_id'];

        // A client-supplied approver can never be the subject, even when the
        // self branch would otherwise strip manager fields (ignoring input is
        // not enough: the request must be rejected, not silently rewritten).
        if (isset($data['manager_employment_id']) && (int) $data['manager_employment_id'] === $employmentId) {
            throw new ConflictException('A review subject and its approver must be different employments.', [
                'reason' => 'SUBJECT_APPROVER_NOT_SEPARATED',
            ]);
        }

        // Self-assessment and manager scoring are different subjects. An
        // employee may write only their own self fields; manager/subject
        // separation is persisted so the scorer cannot be the subject.
        $companyScope = $this->authorizer->allows($principal, 'performance.review.manage', \App\Shared\Authorization\AnchorRef::company(), $today);
        $principalEmploymentId = $principal->employmentId() ?? $this->people->employmentForUser($principal->userId());
        $managedEmploymentIds = $principalEmploymentId !== null
            ? $this->people->managedEmploymentIds((int) $principalEmploymentId, $today)
            : [];
        $isSelf = $principalEmploymentId !== null && (int) $principalEmploymentId === $employmentId;

        if ($isSelf) {
            // Self writes are allowed whenever the actor holds the capability
            // at company scope (HR editing their own draft) or at SELF scope.
            // Manager fields are stripped in both cases.
            foreach (['manager_assessment', 'manager_rating', 'manager_employment_id'] as $managerField) {
                if (array_key_exists($managerField, $data) && $data[$managerField] !== null && $data[$managerField] !== '') {
                    throw new ConflictException('A subject cannot enter their own manager assessment.', [
                        'reason' => 'SUBJECT_APPROVER_NOT_SEPARATED',
                        'field' => $managerField,
                    ]);
                }
            }

            unset($data['manager_assessment'], $data['manager_rating'], $data['manager_employment_id']);

            if (isset($data['goals']) && is_array($data['goals'])) {
                $data['goals'] = array_map(static function (array $goal): array {
                    unset($goal['manager_score']);

                    return $goal;
                }, $data['goals']);
            }

            if ($this->people->employmentSnapshot($employmentId, $today) === null) {
                throw new NotFoundException;
            }
        }

        if (! $isSelf) {
            $this->assertCanActOnEmployment($principal, $employmentId, 'performance.review.manage', $today);
        }

        $managerEmploymentId = $this->managerEmploymentFor($principal, $employmentId, $today);
        if (isset($data['manager_employment_id'])) {
            $managerEmploymentId = (int) $data['manager_employment_id'];
        }

        if ($managerEmploymentId !== null && $managerEmploymentId === $employmentId) {
            throw new ConflictException('A review subject and its approver must be different employments.', [
                'reason' => 'SUBJECT_APPROVER_NOT_SEPARATED',
            ]);
        }

        // A manager may only be named from the persisted reporting line or the
        // acting manager's own employment; client input never invents a manager.
        if ($managerEmploymentId !== null
            && ! $companyScope
            && ! $isSelf
            && $principalEmploymentId !== null
            && $managerEmploymentId !== (int) $principalEmploymentId
            && ! in_array($employmentId, $managedEmploymentIds, true)) {
            throw new ConflictException('The acting manager is not the subject reporting line.', [
                'reason' => 'SUBJECT_APPROVER_NOT_SEPARATED',
            ]);
        }

        $data['manager_employment_id'] = $managerEmploymentId;

        return $this->command($request, 'review', $data, function () use ($principal, $data, $goals, $hasGoals, $isSelf): array {
            $body = $this->performance->createReview($principal->userId(), $data);
            $reviewId = (int) $body['review_id'];
            $review = PerformanceReview::query()->whereKey($reviewId)->lockForUpdate()->first();

            if ($review === null) {
                throw NotFoundException::forResource();
            }

            if ((string) $review->status !== 'DRAFT') {
                throw new ConflictException('This review has been decided and is read-only.', [
                    'reason' => 'REVIEW_DECIDED',
                    'status' => (string) $review->status,
                ]);
            }

            // POST is an idempotent create-or-save for a draft review: there
            // is no PATCH review route, so a later save for the same
            // cycle/employment updates only a still-draft row. Decided
            // reviews are never rewritten here. A self writer never carries
            // manager fields because the controller stripped them above.
            $review->forceFill([
                'manager_employment_id' => $data['manager_employment_id'] ?? $review->manager_employment_id,
                'goals_text' => $data['goals_text'] ?? $review->goals_text,
                'self_assessment' => $data['self_assessment'] ?? $review->self_assessment,
                'self_rating' => $data['self_rating'] ?? $review->self_rating,
                'manager_assessment' => $isSelf ? $review->manager_assessment : ($data['manager_assessment'] ?? $review->manager_assessment),
                'manager_rating' => $isSelf ? $review->manager_rating : ($data['manager_rating'] ?? $review->manager_rating),
            ])->save();
            $review->refresh();

            $body = $this->reviewRow($review);

            if ($hasGoals) {
                $saved = [];
                $titles = [];
                foreach ($goals as $goal) {
                    $title = (string) $goal['title'];
                    $titles[] = $title;
                    $record = PerformanceGoal::query()->updateOrCreate(
                        [
                            'cycle_id' => (int) $data['cycle_id'],
                            'employment_id' => (int) $data['employment_id'],
                            'title' => $title,
                        ],
                        [
                            'target_value' => (string) $goal['target_value'],
                            'weight' => (int) $goal['weight'],
                            'due_on' => $goal['due_on'] ?? null,
                            'status' => (string) ($goal['status'] ?? 'DRAFT'),
                            'recorded_at' => $this->clock->now(),
                        ],
                    );
                    $saved[] = [
                        'goal_id' => (int) $record->id,
                        'title' => (string) $record->title,
                        'target_value' => (string) $record->target_value,
                        'weight' => (int) $record->weight,
                        'self_score' => isset($goal['self_score']) ? (string) $goal['self_score'] : null,
                        'manager_score' => isset($goal['manager_score']) ? (string) $goal['manager_score'] : null,
                        'status' => (string) $record->status,
                    ];
                }

                PerformanceGoal::query()
                    ->where('cycle_id', (int) $data['cycle_id'])
                    ->where('employment_id', (int) $data['employment_id'])
                    ->when($titles !== [], fn ($builder) => $builder->whereNotIn('title', $titles))
                    ->delete();

                // Per-goal scores have no dedicated column in the current
                // schema. They are retained verbatim inside the existing
                // goals_text JSON evidence field, while title/target/weight
                // remain queryable rows in performance_goals.
                $review->forceFill([
                    'goals_text' => json_encode(array_map(static fn (array $goal): array => [
                        'title' => (string) $goal['title'],
                        'target_value' => (string) $goal['target_value'],
                        'weight' => (int) $goal['weight'],
                        'self_score' => isset($goal['self_score']) ? (string) $goal['self_score'] : null,
                        'manager_score' => isset($goal['manager_score']) ? (string) $goal['manager_score'] : null,
                        'status' => (string) ($goal['status'] ?? 'DRAFT'),
                    ], $goals), JSON_THROW_ON_ERROR),
                ])->save();

                $body['goals'] = $saved;
            }

            return $body;
        }, 201, 'performance.review.manage');
    }

    public function submitReview(Request $request, int $reviewId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today()->value();
        $this->assertFeature($principal, 'performance.review.submit', $today);

        $review = PerformanceReview::query()->whereKey($reviewId)->first();
        if ($review === null) {
            throw NotFoundException::forResource();
        }

        $this->assertCanActOnEmployment($principal, (int) $review->employment_id, 'performance.review.submit', $today);

        $data = $request->validate([
            'manager_assessment' => ['nullable', 'string', 'max:10000'],
            'manager_rating' => ['nullable', 'string', 'max:16'],
            'calibrated_rating' => ['nullable', 'string', 'max:16'],
        ]);

        return $this->command($request, "review-{$reviewId}-submit", $data, function () use ($principal, $reviewId, $data): array {
            // The service owns the authoritative cycle lock and
            // CYCLE_PUBLISHED check. The controller must not read a stale
            // pre-transaction cycle row and let a published edit slip through.
            return $this->performance->submitReview($principal->userId(), $reviewId, $data);
        }, 200, 'performance.review.submit');
    }

    public function storeAppeal(Request $request, int $reviewId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today();
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:4000'],
        ]);

        // Company-scope appeal managers (HR) may act for an employee; SELF
        // holders may only appeal their own review. The service repeats the
        // boundary inside the transaction so a direct call cannot bypass it.
        $companyScope = $this->authorizer->allows(
            $principal,
            'performance.appeal.manage',
            \App\Shared\Authorization\AnchorRef::company(),
            $today->value(),
        );
        $selfScope = $this->authorizer->allows(
            $principal,
            'performance.appeal.manage',
            \App\Shared\Authorization\AnchorRef::self(),
            $today->value(),
        );
        if (! $companyScope && ! $selfScope) {
            throw new AuthorizationException('Forbidden.');
        }
        $actorEmploymentId = $principal->employmentId() ?? $this->people->employmentForUser($principal->userId());

        return $this->command($request, "review-{$reviewId}-appeal", $data, fn (): array => $this->performance->createAppeal(
            $principal->userId(),
            $reviewId,
            $data,
            $today->value(),
            $actorEmploymentId,
            $companyScope,
        ), 201, 'performance.appeal.manage');
    }

    /**
     * @param  list<array<string, mixed>>  $goals
     */
    private function assertGoalWeights(array $goals): void
    {
        if ($goals === []) {
            return;
        }

        $total = 0;
        foreach ($goals as $goal) {
            $weight = (int) ($goal['weight'] ?? 0);
            if ($weight < 1 || $weight > 100) {
                throw new ValidationException(['goals' => ['Each goal weight must be an integer between 1 and 100.']], 'The given data was invalid.', [
                    'reason' => 'INVALID_WEIGHT_RANGE',
                    'weight_total' => $total + $weight,
                ]);
            }
            $total += $weight;
        }

        if ($total !== 100) {
            throw new ValidationException(['goals' => ['Goal weights must total exactly 100.']], 'The given data was invalid.', [
                'reason' => 'INVALID_WEIGHT_TOTAL',
                'weight_total' => $total,
            ]);
        }
    }

    private function assertCanActOnEmployment(Principal $principal, int $employmentId, string $capability, string $today): void
    {
        if ($this->authorizer->allows($principal, $capability, \App\Shared\Authorization\AnchorRef::company(), $today)) {
            return;
        }

        $snapshot = $this->people->employmentSnapshot($employmentId, $today);

        if ($snapshot === null) {
            throw new AuthorizationException('Forbidden.');
        }

        $principalEmploymentId = $principal->employmentId();
        if ($principalEmploymentId !== null
            && $principalEmploymentId === $employmentId
            && $this->authorizer->allows($principal, $capability, \App\Shared\Authorization\AnchorRef::self(), $today)) {
            return;
        }

        $managed = $principalEmploymentId !== null
            ? $this->people->managedEmploymentIds((int) $principalEmploymentId, $today)
            : [];
        if ($principalEmploymentId !== null
            && in_array($employmentId, $managed, true)
            && $this->authorizer->allows($principal, $capability, \App\Shared\Authorization\AnchorRef::managed(), $today)) {
            return;
        }

        if ($snapshot->orgUnitId !== null) {
            if ($this->authorizer->allows($principal, $capability, \App\Shared\Authorization\AnchorRef::orgUnit((int) $snapshot->orgUnitId), $today)
                || $this->authorizer->allows($principal, $capability, \App\Shared\Authorization\AnchorRef::orgSubtree((int) $snapshot->orgUnitId), $today)) {
                return;
            }
        }

        throw new AuthorizationException('Forbidden.');
    }

    private function managerEmploymentFor(Principal $principal, int $employmentId, string $today): ?int
    {
        $snapshot = $this->people->employmentSnapshot($employmentId, $today);

        if ($snapshot !== null && $snapshot->managerEmploymentId !== null) {
            return (int) $snapshot->managerEmploymentId;
        }

        $principalEmploymentId = $principal->employmentId() ?? $this->people->employmentForUser($principal->userId());
        if ($principalEmploymentId !== null
            && in_array($employmentId, $this->people->managedEmploymentIds($principalEmploymentId, $today), true)) {
            return $principalEmploymentId;
        }

        return null;
    }

    private function assertFeature(Principal $principal, string $capability, string $today): void
    {
        if (! $this->authorizer->permits($principal, $capability, $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewRow(PerformanceReview $review): array
    {
        return [
            'review_id' => (int) $review->id,
            'cycle_id' => (int) $review->cycle_id,
            'employment_id' => (int) $review->employment_id,
            'manager_employment_id' => $review->manager_employment_id !== null ? (int) $review->manager_employment_id : null,
            'goals_text' => $review->goals_text,
            'goals' => $this->goalsForReview($review),
            'self_assessment' => $review->self_assessment,
            'manager_assessment' => $review->manager_assessment,
            'self_rating' => $review->self_rating,
            'manager_rating' => $review->manager_rating,
            'calibrated_rating' => $review->calibrated_rating,
            // Authoritative server projection only: a calibrated grade when
            // one exists, otherwise the manager grade once the review has
            // left DRAFT. The client never derives a grade from goal scores.
            'final_rating' => $review->calibrated_rating !== null
                ? (string) $review->calibrated_rating
                : ((string) $review->status !== 'DRAFT' ? $review->manager_rating : null),
            'status' => (string) $review->status,
            'submitted_at' => $review->submitted_at !== null
                ? \Carbon\CarbonImmutable::parse((string) $review->submitted_at)->toIso8601ZuluString()
                : null,
            'recorded_at' => $review->recorded_at !== null
                ? \Carbon\CarbonImmutable::parse((string) $review->recorded_at)->toIso8601ZuluString()
                : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function goalsForReview(PerformanceReview $review): array
    {
        $scoreByTitle = [];
        if (is_string($review->goals_text) && str_starts_with(ltrim($review->goals_text), '[')) {
            $decoded = json_decode($review->goals_text, true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    if (is_array($entry) && isset($entry['title']) && is_string($entry['title'])) {
                        $scoreByTitle[$entry['title']] = $entry;
                    }
                }
            }
        }

        return PerformanceGoal::query()
            ->where('cycle_id', (int) $review->cycle_id)
            ->where('employment_id', (int) $review->employment_id)
            ->orderBy('id')
            ->get()
            ->map(static function (PerformanceGoal $goal) use ($scoreByTitle): array {
                $scores = $scoreByTitle[(string) $goal->title] ?? [];

                return [
                    'goal_id' => (int) $goal->id,
                    'title' => (string) $goal->title,
                    'target_value' => (string) $goal->target_value,
                    'weight' => (int) $goal->weight,
                    'self_score' => isset($scores['self_score']) ? (string) $scores['self_score'] : null,
                    'manager_score' => isset($scores['manager_score']) ? (string) $scores['manager_score'] : null,
                    'status' => (string) $goal->status,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  \Closure(): array<string, mixed>  $operation
     */
    private function command(
        Request $request,
        string $scope,
        array $data,
        \Closure $operation,
        int $successStatus = 201,
        string $capability = 'performance.cycle.manage',
    ): JsonResponse {
        $principal = $this->principals->resolve();
        // CommandRunner owns the idempotency-key contract and returns the
        // canonical VALIDATION_ERROR / IDEMPOTENCY_KEY_REQUIRED response.
        $key = (string) $request->header('Idempotency-Key', '');

        // The capability is declared on the command as executable metadata;
        // the route middleware is still the enforcement point.
        $definition = new CommandDefinition(
            name: 'talent.performance.'.$scope,
            capability: $capability,
            anchor: \App\Shared\Authorization\AnchorType::COMPANY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: 'talent.performance.'.$scope,
        );

        $result = $this->commands->run(
            $definition,
            $data,
            function () use ($operation, $successStatus): array {
                return [
                    'http_status' => $successStatus,
                    'body' => $operation(),
                    'resource_type' => 'talent.performance',
                ];
            },
            $key,
        );

        $response = $result->status === 201 && ! $result->replayed
            ? ApiResponse::created($result->body)
            : ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function bonusMappingRow(PerformanceBonusMapping $mapping): array
    {
        return [
            'id' => (int) $mapping->id,
            'performance_snapshot_id' => (int) $mapping->performance_snapshot_id,
            'cycle_id' => (int) $mapping->cycle_id,
            'employment_id' => (int) $mapping->employment_id,
            'payroll_item_code' => (string) $mapping->payroll_item_code,
            'period' => (string) $mapping->period,
            'amount_cents' => (int) $mapping->amount_cents,
            'mapping_version' => (string) $mapping->mapping_version,
            'status' => (string) $mapping->status,
            'approved_by' => $mapping->approved_by !== null ? (int) $mapping->approved_by : null,
            'approved_at' => $mapping->approved_at?->toIso8601ZuluString(),
            'source_revision' => (string) $mapping->source_revision,
        ];
    }

    private function allow(Principal $principal, string $capability): void
    {
        $today = $this->businessDates->today()->value();

        if (! $this->authorizer->allows($principal, $capability, \App\Shared\Authorization\AnchorRef::company(), $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
