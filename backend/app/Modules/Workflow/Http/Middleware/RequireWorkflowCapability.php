<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Middleware;

use App\Modules\Workflow\Contracts\WorkflowPrincipalResolver;
use App\Shared\ApiResponse;
use App\Shared\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Default-deny capability guard for Workflow endpoints. The route's
 * capability:<code> middleware metadata is the source of truth, so a missing
 * code can never silently fall back to authenticated-but-unchecked.
 */
final class RequireWorkflowCapability
{
    public function __construct(private readonly WorkflowPrincipalResolver $resolver) {}

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $principal = $this->resolver->resolve();

        if ($principal === null) {
            return ApiResponse::error(
                ErrorCode::UNAUTHENTICATED->value,
                ErrorCode::UNAUTHENTICATED->defaultMessage(),
                401,
            );
        }

        if (! $principal->can($capability)) {
            return ApiResponse::error(
                ErrorCode::FORBIDDEN->value,
                ErrorCode::FORBIDDEN->defaultMessage(),
                403,
                ['reason' => 'CAPABILITY_REQUIRED', 'capability' => $capability],
            );
        }

        return $next($request);
    }
}
