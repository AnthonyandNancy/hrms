<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Benefits and statutory enrollment ledgers.
 *
 * Owning module: Payroll. Plans are effective-dated versions. Enrollment rows
 * are monthly/effective movement history and are never updated to carry a new
 * statutory base. All amounts are integer minor units; rates are exact decimal
 * strings at scale >= 6 owned by the caller and validated in the service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->nullable()->index();
            $table->string('code', 64);
            $table->string('name', 160);
            $table->string('employer_rate', 32);
            $table->string('employee_rate', 32);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by');
            $table->dateTime('created_at', 6);

            $table->unique(['tenant_id', 'code', 'version'], 'benefit_plans_tenant_code_version_unique');
            $table->index(['tenant_id', 'code', 'effective_from'], 'benefit_plans_effective_idx');
        });

        Schema::create('benefit_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->unsignedBigInteger('plan_id')->index();
            $table->unsignedBigInteger('base_amount_cents');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by');
            $table->dateTime('created_at', 6);

            $table->unique(['tenant_id', 'employment_id', 'plan_id', 'effective_from'], 'benefit_enrollments_version_unique');
            $table->index(['tenant_id', 'employment_id', 'effective_from'], 'benefit_enrollments_effective_idx');
        });

        Schema::create('benefit_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->unsignedBigInteger('enrollment_id')->index();
            $table->string('period', 7);
            $table->unsignedBigInteger('base_amount_cents');
            $table->unsignedBigInteger('employee_amount_cents');
            $table->unsignedBigInteger('employer_amount_cents');
            $table->unsignedBigInteger('total_amount_cents');
            $table->unsignedBigInteger('payroll_run_id')->nullable()->index();
            $table->string('status', 16)->default('RECORDED');
            $table->unsignedBigInteger('created_by');
            $table->dateTime('created_at', 6);

            // A monthly contribution can be linked to a payroll run once.
            $table->unique(['tenant_id', 'enrollment_id', 'period'], 'benefit_transactions_period_unique');
            $table->unique(['tenant_id', 'payroll_run_id'], 'benefit_transactions_payroll_unique');
            $table->index(['tenant_id', 'employment_id', 'period'], 'benefit_transactions_employment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_transactions');
        Schema::dropIfExists('benefit_enrollments');
        Schema::dropIfExists('benefit_plans');
    }
};
