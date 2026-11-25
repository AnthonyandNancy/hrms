<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_period_closures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->string('month', 7);
            $table->string('status', 16)->default('CLOSED');
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'legal_entity_id', 'month'], 'attendance_closure_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_period_closures');
    }
};
