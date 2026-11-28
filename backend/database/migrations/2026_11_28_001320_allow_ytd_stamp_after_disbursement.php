<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Publication may legally happen after disbursement registration. YTD folding
 * is part of publication, so the sealed employee row must allow only the two
 * ytd evidence flags to change; all payroll amounts remain immutable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS payroll_run_employees_sealed_no_update');
        DB::statement("CREATE TRIGGER payroll_run_employees_sealed_no_update BEFORE UPDATE ON payroll_run_employees WHEN EXISTS (SELECT 1 FROM payroll_runs r WHERE r.id = OLD.run_id AND r.status IN ('PUBLISHED','PAID','CLOSED')) AND (NEW.status IS NOT OLD.status OR NEW.gross_cents IS NOT OLD.gross_cents OR NEW.taxable_cents IS NOT OLD.taxable_cents OR NEW.deductions_cents IS NOT OLD.deductions_cents OR NEW.net_cents IS NOT OLD.net_cents OR NEW.employer_cost_cents IS NOT OLD.employer_cost_cents OR NEW.employee_si_cents IS NOT OLD.employee_si_cents OR NEW.employee_hf_cents IS NOT OLD.employee_hf_cents OR NEW.employer_si_cents IS NOT OLD.employer_si_cents OR NEW.employer_hf_cents IS NOT OLD.employer_hf_cents OR NEW.tax_cents IS NOT OLD.tax_cents OR NEW.failure_reason IS NOT OLD.failure_reason) BEGIN SELECT RAISE(ABORT, 'payroll run employees are immutable after publication'); END");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS payroll_run_employees_sealed_no_update');
        DB::statement("CREATE TRIGGER payroll_run_employees_sealed_no_update BEFORE UPDATE ON payroll_run_employees WHEN EXISTS (SELECT 1 FROM payroll_runs r WHERE r.id = OLD.run_id AND r.status IN ('PUBLISHED','PAID','CLOSED')) BEGIN SELECT RAISE(ABORT, 'payroll run employees are immutable after publication'); END");
    }
};
