<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2.4 is single-company: the signed YTD ledger must not retain tenant
 * identifiers. The unique source-run key already scopes rows globally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_ytd_ledger_entries', function (Blueprint $table): void {
            $table->dropIndex('payroll_ytd_ledger_entries_tenant_id_index');
            $table->dropColumn('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_ytd_ledger_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->default(0)->index();
        });
    }
};
