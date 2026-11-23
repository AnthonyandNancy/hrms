<?php

declare(strict_types=1);

namespace App\Providers;

use App\Shared\RequestContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(RequestContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // IAM replaces this alias with its session middleware. Keeping auth as a
        // named route concern makes 401-vs-403 semantics explicit per route.
        if (! class_exists(\App\Modules\IAM\Http\Middleware\AuthenticateSession::class)) {
            app('router')->aliasMiddleware('auth.session', \App\Shared\Auth\RejectUnauthenticated::class);
        }
    }
}
