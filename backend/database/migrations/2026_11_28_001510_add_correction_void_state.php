<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->string('correction_status', 16)->default('OPEN');
            $table->dateTime('correction_voided_at', 6)->nullable();
            $table->string('correction_void_reason', 500)->nullable();
            $table->index(['correction_of_run_id', 'period'], 'payroll_correction_period_lookup');
        });

        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropUnique('payroll_correction_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropIndex('payroll_correction_period_lookup');
            $table->dropColumn(['correction_status', 'correction_voided_at', 'correction_void_reason']);
        });
    }
};
