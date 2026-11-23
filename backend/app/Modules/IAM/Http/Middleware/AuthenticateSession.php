<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Middleware;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Auth\AuthenticationFailedException;
use App\Modules\IAM\Auth\PrincipalResolver;
use App\Shared\ApiResponse;
use App\Shared\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates every protected /v1 request. Unauthenticated requests get the
 * standard envelope and never reach a controller or query.
 */
final class AuthenticateSession
{
    public function __construct(
        private readonly PrincipalResolver $resolver,
        private readonly AuthenticationContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Testing seam: feature tests may bind an explicit Principal instead
        // of driving a browser cookie. Production never has this binding.
        if (app()->environment('testing') && app()->bound(\App\Modules\IAM\Contracts\Principal::class)) {
            $principal = app(\App\Modules\IAM\Contracts\Principal::class);
            $this->context->set($principal);
            $request->attributes->set('iam.principal', $principal);
            app()->instance(\App\Shared\Auth\AuthenticatedPrincipal::class, $principal);

            return $next($request);
        }

        try {
            [$principal, , $user] = $this->resolver->resolve($request);
        } catch (AuthenticationFailedException $exception) {
            return ApiResponse::error(
                ErrorCode::UNAUTHENTICATED->value,
                'Authentication is required or the session is no longer valid.',
                401,
                ['reason' => $exception->details()['reason'] ?? 'UNAUTHENTICATED'],
            );
        }

        $this->context->set($principal);
        app()->instance(\App\Shared\Auth\AuthenticatedPrincipal::class, $principal);
        $request->attributes->set('iam.user', $user);
        $request->attributes->set('iam.principal', $principal);
        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
