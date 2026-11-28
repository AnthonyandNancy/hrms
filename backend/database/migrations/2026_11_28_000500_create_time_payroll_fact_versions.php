<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T06: immutable monthly Time payroll facts.
 *
 * The fact rows are the only Time values Payroll may consume once a month is
 * closed. Versions and rows are append-only; reopening changes only the
 * version state and never rewrites the records a historical payroll saw.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_payroll_fact_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('period', 7);
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('OPEN');
            $table->char('source_hash', 64)->nullable();
            $table->unsignedInteger('exception_count')->default(0);
            $table->json('precheck_json')->nullable();
            $table->timestamp('generated_at', 6);
            $table->timestamp('closed_at', 6)->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('reopened_at', 6)->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->string('reopen_reason', 500)->nullable();
            $table->unsignedBigInteger('reopened_from_version_id')->nullable();
            $table->timestamps(6);

            $table->unique(['period', 'version'], 'time_payroll_fact_version_unique');
            $table->index(['period', 'status'], 'time_payroll_fact_period_status_idx');
        });

        Schema::create('time_payroll_fact_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fact_version_id')->index();
            $table->unsignedBigInteger('employment_id');
            $table->date('business_date');
            $table->date('range_start')->nullable();
            $table->date('range_end')->nullable();
            $table->unsignedInteger('scheduled_minutes')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('paid_leave_minutes')->default(0);
            $table->unsignedInteger('partial_leave_minutes')->default(0);
            $table->unsignedInteger('unpaid_leave_minutes')->default(0);
            $table->unsignedInteger('overtime_pay_minutes')->default(0);
            $table->unsignedInteger('overtime_timeoff_minutes')->default(0);
            $table->unsignedInteger('travel_minutes')->default(0);
            $table->unsignedBigInteger('leave_type_id')->nullable();
            $table->unsignedBigInteger('leave_policy_version_id')->nullable();
            $table->string('overtime_category', 24)->nullable();
            $table->string('travel_mode', 32)->nullable();
            $table->string('source_type', 48);
            $table->string('source_id', 128);
            $table->string('source_revision', 128)->nullable();
            $table->string('approval_status', 24);
            $table->char('source_hash', 64);
            $table->timestamp('recorded_at', 6);
            $table->timestamp('created_at', 6)->nullable();

            $table->unique(
                ['fact_version_id', 'employment_id', 'business_date', 'source_type', 'source_id'],
                'time_payroll_fact_row_source_unique',
            );
            $table->index(['fact_version_id', 'employment_id', 'business_date'], 'time_payroll_fact_employment_day_idx');
        });

        if (! Schema::hasColumn('attendance_period_closures', 'current_fact_version_id')) {
            Schema::table('attendance_period_closures', function (Blueprint $table): void {
                $table->unsignedBigInteger('current_fact_version_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('attendance_period_closures', 'current_fact_version_id')) {
            Schema::table('attendance_period_closures', function (Blueprint $table): void {
                $table->dropIndex(['current_fact_version_id']);
                $table->dropColumn('current_fact_version_id');
            });
        }

        Schema::dropIfExists('time_payroll_fact_rows');
        Schema::dropIfExists('time_payroll_fact_versions');
    }
};
