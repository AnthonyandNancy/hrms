<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Contracts\Principal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test-only session middleware. It requires a bound TestPrincipal and
 * populates IAM's request context; it never fabricates an identity.
 */
final class TestSessionMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->bound(Principal::class)) {
            return \App\Shared\ApiResponse::error(
                \App\Shared\ErrorCode::UNAUTHENTICATED->value,
                'Authentication is required.',
                401,
                ['reason' => 'UNAUTHENTICATED'],
            );
        }

        $principal = app(Principal::class);
        $request->attributes->set('iam.principal', $principal);
        app()->instance(\App\Shared\Auth\AuthenticatedPrincipal::class, $principal);

        if (app()->bound(AuthenticationContext::class)) {
            app(AuthenticationContext::class)->set($principal);
        }

        return $next($request);
    }
}
