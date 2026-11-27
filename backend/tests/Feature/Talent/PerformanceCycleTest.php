<?php

declare(strict_types=1);

namespace Tests\Feature\Talent;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Talent\Models\PerformanceCalibration;
use App\Modules\Talent\Models\PerformanceCycle;
use App\Modules\Talent\Models\PerformanceReview;
use Tests\Support\TalentTestCase;

final class PerformanceCycleTest extends TalentTestCase
{
    public function test_calibration_keeps_original_scores_and_publish_freezes_the_cycle(): void
    {
        $cycle = $this->createPerformanceCycle();
        $review = $this->createReview($cycle, 1, selfRating: '4');

        $this->postJson("/v1/performance/reviews/{$review->id}/calibration", [
            'calibrated_rating' => '3.5',
            'reason' => 'Synthetic calibration',
        ], ['Idempotency-Key' => 'cal-1'])->assertOk();

        $this->assertSame('4', $review->refresh()->self_rating);
        $this->assertSame('3.5', $review->calibrated_rating);
        $this->assertDatabaseHas('performance_calibrations', [
            'review_id' => $review->id,
            'previous_rating' => null,
            'calibrated_rating' => '3.5',
        ]);

        $this->postJson("/v1/performance/cycles/{$cycle->id}/publish", [], ['Idempotency-Key' => 'pub-before-submit'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'REVIEWS_NOT_SUBMITTED');

        $this->postJson("/v1/performance/reviews/{$review->id}/submit", [
            'manager_rating' => '4',
        ], ['Idempotency-Key' => 'submit-before-publish'])->assertOk();

        $this->postJson("/v1/performance/cycles/{$cycle->id}/publish", [], ['Idempotency-Key' => 'pub-1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED');

        $this->postJson("/v1/performance/reviews/{$review->id}/submit", [
            'manager_rating' => '5',
        ], ['Idempotency-Key' => 'late-1'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'CYCLE_PUBLISHED');

        $this->assertSame('4', $review->refresh()->self_rating);
        $this->assertSame('4', $review->manager_rating);
        $this->assertSame('3.5', $review->calibrated_rating);
    }

    public function test_publish_requires_every_review_submitted_and_is_idempotent(): void
    {
        $cycle = $this->createPerformanceCycle();
        $submitted = $this->createReview($cycle, 1);
        $submitted->forceFill(['status' => 'SUBMITTED', 'submitted_at' => $this->clock->now()])->save();
        $this->createReview($cycle, 2);

        $this->postJson("/v1/performance/cycles/{$cycle->id}/publish", [], ['Idempotency-Key' => 'partial-publish'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'REVIEWS_NOT_SUBMITTED')
            ->assertJsonPath('data.unsubmitted_reviews', 1);

        PerformanceReview::query()->whereKey($submitted->id)->update(['status' => 'DRAFT', 'submitted_at' => null]);
        PerformanceReview::query()->where('cycle_id', $cycle->id)->update(['status' => 'SUBMITTED', 'submitted_at' => $this->clock->now()]);

        $first = $this->postJson("/v1/performance/cycles/{$cycle->id}/publish", [], ['Idempotency-Key' => 'cycle-publish-replay'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED')
            ->assertJsonPath('data.review_count', 2);

        $this->postJson("/v1/performance/cycles/{$cycle->id}/publish", [], ['Idempotency-Key' => 'cycle-publish-replay'])
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json('data.cycle_id'), $cycle->id);
        $this->assertSame('PUBLISHED', $cycle->refresh()->status);
    }

    public function test_indicators_goals_feedback_calibration_and_results_are_live_projections(): void
    {
        $cycle = $this->createPerformanceCycle();
        $review = $this->createReview($cycle, 1, selfRating: '4');

        $indicator = $this->postJson('/v1/performance/indicators', [
            'code' => 'IND-QUALITY',
            'name' => 'Synthetic quality indicator',
            'category' => 'DELIVERY',
            'data_source' => 'Synthetic project ledger',
            'description' => 'Synthetic indicator used only by tests.',
        ], ['Idempotency-Key' => 'indicator-1'])->assertCreated()
            ->assertJsonPath('data.code', 'IND-QUALITY')
            ->assertJsonPath('data.active', true);

        $indicatorId = (int) $indicator->json('data.indicator_id');

        $this->getJson('/v1/performance/indicators?as_of=2026-11-25')
            ->assertOk()
            ->assertJsonPath('data.freshness', 'LIVE')
            ->assertJsonPath('data.as_of', '2026-11-25')
            ->assertJsonPath('data.items.0.code', 'IND-QUALITY');

        $goal = $this->postJson('/v1/performance/goals', [
            'cycle_id' => $cycle->id,
            'employment_id' => 1,
            'indicator_id' => $indicatorId,
            'title' => 'Synthetic delivery goal',
            'target_value' => '100',
            'weight' => 40,
            'due_on' => '2026-12-31',
        ], ['Idempotency-Key' => 'goal-1'])->assertCreated()
            ->assertJsonPath('data.weight', 40);

        $this->getJson('/v1/performance/goals?as_of=2026-11-25')
            ->assertOk()
            ->assertJsonPath('data.items.0.goal_id', (int) $goal->json('data.goal_id'));

        $feedback = $this->postJson('/v1/performance/feedback', [
            'cycle_id' => $cycle->id,
            'review_id' => $review->id,
            'from_employment_id' => 2,
            'to_employment_id' => 1,
            'feedback_type' => 'PEER',
            'rating' => '4',
            'comments' => 'Synthetic peer feedback.',
            'anonymous' => true,
        ], ['Idempotency-Key' => 'feedback-1'])->assertCreated()
            ->assertJsonPath('data.anonymous', true);

        $this->getJson('/v1/performance/feedback?as_of=2026-11-25')
            ->assertOk()
            ->assertJsonPath('data.items.0.feedback_id', (int) $feedback->json('data.feedback_id'));

        $this->postJson("/v1/performance/reviews/{$review->id}/calibration", [
            'calibrated_rating' => '4.5',
            'reason' => 'Synthetic calibration evidence.',
        ], ['Idempotency-Key' => 'calibration-projection'])->assertOk();

        $this->getJson('/v1/performance/calibration?as_of=2026-11-25')
            ->assertOk()
            ->assertJsonPath('data.items.0.review_id', $review->id)
            ->assertJsonPath('data.items.0.calibrated_rating', '4.5');

        $this->getJson('/v1/performance/results?as_of=2026-11-25')
            ->assertOk()
            ->assertJsonPath('data.items.0.review_id', $review->id)
            ->assertJsonPath('data.items.0.cycle_status', 'ACTIVE')
            ->assertJsonPath('data.items.0.self_rating', '4');
    }

    public function test_reports_aggregate_real_cycle_review_and_calibration_counts(): void
    {
        $cycle = $this->createPerformanceCycle();
        $submitted = $this->createReview($cycle, 1, selfRating: '4');
        $submitted->forceFill(['status' => 'SUBMITTED', 'submitted_at' => $this->clock->now()])->save();
        $draft = $this->createReview($cycle, 2, selfRating: '3');

        $this->postJson("/v1/performance/reviews/{$submitted->id}/calibration", [
            'calibrated_rating' => '4.5',
            'reason' => 'Synthetic reporting calibration.',
        ], ['Idempotency-Key' => 'report-calibration'])->assertOk();

        $this->getJson('/v1/performance/reports?as_of=2026-11-25')
            ->assertOk()
            ->assertJsonPath('data.items.0.cycle_id', $cycle->id)
            ->assertJsonPath('data.items.0.review_count', 2)
            ->assertJsonPath('data.items.0.submitted_reviews', 1)
            ->assertJsonPath('data.items.0.calibrated_reviews', 1)
            ->assertJsonPath('data.items.0.draft_reviews', 1);

        $this->assertSame('DRAFT', $draft->refresh()->status);
    }

    public function test_indicator_creation_rejects_duplicate_codes_and_weights_outside_range(): void
    {
        $this->postJson('/v1/performance/indicators', [
            'code' => 'IND-DUP',
            'name' => 'Synthetic duplicate indicator',
        ], ['Idempotency-Key' => 'indicator-dup-1'])->assertCreated();

        $this->postJson('/v1/performance/indicators', [
            'code' => 'IND-DUP',
            'name' => 'Synthetic duplicate indicator again',
        ], ['Idempotency-Key' => 'indicator-dup-2'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'PERFORMANCE_INDICATOR_DUPLICATE');

        $cycle = $this->createPerformanceCycle();

        $this->postJson('/v1/performance/goals', [
            'cycle_id' => $cycle->id,
            'employment_id' => 1,
            'title' => 'Synthetic invalid goal',
            'target_value' => '100',
            'weight' => 101,
        ], ['Idempotency-Key' => 'goal-invalid-weight'])->assertUnprocessable();

        $this->postJson('/v1/performance/goals', [
            'cycle_id' => 999,
            'employment_id' => 1,
            'title' => 'Synthetic missing cycle goal',
            'target_value' => '100',
        ], ['Idempotency-Key' => 'goal-missing-cycle'])
            ->assertUnprocessable()
            ->assertJsonPath('data.fields.cycle_id.0', 'The performance cycle does not exist.');
    }

    public function test_write_routes_require_an_idempotency_key_and_reject_payload_mismatch(): void
    {
        $cycle = $this->createPerformanceCycle();

        $this->postJson('/v1/performance/indicators', [
            'code' => 'IND-KEY',
            'name' => 'Synthetic key indicator',
        ])->assertUnprocessable()
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');

        $payload = [
            'cycle_id' => $cycle->id,
            'employment_id' => 1,
            'title' => 'Synthetic idempotent goal',
            'target_value' => '80',
            'weight' => 20,
        ];

        $first = $this->postJson('/v1/performance/goals', $payload, ['Idempotency-Key' => 'goal-key-1'])
            ->assertCreated();

        $this->postJson('/v1/performance/goals', $payload, ['Idempotency-Key' => 'goal-key-1'])
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.goal_id', (int) $first->json('data.goal_id'));

        $this->postJson('/v1/performance/goals', [
            ...$payload,
            'title' => 'Synthetic idempotent goal changed',
        ], ['Idempotency-Key' => 'goal-key-1'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
    }

    public function test_performance_routes_are_authenticated_and_capability_guarded(): void
    {
        $this->app->forgetInstance(Principal::class);
        $this->getJson('/v1/performance/indicators')->assertUnauthorized();

        $this->actAs(10, 1);
        $this->authorizer->deniedCapabilities = ['performance.indicator.manage'];
        $this->postJson('/v1/performance/indicators', [
            'code' => 'IND-DENIED',
            'name' => 'Synthetic denied indicator',
        ], ['Idempotency-Key' => 'indicator-denied'])->assertForbidden();

        $this->authorizer->deniedCapabilities = ['performance.calibration.manage'];
        $cycle = $this->createPerformanceCycle();
        $review = $this->createReview($cycle, 1);

        $this->postJson("/v1/performance/reviews/{$review->id}/calibration", [
            'calibrated_rating' => '3',
            'reason' => 'Synthetic denied calibration.',
        ], ['Idempotency-Key' => 'calibration-denied'])->assertForbidden();

        $this->authorizer->deniedCapabilities = ['performance.report.read'];
        $this->getJson('/v1/performance/reports')->assertForbidden();

        $this->authorizer->deniedCapabilities = ['performance.result.publish'];
        $this->postJson("/v1/performance/cycles/{$cycle->id}/publish", [], ['Idempotency-Key' => 'publish-denied'])
            ->assertForbidden();
    }

    public function test_calibration_cannot_escape_company_scope_or_revise_published_reviews(): void
    {
        $cycle = $this->createPerformanceCycle();
        $review = $this->createReview($cycle, 1);

        $publishedOther = $this->createPerformanceCycle('Synthetic out-of-scope cycle');
        $publishedOther->forceFill(['status' => 'PUBLISHED'])->save();
        $outOfScopeReview = $this->createReview($publishedOther, 2, selfRating: '2');

        $this->postJson("/v1/performance/reviews/{$outOfScopeReview->id}/calibration", [
            'calibrated_rating' => '5',
            'reason' => 'Synthetic out-of-scope cycle attempt.',
        ], ['Idempotency-Key' => 'calibration-out-of-scope-cycle']
        )->assertStatus(409)
            ->assertJsonPath('data.reason', 'CYCLE_PUBLISHED');

        $this->assertSame('2', $outOfScopeReview->refresh()->self_rating);
        $this->assertNull($outOfScopeReview->calibrated_rating);
        $this->assertSame(0, PerformanceCalibration::query()->where('review_id', $outOfScopeReview->id)->count());

        PerformanceCycle::query()->whereKey($cycle->id)->update(['status' => 'PUBLISHED']);

        $this->postJson("/v1/performance/reviews/{$review->id}/calibration", [
            'calibrated_rating' => '5',
            'reason' => 'Synthetic post-publish attempt.',
        ], ['Idempotency-Key' => 'calibration-after-publish'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'CYCLE_PUBLISHED');

        $this->assertNull($review->refresh()->calibrated_rating);
    }

    public function test_published_cycle_rejects_goal_and_feedback_edits(): void
    {
        $cycle = $this->createPerformanceCycle();
        PerformanceCycle::query()->whereKey($cycle->id)->update(['status' => 'PUBLISHED']);

        $this->postJson('/v1/performance/goals', [
            'cycle_id' => $cycle->id,
            'employment_id' => 1,
            'title' => 'Synthetic frozen goal',
            'target_value' => '100',
        ], ['Idempotency-Key' => 'goal-after-publish'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'CYCLE_PUBLISHED');

        $this->postJson('/v1/performance/feedback', [
            'cycle_id' => $cycle->id,
            'from_employment_id' => 2,
            'to_employment_id' => 1,
            'feedback_type' => 'PEER',
            'comments' => 'Synthetic frozen feedback.',
        ], ['Idempotency-Key' => 'feedback-after-publish'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'CYCLE_PUBLISHED');
    }
}
