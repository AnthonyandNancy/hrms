<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Talent\Domain\PerformanceStateMachine;
use App\Modules\Talent\Models\PerformanceAppeal;
use App\Modules\Talent\Models\PerformanceCycle;
use App\Modules\Talent\Models\PerformanceReview;
use App\Modules\Workflow\Contracts\WorkflowCaseGateway;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PerformanceService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly WorkflowCaseGateway $workflow,
        private readonly PerformanceStateMachine $machine,
        private readonly PerformanceCycleService $cycleDetails,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createCycle(int $actorUserId, array $input): array
    {
        $cycle = PerformanceCycle::query()->firstOrCreate(
            ['name' => (string) $input['name']],
            [
                'period_from' => (string) $input['period_from'],
                'period_to' => (string) $input['period_to'],
                'status' => 'ACTIVE',
                'recorded_at' => $this->clock->now(),
            ],
        );

        $this->audit->record($actorUserId, 'talent.performance_cycle.created', 'performance_cycle', (int) $cycle->id, after: [
            'period_from' => (string) $cycle->period_from,
            'period_to' => (string) $cycle->period_to,
        ]);

        return [
            'cycle_id' => (int) $cycle->id,
            'name' => (string) $cycle->name,
            'period_from' => (string) $cycle->period_from,
            'period_to' => (string) $cycle->period_to,
            'status' => (string) $cycle->status,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createReview(int $actorUserId, array $input): array
    {
        $cycle = PerformanceCycle::query()
            ->whereKey((int) $input['cycle_id'])
            ->first();

        if ($cycle === null) {
            throw new ValidationException(['cycle_id' => ['The performance cycle does not exist.']]);
        }

        if ((string) $cycle->status === 'PUBLISHED') {
            throw new ConflictException('The performance cycle is published; reviews can no longer be created or edited.', [
                'reason' => 'CYCLE_PUBLISHED',
            ]);
        }

        $review = PerformanceReview::query()->firstOrCreate(
            [
                'cycle_id' => (int) $cycle->id,
                'employment_id' => (int) $input['employment_id'],
            ],
            [
                'manager_employment_id' => $input['manager_employment_id'] ?? null,
                'goals_text' => $input['goals_text'] ?? null,
                'self_assessment' => $input['self_assessment'] ?? null,
                'self_rating' => $input['self_rating'] ?? null,
                'status' => 'DRAFT',
                'recorded_at' => $this->clock->now(),
            ],
        );

        return $this->reviewData($review);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function submitReview(int $actorUserId, int $reviewId, array $input): array
    {
        return DB::transaction(function () use ($actorUserId, $reviewId, $input): array {
            $this->cycleDetails->assertReviewEditable($reviewId);

            $review = PerformanceReview::query()
                ->whereKey($reviewId)
                ->lockForUpdate()
                ->first();

            if ($review === null) {
                throw NotFoundException::forResource();
            }

            if ($review->status === 'SUBMITTED') {
                return $this->reviewData($review);
            }

            $this->machine->assertCanSubmit((string) $review->status);

            $review->forceFill([
                'manager_assessment' => $input['manager_assessment'] ?? $review->manager_assessment,
                'manager_rating' => $input['manager_rating'] ?? $review->manager_rating,
                'calibrated_rating' => $input['calibrated_rating'] ?? $review->calibrated_rating,
                'status' => 'SUBMITTED',
                'submitted_at' => $this->clock->now(),
            ])->save();

            $this->audit->record($actorUserId, 'talent.review.submitted', 'performance_review', (int) $review->id, after: [
                'status' => 'SUBMITTED',
            ]);

            return $this->reviewData($review);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createAppeal(int $actorUserId, int $reviewId, array $input, string $businessDate): array
    {
        return DB::transaction(function () use ($actorUserId, $reviewId, $input, $businessDate): array {
            $review = PerformanceReview::query()
                ->whereKey($reviewId)
                ->lockForUpdate()
                ->first();

            if ($review === null) {
                throw NotFoundException::forResource();
            }

            $existing = PerformanceAppeal::query()
                ->where('review_id', (int) $review->id)
                ->first();

            if ($existing !== null) {
                throw new ConflictException('This review already has an open appeal.', [
                    'reason' => 'PERFORMANCE_APPEAL_DUPLICATE',
                    'appeal_id' => (int) $existing->id,
                ]);
            }

            $this->machine->assertCanAppeal((string) $review->status);

            $this->ensureAppealTemplate();
            $case = $this->workflow->createCase(
                requesterUserId: $actorUserId,
                type: 'PERFORMANCE_APPEAL',
                subjectType: 'performance_review',
                subjectId: (int) $review->id,
                title: 'Performance appeal for review '.$review->id,
                payload: [
                    'review_id' => (int) $review->id,
                    'employment_id' => (int) $review->employment_id,
                    'reason' => (string) $input['reason'],
                ],
                templateCode: 'talent.performance_appeal',
                businessDate: $businessDate,
                submit: true,
            );

            $appeal = PerformanceAppeal::query()->create([
                'review_id' => (int) $review->id,
                'case_id' => $case->id,
                'reason' => (string) $input['reason'],
                'status' => 'OPEN',
                'recorded_at' => $this->clock->now(),
            ]);

            // The original review is immutable after submission; the case id
            // and status are derived without touching ratings/assessments.
            $review->forceFill(['status' => 'APPEALED'])->save();

            $this->audit->record($actorUserId, 'talent.performance_appeal.created', 'performance_appeal', (int) $appeal->id, after: [
                'review_id' => (int) $review->id,
                'case_id' => $case->id,
            ]);

            return [
                'appeal_id' => (int) $appeal->id,
                'review_id' => (int) $review->id,
                'case_id' => $case->id,
                'status' => 'OPEN',
                'review_status' => 'APPEALED',
                'review_immutable' => true,
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * @return list<array<string, mixed>>
     */
    public function reviews(?int $cycleId = null, ?string $status = null): array
    {
        return PerformanceReview::query()
            ->when($cycleId !== null, fn ($builder) => $builder->where('cycle_id', $cycleId))
            ->when($status !== null && $status !== '', fn ($builder) => $builder->where('status', $status))
            ->orderByDesc('id')
            ->get()
            ->map(fn (PerformanceReview $review): array => [
                'review_id' => (int) $review->id,
                'cycle_id' => (int) $review->cycle_id,
                'employment_id' => (int) $review->employment_id,
                'manager_employment_id' => $review->manager_employment_id !== null ? (int) $review->manager_employment_id : null,
                'status' => (string) $review->status,
                'self_rating' => $review->self_rating,
                'manager_rating' => $review->manager_rating,
                'calibrated_rating' => $review->calibrated_rating,
                'submitted_at' => $this->iso($review->submitted_at),
                'recorded_at' => $this->iso($review->recorded_at),
            ])->values()->all();
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->toIso8601ZuluString();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cycles(): array
    {
        return PerformanceCycle::query()
            ->orderBy('id')
            ->get()
            ->map(static fn (PerformanceCycle $cycle): array => [
                'cycle_id' => (int) $cycle->id,
                'name' => (string) $cycle->name,
                'period_from' => (string) $cycle->period_from,
                'period_to' => (string) $cycle->period_to,
                'status' => (string) $cycle->status,
            ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewData(PerformanceReview $review): array
    {
        return [
            'review_id' => (int) $review->id,
            'cycle_id' => (int) $review->cycle_id,
            'employment_id' => (int) $review->employment_id,
            'goals_text' => $review->goals_text,
            'self_assessment' => $review->self_assessment,
            'manager_assessment' => $review->manager_assessment,
            'self_rating' => $review->self_rating,
            'manager_rating' => $review->manager_rating,
            'calibrated_rating' => $review->calibrated_rating,
            'status' => (string) $review->status,
            'submitted_at' => $review->submitted_at !== null ? CarbonImmutable::parse((string) $review->submitted_at)->toIso8601ZuluString() : null,
        ];
    }

    private function ensureAppealTemplate(): void
    {
        $definition = [
            'nodes' => [
                ['key' => 'start', 'name' => 'Start', 'type' => 'START', 'sequence' => 1],
                ['key' => 'hr_review', 'name' => 'Appeal review', 'type' => 'APPROVAL', 'sequence' => 2, 'assignee_rule' => ['type' => 'ROLE', 'role_code' => 'HR_ADMIN'], 'required_count' => 1],
                ['key' => 'end', 'name' => 'End', 'type' => 'END', 'sequence' => 3],
            ],
            'effects' => [[
                'type' => 'workflow.noop',
                'execution' => 'LOCAL',
                'aggregate_type' => 'case',
                'aggregate_id_field' => 'case_id',
            ]],
        ];

        $this->workflow->ensureTemplate('talent.performance_appeal', 'Performance appeal', $definition);
    }
}
