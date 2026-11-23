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
        Schema::create('legal_entities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 64);
            $table->string('name', 160);
            $table->char('country_code', 2);
            $table->string('timezone', 64)->default('UTC');
            $table->char('currency_code', 3);
            $table->string('status', 16)->default('ACTIVE');
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(['tenant_id', 'code'], 'legal_entities_tenant_code_unique');
        });

        Schema::create('org_units', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('code', 64);
            $table->string('name', 160);
            $table->string('type', 32);
            $table->string('status', 16)->default('ACTIVE');
            $table->date('valid_from');
            $table->date('valid_to')->default('9999-12-31');
            $table->dateTime('recorded_at', 6);
            $table->dateTime('superseded_at', 6)->nullable()->index();
            $table->string('source_type', 32)->default('MANUAL');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(['tenant_id', 'code'], 'org_units_tenant_code_unique');
            $table->index(['tenant_id', 'legal_entity_id', 'valid_from', 'valid_to'], 'org_units_asof_idx');
        });

        Schema::create('org_unit_closure', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('ancestor_id');
            $table->unsignedBigInteger('descendant_id');
            $table->unsignedSmallInteger('depth');
            $table->date('valid_from');
            $table->date('valid_to')->default('9999-12-31');
            $table->dateTime('recorded_at', 6);
            $table->dateTime('superseded_at', 6)->nullable();
            $table->string('source_type', 32)->default('MANUAL');
            $table->unsignedBigInteger('source_id')->nullable();

            $table->primary(['ancestor_id', 'descendant_id', 'valid_from'], 'org_unit_closure_primary');
            $table->index(['tenant_id', 'descendant_id', 'valid_from', 'valid_to'], 'org_unit_closure_descendant_asof_idx');
            $table->index(['tenant_id', 'ancestor_id', 'valid_from', 'valid_to'], 'org_unit_closure_ancestor_asof_idx');
        });

        Schema::create('positions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->unsignedBigInteger('org_unit_id')->nullable()->index();
            $table->string('code', 64);
            $table->string('title', 160);
            $table->string('grade', 32)->nullable();
            $table->unsignedInteger('headcount_limit')->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->default('9999-12-31');
            $table->dateTime('recorded_at', 6);
            $table->dateTime('superseded_at', 6)->nullable();
            $table->string('source_type', 32)->default('MANUAL');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(['tenant_id', 'legal_entity_id', 'code'], 'positions_tenant_legal_entity_code_unique');
            $table->index(['tenant_id', 'org_unit_id', 'valid_from', 'valid_to'], 'positions_asof_idx');
        });

        $this->addRangeTriggers('org_units');
        $this->addRangeTriggers('org_unit_closure');
        $this->addRangeTriggers('positions');
        $this->addMysqlChecks('org_units', 'org_units_valid_range_check', 'valid_from < valid_to');
        $this->addMysqlChecks('org_unit_closure', 'org_unit_closure_valid_range_check', 'valid_from < valid_to');
        $this->addMysqlChecks('positions', 'positions_valid_range_check', 'valid_from < valid_to');
    }

    public function down(): void
    {
        $this->dropRangeTriggers('positions');
        $this->dropRangeTriggers('org_unit_closure');
        $this->dropRangeTriggers('org_units');

        Schema::dropIfExists('positions');
        Schema::dropIfExists('org_unit_closure');
        Schema::dropIfExists('org_units');
        Schema::dropIfExists('legal_entities');
    }

    private function addRangeTriggers(string $table): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        foreach (['insert', 'update'] as $event) {
            DB::statement("CREATE TRIGGER {$table}_valid_range_{$event} BEFORE ".strtoupper($event)." ON {$table} WHEN NEW.valid_from >= NEW.valid_to BEGIN SELECT RAISE(ABORT, 'valid_from must be before valid_to'); END;");
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
