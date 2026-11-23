<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_rule_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('version', 32);
            $table->json('config_json');
            $table->date('effective_from');
            $table->char('checksum', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'version'], 'attendance_rule_version_unique');
        });

        Schema::create('attendance_days', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id');
            $table->date('business_date');
            $table->unsignedBigInteger('shift_template_id')->nullable();
            $table->unsignedInteger('scheduled_minutes')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->unsignedInteger('absence_minutes')->default(0);
            $table->string('status', 16);
            $table->string('rule_version', 32);
            $table->char('source_hash', 64);
            $table->timestamp('calculated_at', 6);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['tenant_id', 'employment_id', 'business_date'], 'attendance_day_unique');
            $table->index(['tenant_id', 'business_date'], 'attendance_business_date_idx');
        });

        Schema::create('attendance_corrections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id');
            $table->date('business_date');
            $table->unsignedBigInteger('requested_by');
            $table->string('reason', 500);
            $table->json('corrected_punches_json');
            $table->unsignedBigInteger('case_id');
            $table->timestamp('applied_at', 6)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'case_id'], 'correction_case_unique');
            $table->index(['tenant_id', 'employment_id', 'business_date'], 'correction_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('attendance_days');
        Schema::dropIfExists('attendance_rule_versions');
    }
};
