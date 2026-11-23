<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_requisitions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->unsignedBigInteger('org_unit_id')->index();
            $table->string('requisition_no', 64);
            $table->string('title', 160);
            $table->string('status', 24)->default('DRAFT');
            $table->unsignedInteger('headcount');
            $table->unsignedBigInteger('case_id')->nullable()->index();
            $table->date('target_start_date')->nullable();
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'requisition_no'], 'requisition_no_unique');
            $table->index(['tenant_id', 'legal_entity_id', 'status']);
        });

        Schema::create('candidates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('display_name', 120);
            $table->string('email', 254)->nullable();
            $table->text('mobile_ciphertext')->nullable();
            $table->string('email_hash', 64)->nullable();
            $table->string('mobile_hash', 64)->nullable();
            $table->string('source', 64)->nullable();
            $table->string('status', 24)->default('NEW');
            $table->unsignedBigInteger('linked_person_id')->nullable()->index();
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'email_hash'], 'candidate_email_hash_unique');
            $table->unique(['tenant_id', 'mobile_hash'], 'candidate_mobile_hash_unique');
        });

        Schema::create('interviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('candidate_id')->index();
            $table->unsignedBigInteger('requisition_id')->index();
            $table->dateTime('scheduled_at', 6);
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->string('interviewer', 120);
            $table->string('mode', 32)->default('VIDEO');
            $table->string('status', 24)->default('SCHEDULED');
            $table->string('recommendation', 24)->nullable();
            $table->text('feedback')->nullable();
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->index(['tenant_id', 'candidate_id', 'scheduled_at']);
        });

        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('candidate_id')->index();
            $table->unsignedBigInteger('requisition_id')->index();
            $table->unsignedInteger('version');
            $table->string('status', 24)->default('DRAFT');
            $table->unsignedBigInteger('base_salary_minor');
            $table->char('salary_currency', 3);
            $table->date('start_date');
            $table->unsignedBigInteger('case_id')->nullable()->index();
            $table->unsignedBigInteger('onboarding_case_id')->nullable()->index();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->unsignedBigInteger('superseded_by_id')->nullable();
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'candidate_id', 'version'], 'offer_candidate_version_unique');
            $table->index(['tenant_id', 'requisition_id', 'status']);
        });

        Schema::create('performance_cycles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name', 120);
            $table->date('period_from');
            $table->date('period_to');
            $table->string('status', 24)->default('DRAFT');
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'performance_cycle_name_unique');
        });

        Schema::create('performance_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 64);
            $table->string('name', 120);
            $table->json('sections_json');
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'code', 'version'], 'performance_template_version_unique');
        });

        Schema::create('performance_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('cycle_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->unsignedBigInteger('manager_employment_id')->nullable()->index();
            $table->text('goals_text')->nullable();
            $table->text('self_assessment')->nullable();
            $table->text('manager_assessment')->nullable();
            $table->string('self_rating', 16)->nullable();
            $table->string('manager_rating', 16)->nullable();
            $table->string('calibrated_rating', 16)->nullable();
            $table->string('status', 24)->default('DRAFT');
            $table->timestamp('submitted_at')->nullable();
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'cycle_id', 'employment_id'], 'review_cycle_employment_unique');
            $table->index(['tenant_id', 'employment_id', 'status']);
        });

        Schema::create('performance_appeals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('review_id')->index();
            $table->unsignedBigInteger('case_id')->unique();
            $table->text('reason');
            $table->string('status', 24)->default('OPEN');
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'review_id'], 'appeal_review_unique');
        });

        Schema::create('training_programs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 64);
            $table->string('title', 160);
            $table->string('status', 24)->default('ACTIVE');
            $table->unsignedSmallInteger('default_capacity')->default(0);
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'training_program_code_unique');
        });

        Schema::create('training_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('program_id')->index();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6);
            $table->unsignedSmallInteger('capacity');
            $table->string('location', 160)->nullable();
            $table->string('status', 24)->default('SCHEDULED');
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->index(['tenant_id', 'program_id', 'starts_on']);
        });

        Schema::create('training_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('session_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->string('status', 24)->default('ENROLLED');
            $table->boolean('attended')->default(false);
            $table->string('completion_result', 24)->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'session_id', 'employment_id'], 'enrollment_session_employment_unique');
            $table->index(['tenant_id', 'employment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_enrollments');
        Schema::dropIfExists('training_sessions');
        Schema::dropIfExists('training_programs');
        Schema::dropIfExists('performance_appeals');
        Schema::dropIfExists('performance_reviews');
        Schema::dropIfExists('performance_templates');
        Schema::dropIfExists('performance_cycles');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('interviews');
        Schema::dropIfExists('candidates');
        Schema::dropIfExists('recruitment_requisitions');
    }
};
