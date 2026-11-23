<?php

declare(strict_types=1);

namespace App\Modules\Time\Providers;

use App\Modules\Time\Contracts\LeaveBalanceReader;
use App\Modules\Time\Services\LeaveBalanceReaderService;
use Illuminate\Support\ServiceProvider;

final class TimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LeaveBalanceReader::class, LeaveBalanceReaderService::class);
    }
}
