<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Shared\ApiResponse;
use App\Shared\ErrorCode;
use App\Shared\RequestContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed capability guard used until the IAM module binds CapabilityGate
 * to the frozen Authorizer. Missing gate, missing principal and unknown
 * capabilities all return the same 403 envelope; no client header can assert a
 * capability. Routes remain auditable by their capability:<code> metadata.
 */
final class EnsureCapability
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $container = Container::getInstance();
        $gate = $container->bound(CapabilityGate::class) ? $container->make(CapabilityGate::class) : null;

        if ($gate !== null && $gate->allows($request, $capability)) {
            return $next($request);
        }

        // A bound Principal plus a permitting Authorizer is the legitimate
        // transitional/test path. Production IAM binds CapabilityGate. When no
        // identity at all is present the request is unauthenticated (401).
        if (! $container->bound(\App\Modules\IAM\Contracts\Principal::class)) {
            // Authentication is an explicit route concern. If the route asks
            // for a session, missing identity is 401; otherwise the capability
            // guard fails closed with 403.
            $routeMiddleware = $request->route()?->gatherMiddleware() ?? [];

            if (in_array('auth.session', $routeMiddleware, true)
                || in_array(\App\Shared\Auth\RejectUnauthenticated::class, $routeMiddleware, true)
                || in_array('App\Modules\IAM\Http\Middleware\AuthenticateSession', $routeMiddleware, true)) {
                throw new AuthenticationException;
            }

            return $this->deny($request, $capability, $gate === null ? 'capability_gate_unbound' : 'principal_missing');
        }

        if ($container->bound(Authorizer::class)
            && $container->make(Authorizer::class)->allows(
                $container->make(\App\Modules\IAM\Contracts\Principal::class),
                $capability,
                AnchorRef::global(),
                \App\Shared\Time\BusinessDate::today((string) config('hrms.default_timezone', 'Asia/Shanghai'), $container->make(\App\Shared\Time\Clock::class))->value(),
            )) {
            return $next($request);
        }

        Log::warning('authorization.denied', [
            'request_id' => RequestContext::requestId(),
            'tenant_id' => RequestContext::tenantId(),
            'user_id' => RequestContext::userId(),
            'route' => $request->route()?->getName() ?? '/'.$request->path(),
            'capability' => $capability,
            'reason' => $gate === null ? 'capability_gate_unbound' : 'capability_denied',
        ]);

        return $this->deny($request, $capability, $gate === null ? 'capability_gate_unbound' : 'capability_denied');
    }

    private function deny(Request $request, string $capability, string $reason): \Illuminate\Http\JsonResponse
    {
        Log::warning('authorization.denied', [
            'request_id' => RequestContext::requestId(),
            'tenant_id' => RequestContext::tenantId(),
            'user_id' => RequestContext::userId(),
            'route' => $request->route()?->getName() ?? '/'.$request->path(),
            'capability' => $capability,
            'reason' => $reason,
        ]);

        return ApiResponse::error(
            ErrorCode::FORBIDDEN->value,
            ErrorCode::FORBIDDEN->defaultMessage(),
            ErrorCode::FORBIDDEN->status(),
            ['capability' => $capability],
        );
    }
}
