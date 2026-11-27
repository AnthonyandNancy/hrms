<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Talent\Models\PerformanceCalibration;
use App\Modules\Talent\Models\PerformanceCycle;
use App\Modules\Talent\Models\PerformanceFeedback;
use App\Modules\Talent\Models\PerformanceGoal;
use App\Modules\Talent\Models\PerformanceIndicator;
use App\Modules\Talent\Models\PerformanceResultSnapshot;
use App\Modules\Talent\Models\PerformanceReview;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Performance-cycle write model and live read projections.
 *
 * Invariants owned here:
 *  - calibration writes calibrated_rating and an append-only evidence row;
 *    it never rewrites self_rating or manager_rating;
 *  - a cycle may be published only when every review is submitted;
 *  - once a cycle is PUBLISHED, reviews are frozen (CYCLE_PUBLISHED).
 */
final class PerformanceCycleService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createIndicator(int $actorUserId, array $input): array
    {
        return DB::transaction(function () use ($actorUserId, $input): array {
            $existing = PerformanceIndicator::query()
                ->where('code', (string) $input['code'])
                ->first();

            if ($existing !== null) {
                throw new ConflictException('An indicator with this code already exists.', [
                    'reason' => 'PERFORMANCE_INDICATOR_DUPLICATE',
                ]);
            }

            $indicator = PerformanceIndicator::query()->create([
                'code' => (string) $input['code'],
                'name' => (string) $input['name'],
                'category' => $this->nullableString($input['category'] ?? null),
                'data_source' => $this->nullableString($input['data_source'] ?? null),
                'description' => $this->nullableString($input['description'] ?? null),
                'active' => (bool) ($input['active'] ?? true),
                'recorded_at' => $this->clock->now(),
            ]);

            $this->audit->record($actorUserId, 'talent.performance_indicator.created', 'performance_indicator', (int) $indicator->id, after: [
                'code' => (string) $indicator->code,
            ]);

            return $this->indicatorData($indicator);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createGoal(int $actorUserId, array $input): array
    {
        return DB::transaction(function () use ($actorUserId, $input): array {
            $cycle = $this->cycleOrFail((int) $input['cycle_id']);
            $cycle = PerformanceCycle::query()
                ->whereKey((int) $cycle->id)
                ->lockForUpdate()
                ->first();
            if ($cycle === null) {
                throw new ValidationException(['cycle_id' => ['The performance cycle does not exist.']]);
            }
            $this->assertCycleOpen($cycle);

            if (isset($input['indicator_id'])) {
                $indicator = PerformanceIndicator::query()
                    ->whereKey((int) $input['indicator_id'])
                    ->first();

                if ($indicator === null) {
                    throw new ValidationException(['indicator_id' => ['The performance indicator does not exist.']]);
                }
            }

            $goal = PerformanceGoal::query()->create([
                'cycle_id' => (int) $cycle->id,
                'employment_id' => (int) $input['employment_id'],
                'indicator_id' => isset($input['indicator_id']) ? (int) $input['indicator_id'] : null,
                'title' => (string) $input['title'],
                'target_value' => (string) $input['target_value'],
                'weight' => (int) ($input['weight'] ?? 0),
                'due_on' => $this->nullableString($input['due_on'] ?? null),
                'status' => (string) ($input['status'] ?? 'DRAFT'),
                'recorded_at' => $this->clock->now(),
            ]);

            $this->audit->record($actorUserId, 'talent.performance_goal.created', 'performance_goal', (int) $goal->id, after: [
                'cycle_id' => (int) $goal->cycle_id,
                'employment_id' => (int) $goal->employment_id,
            ]);

            return $this->goalData($goal);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createFeedback(int $actorUserId, array $input): array
    {
        return DB::transaction(function () use ($actorUserId, $input): array {
            $cycle = $this->cycleOrFail((int) $input['cycle_id']);
            $cycle = PerformanceCycle::query()
                ->whereKey((int) $cycle->id)
                ->lockForUpdate()
                ->first();
            if ($cycle === null) {
                throw new ValidationException(['cycle_id' => ['The performance cycle does not exist.']]);
            }
            $this->assertCycleOpen($cycle);

            $reviewId = isset($input['review_id']) ? (int) $input['review_id'] : null;

            if ($reviewId !== null) {
                $review = PerformanceReview::query()
                    ->where('cycle_id', (int) $cycle->id)
                    ->whereKey($reviewId)
                    ->first();

                if ($review === null) {
                    throw new ValidationException(['review_id' => ['The performance review does not belong to this cycle.']]);
                }
            }

            $feedback = PerformanceFeedback::query()->create([
                'cycle_id' => (int) $cycle->id,
                'review_id' => $reviewId,
                'from_employment_id' => (int) $input['from_employment_id'],
                'to_employment_id' => (int) $input['to_employment_id'],
                'feedback_type' => (string) $input['feedback_type'],
                'rating' => $this->nullableString($input['rating'] ?? null),
                'comments' => (string) $input['comments'],
                'anonymous' => (bool) ($input['anonymous'] ?? false),
                'status' => 'RECORDED',
                'recorded_at' => $this->clock->now(),
            ]);

            $this->audit->record($actorUserId, 'talent.performance_feedback.created', 'performance_feedback', (int) $feedback->id, after: [
                'cycle_id' => (int) $feedback->cycle_id,
                'to_employment_id' => (int) $feedback->to_employment_id,
                'anonymous' => (bool) $feedback->anonymous,
            ]);

            return $this->feedbackData($feedback);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function calibrate(int $actorUserId, int $reviewId, array $input): array
    {
        return DB::transaction(function () use ($actorUserId, $reviewId, $input): array {
            $review = PerformanceReview::query()
                ->whereKey($reviewId)
                ->first();

            if ($review === null) {
                throw NotFoundException::forResource();
            }

            // Lock order is cycle -> review: publish, submit, calibration and
            // goal/feedback writes all take the cycle row first.
            $cycle = PerformanceCycle::query()
                ->whereKey((int) $review->cycle_id)
                ->lockForUpdate()
                ->first();

            if ($cycle === null) {
                throw NotFoundException::forResource();
            }

            $this->assertCycleOpen($cycle);

            // Re-read the review under lock after the cycle guard so the
            // previous value used in evidence is the committed one.
            $review = $this->reviewForUpdate($reviewId);
            $previous = $review->calibrated_rating;

            $calibration = PerformanceCalibration::query()->create([
                'cycle_id' => (int) $review->cycle_id,
                'review_id' => (int) $review->id,
                'previous_rating' => $previous !== null ? (string) $previous : null,
                'calibrated_rating' => (string) $input['calibrated_rating'],
                'reason' => (string) $input['reason'],
                'calibrated_by' => $actorUserId,
                'recorded_at' => $this->clock->now(),
            ]);

            // Calibration is deliberately narrow: only the calibrated rating
            // changes. self_rating and manager_rating remain immutable evidence.
            $review->forceFill(['calibrated_rating' => (string) $input['calibrated_rating']])->save();

            $this->audit->record($actorUserId, 'talent.performance_review.calibrated', 'performance_review', (int) $review->id, before: [
                'calibrated_rating' => $previous,
            ], after: [
                'calibrated_rating' => (string) $review->calibrated_rating,
                'calibration_id' => (int) $calibration->id,
            ]);

            return [
                'calibration_id' => (int) $calibration->id,
                'review_id' => (int) $review->id,
                'cycle_id' => (int) $review->cycle_id,
                'previous_rating' => $calibration->previous_rating,
                'calibrated_rating' => (string) $review->calibrated_rating,
                'self_rating' => $review->self_rating,
                'manager_rating' => $review->manager_rating,
                'reason' => (string) $calibration->reason,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function publish(int $actorUserId, int $cycleId): array
    {
        return DB::transaction(function () use ($actorUserId, $cycleId): array {
            $cycle = PerformanceCycle::query()
                ->whereKey($cycleId)
                ->lockForUpdate()
                ->first();

            if ($cycle === null) {
                throw NotFoundException::forResource();
            }

            if ((string) $cycle->status === 'PUBLISHED') {
                throw new ConflictException('The performance cycle is already published.', [
                    'reason' => 'CYCLE_PUBLISHED',
                ]);
            }

            $previousStatus = (string) $cycle->status;

            $reviews = PerformanceReview::query()
                ->where('cycle_id', (int) $cycle->id)
                ->lockForUpdate()
                ->get();

            if ($reviews->isEmpty()) {
                throw new ConflictException('A performance cycle requires at least one submitted review before publishing.', [
                    'reason' => 'REVIEWS_NOT_SUBMITTED',
                ]);
            }

            $unsubmitted = $reviews
                ->filter(static fn (PerformanceReview $review): bool => (string) $review->status !== 'SUBMITTED')
                ->count();

            if ($unsubmitted > 0) {
                throw new ConflictException('Every performance review must be submitted before the cycle can be published.', [
                    'reason' => 'REVIEWS_NOT_SUBMITTED',
                    'unsubmitted_reviews' => $unsubmitted,
                ]);
            }

            $publishedAt = $this->clock->now();

            $cycle->forceFill([
                'status' => 'PUBLISHED',
                'published_at' => $publishedAt,
                'published_by' => $actorUserId,
            ])->save();

            // Publication is one transaction: the cycle transition and its
            // immutable result facts commit together. The snapshot is the only
            // source for published result reads; live ratings are never read
            // again after this point.
            $snapshots = $reviews->map(function (PerformanceReview $review) use ($cycle, $actorUserId, $publishedAt): PerformanceResultSnapshot {
                return PerformanceResultSnapshot::query()->create([
                    'cycle_id' => (int) $cycle->id,
                    'review_id' => (int) $review->id,
                    'employment_id' => (int) $review->employment_id,
                    'self_rating' => $review->self_rating,
                    'manager_rating' => $review->manager_rating,
                    'calibrated_rating' => $review->calibrated_rating,
                    'final_rating' => $this->finalRating($review),
                    'published_at' => $publishedAt,
                    'published_by' => $actorUserId,
                ]);
            });

            $this->audit->record($actorUserId, 'talent.performance_cycle.published', 'performance_cycle', (int) $cycle->id, before: [
                'status' => $previousStatus,
            ], after: [
                'status' => 'PUBLISHED',
                'review_count' => $reviews->count(),
                'snapshot_count' => $snapshots->count(),
                'published_at' => $publishedAt->toIso8601ZuluString(),
            ]);

            return [
                ...$this->cycleData($cycle, $reviews->count()),
                'published_at' => $this->iso($cycle->published_at),
                'published_by' => (int) ($cycle->published_by ?? $actorUserId),
                'results' => $snapshots
                    ->map(fn (PerformanceResultSnapshot $snapshot): array => $this->snapshotData($snapshot))
                    ->values()
                    ->all(),
            ];
        });
    }

    /**
     * Guard used by every review submit/edit command.
     */
    public function assertReviewEditable(int $reviewId): PerformanceReview
    {
        $review = PerformanceReview::query()
            ->whereKey($reviewId)
            ->first();

        if ($review === null) {
            throw NotFoundException::forResource();
        }

        // Lock order is cycle -> review everywhere a write needs both rows.
        // The review is read once to resolve its cycle, then re-read under
        // lock so a concurrent publish cannot interleave between the guard
        // and the caller's mutation.
        $cycle = PerformanceCycle::query()
            ->whereKey((int) $review->cycle_id)
            ->lockForUpdate()
            ->first();

        if ($cycle === null) {
            throw NotFoundException::forResource();
        }

        if ((string) $cycle->status === 'PUBLISHED') {
            throw new ConflictException('The performance cycle is published; reviews can no longer be submitted or edited.', [
                'reason' => 'CYCLE_PUBLISHED',
            ]);
        }

        return $this->reviewForUpdate($reviewId);
    }

    public function indicators(string $asOf): ProjectionResult
    {
        $items = PerformanceIndicator::query()
            ->orderBy('code')
            ->get()
            ->map(fn (PerformanceIndicator $indicator): array => $this->indicatorData($indicator))
            ->values()
            ->all();

        return $this->projection($items, $asOf);
    }

    public function goals(string $asOf): ProjectionResult
    {
        $items = PerformanceGoal::query()
            ->orderByDesc('id')
            ->get()
            ->map(fn (PerformanceGoal $goal): array => $this->goalData($goal))
            ->values()
            ->all();

        return $this->projection($items, $asOf);
    }

    public function feedback(string $asOf): ProjectionResult
    {
        $items = PerformanceFeedback::query()
            ->orderByDesc('id')
            ->get()
            ->map(fn (PerformanceFeedback $feedback): array => $this->feedbackData($feedback))
            ->values()
            ->all();

        return $this->projection($items, $asOf);
    }

    public function calibrations(string $asOf): ProjectionResult
    {
        $items = PerformanceCalibration::query()
            ->orderByDesc('id')
            ->get()
            ->map(fn (PerformanceCalibration $calibration): array => $this->calibrationData($calibration))
            ->values()
            ->all();

        return $this->projection($items, $asOf);
    }

    /**
     * Published results are read from the immutable snapshot ledger; draft and
     * active cycles retain an explicitly pending live projection. The first row
     * exposes the ledger-level flags consumed by the workspace.
     *
     * @param  int|null  $employmentId  Narrow SELF readers to their own result.
     * @param  list<int>|null  $cycleIds  Restrict cross-anchor readers to visible cycles.
     */
    public function results(string $asOf, ?int $employmentId = null, ?array $cycleIds = null): ProjectionResult
    {
        if ($employmentId !== null) {
            return $this->pendingResults($asOf, $employmentId);
        }

        $snapshots = PerformanceResultSnapshot::query()
            ->when($cycleIds !== null, fn ($builder) => $builder->whereIn('cycle_id', $cycleIds))
            ->orderByDesc('id')
            ->get();

        $publishedCycleIds = $snapshots
            ->pluck('cycle_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($snapshots->isNotEmpty()) {
            $cycles = PerformanceCycle::query()
                ->whereIn('id', $publishedCycleIds->all())
                ->orderByDesc('id')
                ->get()
                ->keyBy(static fn (PerformanceCycle $cycle): int => (int) $cycle->id);

            $items = $snapshots
                ->map(function (PerformanceResultSnapshot $snapshot) use ($cycles): array {
                    $cycle = $cycles->get((int) $snapshot->cycle_id);

                    return [
                        'review_id' => (int) $snapshot->review_id,
                        'cycle_id' => (int) $snapshot->cycle_id,
                        'employment_id' => (int) $snapshot->employment_id,
                        'cycle_status' => $cycle !== null ? (string) $cycle->status : 'PUBLISHED',
                        'status' => 'PUBLISHED',
                        'self_rating' => $snapshot->self_rating,
                        'manager_rating' => $snapshot->manager_rating,
                        'calibrated_rating' => $snapshot->calibrated_rating,
                        'final_rating' => (string) $snapshot->final_rating,
                        'snapshot' => true,
                        'published_at' => $this->iso($snapshot->published_at),
                        'published_by' => (int) $snapshot->published_by,
                        'submitted_at' => null,
                    ];
                })
                ->values()
                ->all();

            return $this->projection($items, $asOf, [
                'snapshot' => true,
                'pending' => false,
            ]);
        }

        // No publication exists yet. Return live evidence but label it pending;
        // the client must not present a draft score as a final result.
        $items = PerformanceReview::query()
            ->when($cycleIds !== null, fn ($builder) => $builder->whereIn('cycle_id', $cycleIds))
            ->orderByDesc('id')
            ->get()
            ->map(function (PerformanceReview $review): array {
                $cycle = PerformanceCycle::query()
                    ->whereKey((int) $review->cycle_id)
                    ->first();

                return [
                    'review_id' => (int) $review->id,
                    'cycle_id' => (int) $review->cycle_id,
                    'employment_id' => (int) $review->employment_id,
                    'cycle_status' => $cycle !== null ? (string) $cycle->status : null,
                    'status' => (string) $review->status,
                    'self_rating' => $review->self_rating,
                    'manager_rating' => $review->manager_rating,
                    'calibrated_rating' => $review->calibrated_rating,
                    'final_rating' => null,
                    'snapshot' => false,
                    'published_at' => null,
                    'published_by' => null,
                    'submitted_at' => $this->iso($review->submitted_at),
                ];
            })
            ->values()
            ->all();

        return $this->projection($items, $asOf, [
            'snapshot' => false,
            'pending' => true,
        ]);
    }

    private function pendingResults(string $asOf, int $employmentId): ProjectionResult
    {
        $snapshots = PerformanceResultSnapshot::query()
            ->where('employment_id', $employmentId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (PerformanceResultSnapshot $snapshot): array => [
                ...$this->snapshotData($snapshot),
                'cycle_status' => 'PUBLISHED',
                'status' => 'PUBLISHED',
                'snapshot' => true,
            ])
            ->values()
            ->all();

        // When this employee has no published snapshot yet, show only their
        // own live row as pending. Never fall through to the company ledger.
        if ($snapshots === []) {
            $snapshots = PerformanceReview::query()
                ->where('employment_id', $employmentId)
                ->orderByDesc('id')
                ->get()
                ->map(function (PerformanceReview $review): array {
                    $cycle = PerformanceCycle::query()
                        ->whereKey((int) $review->cycle_id)
                        ->first();

                    return [
                        'review_id' => (int) $review->id,
                        'cycle_id' => (int) $review->cycle_id,
                        'employment_id' => (int) $review->employment_id,
                        'cycle_status' => $cycle !== null ? (string) $cycle->status : null,
                        'status' => (string) $review->status,
                        'self_rating' => $review->self_rating,
                        'manager_rating' => $review->manager_rating,
                        'calibrated_rating' => $review->calibrated_rating,
                        'final_rating' => null,
                        'snapshot' => false,
                        'published_at' => null,
                        'published_by' => null,
                    ];
                })
                ->values()
                ->all();
        }

        $hasSnapshot = collect($snapshots)->contains(
            static fn (array $row): bool => ($row['snapshot'] ?? false) === true,
        );
        $hasPending = collect($snapshots)->contains(
            static fn (array $row): bool => ($row['snapshot'] ?? false) !== true,
        );

        return $this->projection($snapshots, $asOf, [
            'snapshot' => $hasSnapshot,
            'pending' => $hasPending || $snapshots === [],
        ]);
    }

    /**
     * Report completion counts come only from the publication ledger. A draft
     * review can never inflate a published completion rate.
     */
    public function reports(string $asOf): ProjectionResult
    {
        $items = PerformanceCycle::query()
            ->orderBy('id')
            ->get()
            ->map(function (PerformanceCycle $cycle): array {
                $reviews = PerformanceReview::query()
                    ->where('cycle_id', (int) $cycle->id)
                    ->get();

                $publishedReviews = PerformanceResultSnapshot::query()
                    ->where('cycle_id', (int) $cycle->id)
                    ->count();

                $calibrated = PerformanceCalibration::query()
                    ->where('cycle_id', (int) $cycle->id)
                    ->distinct()
                    ->count('review_id');

                $reviewCount = $reviews->count();
                $completionRate = $reviewCount === 0
                    ? 0
                    : intdiv($publishedReviews * 100, $reviewCount);

                return [
                    'cycle_id' => (int) $cycle->id,
                    'name' => (string) $cycle->name,
                    'period_from' => (string) $cycle->period_from,
                    'period_to' => (string) $cycle->period_to,
                    'status' => (string) $cycle->status,
                    'review_count' => $reviewCount,
                    'submitted_reviews' => $reviews->filter(
                        static fn (PerformanceReview $review): bool => (string) $review->status === 'SUBMITTED',
                    )->count(),
                    'draft_reviews' => $reviews->filter(
                        static fn (PerformanceReview $review): bool => (string) $review->status === 'DRAFT',
                    )->count(),
                    'calibrated_reviews' => $calibrated,
                    'published_reviews' => $publishedReviews,
                    'completion_rate' => $completionRate,
                    'snapshot' => $publishedReviews > 0,
                ];
            })
            ->values()
            ->all();

        return $this->projection($items, $asOf);
    }

    private function cycleOrFail(int $cycleId): PerformanceCycle
    {
        $cycle = PerformanceCycle::query()
            ->whereKey($cycleId)
            ->first();

        if ($cycle === null) {
            throw new ValidationException(['cycle_id' => ['The performance cycle does not exist.']]);
        }

        return $cycle;
    }

    private function reviewForUpdate(int $reviewId): PerformanceReview
    {
        $review = PerformanceReview::query()
            ->whereKey($reviewId)
            ->lockForUpdate()
            ->first();

        if ($review === null) {
            throw NotFoundException::forResource();
        }

        return $review;
    }

    private function assertCycleOpen(PerformanceCycle $cycle): void
    {
        if ((string) $cycle->status === 'PUBLISHED') {
            throw new ConflictException('The performance cycle is published; its records are immutable.', [
                'reason' => 'CYCLE_PUBLISHED',
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    private function projection(array $items, string $asOf, array $extra = []): ProjectionResult
    {
        return new ProjectionResult(
            items: $items,
            page: 1,
            pageSize: max(1, min(ProjectionResult::MAX_PAGE_SIZE, count($items))),
            total: count($items),
            asOf: $asOf,
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode(['items' => $items, 'extra' => $extra], JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: $extra === [] ? null : $extra,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function indicatorData(PerformanceIndicator $indicator): array
    {
        return [
            'indicator_id' => (int) $indicator->id,
            'code' => (string) $indicator->code,
            'name' => (string) $indicator->name,
            'category' => $indicator->category,
            'data_source' => $indicator->data_source,
            'description' => $indicator->description,
            'active' => (bool) $indicator->active,
            'recorded_at' => $this->iso($indicator->recorded_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function goalData(PerformanceGoal $goal): array
    {
        return [
            'goal_id' => (int) $goal->id,
            'cycle_id' => (int) $goal->cycle_id,
            'employment_id' => (int) $goal->employment_id,
            'indicator_id' => $goal->indicator_id !== null ? (int) $goal->indicator_id : null,
            'title' => (string) $goal->title,
            'target_value' => (string) $goal->target_value,
            'weight' => (int) $goal->weight,
            'due_on' => $goal->due_on,
            'status' => (string) $goal->status,
            'recorded_at' => $this->iso($goal->recorded_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function feedbackData(PerformanceFeedback $feedback): array
    {
        return [
            'feedback_id' => (int) $feedback->id,
            'cycle_id' => (int) $feedback->cycle_id,
            'review_id' => $feedback->review_id !== null ? (int) $feedback->review_id : null,
            'from_employment_id' => (int) $feedback->from_employment_id,
            'to_employment_id' => (int) $feedback->to_employment_id,
            'feedback_type' => (string) $feedback->feedback_type,
            'rating' => $feedback->rating,
            'comments' => (string) $feedback->comments,
            'anonymous' => (bool) $feedback->anonymous,
            'status' => (string) $feedback->status,
            'recorded_at' => $this->iso($feedback->recorded_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function calibrationData(PerformanceCalibration $calibration): array
    {
        return [
            'calibration_id' => (int) $calibration->id,
            'cycle_id' => (int) $calibration->cycle_id,
            'review_id' => (int) $calibration->review_id,
            'previous_rating' => $calibration->previous_rating,
            'calibrated_rating' => (string) $calibration->calibrated_rating,
            'reason' => (string) $calibration->reason,
            'calibrated_by' => (int) $calibration->calibrated_by,
            'recorded_at' => $this->iso($calibration->recorded_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cycleData(PerformanceCycle $cycle, int $reviewCount): array
    {
        return [
            'cycle_id' => (int) $cycle->id,
            'name' => (string) $cycle->name,
            'period_from' => (string) $cycle->period_from,
            'period_to' => (string) $cycle->period_to,
            'status' => (string) $cycle->status,
            'review_count' => $reviewCount,
            'published_at' => $this->iso($cycle->published_at),
            'published_by' => $cycle->published_by !== null ? (int) $cycle->published_by : null,
        ];
    }

    /**
     * Server-owned final rating rule. Calibration wins, then manager evidence,
     * then self evidence. The value is stored verbatim as a string.
     */
    private function finalRating(PerformanceReview $review): string
    {
        return (string) ($review->calibrated_rating ?? $review->manager_rating ?? $review->self_rating ?? '0');
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotData(PerformanceResultSnapshot $snapshot): array
    {
        return [
            'review_id' => (int) $snapshot->review_id,
            'cycle_id' => (int) $snapshot->cycle_id,
            'employment_id' => (int) $snapshot->employment_id,
            'self_rating' => $snapshot->self_rating,
            'manager_rating' => $snapshot->manager_rating,
            'calibrated_rating' => $snapshot->calibrated_rating,
            'final_rating' => (string) $snapshot->final_rating,
            'snapshot' => true,
            'published_at' => $this->iso($snapshot->published_at),
            'published_by' => (int) $snapshot->published_by,
        ];
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->toIso8601ZuluString();
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
