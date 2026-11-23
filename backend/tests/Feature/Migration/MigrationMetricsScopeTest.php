<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Modules\Platform\Migration\Adapters\DatabaseMigrationTarget;
use App\Modules\Platform\Migration\Dto\MigrationRow;
use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Time\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PeopleScenario;
use Tests\TestCase;

/**
 * Reconciliation must compare the rows the migration is responsible for, not
 * every row the tenant owns. On a reused or demo-seeded tenant the previous
 * tenant-wide count could never match and `demo:seed --live` reported failures
 * for data that had in fact been imported correctly.
 *
 * The payroll sums also used fixture vocabulary (`taxable_cents`, `si_cents`,
 * `hf_cents`) directly as SQL columns, but payroll_ytd stores
 * `taxable_income_cents`, `social_insurance_cents` and `housing_fund_cents`.
 */
final class MigrationMetricsScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_ignore_rows_not_written_by_the_migration(): void
    {
        $scenario = new PeopleScenario(1, app(\App\Shared\Time\Clock::class));
        $entity = $scenario->legalEntity();
        $unit = $scenario->orgUnit($entity, 'SCOPE-UNIT');

        // A pre-existing, manually created org unit must not be counted.
        $scenario->orgUnit($entity, 'MANUAL-UNIT');

        $migrated = $scenario->orgUnit($entity, 'MIGRATED-UNIT');
        DB::table('org_units')->where('id', $migrated->id)->update(['source_type' => 'MIGRATION']);
        DB::table('org_units')->where('code', 'MANUAL-UNIT')->update(['source_type' => 'MANUAL']);

        $metrics = (new DatabaseMigrationTarget(MigrationDomain::ORGANIZATIONS))->metrics(1);

        $this->assertSame(1, $metrics->count, 'only the MIGRATION-sourced org unit may be counted');
    }

    public function test_payroll_ytd_sums_use_the_real_column_names(): void
    {
        $scenario = new PeopleScenario(1, app(\App\Shared\Time\Clock::class));
        $entity = $scenario->legalEntity();
        $employment = $scenario->employment($scenario->person(), $entity, 'YTD-0001');

        DB::table('payroll_ytd')->insert([
            'tenant_id' => 1,
            'employment_id' => $employment->id,
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

        $metrics = (new DatabaseMigrationTarget(MigrationDomain::PAYROLL_YTD))->metrics(1);

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
        $scenario = new PeopleScenario(1, app(\App\Shared\Time\Clock::class));
        $entity = $scenario->legalEntity();
        $unit = $scenario->orgUnit($entity, 'LO-UNIT');
        $employment = $scenario->employment($scenario->person(), $entity, 'LO-0001');
        $scenario->assignment($employment, $unit);

        $type = LeaveType::query()->create([
            'tenant_id' => 1,
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
        ]), 1, 1);

        // A normal ledger grant from the application must not be counted.
        DB::table('leave_ledger_entries')->insert([
            'tenant_id' => 1,
            'employment_id' => $employment->id,
            'leave_type_id' => $type->id,
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

        $metrics = $target->metrics(1);

        $this->assertSame(1, $metrics->count);
        $this->assertSame(4800, $metrics->sums['quantity_minutes']);
    }
}
