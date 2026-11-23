<?php

declare(strict_types=1);

/**
 * Emit a payroll-period "old system" CSV for the parallel-run gate.
 *
 * READ THIS BEFORE TRUSTING THE RESULT: this produces a *synthetic counterpart*
 * from the same calculated rows. It proves that the parallel-check mechanism
 * reconciles headcount, gross and net end to end; it does NOT prove agreement
 * with a historical payroll system. Real parallel running requires a genuine
 * legacy extract and remains an organizational gate.
 *
 * Usage: php scripts/payroll_old_export.php [--period=YYYY-MM] > /tmp/old.csv
 */

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../backend/vendor/autoload.php';

$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$period = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--period=')) {
        $period = substr($argument, strlen('--period='));
    }
}

$countable = [
    PayrollRunStatus::CALCULATED,
    PayrollRunStatus::REVIEWED,
    PayrollRunStatus::APPROVED,
    PayrollRunStatus::PUBLISHED,
    PayrollRunStatus::PAID,
    PayrollRunStatus::CLOSED,
];

$runs = PayrollRun::query()
    ->orderBy('id')
    ->get()
    ->filter(fn (PayrollRun $run): bool => in_array($run->status, $countable, true))
    ->when($period !== null, fn ($collection) => $collection->where('period', $period));

if ($runs->isEmpty()) {
    fwrite(STDERR, "No calculated payroll run found".($period !== null ? " for period {$period}" : '').".\n");
    exit(2);
}

fwrite(STDERR, "WARNING: synthetic counterpart export generated from the new system; this is not a legacy-system extract.\n");

$rows = PayrollRunEmployee::query()
    ->whereIn('run_id', $runs->pluck('id')->all())
    ->where('status', '!=', 'FAILED')
    ->orderBy('employment_id')
    ->get();

foreach ($rows as $row) {
    printf("%d,%d,%d\n", (int) $row->employment_id, (int) $row->gross_cents, (int) $row->net_cents);
}
