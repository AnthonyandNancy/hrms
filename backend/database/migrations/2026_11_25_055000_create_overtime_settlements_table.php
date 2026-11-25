<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_settlements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('overtime_record_id')->unique();
            $table->unsignedBigInteger('employment_id')->index();
            $table->string('method', 16);
            $table->integer('minutes');
            $table->unsignedBigInteger('payroll_run_id')->nullable()->index();
            $table->unsignedBigInteger('leave_ledger_entry_id')->nullable()->index();
            $table->string('reason', 255);
            $table->dateTime('recorded_at', 6);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_settlements');
    }
};
