<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_entity_calendars', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id');
            $table->string('timezone', 64);
            $table->json('workweek_json');
            $table->unsignedSmallInteger('standard_day_minutes')->default(480);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['tenant_id', 'legal_entity_id'], 'calendar_entity_unique');
        });

        Schema::create('holidays', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id');
            $table->date('date');
            $table->string('name', 128);
            $table->string('type', 16);
            $table->string('source', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'legal_entity_id', 'date'], 'holiday_entity_date_unique');
            $table->index(['tenant_id', 'legal_entity_id', 'date'], 'holiday_lookup_idx');
        });

        Schema::create('shift_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id');
            $table->string('code', 32);
            $table->string('name', 128);
            $table->time('start_local_time');
            $table->time('end_local_time');
            $table->boolean('crosses_midnight')->default(false);
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedSmallInteger('late_grace_minutes')->default(0);
            $table->unsignedSmallInteger('early_grace_minutes')->default(0);
            $table->decimal('standard_hours_decimal', 8, 6);
            $table->unsignedInteger('version')->default(1);
            $table->date('valid_from');
            $table->date('valid_to');
            $table->string('source_type', 32)->default('MANUAL');
            $table->string('source_id', 64)->nullable();
            $table->timestamp('recorded_at', 6)->nullable();
            $table->timestamp('superseded_at', 6)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['tenant_id', 'legal_entity_id', 'code', 'version', 'valid_from'], 'shift_version_unique');
            $table->index(['tenant_id', 'legal_entity_id', 'valid_from', 'valid_to'], 'shift_validity_idx');
        });

        Schema::create('shift_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id');
            $table->unsignedBigInteger('shift_template_id');
            $table->date('valid_from');
            $table->date('valid_to');
            $table->string('source', 32)->default('MANUAL');
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'employment_id', 'valid_from', 'valid_to'], 'assignment_validity_idx');
        });

        Schema::create('punch_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id');
            $table->string('device_id', 64)->nullable();
            $table->dateTime('punched_at_utc', 6);
            $table->string('source_local_offset', 8)->nullable();
            $table->string('source', 16);
            $table->char('raw_payload_hash', 64);
            $table->timestamp('created_at', 6)->nullable();

            $table->unique(['tenant_id', 'employment_id', 'raw_payload_hash'], 'punch_hash_unique');
            $table->index(['tenant_id', 'employment_id', 'punched_at_utc'], 'punch_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('punch_records');
        Schema::dropIfExists('shift_assignments');
        Schema::dropIfExists('shift_templates');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('legal_entity_calendars');
    }
};
