<?php

declare(strict_types=1);

namespace App\Modules\Platform\Demo\Commands;

use App\Modules\Platform\Demo\Services\DemoSeeder;
use Illuminate\Console\Command;
use Throwable;

class DemoResetCommand extends Command
{
    protected $signature = 'demo:reset
        {--force : Required non-interactive confirmation}';

    protected $description = 'Remove synthetic demo data (non-production only)';

    public function handle(DemoSeeder $seeder): int
    {
        if (app()->environment('production')) {
            $this->error('PASS/FAIL: FAIL - demo:reset is refused in production');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Delete the synthetic demo dataset and its staging/rehearsal rows?')) {
            $this->line('ABORTED: no data deleted');

            return self::SUCCESS;
        }

        try {
            $deleted = $seeder->reset();
        } catch (Throwable $exception) {
            $this->error('PASS/FAIL: FAIL - '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line("PASS: demo:reset deleted={$deleted}");

        return self::SUCCESS;
    }
}
