<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('benefit_transactions', function (Blueprint $table): void {
            $table->dropUnique('benefit_transactions_enrollment_id_period_unique');
            $table->dropUnique('benefit_transactions_payroll_run_id_unique');
            $table->string('entry_type', 16)->default('RECORD');
            $table->unsignedBigInteger('reversal_of_id')->nullable()->unique('benefit_transactions_reversal_unique');
            $table->unsignedBigInteger('reversed_by_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->char('source_hash', 64)->nullable();
            $table->unique(['enrollment_id', 'period', 'entry_type'], 'benefit_transactions_entry_unique');
        });
    }

    public function down(): void
    {
        Schema::table('benefit_transactions', function (Blueprint $table): void {
            $table->dropUnique('benefit_transactions_entry_unique');
            $table->dropUnique('benefit_transactions_reversal_unique');
            $table->dropColumn(['entry_type', 'reversal_of_id', 'reversed_by_id', 'reason', 'source_hash']);
            $table->unique(['enrollment_id', 'period'], 'benefit_transactions_enrollment_id_period_unique');
            $table->unique(['payroll_run_id'], 'benefit_transactions_payroll_run_id_unique');
        });
    }
};
