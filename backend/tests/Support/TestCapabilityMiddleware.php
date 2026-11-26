<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IAM\Contracts\Principal;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\Authorizer;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TestCapabilityMiddleware
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        if (! app()->bound(Principal::class)) {
            throw new AuthenticationException;
        }

        $principal = app(Principal::class);

        if (app()->bound(\App\Modules\IAM\Auth\AuthenticationContext::class)) {
            app(\App\Modules\IAM\Auth\AuthenticationContext::class)->set($principal);
        }

        $clock = app(Clock::class);
        $today = BusinessDate::today((string) config('hrms.default_timezone', 'Asia/Shanghai'), $clock)->value();

        // Single-company tests inject an Authorizer whose scope is described
        // by the instance-wide COMPANY anchor. Production uses the route's
        // explicit anchor; this seam only proves the feature-level gate.
        $anchor = AnchorRef::company();

        if (! app(Authorizer::class)->allows($principal, $capability, $anchor, $today)) {
            throw new AuthorizationException('Forbidden.');
        }

        return $next($request);
    }
}
