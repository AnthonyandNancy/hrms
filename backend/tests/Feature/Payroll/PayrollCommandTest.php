<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use Tests\Support\PayrollTestCase;

final class PayrollCommandTest extends PayrollTestCase
{
    public function test_parallel_check_passes_for_matching_export(): void
    {
        $run = $this->run;

        $path = tempnam(sys_get_temp_dir(), 'payroll-parallel-');
        $this->assertIsString($path);
        file_put_contents($path, implode("\n", [
            'employment_id,gross_cents,net_cents',
            $this->employmentId.','.$run->gross_cents.','.$run->net_cents,
        ]));

        try {
            $this->artisan('payroll:parallel-check', [
                '--period' => '2026-01',
                '--old-file' => $path,
            ])->assertSuccessful();
        } finally {
            @unlink($path);
        }
    }

    public function test_parallel_check_reports_failed_employee_rows_as_blockers(): void
    {
        $run = $this->run;

        // A failed employee row is not a zero-value calculation. It must be
        // reported explicitly and fail the check even when the legacy export
        // has no row for it either.
        \App\Modules\Payroll\Models\PayrollRunEmployee::query()->create([
            'tenant_id' => $this->tenantId,
            'run_id' => (int) $run->id,
            'employment_id' => 999_001,
            'status' => \App\Modules\Payroll\Enums\PayrollEmployeeStatus::FAILED,
            'gross_cents' => 0,
            'taxable_cents' => 0,
            'deductions_cents' => 0,
            'net_cents' => 0,
            'employer_cost_cents' => 0,
            'employee_si_cents' => 0,
            'employee_hf_cents' => 0,
            'employer_si_cents' => 0,
            'employer_hf_cents' => 0,
            'tax_cents' => 0,
            'ytd_taxable_opening_cents' => 0,
            'ytd_tax_withheld_opening_cents' => 0,
            'ytd_applied' => false,
            'failure_reason' => 'Synthetic failure for the parallel-run blocker test.',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'payroll-parallel-');
        $this->assertIsString($path);
        file_put_contents($path, implode('
', [
            'employment_id,gross_cents,net_cents',
            $this->employmentId.','.$run->gross_cents.','.$run->net_cents,
        ]));

        try {
            $this->artisan('payroll:parallel-check', [
                '--period' => '2026-01',
                '--old-file' => $path,
            ])->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_parallel_check_fails_for_cent_level_difference(): void
    {
        $run = $this->run;

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'payroll-parallel-diff-'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($path, implode("\n", [
            'employment_id,gross_cents,net_cents',
            $this->employmentId.','.$run->gross_cents.','.((int) $run->net_cents - 2),
        ]));

        try {
            $this->artisan('payroll:parallel-check', [
                '--period' => '2026-01',
                '--old-file' => $path,
            ])->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_parallel_check_missing_file_is_exit_two(): void
    {
        $this->artisan('payroll:parallel-check', [
            '--period' => '2026-01',
            '--old-file' => 'D:/missing/demo.csv',
        ])->assertExitCode(2);
    }

    public function test_parallel_check_headcount_mismatch_fails(): void
    {
        $run = $this->run;

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'payroll-parallel-head-'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($path, implode("\n", [
            'employment_id,gross_cents,net_cents',
            $this->employmentId.','.$run->gross_cents.','.$run->net_cents,
            '999999,100,100',
        ]));

        try {
            $this->artisan('payroll:parallel-check', [
                '--period' => '2026-01',
                '--old-file' => $path,
            ])->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_reconciliation_passes_for_clean_run_and_fails_on_corruption(): void
    {
        $run = $this->run;

        $this->artisan('payroll:reconciliation', [
            '--run' => (string) $run->id,
        ])->assertSuccessful();

        // Corrupt an employee total below the immutable/status guards by
        // changing it while the run is still CALCULATED.
        \Illuminate\Support\Facades\DB::table('payroll_run_employees')
            ->where('run_id', $run->id)
            ->update(['net_cents' => (int) $run->net_cents - 100]);

        $this->artisan('payroll:reconciliation', [
            '--run' => (string) $run->id,
        ])->assertFailed();
    }

    public function test_seed_parameters_requires_entity_year_and_evidence_actor(): void
    {
        $this->artisan('payroll:seed-parameters')->assertFailed();
        $this->artisan('payroll:seed-parameters', [
            '--legal-entity' => (string) $this->legalEntityId,
            '--year' => '2026',
            '--approved-by' => '99',
        ])->assertSuccessful();

        $this->assertDatabaseHas('statutory_parameters', [
            'tenant_id' => $this->tenantId,
            'legal_entity_id' => $this->legalEntityId,
            'parameter_set' => 'TAX',
            'is_demo' => true,
        ]);
    }

    private int $employmentId = 0;

    private \App\Modules\Payroll\Models\PayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $base = $this->seedPayrollBase();
        $this->employmentId = (int) $base['employment_id'];

        $runs = app(PayrollRunService::class);
        $run = $runs->createRun($this->tenantId, 10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-01']);
        $run = $runs->lockInputs($this->tenantId, (int) $run->id, 10);
        app(CalculationService::class)->calculate($run, 10);
        $this->run = $run->refresh();
    }
}
