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
 * Resolves the narrow WorkflowPrincipal contract for every Workflow endpoint.
 * Integration replaces WorkflowPrincipalResolver with an IAM adapter; in the
 * reference build the resolver can also be populated from request attributes.
 */
final class ResolveWorkflowPrincipal
{
    public function __construct(private readonly WorkflowPrincipalResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $principal = $this->resolver->resolve();

        if ($principal === null) {
            return ApiResponse::error(
                ErrorCode::UNAUTHENTICATED->value,
                ErrorCode::UNAUTHENTICATED->defaultMessage(),
                401,
            );
        }

        return $next($request);
    }
}
