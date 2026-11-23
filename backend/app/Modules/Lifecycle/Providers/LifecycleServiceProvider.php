<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Providers;

use App\Modules\Lifecycle\Contracts\TurnoverDirectory;
use App\Modules\Lifecycle\Services\UnavailableTurnoverDirectory;
use Illuminate\Support\ServiceProvider;

final class LifecycleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(TurnoverDirectory::class, UnavailableTurnoverDirectory::class);
    }
}
