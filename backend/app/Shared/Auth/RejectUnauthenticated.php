<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transitional authentication guard used only until IAM is installed. It
 * rejects every request, which preserves 401 semantics for business routes
 * without weakening default deny.
 */
final class RejectUnauthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        throw new AuthenticationException;
    }
}
