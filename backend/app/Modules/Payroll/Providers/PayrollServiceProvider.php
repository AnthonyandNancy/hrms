<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Providers;

use App\Modules\Payroll\Console\PayrollParallelCheckCommand;
use App\Modules\Payroll\Console\PayrollReconciliationCommand;
use App\Modules\Payroll\Console\SeedPayrollParametersCommand;
use App\Modules\Payroll\Contracts\PayrollApprovalEffectPort;
use App\Modules\Payroll\Contracts\PayrollApprovalPort;
use App\Modules\Payroll\Domain\PayrollCalculator;
use App\Modules\Payroll\Services\ApprovalService;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\CorrectionService;
use App\Modules\Payroll\Services\ExportService;
use App\Modules\Payroll\Services\PayrollApprovalEffectService;
use App\Modules\Payroll\Services\PayrollParallelCheckService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Services\PayrollYtdService;
use App\Modules\Payroll\Services\ReceiptReconciliationService;
use App\Modules\Payroll\Services\ReconciliationService;
use App\Modules\Payroll\Services\ReopenService;
use App\Modules\Payroll\Services\SnapshotService;
use App\Modules\Payroll\Services\TaxParameterResolver;
use Illuminate\Support\ServiceProvider;

/**
 * Payroll's composition root. It wires Payroll services plus the narrow
 * Time/People/Workflow read adapters Payroll consumes. No Payroll model is
 * exported outside this module.
 */
final class PayrollServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PayrollApprovalPort::class, PayrollApprovalWorkflow::class);
        $this->app->bind(PayrollApprovalEffectPort::class, PayrollApprovalEffectService::class);

        $this->app->singleton(PayrollCalculator::class);
        $this->app->singleton(TaxParameterResolver::class);
        $this->app->singleton(SnapshotService::class);
        $this->app->singleton(PayrollRunService::class);
        $this->app->singleton(PayrollParallelCheckService::class);
        $this->app->singleton(CalculationService::class);
        $this->app->singleton(ApprovalService::class);
        $this->app->singleton(PayrollYtdService::class);
        $this->app->singleton(CorrectionService::class);
        $this->app->singleton(ReopenService::class);
        $this->app->singleton(ExportService::class);
        $this->app->singleton(ReconciliationService::class);
        $this->app->singleton(ReceiptReconciliationService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SeedPayrollParametersCommand::class,
                PayrollReconciliationCommand::class,
                PayrollParallelCheckCommand::class,
            ]);
        }
    }
}
