<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_result_snapshots', function (Blueprint $table): void {
            $table->integer('score_scaled')->nullable();
            $table->string('grade', 32)->nullable();
            $table->string('source_revision', 64)->nullable();
        });

        Schema::create('performance_bonus_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('performance_snapshot_id')->index();
            $table->unsignedBigInteger('cycle_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->string('payroll_item_code', 64);
            $table->string('period', 7);
            $table->bigInteger('amount_cents');
            $table->string('mapping_version', 32)->default('V1');
            $table->string('status', 16)->default('DRAFT');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at', 6)->nullable();
            $table->string('source_revision', 64);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique(
                ['performance_snapshot_id', 'period', 'mapping_version'],
                'performance_bonus_mapping_version_unique',
            );
            $table->index(['employment_id', 'period', 'status'], 'performance_bonus_payroll_input_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_bonus_mappings');
        Schema::table('performance_result_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['score_scaled', 'grade', 'source_revision']);
        });
    }
};
