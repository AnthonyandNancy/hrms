<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K10 training-lifecycle facts. Owning module: Talent.
 *
 * Plans and courses are catalogue rows. Check-ins are idempotent per
 * (session, employment) and store only clock evidence; study minutes are
 * always derived. Exams are immutable versions once published. Feedback is
 * append-only per enrolment. The existing sessions/enrolments remain the
 * authoritative enrolment and completion facts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('plan_id')->nullable()->index();
            $table->unsignedBigInteger('course_id')->nullable()->index();
        });

        Schema::create('training_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 64);
            $table->string('title', 160);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('budget_cents')->default(0);
            $table->string('currency', 3)->default('CNY');
            $table->string('status', 24)->default('ACTIVE');
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'training_plans_tenant_code_unique');
            $table->index(['tenant_id', 'year', 'status'], 'training_plans_year_status_idx');
        });

        Schema::create('training_courses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('plan_id')->nullable()->index();
            $table->string('code', 64);
            $table->string('title', 160);
            $table->string('instructor', 120)->nullable();
            $table->string('delivery_mode', 24)->default('ONSITE');
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->unsignedBigInteger('cost_cents')->default(0);
            $table->string('currency', 3)->default('CNY');
            $table->text('materials')->nullable();
            $table->string('status', 24)->default('ACTIVE');
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'training_courses_tenant_code_unique');
            $table->index(['tenant_id', 'plan_id', 'status'], 'training_courses_plan_status_idx');
        });

        Schema::create('training_checkins', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('session_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->timestamp('checked_in_at');
            $table->timestamp('checked_out_at')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'session_id', 'employment_id'], 'training_checkins_session_employment_unique');
        });

        Schema::create('training_exams', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('course_id')->nullable()->index();
            $table->string('code', 64);
            $table->string('title', 200);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedSmallInteger('passing_score');
            $table->unsignedSmallInteger('max_score');
            $table->string('status', 24)->default('DRAFT');
            $table->dateTime('published_at', 6)->nullable();
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'code', 'version'], 'training_exams_tenant_code_version_unique');
            $table->index(['tenant_id', 'status'], 'training_exams_status_idx');
        });

        Schema::create('training_feedback', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('enrollment_id')->index();
            $table->unsignedBigInteger('session_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->unsignedSmallInteger('rating')->nullable();
            $table->text('comments');
            $table->json('questionnaire_json')->nullable();
            $table->string('status', 24)->default('RECORDED');
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->index(['tenant_id', 'enrollment_id', 'id'], 'training_feedback_enrollment_idx');
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table): void {
            $table->dropIndex(['plan_id']);
            $table->dropIndex(['course_id']);
            $table->dropColumn(['plan_id', 'course_id']);
        });

        Schema::dropIfExists('training_feedback');
        Schema::dropIfExists('training_exams');
        Schema::dropIfExists('training_checkins');
        Schema::dropIfExists('training_courses');
        Schema::dropIfExists('training_plans');
    }
};
