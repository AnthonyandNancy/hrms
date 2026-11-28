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
            $table->unsignedBigInteger('correction_baseline_run_id')->nullable()->index();
            $table->unsignedInteger('correction_sequence')->default(1);
            $table->json('prior_correction_ids_json')->nullable();
            $table->unique(['correction_of_run_id', 'period'], 'payroll_correction_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropUnique('payroll_correction_period_unique');
            $table->dropColumn([
                'correction_baseline_run_id',
                'correction_sequence',
                'prior_correction_ids_json',
            ]);
        });
    }
};
