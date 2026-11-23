<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Commands;

use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Platform\Migration\Enums\MigrationRunMode;
use App\Modules\Platform\Migration\Models\MigrationIssue;
use App\Modules\Platform\Migration\Services\MigrationRunner;
use Illuminate\Console\Command;
use Throwable;

class MigrateDomainCommand extends Command
{
    protected $signature = 'migrate:domain
        {--domain= : organizations|people|assignments|terms|leave_openings|payroll_ytd}
        {--file= : Path to the source CSV}
        {--mode=validate : validate|apply}
        {--mapping=* : Crosswalk CSV paths (0..n)}
        {--tenant=1 : Tenant id}
        {--shadow : Apply to the rehearsal shadow target instead of business tables}
        {--json : Print a machine-readable run summary}';

    protected $description = 'Validate or apply one CSV migration domain into staging and target ports';

    public function handle(MigrationRunner $runner): int
    {
        $domainValue = (string) $this->option('domain');
        $file = (string) $this->option('file');
        $modeValue = strtolower((string) $this->option('mode'));

        $domain = MigrationDomain::tryFrom($domainValue);
        if ($domain === null) {
            $this->error('PASS/FAIL: FAIL - --domain must be one of: '.implode(', ', array_column(MigrationDomain::cases(), 'value')));

            return self::INVALID;
        }

        if ($file === '' || ! is_file($file)) {
            $this->error("PASS/FAIL: FAIL - source file not found: {$file}");

            return self::FAILURE;
        }

        $mode = match ($modeValue) {
            'validate' => MigrationRunMode::VALIDATE,
            'apply' => MigrationRunMode::APPLY,
            default => null,
        };

        if ($mode === null) {
            $this->error('PASS/FAIL: FAIL - --mode must be validate or apply');

            return self::INVALID;
        }

        try {
            $mappings = array_values((array) $this->option('mapping'));
            $tenantId = (int) $this->option('tenant');
            $run = $mode === MigrationRunMode::APPLY
                ? $runner->apply($domain, $tenantId, $file, $mappings, MigrationRunMode::APPLY, (bool) $this->option('shadow'))
                : $runner->validate($domain, $tenantId, $file, $mappings);
        } catch (Throwable $exception) {
            $this->error('PASS/FAIL: FAIL - '.$exception->getMessage());

            return self::FAILURE;
        }

        $issues = MigrationIssue::query()
            ->where('run_id', $run->id)
            ->where('severity', 'ERROR')
            ->orderBy('id')
            ->get();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'run_id' => $run->id,
                'domain' => $domain->value,
                'mode' => $run->mode->value,
                'status' => $run->status->value,
                'rows_read' => $run->rows_read,
                'rows_valid' => $run->rows_valid,
                'rows_created' => $run->rows_created,
                'rows_updated' => $run->rows_updated,
                'rows_skipped' => $run->rows_skipped,
                'rows_failed' => $run->rows_failed,
                'issues' => $issues->map(static fn (MigrationIssue $issue): array => [
                    'code' => $issue->code,
                    'severity' => $issue->severity->value,
                    'source_row_number' => $issue->source_row_number,
                    'message' => $issue->message,
                ])->all(),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Run', $run->id],
                    ['Domain', $domain->value],
                    ['Mode', $run->mode->value],
                    ['Status', $run->status->value],
                    ['Rows read', $run->rows_read],
                    ['Rows valid', $run->rows_valid],
                    ['Created', $run->rows_created],
                    ['Updated', $run->rows_updated],
                    ['Skipped', $run->rows_skipped],
                    ['Failed', $run->rows_failed],
                ],
            );

            foreach ($issues as $issue) {
                $this->line(sprintf(
                    '  ERROR %s row=%s %s',
                    $issue->code,
                    $issue->source_row_number ?? '-',
                    $issue->message,
                ));
            }
        }

        $passed = $run->status->isSuccessful();

        $this->line(($passed ? 'PASS' : 'FAIL').": migrate:domain {$domain->value} run={$run->id} status={$run->status->value}");

        if (! $passed) {
            return self::FAILURE;
        }

        if ($mode === MigrationRunMode::APPLY && $run->rows_failed > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
