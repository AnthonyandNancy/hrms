<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_definitions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 80);
            $table->string('name', 160);
            $table->string('description', 500)->nullable();
            $table->string('source_projection', 80);
            $table->boolean('as_of_supported')->default(true);
            $table->json('fields_json');
            $table->json('filters_json');
            $table->string('drilldown_route', 200)->nullable();
            $table->unsignedInteger('catalog_version')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'report_definitions_tenant_code_unique');
        });

        Schema::create('report_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('definition_code', 80);
            $table->date('as_of');
            $table->json('filters_json')->nullable();
            $table->string('status', 16)->default('RUNNING');
            $table->unsignedBigInteger('row_count')->default(0);
            $table->unsignedBigInteger('requested_by');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'requested_by', 'created_at'], 'report_runs_requester_idx');
        });

        Schema::create('export_jobs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('report_code', 80);
            $table->string('purpose', 40);
            $table->json('fields_json');
            $table->json('filters_json');
            $table->string('status', 16)->default('QUEUED');
            $table->unsignedBigInteger('row_count')->default(0);
            $table->unsignedBigInteger('file_object_id')->nullable();
            $table->timestamp('expires_at');
            $table->unsignedInteger('download_count')->default(0);
            $table->unsignedInteger('max_downloads')->default(5);
            $table->unsignedBigInteger('requested_by');
            $table->string('download_token_hash', 64);
            $table->timestamp('download_token_expires_at');
            $table->string('watermark', 255);
            $table->unsignedInteger('part_no')->default(1);
            $table->unsignedInteger('total_parts')->default(1);
            $table->unsignedBigInteger('parent_job_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'requested_by', 'created_at'], 'export_jobs_requester_idx');
            $table->index(['tenant_id', 'status', 'expires_at'], 'export_jobs_status_expiry_idx');
        });

        Schema::create('dashboard_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('metric_code', 80);
            $table->date('as_of');
            $table->bigInteger('value')->default(0);
            $table->string('unit', 32)->default('COUNT');
            $table->string('status', 16)->default('AVAILABLE');
            $table->string('reason', 120)->nullable();
            $table->string('source_hash', 64);
            $table->string('scope_hash', 64);
            $table->json('payload_json')->nullable();
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'metric_code', 'as_of', 'scope_hash'], 'dashboard_snapshot_scope_unique');
            $table->index(['tenant_id', 'as_of', 'metric_code'], 'dashboard_snapshot_lookup_idx');
        });

        Schema::create('reporting_projection_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('source_projection', 80);
            $table->date('business_date');
            $table->string('metric_code', 80)->nullable();
            $table->string('entity_type', 40)->default('ROW');
            $table->string('entity_id', 80);
            $table->unsignedBigInteger('employment_id')->nullable();
            $table->unsignedBigInteger('org_unit_id')->nullable();
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->string('label', 200);
            $table->string('secondary_label', 300)->nullable();
            $table->string('status', 40)->nullable();
            $table->bigInteger('numeric_value')->default(0);
            $table->json('attributes_json')->nullable();
            $table->string('source_hash', 64);
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->index(['tenant_id', 'source_projection', 'business_date'], 'reporting_projection_source_idx');
            $table->index(['tenant_id', 'metric_code', 'business_date'], 'reporting_projection_metric_idx');
            $table->index(['tenant_id', 'employment_id'], 'reporting_projection_employment_idx');
            $table->index(['tenant_id', 'org_unit_id'], 'reporting_projection_org_idx');
            $table->unique(
                ['tenant_id', 'source_projection', 'business_date', 'entity_type', 'entity_id'],
                'reporting_projection_identity_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_projection_rows');
        Schema::dropIfExists('dashboard_snapshots');
        Schema::dropIfExists('export_jobs');
        Schema::dropIfExists('report_runs');
        Schema::dropIfExists('report_definitions');
    }
};
