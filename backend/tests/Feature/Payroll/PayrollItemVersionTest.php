<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollItemService;
use App\Modules\Payroll\Services\PayrollReportService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Shared\Exceptions\ConflictException;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PayrollTestCase;

/**
 * M13 item-version, freeze and report boundaries.
 *
 * These tests are intentionally written against the real service seams and are
 * NOT RUN in this workspace (per the task instructions). They document the
 * expected behavior for the lead gate run.
 */
final class PayrollItemVersionTest extends PayrollTestCase
{
    public function test_identity_table_keeps_only_code_and_status_while_versions_hold_facts(): void
    {
        $this->assertTrue(Schema::hasTable('payroll_item_versions'));
        $this->assertTrue(Schema::hasColumns('payroll_items', ['code', 'status']));
        $this->assertFalse(Schema::hasColumn('payroll_items', 'name'));
        $this->assertFalse(Schema::hasColumn('payroll_items', 'formula_json'));
        $this->assertTrue(Schema::hasColumns('payroll_item_versions', [
            'payroll_item_id',
            'version',
            'name',
            'type',
            'calculation',
            'formula_json',
            'effective_from',
            'effective_to',
            'retired_at',
            'retire_reason',
            'status',
        ]));
    }

    public function test_append_version_is_server_numbered_and_does_not_rewrite_history(): void
    {
        $service = app(PayrollItemService::class);
        $first = $service->create(10, [
            'code' => 'DEMO_BONUS',
            'name' => 'Synthetic demo bonus',
            'type' => 'EARNING',
            'effective_from' => '2026-01-01',
        ]);
        $item = PayrollItem::query()->whereKey((int) $first->payroll_item_id)->firstOrFail();
        $businessSnapshot = [
            ...$first->only(['name', 'type', 'taxable', 'insurable', 'calculation']),
            'effective_from' => \Carbon\CarbonImmutable::parse((string) $first->effective_from)->format('Y-m-d'),
        ];

        $second = $service->appendVersion(10, (int) $item->id, [
            'name' => 'Synthetic renamed bonus',
            'effective_from' => '2026-12-01',
            'version' => 99,
        ]);

        $this->assertSame(2, (int) $second->version);
        $afterAppend = $first->refresh();
        $this->assertSame($businessSnapshot['name'], $afterAppend->name);
        $this->assertSame($businessSnapshot['type'], $afterAppend->type);
        $this->assertSame($businessSnapshot['taxable'], $afterAppend->taxable);
        $this->assertSame($businessSnapshot['insurable'], $afterAppend->insurable);
        $this->assertSame($businessSnapshot['calculation'], $afterAppend->calculation);
        $this->assertSame($businessSnapshot['effective_from'], \Carbon\CarbonImmutable::parse((string) $afterAppend->effective_from)->format('Y-m-d'));
        $this->assertSame('2026-11-30', \Carbon\CarbonImmutable::parse((string) $first->effective_to)->format('Y-m-d'));
        $this->assertSame('Synthetic demo bonus', $first->name);
        $this->assertDatabaseCount('payroll_item_versions', 2);
    }

    public function test_overlapping_version_is_a_409_business_conflict(): void
    {
        $service = app(PayrollItemService::class);
        $first = $service->create(10, [
            'code' => 'DEMO_ALLOWANCE',
            'name' => 'Synthetic demo allowance',
            'type' => 'EARNING',
            'effective_from' => '2026-01-01',
        ]);

        $this->expectException(ConflictException::class);
        $service->appendVersion(10, (int) $first->payroll_item_id, [
            'name' => 'Synthetic overlap',
            'effective_from' => '2026-01-01',
        ]);
    }

    public function test_retire_keeps_history_and_is_idempotent(): void
    {
        $service = app(PayrollItemService::class);
        $first = $service->create(10, [
            'code' => 'DEMO_DEDUCTION',
            'name' => 'Synthetic demo deduction',
            'type' => 'DEDUCTION',
            'effective_from' => '2026-01-01',
        ]);
        $item = PayrollItem::query()->whereKey((int) $first->payroll_item_id)->firstOrFail();

        $retired = $service->retire(10, (int) $item->id, 'Synthetic retirement reason');
        $this->assertSame('RETIRED', (string) $retired->status);
        $this->assertDatabaseCount('payroll_item_versions', 1);

        $version = $first->refresh();
        $this->assertSame('RETIRED', (string) $version->status);
        $this->assertNotNull($version->retired_at);
        $this->assertSame('Synthetic demo deduction', $version->name);

        $again = $service->retire(10, (int) $item->id, 'Synthetic retirement reason');
        $this->assertSame('RETIRED', (string) $again->status);
    }

    public function test_item_versions_cannot_enter_a_frozen_payroll_period(): void
    {
        $base = $this->seedPayrollBase();
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-11']);
        $run = $runs->lockInputs((int) $run->id, 10);
        app(CalculationService::class)->calculate($run, 10);
        $approval = app(\App\Modules\Payroll\Services\ApprovalService::class);
        $approval->review((int) $run->id, 11);
        $approval->approve((int) $run->id, 12);
        $approval->publish((int) $run->id, 13);

        $this->expectException(ConflictException::class);
        app(PayrollItemService::class)->create(10, [
            'code' => 'DEMO_FROZEN_ITEM',
            'name' => 'Synthetic frozen-period item',
            'type' => 'EARNING',
            'effective_from' => '2026-11-01',
        ]);
    }

    public function test_report_only_reads_finalized_runs_and_never_returns_employee_rows(): void
    {
        $base = $this->seedPayrollBase();
        $runs = app(PayrollRunService::class);
        $draft = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-11']);

        $report = app(PayrollReportService::class)->summary(['period' => '2026-11', 'group_by' => 'period']);

        $this->assertSame([], $report['items']);
        $this->assertArrayNotHasKey('employees', $report);
        $this->assertSame(0, $report['totals']['gross_cents']);
        $this->assertSame('PUBLISHED_PAYROLL_RUNS', $report['source']);
        $this->assertSame(0, (int) $draft->gross_cents);
    }
}
