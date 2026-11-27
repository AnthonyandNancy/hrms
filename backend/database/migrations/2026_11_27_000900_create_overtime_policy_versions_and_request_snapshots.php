<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M11 overtime policy closure.
 *
 * Company overtime rules become effective-dated, append-only versions. The
 * existence of this table is the only source of a company rule: no code-owned
 * default is backfilled as if the company had configured one.
 *
 * Confirmation snapshots the selected version onto the request row so later
 * policy changes cannot rewrite the evidentiary basis of historical overtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('overtime_policy_versions')) {
            Schema::create('overtime_policy_versions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('version');
                $table->string('status', 16)->default('PUBLISHED');
                $table->date('effective_from');
                $table->date('effective_to');
                $table->unsignedSmallInteger('time_unit_minutes')->default(30);
                $table->string('multiplier', 32)->default('1.500000');
                $table->string('statutory_holiday_multiplier', 32)->default('3.000000');
                $table->boolean('allow_pay')->default(true);
                $table->boolean('allow_timeoff')->default(true);
                $table->string('allocation', 32)->default('EMPLOYEE_CHOICE');
                $table->string('rounding', 16)->default('HALF_UP');
                $table->string('legal_basis', 255)->nullable();
                $table->dateTime('published_at', 6);
                $table->unsignedBigInteger('published_by');
                $table->dateTime('retired_at', 6)->nullable();
                $table->unsignedBigInteger('retired_by')->nullable();
                $table->string('retire_reason', 500)->nullable();
                $table->dateTime('created_at', 6)->nullable();

                $table->unique(['version'], 'overtime_policy_version_unique');
                $table->index(['effective_from', 'effective_to'], 'overtime_policy_effective_idx');
                $table->index(['status', 'retired_at', 'effective_to'], 'overtime_policy_state_idx');
            });
        }

        if (Schema::hasTable('overtime_requests')) {
            Schema::table('overtime_requests', function (Blueprint $table): void {
                if (! Schema::hasColumn('overtime_requests', 'policy_version_id')) {
                    $table->unsignedBigInteger('policy_version_id')->nullable()->index();
                }

                if (! Schema::hasColumn('overtime_requests', 'policy_snapshot_json')) {
                    $table->json('policy_snapshot_json')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Forward-only: confirmed requests keep their policy evidence. Dropping
        // this table or the snapshot columns would destroy audit facts.
    }
};
