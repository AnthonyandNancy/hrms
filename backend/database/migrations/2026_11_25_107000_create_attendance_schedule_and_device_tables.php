<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K4/C2 — attendance devices and per-day shift schedules.
 *
 * `shift_schedules` is deliberately separate from `shift_assignments`:
 * assignments are validity ranges of a template, while a schedule pins one
 * employment to one template on one business date. The uniqueness constraint
 * is the database half of the overlap invariant ("one shift per employment
 * per day"); the service translates a duplicate into a 409 SCHEDULE_CONFLICT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_schedules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->date('business_date');
            $table->unsignedBigInteger('shift_template_id');
            $table->unsignedBigInteger('created_by');
            $table->dateTime('created_at', 6)->nullable();

            // One schedule row per employment per business day. A second row is
            // an overlap, never an overwrite.
            $table->unique(['tenant_id', 'employment_id', 'business_date'], 'shift_schedules_employment_date_unique');
            $table->index(['tenant_id', 'legal_entity_id', 'business_date'], 'shift_schedules_entity_date_idx');
        });

        Schema::create('attendance_devices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->string('code', 64);
            $table->string('name', 128);
            $table->string('adapter', 64);
            $table->string('health', 16)->default('UNKNOWN');
            $table->timestamp('last_seen_at', 6)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('created_at', 6)->nullable();
            $table->timestamp('updated_at', 6)->nullable();

            $table->unique(['tenant_id', 'legal_entity_id', 'code'], 'attendance_devices_entity_code_unique');
            $table->index(['tenant_id', 'legal_entity_id', 'active'], 'attendance_devices_entity_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_devices');
        Schema::dropIfExists('shift_schedules');
    }
};
