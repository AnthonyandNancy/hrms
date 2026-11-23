<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Services\ApprovalService;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Shared\Exceptions\ConflictException;
use Tests\Support\PayrollTestCase;

final class PayrollRunLifecycleTest extends PayrollTestCase
{
    public function test_full_lifecycle_is_legal_and_seals_the_run(): void
    {
        $base = $this->seedPayrollBase();
        $runs = app(PayrollRunService::class);

        $run = $runs->createRun($this->tenantId, 10, [
            'scheme_id' => (int) $base['scheme']->id,
            'period' => '2026-01',
        ]);

        $this->assertSame(PayrollRunStatus::DRAFT, $run->status);

        $run = $runs->lockInputs($this->tenantId, (int) $run->id, 10);
        $this->assertSame(PayrollRunStatus::INPUT_LOCKED, $run->status);
        $hash = (string) $run->input_hash;
        $this->assertSame(64, strlen($hash));

        app(CalculationService::class)->calculate($run, 10);
        $run = $run->refresh();
        $this->assertSame(PayrollRunStatus::CALCULATED, $run->status);
        $this->assertGreaterThan(0, $run->gross_cents);
        $this->assertSame($run->gross_cents - $run->deductions_cents, $run->net_cents);

        $approval = app(ApprovalService::class);
        $run = $approval->review($this->tenantId, (int) $run->id, 11);
        $this->assertSame(PayrollRunStatus::REVIEWED, $run->status);

        $run = $approval->approve($this->tenantId, (int) $run->id, 12);
        $this->assertSame(PayrollRunStatus::APPROVED, $run->status);

        $run = $approval->publish($this->tenantId, (int) $run->id, 13);
        $this->assertSame(PayrollRunStatus::PUBLISHED, $run->status);

        $run = $approval->markPaid($this->tenantId, (int) $run->id, 13, 'DEMO-BANK-REF');
        $this->assertSame(PayrollRunStatus::PAID, $run->status);

        $run = $approval->close($this->tenantId, (int) $run->id, 13);
        $this->assertSame(PayrollRunStatus::CLOSED, $run->status);

        $this->assertDatabaseCount('payroll_ytd', 1);
        $ytd = \App\Modules\Payroll\Models\PayrollYtd::query()->firstOrFail();
        $this->assertSame($run->gross_cents, $ytd->gross_cents);
    }

    public function test_illegal_transitions_are_409_conflicts(): void
    {
        $base = $this->seedPayrollBase();
        $run = app(PayrollRunService::class)->createRun($this->tenantId, 10, [
            'scheme_id' => (int) $base['scheme']->id,
            'period' => '2026-02',
        ]);

        $this->expectException(ConflictException::class);
        app(ApprovalService::class)->approve($this->tenantId, (int) $run->id, 12);
    }

    public function test_published_payslip_and_lines_are_immutable(): void
    {
        $base = $this->seedPayrollBase();
        $run = $this->publishedRun('2026-03');

        $payslip = Payslip::query()->where('run_id', $run->id)->firstOrFail();
        $this->assertNotNull($payslip->published_at);

        $this->expectException(\DomainException::class);
        $line = PayslipLine::query()->where('payslip_id', $payslip->id)->firstOrFail();
        $line->forceFill(['amount_cents' => (int) $line->amount_cents + 1])->save();
    }

    public function test_sealed_run_rejects_financial_updates_at_database_level(): void
    {
        $run = $this->publishedRun('2026-04');

        try {
            \Illuminate\Support\Facades\DB::table('payroll_runs')
                ->where('id', $run->id)
                ->update(['net_cents' => (int) $run->net_cents + 1]);
            $this->fail('Sealed payroll run was mutated.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $this->assertSame((int) $run->net_cents, (int) PayrollRun::query()->whereKey($run->id)->firstOrFail()->net_cents);
    }

    public function test_snapshots_are_append_only_at_database_level(): void
    {
        $base = $this->seedPayrollBase();
        $run = app(PayrollRunService::class)->createRun($this->tenantId, 10, [
            'scheme_id' => (int) $base['scheme']->id,
            'period' => '2026-05',
        ]);
        $run = app(PayrollRunService::class)->lockInputs($this->tenantId, (int) $run->id, 10);
        $snapshot = \App\Modules\Payroll\Models\PayrollInputSnapshot::query()->where('run_id', $run->id)->firstOrFail();

        try {
            \Illuminate\Support\Facades\DB::table('payroll_input_snapshots')
                ->where('id', $snapshot->id)
                ->update(['source_hash' => str_repeat('b', 64)]);
            $this->fail('Snapshot update was not blocked.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->assertSame($snapshot->source_hash, \App\Modules\Payroll\Models\PayrollInputSnapshot::query()->whereKey($snapshot->id)->firstOrFail()->source_hash);
    }

    public function test_preparer_cannot_approve_own_run(): void
    {
        $run = $this->reviewedRun('2026-06');

        $this->expectException(ConflictException::class);
        app(ApprovalService::class)->approve($this->tenantId, (int) $run->id, 10);
    }

    public function test_reviewer_cannot_approve_same_run(): void
    {
        $run = $this->reviewedRun('2026-07', reviewerId: 11);

        $this->expectException(ConflictException::class);
        app(ApprovalService::class)->approve($this->tenantId, (int) $run->id, 11);
    }

    private function publishedRun(string $period, int $preparedBy = 10): PayrollRun
    {
        $run = $this->reviewedRun($period, preparedBy: $preparedBy);
        $approval = app(ApprovalService::class);
        $run = $approval->approve($this->tenantId, (int) $run->id, 12);

        return $approval->publish($this->tenantId, (int) $run->id, 13);
    }

    private function reviewedRun(string $period, int $preparedBy = 10, int $reviewerId = 11): PayrollRun
    {
        $base = $this->seedPayrollBase();
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun($this->tenantId, $preparedBy, [
            'scheme_id' => (int) $base['scheme']->id,
            'period' => $period,
        ]);
        $run = $runs->lockInputs($this->tenantId, (int) $run->id, $preparedBy);
        app(CalculationService::class)->calculate($run, $preparedBy);

        return app(ApprovalService::class)->review($this->tenantId, (int) $run->id, $reviewerId);
    }
}
