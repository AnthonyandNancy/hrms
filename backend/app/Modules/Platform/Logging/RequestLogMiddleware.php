<?php

declare(strict_types=1);

namespace App\Modules\Platform\Logging;

use App\Shared\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * One structured line per request with correlation ids and timing, never the
 * request body, query string or response payload. Emitted on the stack channel
 * so the shared redaction processor applies.
 */
final class RequestLogMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->write($request, 500, $started, $exception);

            throw $exception;
        }

        $this->write($request, $response->getStatusCode(), $started);

        return $response;
    }

    private function write(Request $request, int $status, int $started, ?Throwable $exception = null): void
    {
        $durationMs = (hrtime(true) - $started) / 1_000_000;
        $context = [
            'request_id' => RequestContext::requestId(),
            'user_id' => RequestContext::userId(),
            'route' => $request->route()?->getName() ?? '/'.$request->path(),
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $status,
            'duration_ms' => round($durationMs, 2),
        ];

        if ($exception !== null) {
            $context['exception_class'] = $exception::class;
        }

        Log::info('http.request', $context);
    }
}
