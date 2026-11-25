<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance-cycle detail facts. Owning module: Talent.
 *
 * Indicators are a tenant-scoped catalogue. Goals and feedback reference the
 * cycle and employment aggregate IDs without duplicating employee truth.
 * Calibrations are append-only evidence: the review keeps the authoritative
 * calibrated_rating while every change retains its previous value and reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_indicators', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 64);
            $table->string('name', 160);
            $table->string('category', 64)->nullable();
            $table->string('data_source', 120)->nullable();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'performance_indicator_code_unique');
            $table->index(['tenant_id', 'active']);
        });

        Schema::create('performance_goals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('cycle_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->unsignedBigInteger('indicator_id')->nullable()->index();
            $table->string('title', 200);
            $table->string('target_value', 120);
            $table->unsignedSmallInteger('weight')->default(0);
            $table->date('due_on')->nullable();
            $table->string('status', 24)->default('DRAFT');
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->index(['tenant_id', 'cycle_id', 'employment_id'], 'performance_goals_scope_idx');
            $table->index(['tenant_id', 'cycle_id', 'status'], 'performance_goals_status_idx');
        });

        Schema::create('performance_feedback', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('cycle_id')->index();
            $table->unsignedBigInteger('review_id')->nullable()->index();
            $table->unsignedBigInteger('from_employment_id')->index();
            $table->unsignedBigInteger('to_employment_id')->index();
            $table->string('feedback_type', 24);
            $table->string('rating', 16)->nullable();
            $table->text('comments');
            $table->boolean('anonymous')->default(false);
            $table->string('status', 24)->default('RECORDED');
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->index(['tenant_id', 'cycle_id', 'to_employment_id'], 'performance_feedback_scope_idx');
        });

        Schema::create('performance_calibrations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('cycle_id')->index();
            $table->unsignedBigInteger('review_id')->index();
            $table->string('previous_rating', 16)->nullable();
            $table->string('calibrated_rating', 16);
            $table->text('reason');
            $table->unsignedBigInteger('calibrated_by')->index();
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->index(['tenant_id', 'review_id', 'id'], 'performance_calibrations_review_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_calibrations');
        Schema::dropIfExists('performance_feedback');
        Schema::dropIfExists('performance_goals');
        Schema::dropIfExists('performance_indicators');
    }
};
