<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('legal_name', 120);
            $table->string('preferred_name', 120)->nullable();
            $table->string('id_type', 32)->nullable();
            $table->text('id_number_ciphertext')->nullable();
            $table->char('id_number_hash', 64)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 32)->nullable();
            $table->string('nationality', 2)->nullable();
            $table->text('mobile_ciphertext')->nullable();
            $table->string('email', 254)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(['tenant_id', 'id_type', 'id_number_hash'], 'persons_tenant_identity_unique');
            $table->index(['tenant_id', 'email'], 'persons_tenant_email_idx');
            $table->index(['tenant_id', 'legal_name']);
        });

        Schema::create('employments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('person_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->string('employee_no', 64);
            $table->string('employment_type', 32);
            $table->string('status', 24)->default('PROBATION');
            $table->date('valid_from');
            $table->date('valid_to')->default('9999-12-31');
            $table->date('hire_date');
            $table->date('termination_date')->nullable();
            $table->date('seniority_start_date');
            $table->dateTime('recorded_at', 6);
            $table->dateTime('superseded_at', 6)->nullable()->index();
            $table->string('source_type', 32)->default('MANUAL');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(['tenant_id', 'legal_entity_id', 'employee_no'], 'employments_employee_no_unique');
            $table->index(['tenant_id', 'legal_entity_id', 'valid_from', 'valid_to'], 'employments_asof_idx');
        });

        Schema::create('assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->unsignedBigInteger('org_unit_id')->index();
            $table->unsignedBigInteger('position_id')->nullable()->index();
            $table->unsignedBigInteger('manager_assignment_id')->nullable()->index();
            $table->string('assignment_type', 24);
            $table->boolean('is_primary')->default(false);
            $table->date('valid_from');
            $table->date('valid_to')->default('9999-12-31');
            $table->dateTime('recorded_at', 6);
            $table->dateTime('superseded_at', 6)->nullable()->index();
            $table->string('source_type', 32)->default('MANUAL');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->index(['tenant_id', 'employment_id', 'valid_from', 'valid_to'], 'assignments_employment_asof_idx');
            $table->index(['tenant_id', 'org_unit_id', 'valid_from', 'valid_to'], 'assignments_org_asof_idx');
            $table->index(['manager_assignment_id', 'valid_from', 'valid_to'], 'assignments_manager_asof_idx');
        });

        Schema::create('employment_terms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->unsignedInteger('version');
            $table->date('effective_from');
            $table->date('effective_to')->default('9999-12-31');
            $table->date('probation_end_date')->nullable();
            $table->string('work_location', 160);
            $table->string('work_schedule', 80);
            $table->string('salary_scheme_code', 64)->nullable();
            $table->unsignedSmallInteger('notice_period_days')->default(0);
            $table->boolean('confidentiality')->default(false);
            $table->boolean('non_compete')->default(false);
            $table->dateTime('signed_at', 6)->nullable();
            $table->string('source_type', 32)->default('MANUAL');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->dateTime('recorded_at', 6);
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(['employment_id', 'version'], 'employment_terms_version_unique');
            $table->index(['tenant_id', 'employment_id', 'effective_from', 'effective_to'], 'employment_terms_asof_idx');
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('category', 32);
            $table->unsignedBigInteger('file_object_id')->index();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('signed_at', 6)->nullable();
            $table->char('sha256', 64);
            $table->date('expires_at')->nullable();
            $table->string('retention_class', 32);
            $table->boolean('legal_hold')->default(false);
            $table->dateTime('created_at', 6)->nullable();

            $table->unique(['owner_type', 'owner_id', 'category', 'version'], 'documents_owner_category_version_unique');
            $table->index(['tenant_id', 'owner_type', 'owner_id'], 'documents_owner_idx');
        });

        Schema::create('employment_user_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('employment_id')->index();
            $table->date('valid_from');
            $table->date('valid_to')->default('9999-12-31');
            $table->dateTime('recorded_at', 6);
            $table->string('source_type', 32)->default('MANUAL');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(['tenant_id', 'user_id', 'employment_id', 'valid_from'], 'employment_user_links_unique');
            $table->index(['tenant_id', 'user_id', 'valid_from', 'valid_to'], 'employment_user_links_asof_idx');
        });

        $this->addRangeTriggers('employments');
        $this->addRangeTriggers('assignments');
        $this->addRangeTriggers('employment_terms', 'effective_from', 'effective_to');
        $this->addRangeTriggers('employment_user_links');
        $this->addMysqlChecks('employments', 'employments_valid_range_check', 'valid_from < valid_to');
        $this->addMysqlChecks('assignments', 'assignments_valid_range_check', 'valid_from < valid_to');
        $this->addMysqlChecks('employment_terms', 'employment_terms_valid_range_check', 'effective_from < effective_to');
        $this->addMysqlChecks('employment_user_links', 'employment_user_links_valid_range_check', 'valid_from < valid_to');
    }

    public function down(): void
    {
        $this->dropRangeTriggers('employment_user_links');
        $this->dropRangeTriggers('employment_terms');
        $this->dropRangeTriggers('assignments');
        $this->dropRangeTriggers('employments');

        Schema::dropIfExists('employment_user_links');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('employment_terms');
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('employments');
        Schema::dropIfExists('persons');
    }

    private function addRangeTriggers(string $table, string $from = 'valid_from', string $to = 'valid_to'): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        foreach (['insert', 'update'] as $event) {
            DB::statement("CREATE TRIGGER {$table}_valid_range_{$event} BEFORE ".strtoupper($event)." ON {$table} WHEN NEW.{$from} >= NEW.{$to} BEGIN SELECT RAISE(ABORT, '{$from} must be before {$to}'); END;");
        }
    }

    private function dropRangeTriggers(string $table): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement("DROP TRIGGER IF EXISTS {$table}_valid_range_insert");
        DB::statement("DROP TRIGGER IF EXISTS {$table}_valid_range_update");
    }

    private function addMysqlChecks(string $table, string $constraint, string $predicate): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK ({$predicate})");
    }
};
