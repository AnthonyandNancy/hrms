<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Commands;

use App\Modules\Platform\Migration\Models\MigrationRun;
use App\Modules\Platform\Migration\Services\MigrationReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class MigrateReconcileCommand extends Command
{
    protected $signature = 'migrate:reconcile
        {--run= : Migration run id (all applied runs when omitted)}
        {--json : Print JSON instead of a human table}
        {--output= : Write the JSON report to this path}';

    protected $description = 'Reconcile staged source counts and sums against target metrics';

    public function handle(MigrationReconciler $reconciler): int
    {
        $runOption = $this->option('run');

        try {
            $reports = [];

            if ($runOption !== null && $runOption !== '') {
                $run = MigrationRun::query()->find((int) $runOption);

                if ($run === null) {
                    $this->error("PASS/FAIL: FAIL - migration run {$runOption} not found");

                    return self::FAILURE;
                }

                $reports[] = $reconciler->reconcile($run);
            } else {
                $reports = $reconciler->reconcileAll();
            }
        } catch (Throwable $exception) {
            $this->error('PASS/FAIL: FAIL - '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($reports === []) {
            $this->error('PASS/FAIL: FAIL - no applied migration runs to reconcile');

            return self::FAILURE;
        }

        $body = [
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            'passed' => array_reduce($reports, static fn (bool $carry, $report): bool => $carry && $report->passed, true),
            'reports' => array_map(static fn ($report): array => $report->toArray(), $reports),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($body, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($reports as $report) {
                $this->line($report->humanTable());
            }
        }

        $output = (string) $this->option('output');

        if ($output !== '') {
            File::ensureDirectoryExists(dirname($output));
            File::put($output, json_encode($body, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line("Report written: {$output}");
        }

        $this->line(($body['passed'] ? 'PASS' : 'FAIL').': migrate:reconcile '.count($reports).' report(s)');

        return $body['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
