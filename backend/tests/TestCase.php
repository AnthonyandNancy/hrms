<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Test seam: replace the production session gate with one that reads
        // the explicitly bound TestPrincipal, and keep the capability alias
        // testable. Production bootstrap aliases still apply to app code.
        $this->app['router']->aliasMiddleware('auth.session', Support\TestSessionMiddleware::class);
        $this->app['router']->aliasMiddleware('capability', Support\TestCapabilityMiddleware::class);
    }

    /**
     * Register HTTP fixtures used to exercise framework exception mapping.
     * These routes exist only in the in-memory test application.
     */
    protected function defineExceptionFixtureRoutes(): void
    {
        Route::middleware('api')->prefix('v1')->group(function (): void {
            Route::post('/test-validation', function (Request $request): never {
                $request->validate(['name' => ['required', 'string']]);

                throw new RuntimeException('Validation route should not reach this point.');
            });

            Route::get('/test-throttle', function (): never {
                throw new ThrottleRequestsException('Too Many Attempts.');
            });

            Route::get('/test-boom', function (): never {
                throw new RuntimeException('SQLSTATE[HY000] secret query in D:/srv/app/Private.php at line 42');
            });
        });
    }
}
