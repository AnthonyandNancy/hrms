<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * First-class agreed terms on the append-only contract version ledger.
 * Execution compensation remains Payroll-owned and is linked by a later
 * compensation-version migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employment_contract_versions', function (Blueprint $table): void {
            $table->bigInteger('agreed_salary_minor')->nullable()->after('effective_to');
            $table->char('agreed_currency', 3)->nullable()->after('agreed_salary_minor');
            $table->char('terms_hash', 64)->nullable()->after('agreed_currency');
            $table->string('status', 24)->default('RECORDED')->after('terms_hash');
            $table->dateTime('activated_at', 6)->nullable()->after('status');
            $table->unsignedBigInteger('activated_by')->nullable()->after('activated_at');
            $table->index(['terms_hash'], 'contract_terms_hash_idx');
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER employment_contract_versions_no_update BEFORE UPDATE ON employment_contract_versions BEGIN SELECT RAISE(ABORT, 'contract versions are append-only'); END");
            DB::statement("CREATE TRIGGER employment_contract_versions_no_delete BEFORE DELETE ON employment_contract_versions BEGIN SELECT RAISE(ABORT, 'contract versions are append-only'); END");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS employment_contract_versions_no_update');
            DB::statement('DROP TRIGGER IF EXISTS employment_contract_versions_no_delete');
        }

        Schema::table('employment_contract_versions', function (Blueprint $table): void {
            $table->dropIndex('contract_terms_hash_idx');
            $table->dropColumn([
                'agreed_salary_minor',
                'agreed_currency',
                'terms_hash',
                'status',
                'activated_at',
                'activated_by',
            ]);
        });
    }
};
