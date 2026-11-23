<?php

declare(strict_types=1);

namespace App\Modules\Platform\Demo\Commands;

use App\Modules\Platform\Demo\Services\DemoSeeder;
use Illuminate\Console\Command;
use Throwable;

class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed
        {--tenant=1 : Tenant id}
        {--live : Write live business tables when every target adapter is available}
        {--json : Print JSON}';

    protected $description = 'Seed the synthetic demo tenant (LIVE business tables, or SHADOW rehearsal target)';

    public function handle(DemoSeeder $seeder): int
    {
        if (app()->environment('production')) {
            $this->error('PASS/FAIL: FAIL - demo:seed is refused in production');

            return self::FAILURE;
        }

        try {
            $result = $seeder->seed((bool) $this->option('live'), (int) $this->option('tenant'));
        } catch (Throwable $exception) {
            $this->error('PASS/FAIL: FAIL - '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['Domain', 'Mode', 'Status', 'Read', 'Created', 'Updated', 'Skipped'],
                array_map(static fn (string $domain, array $row): array => [
                    $domain,
                    $row['mode'],
                    $row['status'],
                    $row['rows_read'],
                    $row['rows_created'],
                    $row['rows_updated'],
                    $row['rows_skipped'],
                ], array_keys($result['domains']), array_values($result['domains'])),
            );

            if ($result['deferred'] !== []) {
                $this->warn('SHADOW MODE: these domains were rehearsed in the shadow target only, not written to business tables. Re-run with --live to import into real tables.');
            }
        }

        $this->line(sprintf(
            'PASS: demo:seed dataset=%s mode=%s domains=%d',
            $result['dataset'],
            $result['mode'],
            count($result['domains']),
        ));

        return self::SUCCESS;
    }
}
