<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Console\Commands\CompanyPreflightCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SingleCompanyPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_json_output_is_read_only_and_exits_successfully(): void
    {
        $this->artisan('company:preflight', ['--json' => true])
            ->expectsOutputToContain('"tables"')
            ->assertExitCode(0);
    }

    public function test_build_report_is_driver_neutral_and_structured(): void
    {
        $report = $this->app->make(CompanyPreflightCommand::class)->buildReport();

        $this->assertContains($report['connection'], ['sqlite', 'mysql']);
        $this->assertSame('REBUILD', $report['data_disposition']);
        $this->assertIsInt($report['company_profile_rows']);
        $this->assertContains($report['company_profile_rows'], [0, 1]);
        $this->assertIsInt($report['legal_entity_bindings']);
        $this->assertSame(
            Schema::hasTable('authority_bindings')
                ? DB::table('authority_bindings')->where('anchor_type', 'LEGAL_ENTITY')->count()
                : 0,
            $report['legal_entity_bindings'],
        );
        $this->assertIsArray($report['tables']);
        $this->assertNotEmpty($report['tables']);

        $tableNames = array_map(
            static fn (array $table): string => $table['table'],
            $report['tables'],
        );
        $schemaTables = array_map(
            static fn (array $table): string => (string) $table['name'],
            Schema::getTables(),
        );
        sort($tableNames);
        sort($schemaTables);
        $this->assertSame($schemaTables, $tableNames);

        foreach ($report['tables'] as $table) {
            $this->assertIsString($table['table']);
            $this->assertIsInt($table['rows']);
            $this->assertGreaterThanOrEqual(0, $table['rows']);
            $this->assertIsArray($table['legacy_columns']);
            foreach ($table['legacy_columns'] as $column) {
                $this->assertContains($column, ['tenant_id', 'legal_entity_id']);
            }
        }
    }

    public function test_company_profile_rows_are_zero_when_the_table_is_missing(): void
    {
        Schema::dropIfExists('company_profile');

        $report = $this->app->make(CompanyPreflightCommand::class)->buildReport();

        $this->assertSame(0, $report['company_profile_rows']);
        $this->assertSame('REBUILD', $report['data_disposition']);
    }
}
