<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use Tests\Support\TalentTestCase;

final class PerformanceCycleTest extends TalentTestCase
{
    public function test_cycle_review_submit_and_appeal_create_a_case_without_rewriting_review(): void
    {
        $cycle = $this->postJson('/v1/performance/cycles', [
            'name' => 'FY2026 H2',
            'period_from' => '2026-07-01',
            'period_to' => '2026-12-31',
        ], ['Idempotency-Key' => 'perf-cycle-1'])->assertCreated();
        $cycleId = (int) $cycle->json('data.cycle_id');

        $review = $this->postJson('/v1/performance/reviews', [
            'cycle_id' => $cycleId,
            'employment_id' => 1,
            'manager_employment_id' => 2,
            'goals_text' => 'Ship lifecycle slice',
        ], ['Idempotency-Key' => 'perf-review-1'])->assertCreated();
        $reviewId = (int) $review->json('data.review_id');

        $this->postJson("/v1/performance/reviews/{$reviewId}/submit", [
            'manager_assessment' => 'Strong delivery.',
            'manager_rating' => 'EXCEEDS',
            'calibrated_rating' => 'MEETS',
        ], ['Idempotency-Key' => 'perf-submit-1'])->assertOk()
            ->assertJsonPath('data.status', 'SUBMITTED');

        $immutableFields = \App\Modules\Talent\Models\PerformanceReview::query()->findOrFail($reviewId)->only([
            'goals_text', 'self_assessment', 'manager_assessment', 'manager_rating', 'calibrated_rating',
        ]);

        $appeal = $this->postJson("/v1/performance/reviews/{$reviewId}/appeals", [
            'reason' => 'Calibration result is inconsistent with documented goals.',
        ], ['Idempotency-Key' => 'perf-appeal-1'])->assertCreated();

        $this->assertGreaterThan(0, (int) $appeal->json('data.case_id'));
        $this->assertSame($immutableFields, \App\Modules\Talent\Models\PerformanceReview::query()->findOrFail($reviewId)->only(array_keys($immutableFields)));
    }
}
