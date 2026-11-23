<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->string('code', 64);
            $table->string('name', 160);
            $table->string('type', 24);
            $table->boolean('taxable')->default(true);
            $table->boolean('insurable')->default(true);
            $table->string('calculation', 32)->default('FIXED');
            $table->json('formula_json')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 24)->default('ACTIVE');
            $table->timestamps(6);

            $table->unique(['tenant_id', 'legal_entity_id', 'code'], 'payroll_item_code_unique');
        });

        Schema::create('payroll_schemes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->string('code', 64);
            $table->string('name', 160);
            $table->string('frequency', 16)->default('MONTHLY');
            $table->string('currency', 3);
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 24)->default('ACTIVE');
            $table->date('valid_from');
            $table->date('valid_to')->default('9999-12-31');
            $table->string('proration_divisor', 32)->default('ANNUAL_DAYS_365');
            $table->string('rounding_mode', 16)->default('HALF_UP');
            $table->string('overtime_multiplier', 32)->default('1.500000');
            $table->boolean('allow_negative_net')->default(false);
            $table->bigInteger('base_salary_cents')->default(0);
            $table->timestamps(6);

            $table->unique(['tenant_id', 'legal_entity_id', 'code', 'version'], 'payroll_scheme_version_unique');
        });

        Schema::create('payroll_scheme_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('scheme_id')->index();
            $table->unsignedBigInteger('payroll_item_id')->nullable()->index();
            $table->string('item_code', 64);
            $table->string('label', 160);
            $table->string('category', 24);
            $table->string('calculation', 32)->default('FIXED');
            $table->integer('amount_cents')->default(0);
            $table->bigInteger('rate_scaled')->nullable();
            $table->string('basis', 24)->default('BASE_SALARY');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('taxable')->default(true);
            $table->timestamps(6);

            $table->unique(['scheme_id', 'item_code'], 'payroll_scheme_item_code_unique');
        });

        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->unsignedBigInteger('scheme_id')->index();
            $table->string('period', 7);
            $table->date('earning_period_start');
            $table->date('earning_period_end');
            $table->string('posting_period', 7);
            $table->date('pay_date');
            $table->dateTime('cutoff_at', 6);
            $table->string('status', 24)->default('DRAFT');
            $table->string('run_type', 16)->default('REGULAR');
            $table->unsignedBigInteger('correction_of_run_id')->nullable()->index();
            $table->unsignedBigInteger('prepared_by');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->bigInteger('gross_cents')->default(0);
            $table->bigInteger('deductions_cents')->default(0);
            $table->bigInteger('net_cents')->default(0);
            $table->bigInteger('employer_cost_cents')->default(0);
            $table->char('input_hash', 64)->nullable()->index();
            $table->string('rule_version', 64)->nullable();
            $table->unsignedInteger('snapshot_version')->default(1);
            $table->unsignedInteger('reopen_count')->default(0);
            $table->json('reopen_approvals_json')->nullable();
            $table->dateTime('reopened_at', 6)->nullable();
            $table->unsignedBigInteger('approval_case_id')->nullable();
            $table->string('approval_effect_status', 24)->nullable();
            $table->string('cancellation_reason', 1000)->nullable();
            $table->char('bank_export_hash', 64)->nullable();
            $table->char('tax_export_hash', 64)->nullable();
            $table->dateTime('export_generated_at', 6)->nullable();
            $table->dateTime('calculated_at', 6)->nullable();
            $table->dateTime('reviewed_at', 6)->nullable();
            $table->dateTime('approved_at', 6)->nullable();
            $table->dateTime('published_at', 6)->nullable();
            $table->dateTime('paid_at', 6)->nullable();
            $table->dateTime('closed_at', 6)->nullable();
            $table->timestamps(6);

            $table->index(['tenant_id', 'legal_entity_id', 'period', 'status'], 'payroll_run_period_idx');
            $table->index(['tenant_id', 'period', 'run_type'], 'payroll_run_period_type_idx');
        });

        Schema::create('payroll_run_employees', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('run_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->string('status', 16)->default('PENDING');
            $table->bigInteger('gross_cents')->default(0);
            $table->bigInteger('taxable_cents')->default(0);
            $table->bigInteger('deductions_cents')->default(0);
            $table->bigInteger('net_cents')->default(0);
            $table->bigInteger('employer_cost_cents')->default(0);
            $table->bigInteger('employee_si_cents')->default(0);
            $table->bigInteger('employee_hf_cents')->default(0);
            $table->bigInteger('employer_si_cents')->default(0);
            $table->bigInteger('employer_hf_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('ytd_taxable_opening_cents')->default(0);
            $table->bigInteger('ytd_tax_withheld_opening_cents')->default(0);
            $table->boolean('ytd_applied')->default(false);
            $table->dateTime('ytd_applied_at', 6)->nullable();
            $table->string('failure_reason', 1000)->nullable();
            $table->timestamps(6);

            $table->unique(['run_id', 'employment_id'], 'payroll_run_employee_unique');
        });

        Schema::create('payroll_input_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('run_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->string('snapshot_type', 32)->default('INPUT');
            $table->unsignedInteger('snapshot_version')->default(1);
            $table->json('payload_json');
            $table->char('source_hash', 64);
            $table->dateTime('created_at', 6);

            $table->unique(['run_id', 'employment_id', 'snapshot_type', 'snapshot_version'], 'payroll_snapshot_unique');
        });

        Schema::create('payroll_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('run_id')->nullable()->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->string('item_code', 64);
            $table->integer('amount_cents');
            $table->string('reason', 1000);
            $table->unsignedBigInteger('case_id')->nullable();
            $table->unsignedBigInteger('approved_by');
            $table->string('effective_period', 7);
            $table->dateTime('created_at', 6);

            $table->index(['tenant_id', 'employment_id', 'effective_period'], 'payroll_adjustment_input_idx');
        });

        Schema::create('payslips', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('run_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->unsignedInteger('version')->default(1);
            $table->bigInteger('gross_cents')->default(0);
            $table->bigInteger('deductions_cents')->default(0);
            $table->bigInteger('net_cents')->default(0);
            $table->bigInteger('employer_cost_cents')->default(0);
            $table->dateTime('published_at', 6)->nullable()->index();
            $table->unsignedBigInteger('pdf_file_id')->nullable();
            $table->timestamps(6);

            $table->unique(['run_id', 'employment_id'], 'payslip_run_employee_unique');
            $table->index(['tenant_id', 'employment_id', 'published_at'], 'payslip_self_idx');
        });

        Schema::create('payslip_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('payslip_id')->index();
            $table->string('item_code', 64);
            $table->string('category', 24);
            $table->bigInteger('quantity_scaled')->default(0);
            $table->string('unit', 24)->default('MONTH');
            $table->bigInteger('rate_scaled')->default(0);
            $table->bigInteger('amount_cents');
            $table->boolean('taxable')->default(true);
            $table->string('source_type', 48);
            $table->string('source_id', 128);
            $table->unsignedInteger('sort_order')->default(0);
            $table->dateTime('created_at', 6);

            $table->index(['payslip_id', 'sort_order'], 'payslip_line_order_idx');
        });

        Schema::create('statutory_parameters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->string('parameter_set', 32);
            $table->string('code', 64);
            $table->string('value_decimal', 40)->nullable();
            $table->bigInteger('value_int')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->default('9999-12-31');
            $table->string('version', 64);
            $table->unsignedBigInteger('approved_by');
            $table->dateTime('approved_at', 6);
            $table->string('evidence_ref', 255);
            $table->boolean('is_demo')->default(false);
            $table->timestamps(6);

            $table->unique(
                ['tenant_id', 'legal_entity_id', 'parameter_set', 'code', 'version', 'effective_from'],
                'statutory_parameter_version_unique',
            );
            $table->index(['tenant_id', 'legal_entity_id', 'parameter_set', 'effective_from'], 'statutory_parameter_asof_idx');
        });

        Schema::create('tax_brackets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('parameter_set_id')->index();
            $table->bigInteger('lower_bound_cents');
            $table->bigInteger('upper_bound_cents')->nullable();
            $table->unsignedInteger('rate_bps');
            $table->bigInteger('quick_deduction_cents')->default(0);
            $table->dateTime('created_at', 6);

            $table->index(['parameter_set_id', 'lower_bound_cents'], 'tax_bracket_order_idx');
        });

        Schema::create('payroll_ytd', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->unsignedSmallInteger('tax_year');
            $table->bigInteger('taxable_income_cents')->default(0);
            $table->bigInteger('tax_withheld_cents')->default(0);
            $table->bigInteger('social_insurance_cents')->default(0);
            $table->bigInteger('housing_fund_cents')->default(0);
            $table->bigInteger('gross_cents')->default(0);
            $table->bigInteger('net_cents')->default(0);
            $table->bigInteger('carry_forward_cents')->default(0);
            $table->unsignedBigInteger('last_run_id')->nullable();
            $table->string('source_type', 32)->default('PAYROLL');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();

            $table->unique(['tenant_id', 'employment_id', 'tax_year'], 'payroll_ytd_unique');
        });

        Schema::create('payroll_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('run_id')->index();
            $table->string('type', 8);
            $table->char('file_hash', 64)->nullable();
            $table->string('external_reference', 255)->nullable();
            $table->dateTime('received_at', 6);
            $table->dateTime('reconciled_at', 6)->nullable();
            $table->json('metadata_json')->nullable();
            $table->dateTime('created_at', 6);

            $table->index(['tenant_id', 'run_id', 'type'], 'payroll_receipt_lookup_idx');
        });

        $this->addSqliteImmutabilityTriggers();
    }

    public function down(): void
    {
        $this->dropSqliteImmutabilityTriggers();

        Schema::dropIfExists('payroll_receipts');
        Schema::dropIfExists('payroll_ytd');
        Schema::dropIfExists('tax_brackets');
        Schema::dropIfExists('statutory_parameters');
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_adjustments');
        Schema::dropIfExists('payroll_input_snapshots');
        Schema::dropIfExists('payroll_run_employees');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('payroll_scheme_items');
        Schema::dropIfExists('payroll_schemes');
        Schema::dropIfExists('payroll_items');
    }

    private function addSqliteImmutabilityTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement("CREATE TRIGGER payroll_snapshots_no_update BEFORE UPDATE ON payroll_input_snapshots BEGIN SELECT RAISE(ABORT, 'payroll input snapshots are append-only'); END");
        DB::statement("CREATE TRIGGER payroll_snapshots_no_delete BEFORE DELETE ON payroll_input_snapshots BEGIN SELECT RAISE(ABORT, 'payroll input snapshots are append-only'); END");
        DB::statement("CREATE TRIGGER payslips_published_no_update BEFORE UPDATE ON payslips WHEN OLD.published_at IS NOT NULL BEGIN SELECT RAISE(ABORT, 'published payslips are immutable'); END");
        DB::statement("CREATE TRIGGER payslips_published_no_delete BEFORE DELETE ON payslips WHEN OLD.published_at IS NOT NULL BEGIN SELECT RAISE(ABORT, 'published payslips are immutable'); END");
        DB::statement("CREATE TRIGGER payslip_lines_sealed_no_update BEFORE UPDATE ON payslip_lines WHEN EXISTS (SELECT 1 FROM payslips p JOIN payroll_runs r ON r.id = p.run_id WHERE p.id = OLD.payslip_id AND (p.published_at IS NOT NULL OR r.status IN ('PUBLISHED','PAID','CLOSED'))) BEGIN SELECT RAISE(ABORT, 'payslip lines are immutable after publication'); END");
        DB::statement("CREATE TRIGGER payslip_lines_sealed_no_delete BEFORE DELETE ON payslip_lines WHEN EXISTS (SELECT 1 FROM payslips p JOIN payroll_runs r ON r.id = p.run_id WHERE p.id = OLD.payslip_id AND (p.published_at IS NOT NULL OR r.status IN ('PUBLISHED','PAID','CLOSED'))) BEGIN SELECT RAISE(ABORT, 'payslip lines are immutable after publication'); END");
        DB::statement("CREATE TRIGGER payroll_run_employees_sealed_no_update BEFORE UPDATE ON payroll_run_employees WHEN EXISTS (SELECT 1 FROM payroll_runs r WHERE r.id = OLD.run_id AND r.status IN ('PUBLISHED','PAID','CLOSED')) BEGIN SELECT RAISE(ABORT, 'payroll run employees are immutable after publication'); END");
        DB::statement("CREATE TRIGGER payroll_run_employees_sealed_no_delete BEFORE DELETE ON payroll_run_employees WHEN EXISTS (SELECT 1 FROM payroll_runs r WHERE r.id = OLD.run_id AND r.status IN ('PUBLISHED','PAID','CLOSED')) BEGIN SELECT RAISE(ABORT, 'payroll run employees are immutable after publication'); END");
        DB::statement("CREATE TRIGGER payroll_runs_closed_no_update BEFORE UPDATE ON payroll_runs WHEN OLD.status = 'CLOSED' BEGIN SELECT RAISE(ABORT, 'closed payroll runs are immutable'); END");
        DB::statement("CREATE TRIGGER payroll_runs_sealed_financial_update BEFORE UPDATE ON payroll_runs WHEN OLD.status IN ('PUBLISHED','PAID') AND (NEW.gross_cents IS NOT OLD.gross_cents OR NEW.deductions_cents IS NOT OLD.deductions_cents OR NEW.net_cents IS NOT OLD.net_cents OR NEW.employer_cost_cents IS NOT OLD.employer_cost_cents OR NEW.input_hash IS NOT OLD.input_hash OR NEW.period IS NOT OLD.period OR NEW.scheme_id IS NOT OLD.scheme_id OR NEW.cutoff_at IS NOT OLD.cutoff_at OR NEW.correction_of_run_id IS NOT OLD.correction_of_run_id) BEGIN SELECT RAISE(ABORT, 'published payroll financial facts are immutable'); END");
        DB::statement("CREATE TRIGGER payroll_runs_sealed_no_delete BEFORE DELETE ON payroll_runs WHEN OLD.status IN ('PUBLISHED','PAID','CLOSED') BEGIN SELECT RAISE(ABORT, 'published payroll runs are immutable'); END");
    }

    private function dropSqliteImmutabilityTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        foreach ([
            'payroll_snapshots_no_update',
            'payroll_snapshots_no_delete',
            'payslips_published_no_update',
            'payslips_published_no_delete',
            'payslip_lines_sealed_no_update',
            'payslip_lines_sealed_no_delete',
            'payroll_run_employees_sealed_no_update',
            'payroll_run_employees_sealed_no_delete',
            'payroll_runs_closed_no_update',
            'payroll_runs_sealed_financial_update',
            'payroll_runs_sealed_no_delete',
        ] as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
