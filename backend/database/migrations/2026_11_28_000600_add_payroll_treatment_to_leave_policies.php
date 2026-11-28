<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T07: payroll treatment is a first-class leave-policy property.
 *
 * Both columns stay nullable on purpose. A legacy policy without an explicit
 * treatment remains identifiable as UNRESOLVED instead of being guessed from
 * the leave-type code. `accrual_json` may still carry the old shape for read
 * compatibility, but new writes use these columns only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leave_policy_versions', 'payroll_treatment')) {
            Schema::table('leave_policy_versions', static function (Blueprint $table): void {
                $table->string('payroll_treatment', 16)->nullable()->after('accrual_json');
            });
        }

        if (! Schema::hasColumn('leave_policy_versions', 'paid_ratio_scaled')) {
            Schema::table('leave_policy_versions', static function (Blueprint $table): void {
                $table->unsignedInteger('paid_ratio_scaled')->nullable()->after('payroll_treatment');
            });
        }
    }

    public function down(): void
    {
        // Forward-only: payroll evidence cannot be removed by an automatic rollback.
    }
};
