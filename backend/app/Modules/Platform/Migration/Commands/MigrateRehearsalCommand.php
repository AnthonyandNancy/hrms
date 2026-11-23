<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Commands;

use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Platform\Migration\Enums\MigrationRunMode;
use App\Modules\Platform\Migration\Models\MigrationIssue;
use App\Modules\Platform\Migration\Services\MigrationReconciler;
use App\Modules\Platform\Migration\Services\MigrationRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Executes a complete cutover rehearsal through the shadow target. The shadow
 * prefix is written into every rehearsed row and repeated in the report, so a
 * rehearsal can never be mistaken for a live import.
 */
class MigrateRehearsalCommand extends Command
{
    protected $signature = 'migrate:rehearsal
        {--round=1 : Rehearsal round number (>=1)}
        {--dataset=demo : Fixture directory under database/migration-fixtures, or "demo" for known-good}
        {--tenant=1 : Tenant id}
        {--mapping=database/migration-fixtures/mapping/demo.csv : Crosswalk CSV path}
        {--output= : JSON report path}
        {--json : Print JSON instead of the human summary}';

    protected $description = 'Run validate+apply+reconcile for every domain against the shadow target';

    public function handle(MigrationRunner $runner, MigrationReconciler $reconciler): int
    {
        $round = (int) $this->option('round');

        if ($round < 1) {
            $this->error('PASS/FAIL: FAIL - --round must be >= 1');

            return self::INVALID;
        }

        $tenantId = (int) $this->option('tenant');
        $dataset = (string) $this->option('dataset');
        $fixtureSet = $dataset === 'demo' ? 'known-good' : $dataset;
        $fixtureRoot = base_path('database/migration-fixtures/'.$fixtureSet);
        $mapping = (string) $this->option('mapping');

        if (! is_dir($fixtureRoot)) {
            $this->error("PASS/FAIL: FAIL - fixture dataset not found: {$fixtureSet}");

            return self::FAILURE;
        }

        if ($mapping !== '' && ! is_file($mapping)) {
            $this->error("PASS/FAIL: FAIL - mapping file not found: {$mapping}");

            return self::FAILURE;
        }

        foreach (MigrationDomain::cases() as $domain) {
            $csv = $fixtureRoot.'/'.$domain->value.'.csv';

            if (! is_file($csv)) {
                $this->error("PASS/FAIL: FAIL - dataset {$fixtureSet} is missing {$domain->value}.csv");

                return self::FAILURE;
            }
        }

        $startedAt = CarbonImmutable::now('UTC');
        $domains = [];
        $passed = true;

        foreach (MigrationDomain::cases() as $domain) {
            $file = $fixtureRoot.'/'.$domain->value.'.csv';
            $mappingPaths = $mapping === '' ? [] : [$mapping];

            try {
                $run = $runner->apply(
                    $domain,
                    $tenantId,
                    $file,
                    $mappingPaths,
                    MigrationRunMode::APPLY,
                    true,
                );
                $report = $run->status->isSuccessful() ? $reconciler->reconcile($run) : null;
                $domainPassed = $run->status->isSuccessful() && $report !== null && $report->passed;
            } catch (Throwable $exception) {
                $run = null;
                $report = null;
                $domainPassed = false;
                $error = $exception->getMessage();
            }

            $domains[$domain->value] = [
                'wave' => $domain->cutoverWave(),
                'run_id' => $run?->id,
                'status' => $run?->status->value ?? 'EXCEPTION',
                'rows_read' => $run === null ? 0 : $run->rows_read,
                'rows_valid' => $run === null ? 0 : $run->rows_valid,
                'rows_created' => $run === null ? 0 : $run->rows_created,
                'rows_updated' => $run === null ? 0 : $run->rows_updated,
                'rows_skipped' => $run === null ? 0 : $run->rows_skipped,
                'rows_failed' => $run === null ? 0 : $run->rows_failed,
                'reconciliation' => $report?->toArray(),
                'passed' => $domainPassed,
                'error' => $error ?? null,
                'error_count' => $run === null ? null : MigrationIssue::query()
                    ->where('run_id', $run->id)
                    ->where('severity', 'ERROR')
                    ->count(),
            ];
            $passed = $passed && $domainPassed;

            if (! $domainPassed) {
                // Stop at the failed wave boundary; a later domain must never be
                // rehearsed against references that did not reach the shadow target.
                break;
            }
        }

        $body = [
            'rehearsal' => true,
            'target' => 'SHADOW',
            'round' => $round,
            'dataset' => $dataset,
            'tenant_id' => $tenantId,
            'started_at' => $startedAt->toIso8601ZuluString(),
            'finished_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            'passed' => $passed,
            'domains' => $domains,
            'note' => 'All rows are SHADOW rehearsal records; none are live business data.',
        ];
        $output = (string) $this->option('output');

        if ($output === '') {
            $output = storage_path("app/migration-rehearsals/round-{$round}.json");
        }

        File::ensureDirectoryExists(dirname($output));
        File::put($output, json_encode($body, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($this->option('json')) {
            $this->line(json_encode($body, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['Domain', 'Wave', 'Status', 'Read', 'Created', 'Updated', 'Skipped', 'Reconcile'],
                array_map(static fn (array $row): array => [
                    $row['status'],
                    $row['wave'],
                    $row['status'],
                    $row['rows_read'],
                    $row['rows_created'],
                    $row['rows_updated'],
                    $row['rows_skipped'],
                    $row['reconciliation'] === null ? 'not run' : ($row['reconciliation']['passed'] ? 'PASS' : 'FAIL'),
                ], $domains),
            );
            $this->line("Report written: {$output}");
        }

        $this->line(($passed ? 'PASS' : 'FAIL').": migrate:rehearsal round={$round} dataset={$dataset} modes=SHADOW");

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
