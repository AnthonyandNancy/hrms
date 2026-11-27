<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\Workflow\Models\CaseModel;
use Tests\Support\TalentTestCase;

final class PerformanceAppealTest extends TalentTestCase
{
    public function test_appeal_is_a_workflow_case_and_duplicate_appeal_is_rejected(): void
    {
        $cycle = $this->postJson('/v1/performance/cycles', [
            'name' => 'FY2027 H1', 'period_from' => '2027-01-01', 'period_to' => '2027-06-30',
        ], ['Idempotency-Key' => 'perf2-cycle'])->assertCreated();

        $review = $this->postJson('/v1/performance/reviews', [
            'cycle_id' => (int) $cycle->json('data.cycle_id'),
            'employment_id' => 11,
        ], ['Idempotency-Key' => 'perf2-review'])->assertCreated();
        $reviewId = (int) $review->json('data.review_id');
        $this->postJson("/v1/performance/reviews/{$reviewId}/submit", [], ['Idempotency-Key' => 'perf2-submit'])->assertOk();

        $this->postJson('/v1/performance/cycles/'.(int) $cycle->json('data.cycle_id').'/publish', [], [
            'Idempotency-Key' => 'perf2-publish',
        ])->assertOk()->assertJsonPath('data.status', 'PUBLISHED');

        $appeal = $this->postJson("/v1/performance/reviews/{$reviewId}/appeals", [
            'reason' => 'The rating process did not follow the published rubric.',
        ], ['Idempotency-Key' => 'perf2-appeal'])->assertCreated();
        $caseId = (int) $appeal->json('data.case_id');
        $this->assertSame('PERFORMANCE_APPEAL', CaseModel::query()->findOrFail($caseId)->type);

        $this->postJson("/v1/performance/reviews/{$reviewId}/appeals", [
            'reason' => 'A second appeal should not be accepted.',
        ], ['Idempotency-Key' => 'perf2-appeal-2'])->assertConflict();
    }
}
