<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Console;

use App\Modules\Payroll\Services\PayrollParallelCheckService;
use App\Shared\Exceptions\DomainException;
use Illuminate\Console\Command;

/**
 * Exit codes: 0 = match, 1 = differences beyond tolerance, 2 = invalid input
 * or a missing old export. Missing files are never treated as "no diff".
 */
final class PayrollParallelCheckCommand extends Command
{
    protected $signature = 'payroll:parallel-check
        {--period= : YYYY-MM period}
        {--old-file= : CSV with employment_id,gross_cents,net_cents}';

    protected $description = 'Compare calculated payroll against an old-system export';

    public function handle(PayrollParallelCheckService $service): int
    {
        $period = (string) $this->option('period');
        $file = (string) $this->option('old-file');

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            $this->error('--period must be YYYY-MM.');

            return 2;
        }

        if ($file === '' || ! is_file($file)) {
            $this->error('--old-file is required and must exist.');

            return 2;
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            $this->error('The old export could not be read.');

            return 2;
        }

        try {
            $result = $service->compareText($period, $contents);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return 2;
        } catch (\Throwable $exception) {
            $this->error('Parallel check failed: '.$exception->getMessage());

            return 2;
        }

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        if (! $result['passed']) {
            $this->error(sprintf(
                'Parallel check FAILED: %d unexplained difference(s), headcount %d vs %d.',
                $result['unexplained_count'],
                $result['headcount_new'],
                $result['headcount_old'],
            ));

            return 1;
        }

        $this->info('Parallel check passed within 0.01 tolerance and matching headcount.');

        return 0;
    }
}
