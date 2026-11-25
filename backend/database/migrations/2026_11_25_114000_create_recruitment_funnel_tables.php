<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_requisitions', function (Blueprint $table): void {
            $table->unsignedBigInteger('budget_minor')->nullable()->after('headcount');
            $table->char('budget_currency', 3)->nullable()->default('CNY')->after('budget_minor');
        });

        Schema::create('candidate_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('candidate_id')->index();
            $table->unsignedBigInteger('interview_id')->nullable()->index();
            $table->unsignedBigInteger('evaluator_user_id')->index();
            $table->unsignedTinyInteger('rating');
            $table->string('recommendation', 24);
            $table->text('strengths')->nullable();
            $table->text('concerns')->nullable();
            $table->text('comments')->nullable();
            $table->string('status', 24)->default('RECORDED');
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->index(['tenant_id', 'candidate_id', 'recorded_at'], 'candidate_evaluations_candidate_idx');
        });

        Schema::create('candidate_communications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('candidate_id')->index();
            $table->string('channel', 24);
            $table->string('direction', 16);
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->string('consent_basis', 64);
            $table->timestamp('sent_at')->nullable();
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->index(['tenant_id', 'candidate_id', 'recorded_at'], 'candidate_communications_candidate_idx');
        });

        Schema::create('talent_pool_tags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('candidate_id')->index();
            $table->string('tag', 64);
            $table->string('reason', 500)->nullable();
            $table->date('retention_until')->nullable();
            $table->string('status', 24)->default('ACTIVE');
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'candidate_id', 'tag'], 'talent_pool_candidate_tag_unique');
        });

        Schema::create('candidate_imports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('requisition_id')->nullable()->index();
            $table->string('source', 32)->default('MANUAL');
            $table->string('status', 24)->default('COMPLETED');
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('accepted_count');
            $table->unsignedInteger('rejected_count');
            $table->json('rows_json');
            $table->dateTime('recorded_at', 6);
            $table->timestamps();
        });

        Schema::create('candidate_conversions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('employment_id')->index();
            $table->unsignedBigInteger('person_id')->index();
            $table->unsignedBigInteger('offer_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->unsignedBigInteger('org_unit_id')->index();
            $table->string('employee_no', 64);
            $table->string('status', 24)->default('COMPLETED');
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'candidate_id'], 'candidate_conversion_candidate_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_conversions');
        Schema::dropIfExists('candidate_imports');
        Schema::dropIfExists('talent_pool_tags');
        Schema::dropIfExists('candidate_communications');
        Schema::dropIfExists('candidate_evaluations');

        Schema::table('recruitment_requisitions', function (Blueprint $table): void {
            $table->dropColumn(['budget_minor', 'budget_currency']);
        });
    }
};
