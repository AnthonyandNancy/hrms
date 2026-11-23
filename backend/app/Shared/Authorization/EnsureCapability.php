<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Shared\ApiResponse;
use App\Shared\ErrorCode;
use App\Shared\RequestContext;
use Closure;
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

        Log::warning('authorization.denied', [
            'request_id' => RequestContext::requestId(),
            'tenant_id' => RequestContext::tenantId(),
            'user_id' => RequestContext::userId(),
            'route' => $request->route()?->getName() ?? '/'.$request->path(),
            'capability' => $capability,
            'reason' => $gate === null ? 'capability_gate_unbound' : 'capability_denied',
        ]);

        return ApiResponse::error(
            ErrorCode::FORBIDDEN->value,
            ErrorCode::FORBIDDEN->defaultMessage(),
            ErrorCode::FORBIDDEN->status(),
            ['capability' => $capability],
        );
    }
}
