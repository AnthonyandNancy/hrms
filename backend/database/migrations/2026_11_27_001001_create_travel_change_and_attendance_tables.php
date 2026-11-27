<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_request_changes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('travel_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->unsignedBigInteger('case_id')->nullable()->unique();
            $table->json('original_json');
            $table->json('proposed_json');
            $table->string('reason', 500);
            $table->string('status', 16)->default('PENDING');
            $table->dateTime('recorded_at', 6);
            $table->dateTime('applied_at', 6)->nullable();
            $table->timestamps(6);

            $table->index(['travel_id', 'status'], 'travel_change_status_idx');
        });

        Schema::create('travel_attendance_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('travel_id')->unique();
            $table->unsignedBigInteger('employment_id')->index();
            $table->date('from_date');
            $table->date('to_date');
            $table->unsignedBigInteger('source_case_id');
            $table->string('status', 16)->default('ACTIVE');
            $table->dateTime('retired_at', 6)->nullable();
            $table->string('retire_reason', 500)->nullable();
            $table->timestamps(6);

            $table->index(['employment_id', 'from_date', 'to_date'], 'travel_attendance_range_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_attendance_links');
        Schema::dropIfExists('travel_request_changes');
    }
};
