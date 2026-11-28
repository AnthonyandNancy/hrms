<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Providers;

use App\Modules\Payroll\Authorization\EloquentPayrollRunMembershipDirectory;
use App\Modules\Payroll\Console\PayrollParallelCheckCommand;
use App\Modules\Payroll\Console\PayrollReconciliationCommand;
use App\Modules\Payroll\Console\SeedDemoPayrollRunCommand;
use App\Modules\Payroll\Console\SeedPayrollParametersCommand;
use App\Modules\Payroll\Contracts\CompensationVersionDirectory;
use App\Modules\Payroll\Contracts\OnboardingCompensationDraftWriter;
use App\Modules\Payroll\Contracts\PayrollApprovalEffectPort;
use App\Modules\Payroll\Contracts\PayrollCostDirectory;
use App\Modules\Payroll\Contracts\PayrollRunDirectory;
use App\Modules\Payroll\Contracts\PayslipWorkspaceDirectory;
use App\Modules\Payroll\Domain\PayrollCalculator;
use App\Modules\Payroll\Services\ApprovalService;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\CorrectionService;
use App\Modules\Payroll\Services\EloquentCompensationVersionDirectory;
use App\Modules\Payroll\Services\EloquentPayrollRunDirectory;
use App\Modules\Payroll\Services\EloquentPayslipWorkspaceDirectory;
use App\Modules\Payroll\Services\ExportService;
use App\Modules\Payroll\Services\OnboardingCompensationDraftService;
use App\Modules\Payroll\Services\PayrollApprovalEffectService;
use App\Modules\Payroll\Services\PayrollParallelCheckService;
use App\Modules\Payroll\Services\PayrollPeriodLockReaderService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Services\PayrollYtdService;
use App\Modules\Payroll\Services\ReceiptReconciliationService;
use App\Modules\Payroll\Services\ReconciliationService;
use App\Modules\Payroll\Services\ReopenService;
use App\Modules\Payroll\Services\SnapshotService;
use App\Modules\Payroll\Services\TaxParameterResolver;
use App\Modules\Payroll\Services\UnavailablePayrollCostDirectory;
use App\Modules\Time\Contracts\PayrollPeriodLockReader;
use App\Shared\Authorization\Contracts\PayrollRunMembershipDirectory;
use Illuminate\Support\ServiceProvider;

/**
 * Payroll's composition root. Time, People and Workflow bind their own
 * adapters for the Payroll-owned ports; Payroll only imports other modules'
 * Contracts here.
 */
final class PayrollServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PayrollApprovalEffectPort::class, PayrollApprovalEffectService::class);
        $this->app->bind(OnboardingCompensationDraftWriter::class, OnboardingCompensationDraftService::class);
        $this->app->bind(CompensationVersionDirectory::class, EloquentCompensationVersionDirectory::class);
        $this->app->bindIf(PayrollCostDirectory::class, UnavailablePayrollCostDirectory::class);
        $this->app->bind(PayslipWorkspaceDirectory::class, EloquentPayslipWorkspaceDirectory::class);
        $this->app->bindIf(PayrollRunDirectory::class, EloquentPayrollRunDirectory::class);
        if (! $this->app->environment('testing')) {
            $this->app->bind(PayrollRunMembershipDirectory::class, EloquentPayrollRunMembershipDirectory::class);
            $this->app->bind(PayrollPeriodLockReader::class, PayrollPeriodLockReaderService::class);
        }
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
                SeedDemoPayrollRunCommand::class,
                PayrollReconciliationCommand::class,
                PayrollParallelCheckCommand::class,
            ]);
        }
    }
}
