<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Models\PayrollInputSnapshot;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Talent\Models\PerformanceCycle;
use App\Modules\Talent\Models\PerformanceResultSnapshot;
use App\Modules\Talent\Models\PerformanceReview;
use App\Modules\Talent\Services\PerformanceBonusMappingService;
use Tests\Support\PayrollTestCase;

final class PerformanceBonusPayrollTest extends PayrollTestCase
{
    public function test_only_approved_matching_period_performance_bonus_enters_payroll(): void
    {
        $base = $this->seedPayrollBase();
        $employmentId = (int) $base['employment_id'];
        $this->closeTimeFact($employmentId, '2026-01', [
            ['business_date' => '2026-01-01', 'scheduled_minutes' => 10_560, 'worked_minutes' => 10_560],
        ]);

        $snapshot = $this->publishedSnapshot($employmentId, '5.0');
        $mappingService = app(PerformanceBonusMappingService::class);
        $approved = $mappingService->createDraft(10, (int) $snapshot->id, 'PERFORMANCE_BONUS', '2026-01', 50_000, 'V1');
        $mappingService->approve(11, (int) $approved->id);

        $draft = $mappingService->createDraft(10, (int) $snapshot->id, 'PERFORMANCE_BONUS_DRAFT', '2026-01', 99_999, 'V2');
        $this->assertSame('DRAFT', (string) $draft->status);

        $future = $mappingService->createDraft(10, (int) $snapshot->id, 'PERFORMANCE_BONUS_FUTURE', '2026-02', 77_777, 'V1');
        $mappingService->approve(11, (int) $future->id);

        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-01']);
        $run = $runs->lockInputs((int) $run->id, 10);
        $snapshotPayload = PayrollInputSnapshot::query()->where('run_id', $run->id)->firstOrFail();
        $bonuses = (array) $snapshotPayload->payload_json['performance_bonuses'];
        $this->assertCount(1, $bonuses);
        $this->assertSame(50_000, (int) $bonuses[0]['amount_cents']);
        $this->assertSame('performance_bonus_mapping', (string) $bonuses[0]['source_type']);

        $result = app(CalculationService::class)->calculate($run, 10);
        $this->assertSame(1_050_000, $result['gross_cents']);
        $employee = PayrollRunEmployee::query()->where('run_id', $run->id)->firstOrFail();
        $this->assertSame(1_050_000, (int) $employee->gross_cents);
        $this->assertDatabaseCount('performance_bonus_mappings', 3);
    }

    private function publishedSnapshot(int $employmentId, string $rating): PerformanceResultSnapshot
    {
        $cycle = PerformanceCycle::query()->create([
            'name' => 'Synthetic bonus cycle '.$employmentId,
            'period_from' => '2026-01-01',
            'period_to' => '2026-03-31',
            'status' => 'PUBLISHED',
            'published_at' => $this->clock->now(),
            'published_by' => 11,
            'recorded_at' => $this->clock->now(),
        ]);
        $review = PerformanceReview::query()->create([
            'cycle_id' => (int) $cycle->id,
            'employment_id' => $employmentId,
            'status' => 'SUBMITTED',
            'self_rating' => $rating,
            'manager_rating' => $rating,
            'recorded_at' => $this->clock->now(),
        ]);

        return PerformanceResultSnapshot::query()->create([
            'cycle_id' => (int) $cycle->id,
            'review_id' => (int) $review->id,
            'employment_id' => $employmentId,
            'self_rating' => $rating,
            'manager_rating' => $rating,
            'calibrated_rating' => null,
            'final_rating' => $rating,
            'score_scaled' => 5_000,
            'grade' => 'A',
            'source_revision' => hash('sha256', 'performance-'.$employmentId),
            'published_at' => $this->clock->now(),
            'published_by' => 11,
        ]);
    }
}
