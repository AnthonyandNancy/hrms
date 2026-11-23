<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Providers;

use App\Modules\Payroll\Contracts\PayrollCostDirectory;
use App\Modules\Payroll\Services\UnavailablePayrollCostDirectory;
use Illuminate\Support\ServiceProvider;

final class PayrollServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(PayrollCostDirectory::class, UnavailablePayrollCostDirectory::class);
    }
}
