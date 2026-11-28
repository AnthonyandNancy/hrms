<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce one pending People draft per employee number without preventing a
 * later rehire after the previous draft is consumed. MySQL/SQLite both allow
 * multiple NULL values in a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people_onboarding_drafts', function (Blueprint $table): void {
            $table->string('pending_employee_no', 64)->nullable()->unique();
        });

        DB::table('people_onboarding_drafts')
            ->where('status', 'PENDING')
            ->update(['pending_employee_no' => DB::raw('employee_no')]);
    }

    public function down(): void
    {
        Schema::table('people_onboarding_drafts', function (Blueprint $table): void {
            $table->dropUnique(['pending_employee_no']);
            $table->dropColumn('pending_employee_no');
        });
    }
};
