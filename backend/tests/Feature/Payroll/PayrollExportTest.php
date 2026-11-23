<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\ApprovalService;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\ExportService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Platform\Models\AuditEvent;
use Tests\Support\PayrollTestCase;

final class PayrollExportTest extends PayrollTestCase
{
    public function test_bank_and_tax_csv_have_bom_hash_and_no_hidden_pii(): void
    {
        $base = $this->seedPayrollBase();
        $run = $this->approvedRun($base, '2026-01');
        $service = app(ExportService::class);

        $bank = $service->bankCsv($this->tenantId, (int) $run->id, 13);
        $tax = $service->taxCsv($this->tenantId, (int) $run->id, 13);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $bank['content']);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $tax['content']);
        $this->assertSame(hash('sha256', $bank['content']), $bank['sha256']);
        $this->assertSame(hash('sha256', $tax['content']), $tax['sha256']);
        $this->assertStringContainsString('CSV ADAPTER ONLY', $bank['content']);
        $this->assertStringContainsString('NOT_FOR_FILING', $tax['content']);

        // Whitelisted export columns only: no national id, mobile, bank
        // account or home address may appear.
        foreach (['id_number', 'mobile', 'bank_account', 'address', 'password'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $bank['content']);
            $this->assertStringNotContainsString($hidden, $tax['content']);
        }

        $fresh = PayrollRun::query()->whereKey($run->id)->firstOrFail();
        $this->assertSame($bank['sha256'], (string) $fresh->bank_export_hash);
        $this->assertSame($tax['sha256'], (string) $fresh->tax_export_hash);

        $this->assertSame(2, AuditEvent::query()->whereIn('action', ['payroll.export.bank', 'payroll.export.tax'])->count());
    }

    public function test_exports_fail_closed_before_approval(): void
    {
        $base = $this->seedPayrollBase();
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun($this->tenantId, 10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-02']);
        $run = $runs->lockInputs($this->tenantId, (int) $run->id, 10);
        app(CalculationService::class)->calculate($run, 10);

        $this->expectException(\App\Shared\Exceptions\ConflictException::class);
        app(ExportService::class)->bankCsv($this->tenantId, (int) $run->id, 13);
    }

    public function test_payslip_lines_are_traceable_and_reconcile_to_totals(): void
    {
        $base = $this->seedPayrollBase();
        $run = $this->approvedRun($base, '2026-03');
        $payslip = Payslip::query()->where('run_id', $run->id)->firstOrFail();
        $lines = \App\Modules\Payroll\Models\PayslipLine::query()->where('payslip_id', $payslip->id)->get();

        $this->assertNotEmpty($lines);
        $earnings = 0;
        $deductions = 0;

        foreach ($lines as $line) {
            $this->assertNotSame('', (string) $line->source_type);
            $this->assertNotSame('', (string) $line->source_id);

            if ((string) $line->category === 'EARNING') {
                $earnings += (int) $line->amount_cents;
            } elseif ((string) $line->category === 'DEDUCTION') {
                $deductions += (int) $line->amount_cents;
            }
        }

        $this->assertSame((int) $payslip->gross_cents, $earnings);
        $this->assertSame((int) $payslip->deductions_cents, $deductions);
    }

    /**
     * @param  array{scheme: \App\Modules\Payroll\Models\PayrollScheme, employment_id: int}  $base
     */
    private function approvedRun(array $base, string $period): PayrollRun
    {
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun($this->tenantId, 10, ['scheme_id' => (int) $base['scheme']->id, 'period' => $period]);
        $run = $runs->lockInputs($this->tenantId, (int) $run->id, 10);
        app(CalculationService::class)->calculate($run, 10);
        $approval = app(ApprovalService::class);
        $approval->review($this->tenantId, (int) $run->id, 11);

        return $approval->approve($this->tenantId, (int) $run->id, 12);
    }
}
