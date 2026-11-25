<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K12 / D4: stored custom report definitions and report subscriptions.
 *
 * A definition is a whitelist selection over a code-owned projection, never a
 * SQL fragment. A subscription stores only the schedule and recipient; report
 * data is rebuilt on every run so it cannot outlive the recipient's authority.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_definitions', function (Blueprint $table): void {
            $table->boolean('is_system')->default(true)->after('tenant_id');
            $table->unsignedBigInteger('created_by')->nullable()->after('active');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');

            $table->index(['tenant_id', 'is_system', 'active'], 'report_definitions_system_active_idx');
        });

        Schema::table('export_jobs', function (Blueprint $table): void {
            $table->string('source_hash', 64)->nullable()->after('row_count');
            $table->json('field_whitelist_json')->nullable()->after('fields_json');
            $table->json('audit_trail_json')->nullable()->after('watermark');

            $table->index(['tenant_id', 'report_code', 'created_at'], 'export_jobs_report_history_idx');
        });

        Schema::create('report_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('owner_user_id');
            $table->unsignedBigInteger('recipient_user_id');
            $table->string('definition_code', 80);
            $table->string('frequency', 16);
            $table->json('filters_json')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'owner_user_id', 'active'], 'report_subscriptions_owner_idx');
            $table->index(['tenant_id', 'active', 'next_run_at'], 'report_subscriptions_due_idx');
            $table->index(['tenant_id', 'recipient_user_id'], 'report_subscriptions_recipient_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_subscriptions');

        Schema::table('export_jobs', function (Blueprint $table): void {
            $table->dropIndex('export_jobs_report_history_idx');
            $table->dropColumn(['source_hash', 'field_whitelist_json', 'audit_trail_json']);
        });

        Schema::table('report_definitions', function (Blueprint $table): void {
            $table->dropIndex('report_definitions_system_active_idx');
            $table->dropColumn(['is_system', 'created_by', 'updated_by']);
        });
    }
};
