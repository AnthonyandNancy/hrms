<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Platform\Migration\Models\MigrationRun;
use App\Modules\Platform\Migration\Services\MigrationRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MigrationCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_validate_command_is_read_only_and_exits_zero(): void
    {
        $this->artisan('migrate:domain', [
            '--domain' => 'organizations',
            '--file' => $this->fixture('organizations.csv'),
            '--mode' => 'validate',
            '--mapping' => [$this->fixture('mapping/demo.csv')],
            '--json' => true,
        ])->assertSuccessful();

        $run = MigrationRun::query()->latest('id')->firstOrFail();
        $this->assertSame('VALIDATE', $run->mode->value);
        $this->assertSame('VALIDATED', $run->status->value);
        $this->assertSame(8, $run->rows_valid);
        $this->assertSame(0, \App\Modules\Platform\Migration\Models\MigrationShadowRow::query()->count());
    }

    public function test_validate_command_exits_non_zero_for_broken_fixture(): void
    {
        $this->artisan('migrate:domain', [
            '--domain' => 'organizations',
            '--file' => $this->fixture('broken/organizations.csv'),
            '--mode' => 'validate',
            '--mapping' => [$this->fixture('mapping/demo.csv')],
        ])->assertFailed();

        $this->assertSame('VALIDATION_FAILED', MigrationRun::query()->latest('id')->firstOrFail()->status->value);
    }

    public function test_apply_without_shadow_fails_closed_when_no_database_target_is_available(): void
    {
        // Simulate a phased deployment where only rehearsal shadow targets are
        // registered. Production apply must refuse rather than silently write
        // to a shadow store.
        $registry = new \App\Modules\Platform\Migration\Services\MigrationTargetRegistry;

        foreach (MigrationDomain::cases() as $domain) {
            $registry->register(new \App\Modules\Platform\Migration\Services\ShadowMigrationTarget($domain), shadow: true);
        }

        $this->app->instance(\App\Modules\Platform\Migration\Services\MigrationTargetRegistry::class, $registry);

        $this->artisan('migrate:domain', [
            '--domain' => 'organizations',
            '--file' => $this->fixture('organizations.csv'),
            '--mode' => 'apply',
            '--mapping' => [$this->fixture('mapping/demo.csv')],
        ])->assertFailed();

        $run = MigrationRun::query()->latest('id')->firstOrFail();
        $this->assertSame('APPLY_FAILED', $run->status->value);
        $this->assertDatabaseHas('migration_issues', ['run_id' => $run->id, 'code' => 'TARGET_UNAVAILABLE']);
    }

    public function test_apply_to_shadow_reconciles_with_positive_exit(): void
    {
        $this->artisan('migrate:domain', [
            '--domain' => 'organizations',
            '--file' => $this->fixture('organizations.csv'),
            '--mode' => 'apply',
            '--mapping' => [$this->fixture('mapping/demo.csv')],
            '--shadow' => true,
        ])->assertSuccessful();

        $run = MigrationRun::query()->latest('id')->firstOrFail();
        $this->assertSame('APPLIED', $run->status->value);
        $this->assertSame('SHADOW', $run->report_json['target']);
        $this->assertSame(8, $run->rows_created);

        $output = storage_path('app/test-reconcile/report.json');
        File::deleteDirectory(dirname($output));

        $this->artisan('migrate:reconcile', [
            '--run' => $run->id,
            '--json' => true,
            '--output' => $output,
        ])->assertSuccessful();

        $this->assertFileExists($output);
        $this->assertTrue(json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR)['passed']);
    }

    public function test_rollback_plan_writes_json_and_never_executes_destructive_sql(): void
    {
        $run = app(MigrationRunner::class)->apply(
            MigrationDomain::ORGANIZATIONS,
            $this->fixture('organizations.csv'),
            [$this->fixture('mapping/demo.csv')],
            \App\Modules\Platform\Migration\Enums\MigrationRunMode::APPLY,
            true,
        );

        $output = storage_path('app/test-rollback/plan.json');
        File::deleteDirectory(dirname($output));

        $this->artisan('migrate:rollback-plan', [
            '--run' => $run->id,
            '--output' => $output,
        ])->assertSuccessful();

        $plan = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($plan['executed']);
        $this->assertSame('APPLIED', $plan['status']);
        $this->assertNotEmpty($plan['statements']);
        $this->assertGreaterThan(0, \App\Modules\Platform\Migration\Models\MigrationShadowRow::query()->count());

        $this->artisan('migrate:rollback-plan', [
            '--run' => $run->id,
            '--output' => $output,
            '--execute' => true,
        ])->assertFailed();

        $this->assertGreaterThan(0, \App\Modules\Platform\Migration\Models\MigrationShadowRow::query()->count());
    }

    public function test_command_rejects_unknown_domain(): void
    {
        $this->artisan('migrate:domain', [
            '--domain' => 'nope',
            '--file' => $this->fixture('organizations.csv'),
        ])->assertExitCode(2);
    }

    private function fixture(string $file): string
    {
        $prefix = str_contains($file, '/') ? '' : 'known-good/';

        return dirname(__DIR__, 3).'/database/migration-fixtures/'.$prefix.$file;
    }
}
