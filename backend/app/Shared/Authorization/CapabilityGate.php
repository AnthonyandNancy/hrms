<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use Illuminate\Http\Request;

/**
 * Temporary integration seam for the IAM-owned Authorizer described in the
 * interface freeze. The capability middleware fails closed when no gate is
 * bound, so a parallel branch can never accidentally expose a /v1 route.
 */
interface CapabilityGate
{
    public function allows(Request $request, string $capability): bool;
}
