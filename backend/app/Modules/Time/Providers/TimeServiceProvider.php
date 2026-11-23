<?php

declare(strict_types=1);

namespace App\Modules\Time\Providers;

use App\Modules\Time\Contracts\LeaveLiabilityDirectory;
use App\Modules\Time\Services\EloquentLeaveLiabilityDirectory;
use Illuminate\Support\ServiceProvider;

final class TimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(LeaveLiabilityDirectory::class, EloquentLeaveLiabilityDirectory::class);
    }
}
