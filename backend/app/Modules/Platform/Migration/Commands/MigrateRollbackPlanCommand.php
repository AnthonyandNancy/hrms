<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Commands;

use App\Modules\Platform\Migration\Models\MigrationRun;
use App\Modules\Platform\Migration\Services\MigrationRollbackPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MigrateRollbackPlanCommand extends Command
{
    protected $signature = 'migrate:rollback-plan
        {--run= : Migration run id}
        {--output= : Write JSON plan to this path}
        {--execute : Required together with --confirm-destructive to mark a reviewed execution}
        {--confirm-destructive : Explicit second confirmation; does not bypass runbook approval}';

    protected $description = 'Produce a non-executing JSON rollback plan for a migration run';

    public function handle(MigrationRollbackPlanner $planner): int
    {
        $runId = (int) $this->option('run');

        if ($runId <= 0) {
            $this->error('PASS/FAIL: FAIL - --run is required');

            return self::INVALID;
        }

        $run = MigrationRun::query()->find($runId);

        if ($run === null) {
            $this->error("PASS/FAIL: FAIL - migration run {$runId} not found");

            return self::FAILURE;
        }

        if ($this->option('execute') && ! $this->option('confirm-destructive')) {
            $this->error('PASS/FAIL: FAIL - --execute requires --confirm-destructive and an approved rollback runbook');

            return self::FAILURE;
        }

        $plan = $planner->plan($run, (bool) $this->option('execute'));
        $output = (string) ($this->option('output') ?? storage_path("app/migration-rollback-run-{$runId}.json"));

        File::ensureDirectoryExists(dirname($output));
        File::put($output, $plan->toJson());

        $this->line("Rollback plan written: {$output}");
        $this->line('Plan digest: '.$plan->body['plan_digest']);
        $this->line('Executed: NO (plan-only command; follow docs/runbooks/rollback.md)');
        $this->line('PASS: migrate:rollback-plan run='.$runId);

        return self::SUCCESS;
    }
}
