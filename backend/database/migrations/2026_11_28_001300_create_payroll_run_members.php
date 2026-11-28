<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Run-scoped payroll membership. Actor columns remain the compatibility
 * projection for historical runs; new assignments are sourced from this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_run_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('run_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('member_role', 24);
            $table->unsignedBigInteger('assigned_by');
            $table->string('reason', 500);
            $table->dateTime('valid_from', 6);
            $table->timestamps(6);

            $table->unique(['run_id', 'user_id', 'member_role'], 'payroll_run_member_unique');
            $table->index(['user_id', 'run_id'], 'payroll_run_member_user_run_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_run_members');
    }
};
