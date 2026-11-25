<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_compensations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->date('effective_from');
            $table->char('currency', 3)->default('CNY');
            $table->unsignedBigInteger('base_salary_cents');
            $table->string('salary_scheme_code', 64)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->dateTime('created_at', 6)->nullable();

            // Effective-dated archive: a second row for the same employment
            // and start date is a conflict, never an overwrite.
            $table->unique(['employment_id', 'effective_from'], 'employee_compensations_employment_effective_unique');
            $table->index(['tenant_id', 'employment_id', 'effective_from'], 'employee_compensations_asof_idx');
            $table->index(['tenant_id', 'legal_entity_id', 'employment_id'], 'employee_compensations_entity_idx');
        });

        Schema::create('salary_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->date('effective_from');
            $table->char('currency', 3)->default('CNY');
            $table->unsignedBigInteger('base_salary_cents');
            $table->string('reason', 500);
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('compensation_id');
            $table->unsignedBigInteger('created_by');
            $table->dateTime('created_at', 6)->nullable();

            // One approved salary-change case may be applied exactly once.
            $table->unique(['tenant_id', 'case_id'], 'salary_adjustments_case_unique');
            $table->index(['tenant_id', 'employment_id', 'effective_from'], 'salary_adjustments_employment_idx');
            $table->index(['tenant_id', 'legal_entity_id', 'created_at'], 'salary_adjustments_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_adjustments');
        Schema::dropIfExists('employee_compensations');
    }
};
