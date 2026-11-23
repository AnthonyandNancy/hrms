<?php

declare(strict_types=1);

namespace App\Modules\Time\Providers;

use App\Modules\Time\Contracts\PayrollInputDirectory;
use App\Modules\Time\Services\PayrollInputDirectoryService;
use Illuminate\Support\ServiceProvider;

/**
 * Time owns the payroll attendance projection. Payroll consumes only the
 * Time\Contracts\PayrollInputDirectory interface.
 */
final class TimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PayrollInputDirectory::class, PayrollInputDirectoryService::class);
    }
}
