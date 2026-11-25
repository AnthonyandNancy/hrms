<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract templates plus the append-only lifecycle columns they depend on.
 *
 * The version ledger is still owned by Lifecycle; this migration only makes
 * the contract linkage and termination date available. Existing version rows
 * keep NULL contract_id/change_type so no history is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 64);
            $table->string('name', 160);
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 24)->default('DRAFT');
            $table->text('body');
            $table->dateTime('published_at', 6)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code', 'version'], 'contract_templates_tenant_code_version_unique');
            $table->index(['tenant_id', 'status'], 'contract_templates_tenant_status_idx');
        });

        Schema::table('employment_contracts', function (Blueprint $table): void {
            $table->date('terminated_on')->nullable();
        });

        Schema::table('employment_contract_versions', function (Blueprint $table): void {
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->string('change_type', 32)->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('snapshot_json')->nullable();

            $table->index(['tenant_id', 'contract_id', 'effective_from'], 'contract_versions_contract_history_idx');
            $table->index(['tenant_id', 'change_type'], 'contract_versions_change_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('employment_contract_versions', function (Blueprint $table): void {
            $table->dropIndex('contract_versions_contract_history_idx');
            $table->dropIndex('contract_versions_change_type_idx');
            $table->dropColumn(['contract_id', 'change_type', 'reason', 'snapshot_json']);
        });

        Schema::table('employment_contracts', function (Blueprint $table): void {
            $table->dropColumn('terminated_on');
        });

        Schema::dropIfExists('contract_templates');
    }
};
