<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Contracts;

/**
 * Adapter point to the IAM request principal. Tests bind a fake; integration
 * binds the authenticated IAM principal without Workflow depending on IAM
 * models or middleware internals.
 */
interface WorkflowPrincipalResolver
{
    public function resolve(): ?WorkflowPrincipal;
}
