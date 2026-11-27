<?php

declare(strict_types=1);

namespace App\Modules\Platform\Retention\Commands;

use App\Modules\Platform\Retention\RetentionClass;
use App\Modules\Platform\Retention\RetentionService;
use Illuminate\Console\Command;

class RetentionPreviewCommand extends Command
{
    protected $signature = 'retention:preview
        {--class= : Retention class}
        {--as-of= : Evaluate expiry at this date (YYYY-MM-DD)}
        {--json : Print JSON}
        {--all : Preview every retention class}';

    protected $description = 'Preview retention candidates without deleting anything';

    public function handle(RetentionService $retention): int
    {
        $classes = $this->option('all')
            ? RetentionClass::names()
            : [(string) $this->option('class')];

        if ($classes === [''] || $classes === []) {
            $this->error('PASS/FAIL: FAIL - provide --class or --all');

            return self::INVALID;
        }

        $asOf = null;

        if ($this->option('as-of') !== null) {
            try {
                $parsed = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('as-of'));
            } catch (\Throwable) {
                $parsed = null;
            }

            if (! $parsed instanceof \Carbon\CarbonImmutable) {
                $this->error('PASS/FAIL: FAIL - invalid --as-of date');

                return self::INVALID;
            }

            $asOf = $parsed;
        }

        $results = [];

        foreach ($classes as $class) {
            try {
                $results[] = $retention->preview($class, $asOf);
            } catch (\InvalidArgumentException $exception) {
                $this->error('PASS/FAIL: FAIL - '.$exception->getMessage());

                return self::INVALID;
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['Class', 'Table', 'Available', 'Cutoff', 'Candidates', 'Held', 'Purgeable'],
                array_map(static fn (array $row): array => [
                    $row['retention_class'],
                    $row['table'],
                    $row['available'] ? 'yes' : 'no',
                    $row['cutoff'] ?? '-',
                    $row['candidates'],
                    $row['held'],
                    $row['purgeable'],
                ], $results),
            );
        }

        $this->line('PASS: retention:preview - read-only, nothing deleted');

        return self::SUCCESS;
    }
}
