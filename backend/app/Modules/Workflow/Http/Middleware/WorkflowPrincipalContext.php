<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Middleware;

use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Contracts\WorkflowPrincipalResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the resolved principal available to controllers without exposing the
 * resolver and IAM wiring in every action.
 */
final class WorkflowPrincipalContext
{
    public const ATTRIBUTE = 'workflow_principal';

    public function __construct(private readonly WorkflowPrincipalResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $principal = $this->resolver->resolve();

        if ($principal !== null) {
            $request->attributes->set(self::ATTRIBUTE, $principal);
        }

        return $next($request);
    }

    public static function from(Request $request): WorkflowPrincipal
    {
        $principal = $request->attributes->get(self::ATTRIBUTE);

        if (! $principal instanceof WorkflowPrincipal) {
            throw new \RuntimeException('Workflow principal was not resolved.');
        }

        return $principal;
    }
}
