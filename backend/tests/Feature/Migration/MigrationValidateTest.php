<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Modules\Platform\Migration\Enums\IssueCode;
use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Platform\Migration\Enums\MigrationRunMode;
use App\Modules\Platform\Migration\Enums\MigrationRunStatus;
use App\Modules\Platform\Migration\Models\MigrationIssue;
use App\Modules\Platform\Migration\Services\MigrationRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MigrationTargetRegistryFakes;
use Tests\TestCase;

class MigrationValidateTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_good_fixture_validates_for_every_domain(): void
    {
        $targets = MigrationTargetRegistryFakes::install();
        $runner = app(MigrationRunner::class);

        foreach (MigrationDomain::cases() as $domain) {
            $run = $runner->validate(
                domain: $domain,
                file: $this->fixture('known-good', $domain->value.'.csv'),
                mappingPaths: [$this->mappingFile()],
            );

            $this->assertSame(MigrationRunStatus::VALIDATED, $run->status, $domain->value);
            $this->assertSame(0, $run->rows_failed, $domain->value);
            $this->assertGreaterThan(0, $run->rows_valid, $domain->value);
            $this->assertSame(
                0,
                MigrationIssue::query()->where('run_id', $run->id)->where('severity', 'ERROR')->count(),
            );
            $this->assertSame([], $targets[$domain->value]->rows, 'Validate must not write target rows.');
        }
    }

    public function test_broken_fixtures_report_expected_codes_and_write_no_target_rows(): void
    {
        $targets = MigrationTargetRegistryFakes::install();
        $runner = app(MigrationRunner::class);

        $expectations = [
            MigrationDomain::ORGANIZATIONS->value => [IssueCode::ORPHAN_PARENT, IssueCode::ORGANIZATION_CYCLE],
            MigrationDomain::PEOPLE->value => [IssueCode::DUPLICATE_EMPLOYEE_NO],
            MigrationDomain::ASSIGNMENTS->value => [
                IssueCode::OVERLAPPING_PRIMARY_ASSIGNMENT,
                IssueCode::ORPHAN_MANAGER,
                IssueCode::ORPHAN_ORGANIZATION,
            ],
            MigrationDomain::TERMS->value => [IssueCode::TERMS_VERSION_CONFLICT],
            MigrationDomain::LEAVE_OPENINGS->value => [IssueCode::NEGATIVE_LEAVE_QUANTITY],
            MigrationDomain::PAYROLL_YTD->value => [IssueCode::NEGATIVE_PAYROLL_AMOUNT],
        ];

        foreach ($expectations as $domainValue => $codes) {
            $domain = MigrationDomain::from($domainValue);
            $run = $runner->apply(
                domain: $domain,
                file: $this->fixture('broken', $domain->value.'.csv'),
                mappingPaths: [$this->mappingFile()],
                mode: MigrationRunMode::APPLY,
            );

            $this->assertContains(
                $run->status,
                [MigrationRunStatus::VALIDATION_FAILED, MigrationRunStatus::APPLY_FAILED],
                $domain->value,
            );
            $this->assertSame([], $targets[$domain->value]->writes, "Broken {$domain->value} must not write target rows.");

            $reported = MigrationIssue::query()->where('run_id', $run->id)->pluck('code')->all();

            foreach ($codes as $code) {
                $this->assertContains($code->value, $reported, "{$domain->value} missing {$code->value}");
            }
        }
    }

    public function test_missing_required_header_fails_run_without_writing(): void
    {
        $targets = MigrationTargetRegistryFakes::install();
        $file = tempnam(sys_get_temp_dir(), 'migration-');
        $this->assertIsString($file);
        file_put_contents($file, "code,name\nORG-X,Demo\n");

        try {
            $run = app(MigrationRunner::class)->validate(
                MigrationDomain::ORGANIZATIONS,
                $file,
            );

            $this->assertSame(MigrationRunStatus::VALIDATION_FAILED, $run->status);
            $this->assertSame([], $targets[MigrationDomain::ORGANIZATIONS->value]->rows);
        } finally {
            unlink($file);
        }
    }

    private function fixture(string $set, string $file): string
    {
        return dirname(__DIR__, 3)."/database/migration-fixtures/{$set}/{$file}";
    }

    private function mappingFile(): string
    {
        return dirname(__DIR__, 3).'/database/migration-fixtures/mapping/demo.csv';
    }
}
