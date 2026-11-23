<?php

declare(strict_types=1);

namespace App\Shared;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts a safe inbound correlation id or generates a ULID. The id is exposed
 * as a request attribute, response header, log context and RequestContext.
 */
final class RequestId
{
    public const HEADER = 'X-Request-Id';

    public const ATTRIBUTE = 'request_id';

    private const PATTERN = '/^[A-Za-z0-9._:-]{8,64}$/';

    public function __construct(private readonly RequestContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $inbound = $request->headers->get(self::HEADER);
        $requestId = is_string($inbound) && preg_match(self::PATTERN, $inbound) === 1
            ? $inbound
            : (string) Str::ulid();

        $request->attributes->set(self::ATTRIBUTE, $requestId);
        $this->context->set($requestId);
        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
