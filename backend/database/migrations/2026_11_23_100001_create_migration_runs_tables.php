<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('domain', 32);
            $table->text('source_file')->nullable();
            $table->char('source_file_hash', 64)->default('');
            $table->char('mapping_hash', 64)->nullable();
            $table->string('mode', 16);
            $table->string('status', 24)->index();
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('rows_valid')->default(0);
            $table->unsignedInteger('rows_created')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('report_json')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'domain', 'status'], 'migration_runs_tenant_domain_status_idx');
            $table->index(['domain', 'source_file_hash'], 'migration_runs_domain_file_idx');
        });

        Schema::create('migration_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('migration_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('stg_id')->nullable()->index();
            $table->string('severity', 16);
            $table->string('code', 64);
            $table->text('message');
            $table->unsignedInteger('source_row_number')->nullable();
            $table->string('field', 64)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['run_id', 'severity'], 'migration_issues_run_severity_idx');
            $table->index(['code', 'created_at'], 'migration_issues_code_idx');
        });

        Schema::create('migration_shadow_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('domain', 32);
            $table->string('natural_key', 191);
            $table->unsignedBigInteger('migration_run_id')->index();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->char('data_hash', 64);
            $table->json('payload_json');
            $table->timestamps();

            $table->unique(['tenant_id', 'domain', 'natural_key'], 'migration_shadow_natural_key_unique');
        });

        $this->createStagingTable('stg_organizations');
        $this->createStagingTable('stg_people');
        $this->createStagingTable('stg_assignments');
        $this->createStagingTable('stg_terms');
        $this->createStagingTable('stg_leave_openings');
        $this->createStagingTable('stg_payroll_ytd');
    }

    public function down(): void
    {
        foreach ([
            'stg_organizations',
            'stg_people',
            'stg_assignments',
            'stg_terms',
            'stg_leave_openings',
            'stg_payroll_ytd',
        ] as $tableName) {
            Schema::dropIfExists($tableName);
        }

        Schema::dropIfExists('migration_shadow_rows');
        Schema::dropIfExists('migration_issues');
        Schema::dropIfExists('migration_runs');
    }

    private function createStagingTable(string $name): void
    {
        Schema::create($name, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('run_id')->constrained('migration_runs')->cascadeOnDelete();
            $table->unsignedInteger('source_row_number');
            $table->char('row_hash', 64);
            $table->json('raw_json');
            $table->json('normalized_json')->nullable();
            $table->string('validation_status', 16)->default('PENDING');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['run_id', 'source_row_number'], $table->getTable().'_run_row_unique');
            $table->index(['tenant_id', 'row_hash'], $table->getTable().'_tenant_hash_idx');
        });
    }
};
