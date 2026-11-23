<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Console;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\ReconciliationService;
use App\Shared\Exceptions\DomainException;
use Illuminate\Console\Command;

final class PayrollReconciliationCommand extends Command
{
    protected $signature = 'payroll:reconciliation
        {--period= : Optional YYYY-MM period}
        {--run= : Optional payroll run id}';

    protected $description = 'Verify payroll run, employee, payslip and correction-chain totals';

    public function handle(ReconciliationService $service): int
    {
        $tenantId = \App\Shared\RequestContext::tenantId();
        $tenantId = $tenantId > 0 ? $tenantId : 1;
        $runId = $this->option('run');
        $period = $this->option('period');

        $query = PayrollRun::query()
            ->where('tenant_id', $tenantId)
            ->when(is_numeric($runId), fn ($q) => $q->whereKey((int) $runId))
            ->when(is_string($period) && $period !== '', fn ($q) => $q->where('period', $period))
            ->whereIn('status', [
                PayrollRunStatus::CALCULATED->value,
                PayrollRunStatus::REVIEWED->value,
                PayrollRunStatus::APPROVED->value,
                PayrollRunStatus::PUBLISHED->value,
                PayrollRunStatus::PAID->value,
                PayrollRunStatus::CLOSED->value,
            ])
            ->orderBy('period')
            ->orderBy('id');

        $runs = $query->get();

        if ($runs->isEmpty()) {
            $this->warn('No calculated payroll runs matched.');

            return 0;
        }

        $failed = 0;
        $results = [];

        foreach ($runs as $run) {
            try {
                $report = $service->reconcile($tenantId, (int) $run->id);
            } catch (DomainException $exception) {
                $this->error("Run {$run->id}: ".$exception->getMessage());
                $failed++;

                continue;
            } catch (\Throwable $exception) {
                $this->error("Run {$run->id}: ".$exception->getMessage());
                $failed++;

                continue;
            }

            $results[] = $report;

            if (! $report['passed']) {
                $failed++;
                $this->error("Run {$run->id} ({$run->period}) RECONCILIATION FAILED.");
            } else {
                $this->info("Run {$run->id} ({$run->period}) reconciled: {$report['employee_count']} employees, net {$report['net_cents']} cents.");
            }
        }

        $this->line((string) json_encode(['runs' => $results, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $failed === 0 ? 0 : 1;
    }
}
