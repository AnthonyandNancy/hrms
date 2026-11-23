<?php

declare(strict_types=1);

namespace App\Modules\Platform\Retention\Commands;

use App\Modules\Platform\Retention\RetentionClass;
use App\Modules\Platform\Retention\RetentionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class RetentionPurgeCommand extends Command
{
    protected $signature = 'retention:purge
        {--class= : Retention class}
        {--tenant=1 : Tenant id}
        {--as-of= : Evaluate expiry at this date (YYYY-MM-DD)}
        {--confirm : Execute the purge; without this the command is a dry-run}
        {--json : Print JSON}';

    protected $description = 'Purge expired records with legal-hold checks (dry-run by default)';

    public function handle(RetentionService $retention): int
    {
        $class = (string) $this->option('class');

        if ($class === '') {
            $this->error('PASS/FAIL: FAIL - --class is required. Known: '.implode(', ', RetentionClass::names()));

            return self::INVALID;
        }

        $asOf = null;

        if ($this->option('as-of') !== null) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('as-of'));
            } catch (Throwable) {
                $parsed = null;
            }

            if (! $parsed instanceof CarbonImmutable) {
                $this->error('PASS/FAIL: FAIL - invalid --as-of date');

                return self::INVALID;
            }

            $asOf = $parsed;
        }

        try {
            $result = $retention->purge(
                $class,
                (int) $this->option('tenant'),
                $asOf,
                (bool) $this->option('confirm'),
            );
        } catch (Throwable $exception) {
            $this->error('PASS/FAIL: FAIL - '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $preview = $result['preview'];
            $this->table(['Metric', 'Value'], [
                ['Class', $preview['retention_class']],
                ['Candidates', $preview['candidates']],
                ['Held (skipped)', $preview['held']],
                ['Purgeable', $preview['purgeable']],
                ['Confirm supplied', $this->option('confirm') ? 'yes' : 'no'],
                ['Executed', $result['executed'] ? 'yes' : 'no'],
                ['Purged', $result['purged']],
            ]);
        }

        if (! $this->option('confirm')) {
            $this->line('DRY-RUN: retention:purge - nothing deleted; rerun with --confirm after approval');

            return self::SUCCESS;
        }

        $this->line("PASS: retention:purge {$class} purged={$result['purged']} legal_hold_skipped={$result['preview']['held']}");

        return self::SUCCESS;
    }
}
