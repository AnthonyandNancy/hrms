<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

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
}
