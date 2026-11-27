<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract detail fields that were previously kept only in the append-only
 * version snapshot. Persisting the current value lets the ledger/detail read
 * model return real stored values without reconstructing or fabricating a
 * contract. Historical values remain in employment_contract_versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employment_contracts', function (Blueprint $table): void {
            $table->string('work_location', 160)->nullable()->after('terms_summary');
            $table->date('probation_end_date')->nullable()->after('work_location');
        });
    }

    public function down(): void
    {
        Schema::table('employment_contracts', function (Blueprint $table): void {
            $table->dropColumn(['probation_end_date', 'work_location']);
        });
    }
};
