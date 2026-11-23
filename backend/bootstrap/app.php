<?php

declare(strict_types=1);

use App\Shared\Exceptions\ExceptionRenderer;
use App\Shared\RequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('v1/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->is('v1/*') && ! $request->expectsJson()) {
                return null;
            }

            return app(ExceptionRenderer::class)->render($exception);
        });

        $exceptions->respond(function ($response, Throwable $exception, Request $request) {
            if (($request->is('api/*') || $request->is('v1/*') || $request->expectsJson())
                && method_exists($response, 'headers')
                && $request->attributes->has(RequestId::ATTRIBUTE)) {
                $response->headers->set(RequestId::HEADER, (string) $request->attributes->get(RequestId::ATTRIBUTE));
            }

            return $response;
        });
    })->create();
