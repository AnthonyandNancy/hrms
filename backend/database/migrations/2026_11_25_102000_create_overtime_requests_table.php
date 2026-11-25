<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Overtime request lifecycle. Declared and confirmed minutes are deliberately
 * separate columns: the employee declares planned overtime, a different
 * capability confirms the actual minutes. Confirmation materialises exactly
 * one `overtime_records` row, which may later be settled once as PAY or
 * TIMEOFF (enforced by `overtime_settlements.overtime_record_id` unique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id');
            $table->date('business_date');
            $table->integer('declared_minutes');
            $table->integer('confirmed_minutes')->nullable();
            $table->string('status', 24)->default('APPROVED');
            $table->string('reason', 500);
            $table->unsignedBigInteger('case_id')->nullable()->index();
            $table->unsignedBigInteger('overtime_record_id')->nullable()->unique();
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'employment_id', 'business_date'],
                'overtime_request_day_unique',
            );
            $table->index(
                ['tenant_id', 'employment_id', 'status'],
                'overtime_request_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_requests');
    }
};
