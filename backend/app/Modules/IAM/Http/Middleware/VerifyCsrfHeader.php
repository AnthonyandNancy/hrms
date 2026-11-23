<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Middleware;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Services\SessionManager;
use App\Shared\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session-bound CSRF check using X-CSRF-Token. Safe methods are unaffected;
 * an absent/invalid token on a mutating request is 419 with the API envelope.
 */
final class VerifyCsrfHeader
{
    public function __construct(
        private readonly AuthenticationContext $context,
        private readonly SessionManager $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $principal = $this->context->principalOrNull();
        $sessionId = $principal?->sessionId();

        if ($sessionId === null) {
            return ApiResponse::error('CSRF_FAILED', 'The CSRF token is missing or invalid.', 419, ['reason' => 'CSRF_FAILED']);
        }

        $session = \App\Modules\IAM\Models\Session::query()->whereKey($sessionId)->first();

        if ($session === null || ! $this->sessions->csrfMatches($session, $request->header('X-CSRF-Token'))) {
            return ApiResponse::error('CSRF_FAILED', 'The CSRF token is missing or invalid.', 419, ['reason' => 'CSRF_FAILED']);
        }

        return $next($request);
    }
}
