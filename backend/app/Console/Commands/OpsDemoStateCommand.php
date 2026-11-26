<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Platform\Migration\Models\MigrationRun;
use Illuminate\Console\Command;

class OpsDemoStateCommand extends Command
{
    protected $signature = 'ops:demo-state
        {--json : Print JSON}';

    protected $description = 'Summarise demo migration rows for deploy smoke checks';

    public function handle(): int
    {
        $rows = [];

        foreach (MigrationDomain::cases() as $domain) {
            $run = MigrationRun::query()
                ->where('domain', $domain->value)
                ->where('status', 'APPLIED')
                ->latest('id')
                ->first();
            $rows[] = [
                'domain' => $domain->value,
                'run_id' => $run?->id,
                'status' => $run?->status->value ?? 'MISSING',
                'target' => $run?->report_json['target'] ?? null,
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(['Domain', 'Run', 'Status', 'Target'], array_map(static fn (array $row): array => array_values($row), $rows));
        }

        $applied = count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'APPLIED'));
        $this->line(($applied === count($rows) ? 'PASS' : 'FAIL').": ops:demo-state applied={$applied}/".count($rows));

        return $applied === count($rows) ? self::SUCCESS : self::FAILURE;
    }
}
