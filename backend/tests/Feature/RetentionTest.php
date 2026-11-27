<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Platform\Models\AuditEvent;
use App\Modules\Platform\Retention\LegalHold;
use App\Modules\Platform\Retention\RetentionClass;
use App\Modules\Platform\Retention\RetentionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The real payroll migrations own these tables now.
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function test_preview_reports_candidates_without_deleting(): void
    {
        $this->seedPayslip('2019-01-01');
        $this->seedPayslip('2025-01-01');

        $preview = app(RetentionService::class)->preview(
            'payroll_payment_records',
            CarbonImmutable::parse('2026-11-23'),
        );

        $this->assertTrue($preview['available']);
        $this->assertSame(1, $preview['candidates']);
        $this->assertSame(1, $preview['purgeable']);
        $this->assertSame(2, DB::table('payslips')->count());
    }

    public function test_legal_hold_blocks_purge_and_is_skipped(): void
    {
        $old = $this->seedPayslip('2019-01-01');
        $otherOld = $this->seedPayslip('2019-02-01');
        LegalHold::query()->create([
            'retention_class' => 'payroll_payment_records',
            'subject_type' => 'payslips',
            'subject_id' => (string) $old,
            'reason' => 'Demo litigation hold',
            'starts_at' => CarbonImmutable::parse('2026-01-01'),
            'ends_at' => null,
        ]);

        $result = app(RetentionService::class)->purge(
            'payroll_payment_records',
            CarbonImmutable::parse('2026-11-23'),
            confirm: true,
            actorUserId: 7,
        );

        $this->assertTrue($result['executed']);
        $this->assertSame(1, $result['preview']['held']);
        $this->assertSame(1, $result['purged']);
        $this->assertDatabaseHas('payslips', ['id' => $old]);
        $this->assertDatabaseMissing('payslips', ['id' => $otherOld]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'retention.purged',
            'aggregate_id' => 'payroll_payment_records',
        ]);
        $this->assertDatabaseHas('retention_purge_runs', [
            'retention_class' => 'payroll_payment_records',
            'purged' => 1,
            'held' => 1,
        ]);
    }

    public function test_purge_without_confirm_is_a_dry_run(): void
    {
        $this->seedPayslip('2019-01-01');

        $result = app(RetentionService::class)->purge(
            'payroll_payment_records',
            CarbonImmutable::parse('2026-11-23'),
            confirm: false,
        );

        $this->assertFalse($result['executed']);
        $this->assertSame(0, $result['purged']);
        $this->assertSame(1, DB::table('payslips')->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'retention.purged')->count());
    }

    public function test_contract_and_accounting_retention_classes_exist(): void
    {
        $this->assertContains('employment_contracts', RetentionClass::names());
        $this->assertContains('accounting_vouchers', RetentionClass::names());
        $this->assertContains('payroll_payment_records', RetentionClass::names());
        $this->assertSame(360, RetentionClass::get('accounting_vouchers')['period_months']);
        $this->assertTrue(RetentionClass::get('annual_financial_reports')['permanent']);
    }

    public function test_permanent_classes_are_never_purged(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Permanent records cannot be purged.');

        // Reporting now owns a real `report_runs` table via migration, so the
        // permanent-class guard is asserted without creating a duplicate stub.
        app(RetentionService::class)->purge('annual_financial_reports', confirm: true);
    }

    private function seedPayslip(string $publishedAt): int
    {
        $runId = (int) DB::table('payroll_runs')->insertGetId([
            'scheme_id' => 1,
            'period' => '2019-01',
            'earning_period_start' => '2019-01-01',
            'earning_period_end' => '2019-01-31',
            'posting_period' => '2019-01',
            'pay_date' => '2019-01-31',
            'cutoff_at' => $publishedAt.' 00:00:00',
            'status' => 'PUBLISHED',
            'run_type' => 'REGULAR',
            'prepared_by' => 10,
            'published_by' => 10,
            'created_at' => $publishedAt.' 00:00:00',
            'updated_at' => $publishedAt.' 00:00:00',
        ]);

        return (int) DB::table('payslips')->insertGetId([
            'run_id' => $runId,
            'employment_id' => 1,
            'version' => 1,
            'gross_cents' => 0,
            'deductions_cents' => 0,
            'net_cents' => 0,
            'employer_cost_cents' => 0,
            'published_at' => $publishedAt.' 00:00:00',
        ]);
    }
}
