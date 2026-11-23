<?php

declare(strict_types=1);

namespace App\Providers;

use App\Shared\RequestContext;
use App\Shared\Time\Clock;
use App\Shared\Time\SystemClock;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(RequestContext::class);
        $this->app->bind(Clock::class, SystemClock::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
