<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * M11 schema closure: effective-dated company policy versions plus immutable
 * policy evidence on confirmation.
 */
final class OvertimePolicyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_table_and_snapshot_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('overtime_policy_versions'));
        $this->assertTrue(Schema::hasColumns('overtime_requests', [
            'policy_version_id',
            'policy_snapshot_json',
        ]));

        $this->assertTrue(Schema::hasColumns('overtime_policy_versions', [
            'version',
            'status',
            'effective_from',
            'effective_to',
            'time_unit_minutes',
            'multiplier',
            'statutory_holiday_multiplier',
            'allow_pay',
            'allow_timeoff',
            'allocation',
            'rounding',
            'legal_basis',
            'published_at',
            'published_by',
            'retired_at',
            'retired_by',
            'retire_reason',
            'created_at',
        ]));
    }

    public function test_policy_version_is_unique_and_snapshot_columns_are_nullable_for_existing_requests(): void
    {
        $indexes = collect(Schema::getIndexes('overtime_policy_versions'));

        $this->assertTrue($indexes->contains(
            static fn (array $index): bool => ($index['unique'] ?? false) === true
                && (array) $index['columns'] === ['version'],
        ));

        $requestColumns = collect(Schema::getColumns('overtime_requests'))->keyBy('name');

        $this->assertTrue((bool) ($requestColumns['policy_version_id']['nullable'] ?? false));
        $this->assertTrue((bool) ($requestColumns['policy_snapshot_json']['nullable'] ?? false));
    }
}
