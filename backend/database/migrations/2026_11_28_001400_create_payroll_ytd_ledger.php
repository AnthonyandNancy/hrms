<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only, signed YTD evidence. payroll_ytd remains a recomputable
 * projection; every publication/correction is represented once here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_ytd_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->unsignedSmallInteger('tax_year')->index();
            $table->unsignedBigInteger('source_run_id')->index();
            $table->unsignedBigInteger('source_employee_id')->index();
            $table->string('source_type', 24);
            $table->string('entry_type', 24)->default('PUBLISH');
            $table->bigInteger('taxable_income_cents')->default(0);
            $table->bigInteger('tax_withheld_cents')->default(0);
            $table->bigInteger('social_insurance_cents')->default(0);
            $table->bigInteger('housing_fund_cents')->default(0);
            $table->bigInteger('gross_cents')->default(0);
            $table->bigInteger('net_cents')->default(0);
            $table->bigInteger('carry_forward_cents')->default(0);
            $table->char('source_hash', 64);
            $table->dateTime('created_at', 6);

            $table->unique(['source_run_id', 'employment_id', 'entry_type'], 'payroll_ytd_ledger_source_unique');
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER payroll_ytd_ledger_no_update BEFORE UPDATE ON payroll_ytd_ledger_entries BEGIN SELECT RAISE(ABORT, 'payroll YTD ledger is append-only'); END");
            DB::statement("CREATE TRIGGER payroll_ytd_ledger_no_delete BEFORE DELETE ON payroll_ytd_ledger_entries BEGIN SELECT RAISE(ABORT, 'payroll YTD ledger is append-only'); END");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS payroll_ytd_ledger_no_update');
            DB::statement('DROP TRIGGER IF EXISTS payroll_ytd_ledger_no_delete');
        }

        Schema::dropIfExists('payroll_ytd_ledger_entries');
    }
};
