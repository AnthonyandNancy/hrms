<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Modules\Platform\Migration\Adapters\DatabaseMigrationTarget;
use App\Modules\Platform\Migration\Dto\MigrationRow;
use App\Modules\Platform\Migration\Enums\MigrationDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reconciliation must compare the rows the migration is responsible for, not
 * every row already present. The payroll sums also used fixture vocabulary
 * (`taxable_cents`, `si_cents`, `hf_cents`) directly as SQL columns, but
 * payroll_ytd stores the longer domain names.
 */
final class MigrationMetricsScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_ignore_rows_not_written_by_the_migration(): void
    {
        $this->orgUnit('SCOPE-UNIT', 'MIGRATION');
        $this->orgUnit('MANUAL-UNIT', 'MANUAL');

        $metrics = (new DatabaseMigrationTarget(MigrationDomain::ORGANIZATIONS))->metrics();

        $this->assertSame(1, $metrics->count, 'only the MIGRATION-sourced org unit may be counted');
    }

    public function test_payroll_ytd_sums_use_the_real_column_names(): void
    {
        $employmentId = $this->employment('YTD-0001');

        DB::table('payroll_ytd')->insert([
            'employment_id' => $employmentId,
            'tax_year' => 2026,
            'taxable_income_cents' => 10_100_000,
            'tax_withheld_cents' => 500_000,
            'social_insurance_cents' => 598_000,
            'housing_fund_cents' => 299_000,
            'gross_cents' => 12_000_000,
            'net_cents' => 11_000_000,
            'source_type' => 'MIGRATION',
            'source_id' => 1,
            'created_at' => now('UTC'),
        ]);

        $metrics = (new DatabaseMigrationTarget(MigrationDomain::PAYROLL_YTD))->metrics();

        $this->assertSame(1, $metrics->count);
        $this->assertSame(10_100_000, $metrics->sums['taxable_cents']);
        $this->assertSame(598_000, $metrics->sums['si_cents']);
        $this->assertSame(299_000, $metrics->sums['hf_cents']);
        $this->assertSame(12_000_000, $metrics->sums['gross_cents']);
        $this->assertSame(11_000_000, $metrics->sums['net_cents']);
        $this->assertSame(500_000, $metrics->sums['tax_withheld_cents']);
    }

    public function test_leave_opening_metrics_count_only_migration_openings(): void
    {
        $employmentId = $this->employment('LO-0001');

        $typeId = (int) DB::table('leave_types')->insertGetId([
            'code' => 'ANNUAL',
            'name' => 'Annual leave',
            'is_statutory' => true,
            'is_annual' => true,
            'unit' => 'DAY',
            'annual_entitlement_days' => '15.00',
            'requires_attachment' => false,
            'allow_negative' => false,
            'expiry_rule_json' => null,
            'legal_basis' => 'Synthetic',
            'created_at' => now('UTC'),
        ]);

        $target = new DatabaseMigrationTarget(MigrationDomain::LEAVE_OPENINGS);

        $target->apply(new MigrationRow(stgId: 1, sourceRowNumber: 1, data: [
            'employment_no' => 'LO-0001',
            'leave_type' => 'ANNUAL',
            'quantity_minutes' => 4800,
            'business_date' => '2026-01-01',
            'effective_on' => '2026-12-31',
        ]), 1);

        // A normal ledger grant from the application must not be counted.
        DB::table('leave_ledger_entries')->insert([
            'employment_id' => $employmentId,
            'leave_type_id' => $typeId,
            'account' => 'GRANT',
            'quantity_minutes' => 999,
            'business_date' => '2026-01-01',
            'effective_on' => '2026-01-01',
            'source_type' => 'ACCRUAL',
            'source_id' => '2026',
            'reason' => 'Application accrual',
            'entry_hash' => hash('sha256', 'app-accrual'),
            'recorded_at' => now('UTC'),
        ]);

        $metrics = $target->metrics();

        $this->assertSame(1, $metrics->count);
        $this->assertSame(4800, $metrics->sums['quantity_minutes']);
    }

    private function orgUnit(string $code, string $sourceType): void
    {
        DB::table('org_units')->insert([
            'parent_id' => null,
            'code' => $code,
            'name' => $code,
            'type' => 'DEPARTMENT',
            'status' => 'ACTIVE',
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'recorded_at' => now('UTC'),
            'source_type' => $sourceType,
        ]);
    }

    private function employment(string $employeeNo): int
    {
        $personId = (int) DB::table('persons')->insertGetId([
            'legal_name' => 'Synthetic Person',
            'birth_date' => '1990-01-01',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return (int) DB::table('employments')->insertGetId([
            'person_id' => $personId,
            'employee_no' => $employeeNo,
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'hire_date' => '2026-01-01',
            'seniority_start_date' => '2026-01-01',
            'recorded_at' => now('UTC'),
            'source_type' => 'TEST',
        ]);
    }
}
