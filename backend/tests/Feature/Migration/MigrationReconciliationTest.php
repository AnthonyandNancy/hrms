<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Platform\Migration\Enums\MigrationRunMode;
use App\Modules\Platform\Migration\Services\MigrationReconciler;
use App\Modules\Platform\Migration\Services\MigrationRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MigrationTargetRegistryFakes;
use Tests\TestCase;

class MigrationReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_leave_opening_reconciliation_matches_count_and_sum(): void
    {
        $targets = MigrationTargetRegistryFakes::install();
        $run = app(MigrationRunner::class)->apply(
            MigrationDomain::LEAVE_OPENINGS,
            $this->fixture('leave_openings.csv'),
            [$this->mappingFile()],
            MigrationRunMode::APPLY,
        );

        $report = app(MigrationReconciler::class)->reconcile($run);

        $this->assertTrue($report->passed);
        $this->assertSame(12, $report->sourceCount);
        $this->assertSame(12, $report->targetCount);
        $this->assertSame(12 * 4800, $report->sourceSums['quantity_minutes']);
        $this->assertSame(12 * 4800, $report->targetSums['quantity_minutes']);
        $this->assertSame([], $report->differences);
        $this->assertSame($run->id, $targets[MigrationDomain::LEAVE_OPENINGS->value]->rows[array_key_first($targets[MigrationDomain::LEAVE_OPENINGS->value]->rows)]['migration_run_id']);
    }

    public function test_payroll_ytd_reconciliation_covers_all_totals(): void
    {
        MigrationTargetRegistryFakes::install();
        $run = app(MigrationRunner::class)->apply(
            MigrationDomain::PAYROLL_YTD,
            $this->fixture('payroll_ytd.csv'),
            [$this->mappingFile()],
            MigrationRunMode::APPLY,
        );

        $report = app(MigrationReconciler::class)->reconcile($run);

        $this->assertTrue($report->passed);
        foreach (['gross_cents', 'net_cents', 'taxable_cents', 'tax_withheld_cents', 'si_cents', 'hf_cents'] as $sum) {
            $this->assertArrayHasKey($sum, $report->sourceSums);
            $this->assertSame($report->sourceSums[$sum], $report->targetSums[$sum], $sum);
        }
    }

    public function test_injected_target_mismatch_fails_reconciliation(): void
    {
        $targets = MigrationTargetRegistryFakes::install();
        $run = app(MigrationRunner::class)->apply(
            MigrationDomain::PEOPLE,
            $this->fixture('people.csv'),
            [$this->mappingFile()],
            MigrationRunMode::APPLY,
        );

        $targets[MigrationDomain::PEOPLE->value]->forceCount(11);

        $report = app(MigrationReconciler::class)->reconcile($run);

        $this->assertFalse($report->passed);
        $this->assertArrayHasKey('count', $report->differences);
        $this->assertSame(1, abs($report->differences['count']));
    }

    public function test_injected_sum_mismatch_fails_reconciliation(): void
    {
        $targets = MigrationTargetRegistryFakes::install();
        $run = app(MigrationRunner::class)->apply(
            MigrationDomain::LEAVE_OPENINGS,
            $this->fixture('leave_openings.csv'),
            [$this->mappingFile()],
            MigrationRunMode::APPLY,
        );

        $targets[MigrationDomain::LEAVE_OPENINGS->value]->forceSums(['quantity_minutes' => 1]);

        $report = app(MigrationReconciler::class)->reconcile($run);

        $this->assertFalse($report->passed);
        $this->assertArrayHasKey('quantity_minutes', $report->differences);
    }

    private function fixture(string $file): string
    {
        return dirname(__DIR__, 3)."/database/migration-fixtures/known-good/{$file}";
    }

    private function mappingFile(): string
    {
        return dirname(__DIR__, 3).'/database/migration-fixtures/mapping/demo.csv';
    }
}
