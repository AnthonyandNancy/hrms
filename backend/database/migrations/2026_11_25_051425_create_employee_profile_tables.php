<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned employee-profile subresources. Headers are forward-only and every
 * row carries tenant_id; history is retained by superseded_at rather than
 * destructive updates, matching the effective-dated People model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_educations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->string('school', 160);
            $table->string('degree', 80);
            $table->string('major', 160)->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('recorded_at', 6);
            $table->dateTime('superseded_at', 6)->nullable()->index();
            $table->timestamps();

            $table->index(['tenant_id', 'employment_id', 'superseded_at'], 'employee_educations_effective_idx');
        });

        Schema::create('employee_experiences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->string('company', 160);
            $table->string('title', 160);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('leave_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('recorded_at', 6);
            $table->dateTime('superseded_at', 6)->nullable()->index();
            $table->timestamps();

            $table->index(['tenant_id', 'employment_id', 'superseded_at'], 'employee_experiences_effective_idx');
        });

        Schema::create('employee_contacts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->string('name', 120);
            $table->string('relationship', 64);
            $table->string('phone_ciphertext', 1024)->nullable();
            $table->string('email', 255)->nullable();
            $table->boolean('is_emergency')->default(false);
            $table->boolean('is_primary')->default(false);
            $table->dateTime('recorded_at', 6);
            $table->dateTime('superseded_at', 6)->nullable()->index();
            $table->timestamps();

            $table->index(['tenant_id', 'employment_id', 'superseded_at'], 'employee_contacts_effective_idx');
        });

        Schema::create('employee_certificates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->string('certificate_type', 80);
            $table->string('certificate_no', 120)->nullable();
            $table->string('issuer', 160)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->dateTime('recorded_at', 6);
            $table->dateTime('superseded_at', 6)->nullable()->index();
            $table->timestamps();

            $table->index(['tenant_id', 'employment_id', 'superseded_at'], 'employee_certificates_effective_idx');
        });

        Schema::create('employee_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->unsignedBigInteger('requested_by')->index();
            $table->string('status', 24)->default('DRAFT');
            $table->json('diff_json');
            $table->string('reason', 500);
            $table->unsignedBigInteger('case_id')->nullable()->index();
            $table->dateTime('submitted_at', 6)->nullable();
            $table->dateTime('decided_at', 6)->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employment_id', 'status'], 'employee_change_requests_effective_idx');
        });

        Schema::create('employee_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('uploaded_by')->index();
            $table->string('status', 24)->default('VALIDATING');
            $table->string('original_name', 255);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->json('errors_json')->nullable();
            $table->dateTime('validated_at', 6)->nullable();
            $table->dateTime('committed_at', 6)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_import_batches');
        Schema::dropIfExists('employee_change_requests');
        Schema::dropIfExists('employee_certificates');
        Schema::dropIfExists('employee_contacts');
        Schema::dropIfExists('employee_experiences');
        Schema::dropIfExists('employee_educations');
    }
};
