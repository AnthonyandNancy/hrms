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
            $table->bigInteger('correction_target_net_cents')->nullable();
            $table->bigInteger('correction_baseline_net_cents')->nullable();
            $table->bigInteger('correction_delta_net_cents')->nullable();
            $table->bigInteger('correction_cumulative_net_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'correction_target_net_cents',
                'correction_baseline_net_cents',
                'correction_delta_net_cents',
                'correction_cumulative_net_cents',
            ]);
        });
    }
};
