<?php

declare(strict_types=1);

namespace Tests\Feature\Talent;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\People\Models\Employment;
use App\Modules\Talent\Models\PerformanceResultSnapshot;
use App\Modules\Talent\Models\PerformanceReview;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\FieldSet;
use App\Shared\Authorization\ResourceRef;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TalentTestCase;

/**
 * M15 publication ledger and appeal gate. These tests describe the HTTP
 * contract; helper rows only carry synthetic evidence.
 */
final class PerformanceResultSnapshotTest extends TalentTestCase
{
    public function test_snapshot_table_has_append_only_shape_and_cycle_columns(): void
    {
        $this->assertTrue(Schema::hasTable('performance_result_snapshots'));
        $this->assertTrue(Schema::hasColumns('performance_result_snapshots', [
            'cycle_id',
            'review_id',
            'employment_id',
            'self_rating',
            'manager_rating',
            'calibrated_rating',
            'final_rating',
            'published_at',
            'published_by',
        ]));
        $this->assertTrue(Schema::hasColumns('performance_cycles', ['published_at', 'published_by']));

        $indexes = collect(Schema::getIndexes('performance_result_snapshots'))
            ->keyBy('name');
        $unique = $indexes->get('performance_result_snapshot_unique');
        $this->assertNotNull($unique);
        $this->assertTrue((bool) ($unique['unique'] ?? false));
    }

    public function test_snapshot_model_rejects_update_and_delete(): void
    {
        $cycle = $this->createPerformanceCycle();
        $review = $this->createReview($cycle, 1, selfRating: '4');
        $snapshot = PerformanceResultSnapshot::query()->create([
            'cycle_id' => (int) $cycle->id,
            'review_id' => (int) $review->id,
            'employment_id' => (int) $review->employment_id,
            'self_rating' => '4',
            'manager_rating' => '4.5',
            'calibrated_rating' => null,
            'final_rating' => '4.5',
            'published_at' => $this->clock->now(),
            'published_by' => 10,
        ]);

        try {
            $snapshot->forceFill(['final_rating' => '1'])->save();
            $this->fail('Snapshot updates must be rejected.');
        } catch (\DomainException $exception) {
            $this->assertSame('Performance result snapshots are append-only.', $exception->getMessage());
        }

        try {
            $snapshot->delete();
            $this->fail('Snapshot deletes must be rejected.');
        } catch (\DomainException $exception) {
            $this->assertSame('Performance result snapshots are append-only.', $exception->getMessage());
        }

        $this->assertDatabaseHas('performance_result_snapshots', [
            'id' => (int) $snapshot->id,
            'final_rating' => '4.5',
        ]);
    }

    public function test_publish_writes_one_snapshot_per_submitted_review_and_replays(): void
    {
        $cycle = $this->createPerformanceCycle();
        $first = $this->createReview($cycle, 1, selfRating: '4');
        $second = $this->createReview($cycle, 2, selfRating: '3');
        PerformanceReview::query()->where('cycle_id', $cycle->id)->update([
            'status' => 'SUBMITTED',
            'manager_rating' => '4.5',
            'submitted_at' => $this->clock->now(),
        ]);

        $response = $this->postJson("/v1/performance/cycles/{$cycle->id}/publish", [], [
            'Idempotency-Key' => 'snapshot-publish-1',
        ])->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED')
            ->assertJsonCount(2, 'data.results')
            ->assertJsonPath('data.published_by', 10);

        $this->assertNotNull($response->json('data.published_at'));
        $this->assertSame(2, PerformanceResultSnapshot::query()->where('cycle_id', $cycle->id)->count());
        $this->assertDatabaseHas('performance_result_snapshots', [
            'cycle_id' => (int) $cycle->id,
            'review_id' => (int) $first->id,
            'self_rating' => '4',
            'manager_rating' => '4.5',
            'final_rating' => '4.5',
        ]);
        $this->assertDatabaseHas('performance_result_snapshots', [
            'cycle_id' => (int) $cycle->id,
            'review_id' => (int) $second->id,
            'final_rating' => '4.5',
        ]);

        $this->postJson("/v1/performance/cycles/{$cycle->id}/publish", [], [
            'Idempotency-Key' => 'snapshot-publish-1',
        ])->assertOk()->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(2, PerformanceResultSnapshot::query()->where('cycle_id', $cycle->id)->count());
    }

    public function test_results_read_the_frozen_snapshot_after_underlying_review_rows_change(): void
    {
        $cycle = $this->createPerformanceCycle();
        $review = $this->createReview($cycle, 1, selfRating: '4');
        $review->forceFill([
            'status' => 'SUBMITTED',
            'manager_rating' => '4.5',
            'calibrated_rating' => '4.25',
            'submitted_at' => $this->clock->now(),
        ])->save();

        $this->postJson("/v1/performance/cycles/{$cycle->id}/publish", [], [
            'Idempotency-Key' => 'snapshot-freeze-1',
        ])->assertOk();

        // Simulate an out-of-band row edit. The read model must still reflect
        // the publication snapshot, proving it is not a live review join.
        PerformanceReview::query()->whereKey($review->id)->update([
            'self_rating' => '1',
            'manager_rating' => '1',
            'calibrated_rating' => '1',
        ]);

        $this->getJson('/v1/performance/results?as_of=2026-11-27')
            ->assertOk()
            ->assertJsonPath('data.snapshot', true)
            ->assertJsonPath('data.pending', false)
            ->assertJsonPath('data.items.0.review_id', $review->id)
            ->assertJsonPath('data.items.0.self_rating', '4')
            ->assertJsonPath('data.items.0.manager_rating', '4.5')
            ->assertJsonPath('data.items.0.calibrated_rating', '4.25')
            ->assertJsonPath('data.items.0.final_rating', '4.25')
            ->assertJsonPath('data.items.0.snapshot', true);
    }

    public function test_unpublished_results_are_pending_and_reports_count_only_snapshots(): void
    {
        $cycle = $this->createPerformanceCycle();
        $submitted = $this->createReview($cycle, 1, selfRating: '4');
        $submitted->forceFill(['status' => 'SUBMITTED', 'manager_rating' => '4.5', 'submitted_at' => $this->clock->now()])->save();
        $this->createReview($cycle, 2, selfRating: '3');

        $this->getJson('/v1/performance/results?as_of=2026-11-27')
            ->assertOk()
            ->assertJsonPath('data.snapshot', false)
            ->assertJsonPath('data.pending', true)
            ->assertJsonPath('data.items.0.snapshot', false)
            ->assertJsonPath('data.items.0.final_rating', null);

        $this->getJson('/v1/performance/reports?as_of=2026-11-27')
            ->assertOk()
            ->assertJsonPath('data.items.0.review_count', 2)
            ->assertJsonPath('data.items.0.submitted_reviews', 1)
            ->assertJsonPath('data.items.0.draft_reviews', 1)
            ->assertJsonPath('data.items.0.published_reviews', 0)
            ->assertJsonPath('data.items.0.completion_rate', 0)
            ->assertJsonPath('data.items.0.snapshot', false);
    }

    public function test_appeal_before_publication_is_rejected_then_own_published_appeal_succeeds(): void
    {
        $this->actAs(10, 21, false);
        $cycle = $this->createPerformanceCycle();
        $review = $this->createReview($cycle, 21, selfRating: '4');
        $review->forceFill(['status' => 'SUBMITTED', 'manager_rating' => '4.5', 'submitted_at' => $this->clock->now()])->save();

        $this->postJson("/v1/performance/reviews/{$review->id}/appeals", [
            'reason' => 'Synthetic unpublished appeal reason.',
        ], ['Idempotency-Key' => 'snapshot-appeal-before'])->assertStatus(409)
            ->assertJsonPath('data.reason', 'APPEAL_REQUIRES_PUBLISHED_RESULT');

        $this->assertDatabaseCount('performance_appeals', 0);
        $this->assertSame('SUBMITTED', $review->refresh()->status);

        $this->postJson("/v1/performance/cycles/{$cycle->id}/publish", [], [
            'Idempotency-Key' => 'snapshot-appeal-publish',
        ])->assertOk();

        $this->postJson("/v1/performance/reviews/{$review->id}/appeals", [
            'reason' => 'Synthetic published appeal reason.',
        ], ['Idempotency-Key' => 'snapshot-appeal-after'])->assertCreated()
            ->assertJsonPath('data.review_status', 'APPEALED')
            ->assertJsonPath('data.review_immutable', true);

        $this->assertDatabaseHas('performance_appeals', [
            'review_id' => (int) $review->id,
            'status' => 'OPEN',
        ]);
        $this->assertSame('APPEALED', $review->refresh()->status);
        $this->assertSame('4.5', $review->manager_rating);
        $this->assertSame('4', $review->self_rating);

        $this->postJson("/v1/performance/reviews/{$review->id}/appeals", [
            'reason' => 'Synthetic duplicate appeal reason.',
        ], ['Idempotency-Key' => 'snapshot-appeal-duplicate'])->assertStatus(409)
            ->assertJsonPath('data.reason', 'PERFORMANCE_APPEAL_DUPLICATE');
    }

    public function test_an_unrelated_self_actor_cannot_appeal_someone_elses_published_review(): void
    {
        $this->actAs(10, 21, false);
        $this->bindScopedAuthorizer(company: false, managed: false, self: true);
        $cycle = $this->createPerformanceCycle();
        $review = $this->createReview($cycle, 22, selfRating: '4');
        $review->forceFill(['status' => 'SUBMITTED', 'submitted_at' => $this->clock->now()])->save();

        PerformanceResultSnapshot::query()->create([
            'cycle_id' => (int) $cycle->id,
            'review_id' => (int) $review->id,
            'employment_id' => (int) $review->employment_id,
            'self_rating' => '4',
            'manager_rating' => '4.5',
            'calibrated_rating' => null,
            'final_rating' => '4.5',
            'published_at' => $this->clock->now(),
            'published_by' => 99,
        ]);
        $cycle->forceFill(['status' => 'PUBLISHED'])->save();

        // SELF principals hold performance.appeal.manage but not the result
        // publisher route; the route group therefore lets the request reach
        // the service, where the self boundary returns a 404.
        $this->postJson("/v1/performance/reviews/{$review->id}/appeals", [
            'reason' => 'Synthetic unrelated appeal attempt.',
        ], ['Idempotency-Key' => 'snapshot-appeal-unrelated'])->assertNotFound();
    }

    public function test_published_cycle_blocks_review_calibration_goal_and_feedback_writes(): void
    {
        $cycle = $this->createPerformanceCycle();
        $review = $this->createReview($cycle, 1, selfRating: '4');
        $review->forceFill(['status' => 'SUBMITTED', 'submitted_at' => $this->clock->now()])->save();

        $this->postJson("/v1/performance/cycles/{$cycle->id}/publish", [], [
            'Idempotency-Key' => 'snapshot-frozen-publish',
        ])->assertOk();

        $this->postJson("/v1/performance/reviews/{$review->id}/calibration", [
            'calibrated_rating' => '1',
            'reason' => 'Synthetic post-publication calibration.',
        ], ['Idempotency-Key' => 'snapshot-frozen-calibration'])->assertStatus(409)
            ->assertJsonPath('data.reason', 'CYCLE_PUBLISHED');

        $this->postJson('/v1/performance/goals', [
            'cycle_id' => $cycle->id,
            'employment_id' => 1,
            'title' => 'Synthetic post-publication goal',
            'target_value' => '100',
        ], ['Idempotency-Key' => 'snapshot-frozen-goal'])->assertStatus(409)
            ->assertJsonPath('data.reason', 'CYCLE_PUBLISHED');

        $this->postJson('/v1/performance/feedback', [
            'cycle_id' => $cycle->id,
            'review_id' => $review->id,
            'from_employment_id' => 2,
            'to_employment_id' => 1,
            'feedback_type' => 'PEER',
            'comments' => 'Synthetic post-publication feedback.',
        ], ['Idempotency-Key' => 'snapshot-frozen-feedback'])->assertStatus(409)
            ->assertJsonPath('data.reason', 'CYCLE_PUBLISHED');

        $this->postJson("/v1/performance/reviews/{$review->id}/submit", [
            'manager_rating' => '1',
        ], ['Idempotency-Key' => 'snapshot-frozen-submit'])->assertStatus(409)
            ->assertJsonPath('data.reason', 'CYCLE_PUBLISHED');
    }

    public function test_subject_and_approver_must_differ_and_self_cannot_write_manager_fields(): void
    {
        [, $report] = $this->managerAndReport();
        $cycle = $this->createPerformanceCycle();
        $this->actAs(30, (int) $report->id, false);
        $this->bindScopedAuthorizer(company: true, managed: false, self: true);

        $this->postJson('/v1/performance/reviews', [
            'cycle_id' => (int) $cycle->id,
            'employment_id' => (int) $report->id,
            'manager_employment_id' => (int) $report->id,
            'self_rating' => '4',
        ], ['Idempotency-Key' => 'snapshot-self-manager-same'])->assertStatus(409)
            ->assertJsonPath('data.reason', 'SUBJECT_APPROVER_NOT_SEPARATED');

        // A non-empty manager field on a self write is rejected rather than
        // silently ignored, so a subject cannot enter the approver's decision.
        $this->postJson('/v1/performance/reviews', [
            'cycle_id' => (int) $cycle->id,
            'employment_id' => (int) $report->id,
            'manager_rating' => '5',
            'manager_assessment' => 'Synthetic self manager assessment.',
            'self_rating' => '4',
        ], ['Idempotency-Key' => 'snapshot-self-manager-field'])->assertStatus(409)
            ->assertJsonPath('data.reason', 'SUBJECT_APPROVER_NOT_SEPARATED')
            ->assertJsonPath('data.field', 'manager_assessment');
    }

    /**
     * @return array{0: Employment, 1: Employment}
     */
    private function managerAndReport(): array
    {
        $org = $this->peopleScenario->orgUnit('PERF-SNAPSHOT-ORG');
        $manager = $this->peopleScenario->employment(
            $this->peopleScenario->person(['legal_name' => 'Synthetic Snapshot Manager']),
            'PERF-SNAP-MGR-1',
        );
        $managerAssignment = $this->peopleScenario->assignment($manager, $org);
        $report = $this->peopleScenario->employment(
            $this->peopleScenario->person(['legal_name' => 'Synthetic Snapshot Report']),
            'PERF-SNAP-EMP-2',
        );
        $this->peopleScenario->assignment($report, $org, ['manager_assignment_id' => (int) $managerAssignment->id]);

        return [$manager, $report];
    }

    private function bindScopedAuthorizer(bool $company, bool $managed, bool $self): void
    {
        $this->app->instance(Authorizer::class, new class($company, $managed, $self) implements Authorizer
        {
            public function __construct(
                private readonly bool $company,
                private readonly bool $managed,
                private readonly bool $self,
            ) {}

            public function constrain(Builder $query, Principal $p, string $capability, string $businessDate): Builder
            {
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
