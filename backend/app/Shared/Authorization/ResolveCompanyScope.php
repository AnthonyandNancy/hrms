<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Modules\Organization\Contracts\CompanyProfileReader;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches the singleton company profile to the request. The profile is
 * configuration, not a data-isolation boundary: no scope is resolved, merged
 * or enforced here.
 */
final class ResolveCompanyScope
{
    public function __construct(private readonly CompanyProfileReader $profiles) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('company_profile', $this->profiles->current());

        return $next($request);
    }
}
