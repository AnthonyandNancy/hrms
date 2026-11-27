<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SingleCompanyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_company_schema_has_no_legacy_columns(): void
    {
        foreach (Schema::getTables() as $table) {
            $name = (string) $table['name'];

            $this->assertFalse(Schema::hasColumn($name, 'tenant_id'), "{$name} still has tenant_id");
            $this->assertFalse(Schema::hasColumn($name, 'legal_entity_id'), "{$name} still has legal_entity_id");
        }

        $this->assertFalse(Schema::hasTable('legal_entities'));
    }

    public function test_legal_entity_bindings_become_company(): void
    {
        $this->assertSame(
            0,
            DB::table('authority_bindings')->where('anchor_type', 'LEGAL_ENTITY')->count(),
            'LEGAL_ENTITY authority bindings must be converted to COMPANY anchors.',
        );

        DB::table('authority_bindings')->insert([
            'principal_id' => 999999,
            'capability_code' => 'single-company.probe',
            'anchor_type' => 'LEGAL_ENTITY',
            'anchor_id' => 42,
            'anchor_key' => 42,
            'field_profile' => 'P0_PUBLIC',
            'purpose_code' => 'SINGLE_COMPANY_PROBE',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'source_type' => 'TEST',
            'source_id' => null,
            'auth_version' => 1,
            'created_at' => '2026-11-26 00:00:00',
        ]);

        $migration = require database_path('migrations/2026_11_26_000300_migrate_legal_entity_bindings_to_company.php');
        $migration->up();
        $migration->up();

        $this->assertDatabaseHas('authority_bindings', [
            'capability_code' => 'single-company.probe',
            'anchor_type' => 'COMPANY',
            'anchor_id' => null,
            'anchor_key' => 0,
        ]);
    }

    public function test_binding_migration_skips_when_authority_bindings_is_absent(): void
    {
        Schema::dropIfExists('authority_bindings');

        $migration = require database_path('migrations/2026_11_26_000300_migrate_legal_entity_bindings_to_company.php');
        $migration->up();

        $this->assertFalse(Schema::hasTable('authority_bindings'));
    }

    public function test_company_profile_is_singleton(): void
    {
        $this->assertSame(1, DB::table('company_profile')->count());
    }

    public function test_single_company_business_keys_are_unique(): void
    {
        foreach ([['users', 'username'], ['org_units', 'code'], ['employments', 'employee_no']] as [$table, $column]) {
            $this->assertTrue(
                Schema::hasIndex($table, [$column], 'unique'),
                "{$table}.{$column} must be unique after cutover",
            );
        }

        DB::table('users')->insert([
            'username' => 'unique.probe',
            'password_hash' => 'x',
            'status' => 'ACTIVE',
            'auth_version' => 1,
            'is_privileged' => false,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('users')->insert([
            'username' => 'unique.probe',
            'password_hash' => 'x',
            'status' => 'ACTIVE',
            'auth_version' => 1,
            'is_privileged' => false,
        ]);
    }

    public function test_drop_columns_removes_a_foreign_key_that_uses_a_legacy_column(): void
    {
        Schema::create('cutover_parent', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('legal_entity_id');
            $table->string('code', 32);
        });

        Schema::create('cutover_child', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('parent_id');

            $table->foreign('tenant_id')->references('id')->on('cutover_parent');
        });

        $migration = require database_path('migrations/2026_11_26_000320_drop_tenant_and_legal_entity_columns.php');
        $migration->up();

        $this->assertFalse(Schema::hasColumn('cutover_child', 'tenant_id'));
        $this->assertFalse(Schema::hasColumn('cutover_child', 'legal_entity_id'));
        $this->assertSame([], Schema::getForeignKeys('cutover_child'));
    }

    public function test_drop_columns_rebuilds_derived_keys_and_skips_an_existing_replacement(): void
    {
        Schema::create('cutover_probe', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('legal_entity_id');
            $table->string('code', 32);
            $table->string('label', 32);

            $table->unique(['tenant_id', 'code'], 'cutover_probe_legacy_code_unique');
            $table->unique(['legal_entity_id', 'label'], 'cutover_probe_legacy_label_unique');
            $table->unique(['code'], 'cutover_probe_code_unique');
        });

        $migration = require database_path('migrations/2026_11_26_000320_drop_tenant_and_legal_entity_columns.php');
        $migration->up();
        $migration->up();

        $this->assertFalse(Schema::hasColumn('cutover_probe', 'tenant_id'));
        $this->assertFalse(Schema::hasColumn('cutover_probe', 'legal_entity_id'));
        $this->assertTrue(Schema::hasIndex('cutover_probe', ['code'], 'unique'));
        $this->assertTrue(Schema::hasIndex('cutover_probe', ['label'], 'unique'));
        $this->assertSame(
            1,
            count(array_filter(
                Schema::getIndexes('cutover_probe'),
                static fn (array $index): bool => $index['unique'] && $index['columns'] === ['code'],
            )),
        );
    }
}
