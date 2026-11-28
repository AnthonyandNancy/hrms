<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll execution compensation links back to the agreed contract version,
 * a published scheme/rule version, typed parameter overrides and provenance.
 * Existing rows remain readable; missing links will become unresolved blockers
 * only when a new payroll snapshot tries to consume them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_compensations', function (Blueprint $table): void {
            $table->unsignedBigInteger('contract_version_id')->nullable()->after('employment_id');
            $table->unsignedBigInteger('payroll_scheme_id')->nullable()->after('salary_scheme_code');
            $table->unsignedInteger('payroll_scheme_version')->nullable()->after('payroll_scheme_id');
            $table->json('parameter_overrides_json')->nullable()->after('payroll_scheme_version');
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->string('status', 24)->default('ACTIVE')->after('effective_to');
            $table->unsignedBigInteger('approved_case_id')->nullable()->after('status');
            $table->string('source_type', 32)->nullable()->after('approved_case_id');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->char('content_hash', 64)->nullable()->after('source_id');
            $table->unsignedBigInteger('supersedes_id')->nullable()->after('content_hash');

            $table->index(['contract_version_id'], 'employee_compensations_contract_version_idx');
            $table->index(['payroll_scheme_id', 'payroll_scheme_version'], 'employee_compensations_scheme_idx');
            $table->index(['content_hash'], 'employee_compensations_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::table('employee_compensations', function (Blueprint $table): void {
            $table->dropIndex('employee_compensations_contract_version_idx');
            $table->dropIndex('employee_compensations_scheme_idx');
            $table->dropIndex('employee_compensations_hash_idx');
            $table->dropColumn([
                'contract_version_id',
                'payroll_scheme_id',
                'payroll_scheme_version',
                'parameter_overrides_json',
                'effective_to',
                'status',
                'approved_case_id',
                'source_type',
                'source_id',
                'content_hash',
                'supersedes_id',
            ]);
        });
    }
};
