<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Platform\Migration\Enums\MigrationRunMode;
use App\Modules\Platform\Migration\Enums\MigrationRunStatus;
use App\Modules\Platform\Migration\Services\MigrationRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MigrationTargetRegistryFakes;
use Tests\TestCase;

class MigrationIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_applying_the_same_domain_twice_does_not_duplicate_target_rows(): void
    {
        $targets = MigrationTargetRegistryFakes::install();
        $target = $targets[MigrationDomain::PEOPLE->value];
        $runner = app(MigrationRunner::class);
        $file = $this->fixture('people.csv');
        $mapping = $this->mappingFile();

        $first = $runner->apply(MigrationDomain::PEOPLE, $file, [$mapping], MigrationRunMode::APPLY);
        $countAfterFirst = count($target->rows);

        $second = $runner->apply(MigrationDomain::PEOPLE, $file, [$mapping], MigrationRunMode::APPLY);

        $this->assertSame(MigrationRunStatus::APPLIED, $first->status);
        $this->assertSame(MigrationRunStatus::APPLIED, $second->status);
        $this->assertSame(12, $countAfterFirst);
        $this->assertCount($countAfterFirst, $target->rows);
        $this->assertSame(0, $second->rows_created);
        $this->assertSame(0, $second->rows_updated);
        $this->assertSame(12, $second->rows_skipped);
    }

    public function test_dry_run_never_writes_target_rows(): void
    {
        $targets = MigrationTargetRegistryFakes::install();
        $runner = app(MigrationRunner::class);
        $file = $this->fixture('organizations.csv');
        $mapping = $this->mappingFile();

        $run = $runner->apply(MigrationDomain::ORGANIZATIONS, $file, [$mapping], MigrationRunMode::DRY_RUN);

        $this->assertSame(MigrationRunStatus::VALIDATED, $run->status);
        $this->assertSame([], $targets[MigrationDomain::ORGANIZATIONS->value]->rows);
    }

    private function fixture(string $file): string
    {
        return dirname(__DIR__, 3)."/database/migration-fixtures/known-good/{$file}";
    }

    private function mappingFile(): string
    {
        return dirname(__DIR__, 3).'/database/migration-fixtures/mapping/demo.csv';
    }
}
