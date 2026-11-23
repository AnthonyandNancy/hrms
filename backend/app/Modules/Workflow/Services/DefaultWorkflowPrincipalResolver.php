<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Contracts\WorkflowPrincipalResolver;
use Illuminate\Http\Request;

/**
 * Integration seam. The IAM adapter (or a test fake) can bind itself; this
 * default accepts a WorkflowPrincipal placed on the request attribute by the
 * auth layer, and otherwise fails closed with 401.
 */
final class DefaultWorkflowPrincipalResolver implements WorkflowPrincipalResolver
{
    public const REQUEST_ATTRIBUTE = 'workflow.principal';

    public function resolve(): ?WorkflowPrincipal
    {
        $request = app()->bound('request') ? app('request') : null;

        if (! $request instanceof Request) {
            return null;
        }

        $principal = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return $principal instanceof WorkflowPrincipal ? $principal : null;
    }
}
