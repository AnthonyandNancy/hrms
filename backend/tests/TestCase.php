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
