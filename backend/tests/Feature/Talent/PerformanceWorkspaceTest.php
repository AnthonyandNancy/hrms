<?php

declare(strict_types=1);

namespace Tests\Feature\Talent;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\People\Models\Employment;
use App\Modules\Talent\Models\PerformanceCycle;
use App\Modules\Talent\Models\PerformanceReview;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\FieldSet;
use App\Shared\Authorization\ResourceRef;
use Illuminate\Database\Eloquent\Builder;
use Tests\Support\TalentTestCase;

/**
 * Volume 3 Task 13 — performance workspace backend contract.
 *
 * This suite drives the real HTTP routes. The workspace may narrow rows and
 * add aggregate fields to the existing cycle/review reads; it must not invent
 * a cycle-detail endpoint, a second review-create contract, or a client-side
 * rating.
 */
final class PerformanceWorkspaceTest extends TalentTestCase
{
    public function test_cycle_list_returns_participant_progress_filtering_and_server_pagination(): void
    {
        $draft = $this->createPerformanceCycle('FY2026 draft cycle');
        $active = $this->createPerformanceCycle('FY2026 active cycle');
        $finished = $this->createPerformanceCycle('FY2025 finished cycle');
        $finished->forceFill(['status' => 'PUBLISHED'])->save();

        $this->createReview($active, 1);
        $submitted = $this->createReview($active, 2);
        $submitted->forceFill(['status' => 'SUBMITTED', 'submitted_at' => $this->clock->now()])->save();
        $this->createReview($draft, 1);

        $this->getJson('/v1/performance/cycles?status=ACTIVE&q=2026&page=1&page_size=1')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 1)
            ->assertJsonPath('data.items.0.cycle_id', $active->id)
            ->assertJsonPath('data.items.0.participant_count', 2)
            ->assertJsonPath('data.items.0.submitted_count', 1)
            ->assertJsonPath('data.items.0.draft_count', 1)
            ->assertJsonPath('data.items.0.progress', 50)
            ->assertJsonPath('data.items.0.status', 'ACTIVE');

        $this->getJson('/v1/performance/cycles?status=CANCELLED')
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.items', []);
    }

    public function test_manager_creates_a_review_with_employment_id_and_cannot_target_an_unrelated_employee(): void
    {
        [$manager, $report, $unrelated, $cycle] = $this->managerReportAndUnrelatedEmployee();
        $this->actAs(20, (int) $manager->id);
        $this->bindScopedAuthorizer(company: false, managed: true, self: false, managedEmploymentId: (int) $report->id);

        $this->postJson('/v1/performance/reviews', [
            'cycle_id' => (int) $cycle->id,
            'employment_id' => (int) $report->id,
            'self_rating' => '90',
            'self_assessment' => 'Synthetic self assessment.',
        ], ['Idempotency-Key' => 'performance-review-report'])->assertCreated()
            ->assertJsonPath('data.employment_id', (int) $report->id)
            ->assertJsonPath('data.manager_employment_id', (int) $manager->id)
            ->assertJsonPath('data.self_rating', '90')
            ->assertJsonPath('data.status', 'DRAFT');

        $this->postJson('/v1/performance/reviews', [
            'cycle_id' => (int) $cycle->id,
            'employment_id' => (int) $unrelated->id,
            'self_rating' => '90',
        ], ['Idempotency-Key' => 'performance-review-unrelated'])->assertForbidden();

        $this->assertDatabaseMissing('performance_reviews', [
            'cycle_id' => (int) $cycle->id,
            'employment_id' => (int) $unrelated->id,
        ]);
    }

    public function test_manager_review_list_is_narrowed_to_managed_employments(): void
    {
        [$manager, $report, $unrelated, $cycle] = $this->managerReportAndUnrelatedEmployee();
        $this->createReview($cycle, (int) $report->id, selfRating: '80');
        $this->createReview($cycle, (int) $unrelated->id, selfRating: '70');

        $this->actAs(20, (int) $manager->id);
        $this->bindScopedAuthorizer(company: false, managed: true, self: false, managedEmploymentId: (int) $report->id);

        $this->getJson('/v1/performance/reviews?cycle_id='.(int) $cycle->id)
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.employment_id', (int) $report->id);
    }

    public function test_manager_cycle_list_hides_cycles_without_managed_participants(): void
    {
        [$manager, $report, $unrelated, $managedCycle] = $this->managerReportAndUnrelatedEmployee();
        $this->createReview($managedCycle, (int) $report->id, selfRating: '80');
        $unrelatedCycle = $this->createPerformanceCycle('Synthetic unrelated cycle');
        $this->createReview($unrelatedCycle, (int) $unrelated->id, selfRating: '70');

        $this->actAs(20, (int) $manager->id);
        $this->bindScopedAuthorizer(company: false, managed: true, self: false, managedEmploymentId: (int) $report->id);

        $this->getJson('/v1/performance/cycles')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.cycle_id', (int) $managedCycle->id)
            ->assertJsonPath('data.items.0.participant_count', 1)
            ->assertJsonPath('data.items.0.submitted_count', 0);
    }

    public function test_review_goal_weights_must_be_between_one_and_one_hundred_and_total_exactly_one_hundred(): void
    {
        $cycle = $this->createPerformanceCycle();

        $this->postJson('/v1/performance/reviews', [
            'cycle_id' => (int) $cycle->id,
            'employment_id' => 1,
            'goals' => [
                ['title' => 'Synthetic deliverable', 'target_value' => '100', 'weight' => 101],
            ],
        ], ['Idempotency-Key' => 'performance-review-weight-invalid'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');
        $fields = $this->getJson('/v1/performance/cycles')->assertOk()->json('data');
        $this->assertIsArray($fields);

        $this->postJson('/v1/performance/reviews', [
            'cycle_id' => (int) $cycle->id,
            'employment_id' => 1,
            'goals' => [
                ['title' => 'Synthetic deliverable', 'target_value' => '100', 'weight' => 60],
                ['title' => 'Synthetic quality', 'target_value' => '95', 'weight' => 30],
            ],
        ], ['Idempotency-Key' => 'performance-review-weight-total'])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'INVALID_WEIGHT_TOTAL')
            ->assertJsonPath('data.weight_total', 90);

        $this->postJson('/v1/performance/reviews', [
            'cycle_id' => (int) $cycle->id,
            'employment_id' => 1,
            'goals' => [
                ['title' => 'Synthetic deliverable', 'target_value' => '100', 'weight' => 0],
                ['title' => 'Synthetic quality', 'target_value' => '95', 'weight' => 100],
            ],
        ], ['Idempotency-Key' => 'performance-review-weight-zero'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->assertSame(0, PerformanceReview::query()->where('cycle_id', $cycle->id)->count());
    }

    public function test_a_decided_review_cannot_be_scored_again(): void
    {
        [$manager, $report, , $cycle] = $this->managerReportAndUnrelatedEmployee();
        $review = $this->createReview($cycle, (int) $report->id, selfRating: '88');
        $review->forceFill([
            'status' => 'SUBMITTED',
            'manager_rating' => '90',
            'submitted_at' => $this->clock->now(),
        ])->save();
        $this->actAs(20, (int) $manager->id);
        $this->bindScopedAuthorizer(company: false, managed: true, self: false, managedEmploymentId: (int) $report->id);

        $this->postJson("/v1/performance/reviews/{$review->id}/submit", [
            'manager_rating' => '10',
        ], ['Idempotency-Key' => 'performance-score-impossible'])->assertStatus(409)
            ->assertJsonPath('data.reason', 'REVIEW_DECIDED')
            ->assertJsonPath('data.status', 'SUBMITTED');

        $this->assertSame('90', $review->refresh()->manager_rating);
        $this->assertSame('SUBMITTED', $review->status);
    }

    public function test_a_published_cycle_keeps_every_review_read_only(): void
    {
        [$manager, $report, , $cycle] = $this->managerReportAndUnrelatedEmployee();
        $review = $this->createReview($cycle, (int) $report->id);
        $review->forceFill(['status' => 'SUBMITTED', 'submitted_at' => $this->clock->now()])->save();
        $this->actAs(20, (int) $manager->id);
        $this->bindScopedAuthorizer(company: false, managed: true, self: false, managedEmploymentId: (int) $report->id);

        $this->postJson("/v1/performance/cycles/{$cycle->id}/publish", [], [
            'Idempotency-Key' => 'performance-publish-workspace',
        ])->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED');

        $this->postJson("/v1/performance/reviews/{$review->id}/submit", [
            'manager_rating' => '95',
        ], ['Idempotency-Key' => 'performance-publish-after-submit'])->assertStatus(409)
            ->assertJsonPath('data.reason', 'CYCLE_PUBLISHED');
    }

    public function test_a_decided_review_rejects_a_later_goal_or_score_save(): void
    {
        [$manager, $report, , $cycle] = $this->managerReportAndUnrelatedEmployee();
        $this->actAs(20, (int) $manager->id);
        $this->bindScopedAuthorizer(company: false, managed: true, self: false, managedEmploymentId: (int) $report->id);

        $review = $this->postJson('/v1/performance/reviews', [
            'cycle_id' => (int) $cycle->id,
            'employment_id' => (int) $report->id,
            'self_rating' => '70',
            'goals' => [
                ['title' => 'Synthetic decided goal', 'target_value' => '100', 'weight' => 100],
            ],
        ], ['Idempotency-Key' => 'performance-decided-save-create'])->assertCreated();

        $reviewId = (int) $review->json('data.review_id');
        $this->postJson("/v1/performance/reviews/{$reviewId}/submit", [
            'manager_rating' => '80',
        ], ['Idempotency-Key' => 'performance-decided-save-submit'])->assertOk();

        $this->postJson('/v1/performance/reviews', [
            'cycle_id' => (int) $cycle->id,
            'employment_id' => (int) $report->id,
            'self_rating' => '10',
            'goals' => [
                ['title' => 'Synthetic decided goal', 'target_value' => '10', 'weight' => 100],
            ],
        ], ['Idempotency-Key' => 'performance-decided-save-late'])->assertStatus(409)
            ->assertJsonPath('data.reason', 'REVIEW_DECIDED');

        $this->assertSame('70', PerformanceReview::query()->findOrFail($reviewId)->self_rating);
        $this->assertDatabaseHas('performance_goals', [
            'cycle_id' => (int) $cycle->id,
            'employment_id' => (int) $report->id,
            'title' => 'Synthetic decided goal',
            'target_value' => '100',
        ]);
    }

    public function test_valid_review_goals_round_trip_with_scores_as_strings(): void
    {
        [$manager, $report, , $cycle] = $this->managerReportAndUnrelatedEmployee();
        $this->actAs(20, (int) $manager->id);
        $this->bindScopedAuthorizer(company: false, managed: true, self: false, managedEmploymentId: (int) $report->id);

        $created = $this->postJson('/v1/performance/reviews', [
            'cycle_id' => (int) $cycle->id,
            'employment_id' => (int) $report->id,
            'self_rating' => '88.5',
            'goals' => [
                ['title' => 'Synthetic delivery', 'target_value' => '100', 'weight' => 60, 'self_score' => '88.5'],
                ['title' => 'Synthetic quality', 'target_value' => '99%', 'weight' => 40, 'self_score' => '90'],
            ],
        ], ['Idempotency-Key' => 'performance-goal-roundtrip'])->assertCreated()
            ->assertJsonPath('data.goals.0.title', 'Synthetic delivery')
            ->assertJsonPath('data.goals.0.weight', 60)
            ->assertJsonPath('data.goals.0.self_score', '88.5')
            ->assertJsonPath('data.goals.1.weight', 40);

        $this->assertSame('88.5', $created->json('data.self_rating'));

        $this->getJson('/v1/performance/reviews?cycle_id='.(int) $cycle->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.goals.0.target_value', '100')
            ->assertJsonPath('data.items.0.goals.0.self_score', '88.5')
            ->assertJsonPath('data.items.0.goals.1.self_score', '90');
    }

    public function test_review_and_cycle_routes_reject_unauthenticated_requests(): void
    {
        $this->app->forgetInstance(Principal::class);

        $this->getJson('/v1/performance/cycles')->assertUnauthorized();
        $this->getJson('/v1/performance/reviews')->assertUnauthorized();
        $this->postJson('/v1/performance/reviews', [
            'cycle_id' => 1,
            'employment_id' => 1,
        ], ['Idempotency-Key' => 'performance-unauthenticated'])->assertUnauthorized();
    }

    public function test_an_employee_without_review_manage_cannot_create_or_score_reviews(): void
    {
        [$manager, $report, , $cycle] = $this->managerReportAndUnrelatedEmployee();
        $this->actAs(30, (int) $report->id);
        $this->authorizer->deniedCapabilities = ['performance.review.manage'];

        $this->postJson('/v1/performance/reviews', [
            'cycle_id' => (int) $cycle->id,
            'employment_id' => (int) $manager->id,
            'self_rating' => '100',
        ], ['Idempotency-Key' => 'performance-employee-denied'])->assertForbidden();

        $this->assertSame(0, PerformanceReview::query()->where('cycle_id', $cycle->id)->count());
    }

    /**
     * @return array{0: Employment, 1: Employment, 2: Employment, 3: PerformanceCycle}
     */
    private function managerReportAndUnrelatedEmployee(): array
    {
        $org = $this->peopleScenario->orgUnit('PERF-ORG');
        $manager = $this->peopleScenario->employment(
            $this->peopleScenario->person(['legal_name' => 'Synthetic Performance Manager']),
            'PERF-MGR-0001',
        );
        $managerAssignment = $this->peopleScenario->assignment($manager, $org);

        $report = $this->peopleScenario->employment(
            $this->peopleScenario->person(['legal_name' => 'Synthetic Performance Report']),
            'PERF-EMP-0002',
        );
        $this->peopleScenario->assignment($report, $org, ['manager_assignment_id' => (int) $managerAssignment->id]);

        $unrelated = $this->peopleScenario->employment(
            $this->peopleScenario->person(['legal_name' => 'Synthetic Performance Unrelated']),
            'PERF-EMP-0003',
        );
        $this->peopleScenario->assignment($unrelated, $org);

        return [$manager, $report, $unrelated, $this->createPerformanceCycle('Synthetic manager review cycle')];
    }

    private function bindScopedAuthorizer(bool $company, bool $managed, bool $self, ?int $managedEmploymentId = null): void
    {
        $this->app->instance(Authorizer::class, new class($company, $managed, $self, $managedEmploymentId) implements Authorizer
        {
            public function __construct(
                private readonly bool $company,
                private readonly bool $managed,
                private readonly bool $self,
                private readonly ?int $managedEmploymentId,
            ) {}

            public function constrain(Builder $query, Principal $p, string $capability, string $businessDate): Builder
            {
                if ($this->managedEmploymentId !== null && ! $this->company
                    && in_array($capability, ['performance.review.manage', 'performance.cycle.read'], true)) {
                    return $query->where('employment_id', $this->managedEmploymentId);
                }

                return $query;
            }

            public function permits(Principal $p, string $capability, string $businessDate): bool
            {
                return true;
            }

            public function allows(Principal $p, string $capability, AnchorRef $anchor, string $businessDate): bool
            {
                return match ($anchor->type) {
                    AnchorType::COMPANY => $this->company,
                    AnchorType::MANAGED => $this->managed,
                    AnchorType::SELF => $this->self,
                    default => false,
                };
            }

            public function fields(Principal $p, string $capability, ResourceRef $row): FieldSet
            {
                return new FieldSet([]);
            }
        });
    }
}
