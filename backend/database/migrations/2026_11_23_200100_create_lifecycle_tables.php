<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('case_id')->index();
            $table->unsignedBigInteger('employment_id')->nullable()->index();
            $table->string('task_code', 64);
            $table->string('title', 160);
            $table->string('status', 24)->default('PENDING');
            $table->boolean('required')->default(true);
            $table->string('assignee_role', 64)->nullable();
            $table->string('waiver_reason', 500)->nullable();
            $table->date('due_date')->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->unsignedBigInteger('completed_by_user_id')->nullable();
            $table->dateTime('recorded_at', 6);
            $table->string('source_type', 32)->default('CASE');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();

            $table->unique(['case_id', 'task_code'], 'lifecycle_task_case_code_unique');
            $table->index(['tenant_id', 'employment_id', 'status']);
        });

        Schema::create('lifecycle_intents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('case_id')->unique();
            $table->string('case_type', 64);
            $table->string('status', 24)->default('DRAFT');
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('intent_json');
            $table->unsignedBigInteger('employment_id')->nullable()->index();
            $table->unsignedBigInteger('result_employment_id')->nullable()->index();
            $table->dateTime('effective_at', 6)->nullable();
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->index(['tenant_id', 'case_type', 'status']);
        });

        Schema::create('onboarding_checklist_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('task_code', 64);
            $table->string('title', 160);
            $table->boolean('required')->default(true);
            $table->string('assignee_role', 64)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->dateTime('recorded_at', 6);

            $table->unique(['tenant_id', 'task_code'], 'onboarding_template_code_unique');
        });

        Schema::create('employment_contract_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->unsignedInteger('terms_version');
            $table->unsignedInteger('document_version');
            $table->unsignedBigInteger('file_object_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->default('9999-12-31');
            $table->date('expires_at')->nullable();
            $table->dateTime('recorded_at', 6);
            $table->string('source_type', 32)->default('CASE');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();

            $table->unique(['employment_id', 'terms_version'], 'contract_terms_version_unique');
            $table->index(['tenant_id', 'employment_id', 'effective_from', 'effective_to'], 'contract_asof_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_contract_versions');
        Schema::dropIfExists('onboarding_checklist_templates');
        Schema::dropIfExists('lifecycle_intents');
        Schema::dropIfExists('lifecycle_tasks');
    }
};
