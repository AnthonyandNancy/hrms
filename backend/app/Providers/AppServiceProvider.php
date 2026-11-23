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
        // IAM replaces this alias with its session middleware. Keeping auth as a
        // named route concern makes 401-vs-403 semantics explicit per route.
        if (app()->environment('testing')) {
            // Tests bind an explicit Principal instead of exercising cookies.
            // The seam still denies when no principal is bound.
            app('router')->aliasMiddleware('auth.session', \Tests\Support\TestSessionMiddleware::class);
            app('router')->aliasMiddleware('capability', \Tests\Support\TestCapabilityMiddleware::class);
        } elseif (! class_exists(\App\Modules\IAM\Http\Middleware\AuthenticateSession::class)) {
            app('router')->aliasMiddleware('auth.session', \App\Shared\Auth\RejectUnauthenticated::class);
        }
    }
}
